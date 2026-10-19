<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\DisciplineRepository;
use App\Security\UserRole;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LearningController extends AbstractController
{
    #[Route('/student/disciplines', name: 'app_student_disciplines', methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function disciplines(Request $request, DisciplineRepository $disciplines): Response
    {
        $group = $this->getUser()->getStudentGroup();

        return $this->render('learning/disciplines.html.twig', $disciplines->findForGroupPage(
            $group, $request->query->getInt('page', 1),
        ) + ['group' => $group]);
    }

    #[Route('/student/teachers', name: 'app_student_teachers', methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function teachers(Request $request, UserRepository $users): Response
    {
        $group = $this->getUser()->getStudentGroup();

        return $this->render('learning/teachers.html.twig', $users->findTeachersForGroupPage(
            $group, $request->query->getInt('page', 1),
        ) + ['group' => $group]);
    }

    #[Route('/student/group', name: 'app_student_group', methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function group(Request $request, UserRepository $users): Response
    {
        $group = $this->getUser()->getStudentGroup();
        $result = null === $group
            ? ['users' => [], 'total' => 0, 'page' => 1, 'pages' => 1]
            : $users->findFilteredPage(['studentGroup' => $group, 'role' => UserRole::Student], $request->query->getInt('page', 1), 20);

        return $this->render('learning/group.html.twig', $result + ['group' => $group]);
    }

}
