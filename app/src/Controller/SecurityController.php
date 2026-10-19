<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $error,
        ], new Response(status: $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/registration', name: 'app_registration', methods: ['GET', 'POST'])]
    public function registration(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account', status: Response::HTTP_SEE_OTHER);
        }

        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            try {
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Обліковий запис створено. Увійдіть за допомогою електронної пошти та пароля.');

                return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
            } catch (UniqueConstraintViolationException) {
                $form->get('email')->addError(new FormError('Ця електронна адреса вже зареєстрована.'));
            }
        }

        return $this->render('security/registration.html.twig', ['registrationForm' => $form]);
    }

    #[Route('/account', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function account(): Response
    {
        return $this->render('security/account.html.twig');
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the security firewall.');
    }
}
