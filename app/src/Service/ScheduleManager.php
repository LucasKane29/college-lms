<?php

namespace App\Service;

use App\Entity\Group;
use App\Entity\Pair;
use App\Entity\Schedule;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;

final class ScheduleManager
{
    public function __construct(private EntityManagerInterface $manager) {}

    /** @return list<Group> */
    public function visibleGroups(User $user): array
    {
        $query = $this->manager->getRepository(Group::class)->createQueryBuilder('g')->orderBy('g.name', 'ASC')->addOrderBy('g.id', 'ASC');
        switch ($user->getRole()) {
            case UserRole::Student:
                $query->andWhere('g = :group')->setParameter('group', $user->getStudentGroup()?->getId() ?? 0);
                break;
            case UserRole::Teacher:
                $query->distinct()->innerJoin('g.disciplines', 'd')->andWhere('d.teacher = :user')->setParameter('user', $user);
                break;
            case UserRole::Curator:
                $query->andWhere('g.curator = :user')->setParameter('user', $user);
                break;
            case UserRole::User:
                return [];
            default:
                break;
        }

        return $query->getQuery()->getResult();
    }

    /** @return list<Schedule> */
    public function lessons(\DateTimeImmutable $from, \DateTimeImmutable $to, array $groups): array
    {
        if (!$groups) return [];

        return $this->manager->createQueryBuilder()->select('s', 'd', 't', 'p', 'g')
            ->from(Schedule::class, 's')->join('s.discipline', 'd')->join('d.teacher', 't')
            ->join('s.pair', 'p')->join('s.group', 'g')
            ->where('s.lessonDate BETWEEN :from AND :to')->andWhere('s.group IN (:groups)')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->setParameter('groups', array_map(static fn (Group $group) => $group->getId(), $groups))
            ->orderBy('s.lessonDate', 'ASC')->addOrderBy('p.number', 'ASC')->getQuery()->getResult();
    }

    public function lessonError(Schedule $lesson): ?string
    {
        $discipline = $lesson->getDiscipline();
        if (!$discipline || !$discipline->getGroups()->contains($lesson->getGroup())) {
            return 'Оберіть дисципліну, призначену цій групі.';
        }
        $others = $this->manager->createQueryBuilder()->select('s', 'd')->from(Schedule::class, 's')
            ->join('s.discipline', 'd')->where('s.lessonDate = :date')->andWhere('s.pair = :pair')
            ->andWhere('s.group = :group OR d.teacher = :teacher')
            ->setParameter('date', $lesson->getLessonDate(), 'date_immutable')->setParameter('pair', $lesson->getPair())
            ->setParameter('group', $lesson->getGroup())->setParameter('teacher', $discipline->getTeacher())
            ->getQuery()->getResult();
        foreach ($others as $other) {
            if ($other->getId() === $lesson->getId()) continue;
            return $other->getGroup()->getId() === $lesson->getGroup()->getId()
                ? 'Для цієї групи вже є заняття на цю пару. Оновіть розклад.'
                : 'Викладач вже має заняття в іншій групі на цю пару.';
        }

        return null;
    }

    public function pairError(Pair $pair): ?string
    {
        foreach ($this->manager->getRepository(Pair::class)->findAll() as $other) {
            if ($other->getId() === $pair->getId()) continue;
            foreach (['Weekday', 'Weekend'] as $type) {
                $start = 'get'.$type.'Start';
                $end = 'get'.$type.'End';
                if ($pair->$start()->format('H:i') < $other->$end()->format('H:i') && $pair->$end()->format('H:i') > $other->$start()->format('H:i')) {
                    return 'Час перетинається з парою №'.$other->getNumber().' ('.($type === 'Weekday' ? 'будні' : 'вихідні').').';
                }
            }
        }

        return null;
    }
}
