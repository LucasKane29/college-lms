<?php

namespace App\Controller;

use App\Form\UserRoleType;
use App\Form\UserFilterType;
use App\Form\UserEditType;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        $data = $this->catalogData($request, $users, $formFactory);

        return $this->render('admin/users.html.twig', $data, new Response(
            status: $data['validFilters'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        ));
    }

    private function catalogData(Request $request, UserRepository $users, FormFactoryInterface $formFactory): array
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
            'id', 'email', 'fullName', 'phoneNumber', 'birthDate', 'role', 'studentGroup', 'password', 'createdAtFrom', 'createdAtTo', 'perPage',
        ]));

        return $result + [
            'filterForm' => $form, 'query' => $query, 'validFilters' => $valid,
        ];
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, UserRepository $users, EntityManagerInterface $manager, FormFactoryInterface $factory): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $original = clone $user;
        $catalog = array_intersect_key($request->query->all('catalog'), array_flip([
            'id', 'email', 'fullName', 'phoneNumber', 'birthDate', 'role', 'studentGroup', 'password', 'createdAtFrom', 'createdAtTo', 'perPage', 'page',
        ]));
        foreach ($catalog as $value) {
            if (!is_scalar($value)) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid catalog parameters.');
            }
        }
        $catalog['page'] = max(1, (int) ($catalog['page'] ?? 1));
        $form = $this->createForm(UserEditType::class, $user, [
            'action' => $this->generateUrl('app_admin_user_edit', ['id' => $id, 'catalog' => $catalog]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $manager->flush();
            } catch (UniqueConstraintViolationException) {
                $form->get('email')->addError(new FormError('Ця електронна адреса вже зареєстрована.'));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            if (str_contains($request->headers->get('Accept', ''), 'text/vnd.turbo-stream.html')) {
                $catalogRequest = Request::create($this->generateUrl('app_admin_users'), 'GET', $catalog);
                $data = $this->catalogData($catalogRequest, $users, $factory);

                return $this->render('admin/user_edit.stream.html.twig', $data + ['successMessage' => 'Дані користувача оновлено.'], new Response(headers: [
                    'Content-Type' => 'text/vnd.turbo-stream.html; charset=UTF-8',
                ]));
            }
            $this->addFlash('success', 'Дані користувача оновлено.');

            return $this->redirectToRoute('app_admin_users', $catalog, Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted()) {
            // Invalid self-edits must not change the user stored in the authentication token.
            $user->setFullName($original->getFullName())->setEmail($original->getEmail())
                ->setPhoneNumber($original->getPhoneNumber())->setBirthDate($original->getBirthDate());
        }

        return $this->render('admin/user_edit.html.twig', ['editedUser' => $user, 'editForm' => $form, 'catalog' => $catalog]);
    }

    #[Route('/{id}/role', name: 'app_admin_user_role', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function role(int $id, Request $request, UserRepository $users): Response
    {
        $user = $users->find($id) ?? throw $this->createNotFoundException();
        if ($user->isRoleLocked()) {
            return $this->render('admin/user_role.html.twig', ['user' => $user], new Response(
                status: $request->isMethod('POST') ? Response::HTTP_CONFLICT : Response::HTTP_OK,
            ));
        }
        $form = $this->createForm(UserRoleType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $role = $form->get('role')->getData();
            if ($role !== $user->getRole() && !$users->changeRoleIfAllowed($user, $role)) {
                $form->addError(new FormError('Роль не змінено. Можливо, студента вже додано до групи. Оновіть сторінку.'));

                return $this->render('admin/user_role.html.twig', ['user' => $user, 'roleForm' => $form], new Response(status: Response::HTTP_CONFLICT));
            }
            $this->addFlash('success', 'Роль користувача оновлено.');

            return $this->redirectToRoute('app_admin_users', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/user_role.html.twig', [
            'user' => $user,
            'roleForm' => $form,
        ]);
    }
}
