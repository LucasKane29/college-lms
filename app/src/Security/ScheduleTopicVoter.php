<?php

namespace App\Security;

use App\Entity\Schedule;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

final class ScheduleTopicVoter extends Voter
{
    public const ASSIGN = 'SCHEDULE_TOPIC_ASSIGN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ASSIGN === $attribute && $subject instanceof Schedule;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && UserRole::Teacher === $user->getRole()
            && null !== $user->getId() && $subject->getDiscipline()?->hasTeacher($user);
    }
}
