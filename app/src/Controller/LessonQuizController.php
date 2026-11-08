<?php

namespace App\Controller;

use App\Entity\LessonTopic;
use App\Entity\LessonQuiz;
use App\Entity\QuizQuestion;
use App\Entity\QuizAnswer;
use App\Entity\QuizAttempt;
use App\Form\LessonQuizType;
use App\Form\QuizSubmissionType;
use App\Security\LessonVoter;
use App\Security\UserRole;
use App\Service\QuizSubmission;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LessonQuizController extends AbstractController
{
    #[Route('/lessons/{lessonId}/tests/new', name: 'app_lesson_quiz_new', requirements: ['lessonId' => '\d+'], methods: ['GET', 'POST'])]
    #[Route('/lesson-tests/{id}/edit', name: 'app_lesson_quiz_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $manager, ?int $lessonId = null, ?int $id = null): Response
    {
        if ($id !== null) {
            $quiz = $this->quiz($id, $manager); $lesson = $quiz->getLesson();
        } else {
            $lesson = $manager->find(LessonTopic::class, $lessonId) ?? throw $this->createNotFoundException();
            $quiz = (new LessonQuiz())->setLesson($lesson)->addQuestion((new QuizQuestion())->addAnswer(new QuizAnswer())->addAnswer(new QuizAnswer()));
        }
        $this->denyAccessUnlessGranted(LessonVoter::EDIT, $lesson);
        $form = $this->createForm(LessonQuizType::class, $quiz);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $id !== null && $form->get('revision')->getData() !== (string) $quiz->getRevision()) {
            $form->addError(new FormError('Тест уже змінив інший викладач. Відкрийте редактор повторно.'));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyAccessUnlessGranted(LessonVoter::EDIT, $lesson);
            foreach ($quiz->getQuestions()->getValues() as $position => $question) {
                $question->setPosition($position);
                foreach ($question->getAnswers()->getValues() as $answerPosition => $answer) $answer->setPosition($answerPosition);
            }
            $quiz->revise(); $manager->persist($quiz);
            try { $manager->flush(); } catch (OptimisticLockException $error) {
                throw new ConflictHttpException('Тест уже змінив інший викладач. Відкрийте редактор повторно.', $error);
            }
            $this->addFlash('success', 'Тест збережено.');
            return $this->redirectToRoute('app_lesson_quiz_show', ['id' => $quiz->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('lesson/quiz_form.html.twig', ['quiz' => $quiz, 'lesson' => $lesson, 'quizForm' => $form]);
    }

    #[Route('/lesson-tests/{id}', name: 'app_lesson_quiz_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $manager): Response
    {
        $quiz = $this->quiz($id, $manager);
        $this->view($quiz);
        $filter = ['quiz' => $quiz];
        if ($this->getUser()->getRole() === UserRole::Student) $filter['student'] = $this->getUser();
        $attempts = $manager->getRepository(QuizAttempt::class)->findBy($filter, ['submittedAt' => 'DESC', 'id' => 'DESC']);

        return $this->render('lesson/quiz_show.html.twig', ['quiz' => $quiz, 'lesson' => $quiz->getLesson(), 'attempts' => $attempts]);
    }

    #[Route('/lesson-tests/{id}/take', name: 'app_lesson_quiz_take', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function take(int $id, Request $request, EntityManagerInterface $manager, QuizSubmission $submission): Response
    {
        if ($this->getUser()->getRole() !== UserRole::Student) throw $this->createAccessDeniedException();
        $quiz = $this->quiz($id, $manager); $this->view($quiz);
        $used = $manager->getRepository(QuizAttempt::class)->count(['quiz' => $quiz, 'student' => $this->getUser()]);
        if ($used >= $quiz->getMaxAttempts()) {
            $this->addFlash('success', 'Усі дозволені спроби використано.');
            return $this->redirectToRoute('app_lesson_quiz_show', ['id' => $id]);
        }
        $form = $this->createForm(QuizSubmissionType::class, null, ['quiz' => $quiz]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $attempt = $submission->submit($quiz, $this->getUser(), $form->getData());
                return $this->redirectToRoute('app_lesson_quiz_result', ['id' => $attempt->getId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException|UniqueConstraintViolationException $error) {
                $form->addError(new FormError($error instanceof \DomainException ? $error->getMessage() : 'Спробу вже збережено. Оновіть сторінку.'));
            }
        }

        return $this->render('lesson/quiz_take.html.twig', ['quiz' => $quiz, 'lesson' => $quiz->getLesson(), 'submissionForm' => $form]);
    }

    #[Route('/test-results/{id}', name: 'app_lesson_quiz_result', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function result(int $id, EntityManagerInterface $manager): Response
    {
        $attempt = $manager->find(QuizAttempt::class, $id) ?? throw $this->createNotFoundException();
        $lesson = $attempt->getQuiz()->getLesson();
        $this->denyAccessUnlessGranted(LessonVoter::VIEW, $lesson);
        if ($this->getUser()->getRole() === UserRole::Student && $attempt->getStudent()->getId() !== $this->getUser()->getId()) throw $this->createAccessDeniedException();

        return $this->render('lesson/quiz_result.html.twig', ['attempt' => $attempt, 'lesson' => $lesson]);
    }

    private function quiz(int $id, EntityManagerInterface $manager): LessonQuiz { return $manager->find(LessonQuiz::class, $id) ?? throw $this->createNotFoundException(); }
    private function view(LessonQuiz $quiz): void
    {
        $this->denyAccessUnlessGranted(LessonVoter::VIEW, $quiz->getLesson());
        if ($this->getUser()->getRole() === UserRole::Student && !$quiz->isPublished()) throw $this->createAccessDeniedException();
    }
}
