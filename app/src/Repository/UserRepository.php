<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Group;
use App\Entity\Discipline;
use App\Security\UserRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @param array{id?: ?int, email?: ?string, fullName?: ?string, phoneNumber?: ?string, birthDate?: ?\DateTimeImmutable, role?: ?UserRole, studentGroup?: ?Group, withoutGroup?: bool, password?: ?string, createdAtFrom?: ?\DateTimeImmutable, createdAtTo?: ?\DateTimeImmutable, perPage?: int} $filters
     * @return array{users: list<User>, total: int, page: int, pages: int, perPage: int}
     */
    public function findFilteredPage(array $filters, int $page, int $perPage): array
    {
        if (!in_array($perPage, [10, 20, 50, 100], true)) {
            throw new \InvalidArgumentException('Unsupported page size.');
        }

        $query = $this->createQueryBuilder('u')->leftJoin('u.studentGroup', 'studentGroup')->addSelect('studentGroup');
        if (null !== ($filters['studentGroup'] ?? null)) {
            $query->andWhere('u.studentGroup = :studentGroup')->setParameter('studentGroup', $filters['studentGroup']);
        }
        if ($filters['withoutGroup'] ?? false) {
            $query->andWhere('u.studentGroup IS NULL');
        }
        if (null !== ($filters['id'] ?? null)) {
            $query->andWhere('u.id = :id')->setParameter('id', $filters['id']);
        }

        foreach (['email', 'fullName', 'phoneNumber'] as $field) {
            if ('' !== ($value = trim($filters[$field] ?? ''))) {
                // Treat SQL wildcard characters as literal search text.
                $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
                $query->andWhere("u.$field LIKE :$field ESCAPE '!'")->setParameter($field, '%'.$value.'%');
            }
        }

        if (null !== ($birthDate = $filters['birthDate'] ?? null)) {
            $query->andWhere('u.birthDate = :birthDate')->setParameter('birthDate', $birthDate, Types::DATE_IMMUTABLE);
        }

        if (null !== ($role = $filters['role'] ?? null)) {
            // Match a complete JSON string, so similarly named roles cannot match.
            if (UserRole::User === $role) {
                foreach (UserRole::cases() as $otherRole) {
                    if (UserRole::User !== $otherRole) {
                        $query->andWhere('u.roles NOT LIKE :'.$otherRole->name." ESCAPE '!'")
                            ->setParameter($otherRole->name, '%"'.str_replace('_', '!_', $otherRole->value).'"%');
                    }
                }
            } else {
                $query->andWhere("u.roles LIKE :role ESCAPE '!'")
                    ->setParameter('role', '%"'.str_replace('_', '!_', $role->value).'"%');
            }
        }

        if ('set' === ($filters['password'] ?? null)) {
            $query->andWhere("u.password <> ''");
        } elseif ('empty' === ($filters['password'] ?? null)) {
            $query->andWhere("u.password = ''");
        }
        if (null !== ($from = $filters['createdAtFrom'] ?? null)) {
            $query->andWhere('u.createdAt >= :from')->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        }
        if (null !== ($to = $filters['createdAtTo'] ?? null)) {
            $query->andWhere('u.createdAt < :until')->setParameter('until', $to->modify('+1 day'), Types::DATETIME_IMMUTABLE);
        }

        $total = (int) (clone $query)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, $page));
        $users = $query->orderBy('u.id', 'ASC')->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)->getQuery()->getResult();

        return compact('users', 'total', 'page', 'pages', 'perPage');
    }

    /** @return array{teachers: list<User>, total: int, page: int, pages: int} */
    public function findTeachersForGroupPage(?Group $group, int $page): array
    {
        if (null === $group) {
            return ['teachers' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $query = $this->createQueryBuilder('u')->distinct()
            ->innerJoin(Discipline::class, 'discipline', 'WITH', 'discipline.teacher = u')
            ->innerJoin('discipline.groups', 'studentGroup')
            ->where('studentGroup = :group')->setParameter('group', $group);
        $total = (int) (clone $query)->select('COUNT(DISTINCT u.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, $page));
        $teachers = $query->orderBy('u.fullName', 'ASC')->addOrderBy('u.id', 'ASC')
            ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();

        return compact('teachers', 'total', 'page', 'pages');
    }

    public function changeRoleIfAllowed(User $user, UserRole $role): bool
    {
        // Recheck membership in the UPDATE to cover concurrent group assignments.
        return 1 === $this->createQueryBuilder('u')->update()->set('u.roles', ':roles')
            ->where('u.id = :id')
            ->andWhere("(u.studentGroup IS NULL OR u.roles NOT LIKE :studentRole ESCAPE '!')")
            ->setParameter('id', $user->getId())
            ->setParameter('roles', array_values(array_unique(['ROLE_USER', $role->value])), Types::JSON)
            ->setParameter('studentRole', '%"ROLE!_STUDENT"%')->getQuery()->execute();
    }

    public function assignStudentToGroup(User $student, Group $group): bool
    {
        // The conditional update prevents two concurrent requests from moving a student between groups.
        return 1 === $this->createQueryBuilder('u')->update()
            ->set('u.studentGroup', ':group')->where('u.id = :id')
            ->andWhere('u.studentGroup IS NULL')->andWhere("u.roles LIKE :role ESCAPE '!'")
            ->setParameter('group', $group)->setParameter('id', $student->getId())
            ->setParameter('role', '%"ROLE!_STUDENT"%')->getQuery()->execute();
    }

    public function removeStudentFromGroup(User $student, Group $group): bool
    {
        return 1 === $this->createQueryBuilder('u')->update()
            ->set('u.studentGroup', 'NULL')->where('u.id = :id')->andWhere('u.studentGroup = :group')
            ->setParameter('id', $student->getId())->setParameter('group', $group)->getQuery()->execute();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Unsupported user class: %s.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
