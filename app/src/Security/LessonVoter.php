<?php

namespace App\Security;

use App\Entity\Discipline;
use App\Entity\LessonTopic;
use App\Entity\Schedule;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

final class LessonVoter extends Voter
{
    public const VIEW = 'LESSON_VIEW';
    public const EDIT = 'LESSON_EDIT';

    public function __construct(private EntityManagerInterface $manager) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && ($subject instanceof LessonTopic || $subject instanceof Discipline);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) return false;
        $discipline = $subject instanceof LessonTopic ? $subject->getDiscipline() : $subject;
        if ($user->getRole() === UserRole::Admin) return true;
        if ($user->getRole() === UserRole::Teacher && $discipline->hasTeacher($user)) return true;
        if ($attribute === self::EDIT) return false;
        if ($user->getRole() === UserRole::HeadTeacher) return true;
        if ($user->getRole() !== UserRole::Student || !$user->getStudentGroup()) return false;
        if (!$discipline->getGroups()->contains($user->getStudentGroup())) return false;
        if ($subject instanceof Discipline) return true;

        return 0 < (int) $this->manager->createQueryBuilder()->select('COUNT(s.id)')->from(Schedule::class, 's')
            ->where('s.topic = :lesson')->andWhere('s.group = :group')->andWhere('s.discipline = :discipline')
            ->setParameter('lesson', $subject)->setParameter('group', $user->getStudentGroup())->setParameter('discipline', $discipline)
            ->getQuery()->getSingleScalarResult();
    }
}
