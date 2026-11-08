<?php

namespace App\Controller;

use App\Entity\Discipline;
use App\Entity\LessonTopic;
use App\Form\DisciplineType;
use App\Form\LessonTopicType;
use App\Repository\DisciplineRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Security\DisciplineVoter;
use App\Security\LessonVoter;
use App\Security\UserRole;
use App\Service\ScheduleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\ExpressionLanguage\Expression;

#[Route('/teacher')]
#[IsGranted(new Expression("is_granted('ROLE_TEACHER') or is_granted('ROLE_HEAD_TEACHER')"))]
class TeacherDisciplineController extends AbstractController
{
    #[Route('/disciplines', name: 'app_teacher_disciplines', methods: ['GET'])]
    public function index(Request $request, DisciplineRepository $disciplines): Response
    {
        return $this->render('discipline/index.html.twig', $disciplines->findManagedPage(
            $this->getUser(), $this->isGranted('ROLE_HEAD_TEACHER'), $request->query->getInt('page', 1),
        ));
    }

    #[Route('/disciplines/new', name: 'app_teacher_discipline_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $manager): Response
    {
        $discipline = new Discipline();
        $manageTeachers = $this->isGranted('ROLE_HEAD_TEACHER');
        if (!$manageTeachers) {
            $discipline->addTeacher($this->getUser());
        }
        $form = $this->createForm(DisciplineType::class, $discipline, ['manage_teachers' => $manageTeachers]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($discipline);
            $manager->flush();
            $this->addFlash('success', 'Дисципліну створено.');

            return $this->redirectToRoute('app_teacher_discipline_show', ['id' => $discipline->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('discipline/form.html.twig', ['disciplineForm' => $form, 'discipline' => $discipline, 'creating' => true]);
    }

    #[Route('/disciplines/{id}', name: 'app_teacher_discipline_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, DisciplineRepository $disciplines, ScheduleManager $schedule, EntityManagerInterface $manager): Response
    {
        $discipline = $disciplines->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DisciplineVoter::VIEW, $discipline);

        $topicForm = $this->isGranted(LessonVoter::EDIT, $discipline)
            ? $this->createForm(LessonTopicType::class, (new LessonTopic())->setDiscipline($discipline), [
                'action' => $this->generateUrl('app_teacher_discipline_topic_new', ['id' => $id]),
            ]) : null;

        return $this->render('discipline/show.html.twig', [
            'discipline' => $discipline, 'topicForm' => $topicForm?->createView(),
            'topics' => $manager->getRepository(LessonTopic::class)->findBy(['discipline' => $discipline], ['id' => 'ASC']),
        ]
            + $schedule->disciplinePage($discipline, $request->query->getInt('page', 1)));
    }

    #[Route('/disciplines/{id}/topics/new', name: 'app_teacher_discipline_topic_new', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createTopic(int $id, Request $request, DisciplineRepository $disciplines, ScheduleManager $schedule, EntityManagerInterface $manager): Response
    {
        $discipline = $disciplines->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(LessonVoter::EDIT, $discipline);
        $topic = (new LessonTopic())->setDiscipline($discipline);
        $form = $this->createForm(LessonTopicType::class, $topic, [
            'action' => $this->generateUrl('app_teacher_discipline_topic_new', ['id' => $id]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($topic);
            $manager->flush();
            $this->addFlash('success', 'Заняття додано до каталогу дисципліни.');

            return $this->redirectToRoute('app_teacher_discipline_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('discipline/show.html.twig', [
            'discipline' => $discipline, 'topicForm' => $form->createView(),
            'topics' => $manager->getRepository(LessonTopic::class)->findBy(['discipline' => $discipline], ['id' => 'ASC']),
        ] + $schedule->disciplinePage($discipline, 1), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/disciplines/{id}/edit', name: 'app_teacher_discipline_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, DisciplineRepository $disciplines, EntityManagerInterface $manager): Response
    {
        $discipline = $this->managedDiscipline($id, $disciplines);
        $form = $this->createForm(DisciplineType::class, $discipline, ['manage_teachers' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->flush();
            $this->addFlash('success', 'Дисципліну та її групи оновлено.');

            return $this->redirectToRoute('app_teacher_discipline_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('discipline/form.html.twig', ['disciplineForm' => $form, 'discipline' => $discipline, 'creating' => false]);
    }

    #[Route('/groups', name: 'app_teacher_groups', methods: ['GET'])]
    #[IsGranted('ROLE_TEACHER')]
    public function groups(Request $request, GroupRepository $groups): Response
    {
        return $this->render('discipline/groups.html.twig', $groups->findForTeacherPage(
            $this->getUser(), $this->isGranted('ROLE_ADMIN'), $request->query->getInt('page', 1),
        ));
    }

    #[Route('/groups/{id}', name: 'app_teacher_group_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_TEACHER')]
    public function students(int $id, Request $request, GroupRepository $groups, UserRepository $users): Response
    {
        $group = $groups->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isGranted('ROLE_ADMIN') && !$groups->isTaughtBy($group, $this->getUser())) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('discipline/students.html.twig', $users->findFilteredPage(
            ['studentGroup' => $group, 'role' => UserRole::Student], $request->query->getInt('page', 1), 20,
        ) + ['group' => $group]);
    }

    private function managedDiscipline(int $id, DisciplineRepository $disciplines): Discipline
    {
        $discipline = $disciplines->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DisciplineVoter::MANAGE, $discipline);

        return $discipline;
    }
}
