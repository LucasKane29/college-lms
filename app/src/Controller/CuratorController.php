<?php

namespace App\Controller;

use App\Entity\Group;
use App\Form\AssignStudentGroupType;
use App\Form\GroupType;
use App\Form\UserFilterType;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/curator')]
#[IsGranted('ROLE_CURATOR')]
class CuratorController extends AbstractController
{
    #[Route('/groups', name: 'app_curator_groups', methods: ['GET'])]
    public function groups(Request $request, GroupRepository $groups): Response
    {
        return $this->render('curator/groups.html.twig', $groups->findManagedPage(
            $this->getUser(), $this->isGranted('ROLE_ADMIN'), $request->query->getInt('page', 1),
        ));
    }

    #[Route('/groups/new', name: 'app_curator_group_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $manager): Response
    {
        $group = (new Group())->setCurator($this->getUser());
        $form = $this->createForm(GroupType::class, $group);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($group);
            $manager->flush();
            $this->addFlash('success', 'Групу створено. Тепер додайте студентів.');

            return $this->redirectToRoute('app_curator_group_show', ['id' => $group->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('curator/group_new.html.twig', ['groupForm' => $form]);
    }

    #[Route('/groups/{id}', name: 'app_curator_group_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, GroupRepository $groups, UserRepository $users): Response
    {
        $group = $this->managedGroup($id, $groups);

        return $this->render('curator/group_show.html.twig', $users->findFilteredPage(
            ['studentGroup' => $group, 'role' => UserRole::Student], $request->query->getInt('page', 1), 20,
        ) + ['group' => $group]);
    }

    #[Route('/students', name: 'app_curator_students', methods: ['GET'])]
    public function students(Request $request, FormFactoryInterface $factory, UserRepository $users): Response
    {
        $form = $factory->createNamed('', UserFilterType::class, ['perPage' => 20], [
            'action' => $this->generateUrl('app_curator_students'),
        ]);
        foreach (['role', 'password', 'createdAtFrom', 'createdAtTo', 'studentGroup'] as $field) {
            $form->remove($field);
        }
        $form->add('withoutGroup', CheckboxType::class, ['label' => 'Лише без групи', 'required' => false]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $filters = $form->getData();
        // The role is imposed on the server; query parameters cannot expose other users.
        $filters['role'] = UserRole::Student;
        $result = $valid
            ? $users->findFilteredPage($filters, $request->query->getInt('page', 1), $filters['perPage'])
            : ['users' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 20];

        return $this->render('curator/students.html.twig', $result + [
            'filterForm' => $form, 'validFilters' => $valid,
            'query' => array_intersect_key($request->query->all(), array_flip(['id', 'email', 'fullName', 'perPage', 'withoutGroup'])),
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/students/{id}/assign', name: 'app_curator_student_assign', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function assign(int $id, Request $request, UserRepository $users): Response
    {
        $student = $users->find($id) ?? throw $this->createNotFoundException();
        if (UserRole::Student !== $student->getRole()) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(AssignStudentGroupType::class, null, [
            'actor' => $this->getUser(), 'admin' => $this->isGranted('ROLE_ADMIN'),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $group = $form->get('group')->getData();
            if ($users->assignStudentToGroup($student, $group)) {
                $this->addFlash('success', 'Студента додано до групи.');

                return $this->redirectToRoute('app_curator_group_show', ['id' => $group->getId()], Response::HTTP_SEE_OTHER);
            }
            $form->addError(new FormError('Студент уже належить до групи або більше не має ролі студента. Оновіть список.'));
        }

        return $this->render('curator/student_assign.html.twig', ['student' => $student, 'assignmentForm' => $form]);
    }

    #[Route('/groups/{id}/students/{studentId}/remove', name: 'app_curator_student_remove', requirements: ['id' => '\\d+', 'studentId' => '\\d+'], methods: ['POST'])]
    public function remove(int $id, int $studentId, Request $request, GroupRepository $groups, UserRepository $users): Response
    {
        $group = $this->managedGroup($id, $groups);
        if (!$this->isCsrfTokenValid('remove_student_'.$id.'_'.$studentId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Недійсний токен форми.');
        }
        $student = $users->find($studentId) ?? throw $this->createNotFoundException();
        if (!$users->removeStudentFromGroup($student, $group)) {
            throw $this->createNotFoundException();
        }
        $this->addFlash('success', 'Студента вилучено з групи.');

        return $this->redirectToRoute('app_curator_group_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function managedGroup(int $id, GroupRepository $groups): Group
    {
        $group = $groups->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isGranted('ROLE_ADMIN') && $group->getCurator()?->getId() !== $this->getUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $group;
    }
}
