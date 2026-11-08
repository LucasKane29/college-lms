<?php

namespace App\Security;

use App\Entity\Discipline;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

class DisciplineVoter extends Voter
{
    public const MANAGE = 'DISCIPLINE_MANAGE';
    public const VIEW = 'DISCIPLINE_VIEW';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::MANAGE, self::VIEW], true) && $subject instanceof Discipline;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->decisionManager->decide($token, ['ROLE_HEAD_TEACHER'])) {
            return true;
        }

        return self::VIEW === $attribute && $this->decisionManager->decide($token, ['ROLE_TEACHER'])
            && null !== $user->getId()
            && $subject->hasTeacher($user);
    }
}
