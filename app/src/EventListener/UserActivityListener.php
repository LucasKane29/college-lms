<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class UserActivityListener
{
    public function __construct(private Security $security, private UserRepository $users)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() >= 400) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && null !== $user->getId()) {
            $this->users->recordActivity($user, new \DateTimeImmutable());
        }
    }
}
