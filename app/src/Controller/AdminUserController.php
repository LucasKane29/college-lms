<?php

namespace App\Controller;

use App\Form\UserRoleType;
use App\Form\UserFilterType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users, FormFactoryInterface $formFactory): Response
    {
        $form = $formFactory->createNamed('', UserFilterType::class, ['perPage' => 20], [
            'action' => $this->generateUrl('app_admin_users'),
        ]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $filters = $form->getData();
        if ($valid && ($filters['createdAtFrom'] ?? null) && ($filters['createdAtTo'] ?? null)
            && $filters['createdAtFrom'] > $filters['createdAtTo']) {
            $form->get('createdAtTo')->addError(new FormError('Кінцева дата має бути не раніше початкової.'));
            $valid = false;
        }

        $result = $valid
            ? $users->findFilteredPage($filters, $request->query->getInt('page', 1), $filters['perPage'])
            : ['users' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => 20];
        $query = array_intersect_key($request->query->all(), array_flip([
            'id', 'email', 'fullName', 'role', 'studentGroup', 'password', 'createdAtFrom', 'createdAtTo', 'perPage',
        ]));

        return $this->render('admin/users.html.twig', $result + [
            'filterForm' => $form, 'query' => $query, 'validFilters' => $valid,
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{id}/role', name: 'app_admin_user_role', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function role(int $id, Request $request, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $form = $this->createForm(UserRoleType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Роль користувача оновлено.');

            return $this->redirectToRoute('app_admin_users', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user_role.html.twig', [
            'user' => $user,
            'roleForm' => $form,
        ]);
    }
}
