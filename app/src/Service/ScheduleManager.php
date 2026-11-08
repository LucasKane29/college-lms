<?php

namespace App\Service;

use App\Entity\Group;
use App\Entity\Discipline;
use App\Entity\LessonTopic;
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
                $query->distinct()->innerJoin('g.disciplines', 'd')->andWhere(':user MEMBER OF d.teachers')->setParameter('user', $user);
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
    public function lessons(\DateTimeImmutable $from, \DateTimeImmutable $to, array $groups, ?User $teacher = null): array
    {
        if (!$groups) return [];

        $query = $this->manager->createQueryBuilder()->select('s', 'd', 't', 'p', 'g', 'topic')
            ->from(Schedule::class, 's')->join('s.discipline', 'd')->leftJoin('d.teachers', 't')
            ->join('s.pair', 'p')->join('s.group', 'g')
            ->leftJoin('s.topic', 'topic')
            ->where('s.lessonDate BETWEEN :from AND :to')->andWhere('s.group IN (:groups)')
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->setParameter('groups', array_map(static fn (Group $group) => $group->getId(), $groups))
            ->orderBy('s.lessonDate', 'ASC')->addOrderBy('p.number', 'ASC');
        if ($teacher) {
            $query->andWhere(':teacher MEMBER OF d.teachers')->setParameter('teacher', $teacher);
        }

        return $query->getQuery()->getResult();
    }

    public function assignTopic(Schedule $lesson, ?LessonTopic $topic, User $teacher): bool
    {
        if (UserRole::Teacher !== $teacher->getRole() || ($topic && $topic->getDiscipline() !== $lesson->getDiscipline())) {
            return false;
        }

        $changed = $this->manager->createQueryBuilder()->update(Schedule::class, 's')->set('s.topic', ':topic')
            ->where('s.id = :id')->andWhere('s.discipline = :discipline')
            ->andWhere('s.discipline IN (SELECT d.id FROM App\Entity\Discipline d WHERE :teacher MEMBER OF d.teachers)')
            ->setParameter('topic', $topic)->setParameter('id', $lesson->getId())
            ->setParameter('discipline', $lesson->getDiscipline())->setParameter('teacher', $teacher)->getQuery()->execute();
        if (1 === $changed) {
            return true;
        }
        // MySQL may report zero changed rows when the same topic is selected again.
        $current = $this->manager->createQueryBuilder()->select('COUNT(s.id)')->from(Schedule::class, 's')
            ->join('s.discipline', 'd')->where('s.id = :id')->andWhere('d = :discipline')->andWhere(':teacher MEMBER OF d.teachers')
            ->setParameter('id', $lesson->getId())->setParameter('discipline', $lesson->getDiscipline())->setParameter('teacher', $teacher);
        if ($topic) {
            $current->andWhere('s.topic = :topic')->setParameter('topic', $topic);
        } else {
            $current->andWhere('s.topic IS NULL');
        }

        return 1 === (int) $current->getQuery()->getSingleScalarResult();
    }

    public function lessonError(Schedule $lesson): ?string
    {
        $discipline = $lesson->getDiscipline();
        if (null !== $lesson->getTopic() && $lesson->getTopic()->getDiscipline() !== $discipline) {
            return 'Оберіть тему, що належить вибраній дисципліні.';
        }
        if (!$discipline || !$discipline->getGroups()->contains($lesson->getGroup())) {
            return 'Оберіть дисципліну, призначену цій групі.';
        }
        $others = $this->manager->createQueryBuilder()->select('s', 'd')->distinct()->from(Schedule::class, 's')
            ->join('s.discipline', 'd')->leftJoin('d.teachers', 'sharedTeacher')
            ->where('s.lessonDate = :date')->andWhere('s.pair = :pair')
            ->andWhere('s.group = :group OR sharedTeacher.id IN (:teachers)')
            ->setParameter('date', $lesson->getLessonDate(), 'date_immutable')->setParameter('pair', $lesson->getPair())
            ->setParameter('group', $lesson->getGroup())
            ->setParameter('teachers', $discipline->getTeachers()->map(static fn (User $teacher) => $teacher->getId())->toArray() ?: [0])
            ->getQuery()->getResult();
        foreach ($others as $other) {
            if ($other->getId() === $lesson->getId()) continue;
            return $other->getGroup()->getId() === $lesson->getGroup()->getId()
                ? 'Для цієї групи вже є заняття на цю пару. Оновіть розклад.'
                : 'Викладач вже має заняття в іншій групі на цю пару.';
        }

        return null;
    }

    /** @return array{lessons: list<Schedule>, total: int, page: int, pages: int} */
    public function disciplinePage(Discipline $discipline, int $page): array
    {
        $query = $this->manager->createQueryBuilder()->select('s', 'g', 'p')->from(Schedule::class, 's')
            ->join('s.group', 'g')->join('s.pair', 'p')->leftJoin('s.topic', 'topic')->addSelect('topic');
        // Restrict the schedule to groups currently linked to this discipline.
        $query->where('s.discipline = :discipline')
            ->andWhere('g.id IN (:groups)')->setParameter('discipline', $discipline)
            ->setParameter('groups', $discipline->getGroups()->map(static fn (Group $group) => $group->getId())->toArray() ?: [0]);
        $total = (int) (clone $query)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, $page));
        $lessons = $query->orderBy('s.lessonDate', 'ASC')->addOrderBy('p.number', 'ASC')
            ->addOrderBy('g.name', 'ASC')->addOrderBy('s.id', 'ASC')
            ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();

        return compact('lessons', 'total', 'page', 'pages');
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
