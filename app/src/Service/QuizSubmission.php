<?php

namespace App\Service;

use App\Entity\LessonQuiz;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Security\LessonVoter;
use App\Security\UserRole;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class QuizSubmission
{
    public function __construct(private EntityManagerInterface $manager, private QuizGrader $grader, private AuthorizationCheckerInterface $authorization) {}

    /** @param array<string, mixed> $data */
    public function submit(LessonQuiz $quiz, User $student, array $data): QuizAttempt
    {
        $this->manager->beginTransaction();
        try {
            $this->manager->refresh($quiz, LockMode::PESSIMISTIC_WRITE);
            if ($student->getRole() !== UserRole::Student || !$quiz->isPublished() || !$this->authorization->isGranted(LessonVoter::VIEW, $quiz->getLesson())) {
                throw new \DomainException('Тест більше недоступний.');
            }
            $used = $this->manager->getRepository(QuizAttempt::class)->count(['quiz' => $quiz, 'student' => $student]);
            if (($data['revision'] ?? null) !== (string) $quiz->getRevision()) throw new \DomainException('Тест змінився під час проходження. Відкрийте його ще раз.');
            if ($used >= $quiz->getMaxAttempts()) throw new \DomainException('Усі дозволені спроби використано.');
            $attempt = $this->grader->grade($quiz, $student, $used + 1, $data);
            $this->manager->persist($attempt); $this->manager->flush(); $this->manager->commit();

            return $attempt;
        } catch (\Throwable $error) {
            $this->manager->rollback();
            if (!$error instanceof \DomainException) $this->manager->clear();
            throw $error;
        }
    }
}
