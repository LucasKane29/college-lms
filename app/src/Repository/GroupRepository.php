<?php

namespace App\Repository;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Group> */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function manageableBy(User $actor, bool $admin): QueryBuilder
    {
        $query = $this->createQueryBuilder('g')->orderBy('g.name', 'ASC')->addOrderBy('g.id', 'ASC');
        if (!$admin) {
            $query->andWhere('g.curator = :curator')->setParameter('curator', $actor);
        }

        return $query;
    }

    /** @return array{groups: list<Group>, total: int, page: int, pages: int} */
    public function findManagedPage(User $actor, bool $admin, int $page): array
    {
        $query = $this->manageableBy($actor, $admin);
        $total = (int) (clone $query)->select('COUNT(g.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, $page));
        $groups = $query->leftJoin('g.curator', 'curator')->addSelect('curator')
            ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();

        return compact('groups', 'total', 'page', 'pages');
    }
}
