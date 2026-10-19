<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\UserRole;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LearningController extends AbstractController
{
    #[Route('/student/disciplines', name: 'app_student_disciplines', defaults: ['title' => 'Мої дисципліни', 'description' => 'Тут відображатимуться ваші навчальні дисципліни.'], methods: ['GET'])]
    #[Route('/student/teachers', name: 'app_student_teachers', defaults: ['title' => 'Мої викладачі', 'description' => 'Тут відображатимуться ваші викладачі.'], methods: ['GET'])]
    #[IsGranted('ROLE_STUDENT')]
    public function student(string $title, string $description): Response
    {
        return $this->render('learning/section.html.twig', compact('title', 'description'));
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

    #[Route('/teacher/groups', name: 'app_teacher_groups', defaults: ['title' => 'Мої групи', 'description' => 'Тут відображатимуться групи, у яких ви викладаєте.'], methods: ['GET'])]
    #[Route('/teacher/disciplines', name: 'app_teacher_disciplines', defaults: ['title' => 'Мої дисципліни', 'description' => 'Тут відображатимуться дисципліни, які ви викладаєте.'], methods: ['GET'])]
    #[IsGranted('ROLE_TEACHER')]
    public function teacher(string $title, string $description): Response
    {
        return $this->render('learning/section.html.twig', compact('title', 'description'));
    }
}
