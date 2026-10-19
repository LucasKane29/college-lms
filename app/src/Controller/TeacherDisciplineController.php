<?php

namespace App\Controller;

use App\Entity\Discipline;
use App\Form\DisciplineType;
use App\Repository\DisciplineRepository;
use App\Repository\GroupRepository;
use App\Security\DisciplineVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/teacher')]
#[IsGranted('ROLE_TEACHER')]
class TeacherDisciplineController extends AbstractController
{
    #[Route('/disciplines', name: 'app_teacher_disciplines', methods: ['GET'])]
    public function index(Request $request, DisciplineRepository $disciplines): Response
    {
        return $this->render('discipline/index.html.twig', $disciplines->findManagedPage(
            $this->getUser(), $this->isGranted('ROLE_ADMIN'), $request->query->getInt('page', 1),
        ));
    }

    #[Route('/disciplines/new', name: 'app_teacher_discipline_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $manager): Response
    {
        $discipline = (new Discipline())->setTeacher($this->getUser());
        $form = $this->createForm(DisciplineType::class, $discipline);
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
    public function show(int $id, DisciplineRepository $disciplines): Response
    {
        return $this->render('discipline/show.html.twig', ['discipline' => $this->managedDiscipline($id, $disciplines)]);
    }

    #[Route('/disciplines/{id}/edit', name: 'app_teacher_discipline_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, DisciplineRepository $disciplines, EntityManagerInterface $manager): Response
    {
        $discipline = $this->managedDiscipline($id, $disciplines);
        $form = $this->createForm(DisciplineType::class, $discipline);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->flush();
            $this->addFlash('success', 'Дисципліну та її групи оновлено.');

            return $this->redirectToRoute('app_teacher_discipline_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->render('discipline/form.html.twig', ['disciplineForm' => $form, 'discipline' => $discipline, 'creating' => false]);
    }

    #[Route('/groups', name: 'app_teacher_groups', methods: ['GET'])]
    public function groups(Request $request, GroupRepository $groups): Response
    {
        return $this->render('discipline/groups.html.twig', $groups->findForTeacherPage(
            $this->getUser(), $this->isGranted('ROLE_ADMIN'), $request->query->getInt('page', 1),
        ));
    }

    private function managedDiscipline(int $id, DisciplineRepository $disciplines): Discipline
    {
        $discipline = $disciplines->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DisciplineVoter::MANAGE, $discipline);

        return $discipline;
    }
}
