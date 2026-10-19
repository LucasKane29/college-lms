<?php

namespace App\Repository;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Discipline> */
class DisciplineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discipline::class);
    }

    public function findManagedPage(User $teacher, bool $admin, int $page): array
    {
        $query = $this->listQuery();
        if (!$admin) {
            $query->andWhere('d.teacher = :teacher')->setParameter('teacher', $teacher);
        }

        return $this->paginate($query, $page);
    }

    public function findForGroupPage(?Group $group, int $page): array
    {
        if (null === $group) {
            return ['disciplines' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        return $this->paginate($this->listQuery()->andWhere(':group MEMBER OF d.groups')->setParameter('group', $group), $page);
    }

    private function listQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('d')->leftJoin('d.teacher', 'teacher')->addSelect('teacher')
            ->leftJoin('d.groups', 'groups')->addSelect('groups')->orderBy('d.name', 'ASC')->addOrderBy('d.id', 'ASC');
    }

    /** @return array{disciplines: list<Discipline>, total: int, page: int, pages: int} */
    private function paginate(QueryBuilder $builder, int $page): array
    {
        // Paginator limits root entities, preserving every group in a fetched collection.
        $query = $builder->getQuery();
        $paginator = new Paginator($query, fetchJoinCollection: true);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, $page));
        $query->setFirstResult(($page - 1) * 20)->setMaxResults(20);
        $disciplines = array_values(iterator_to_array($paginator->getIterator()));

        return compact('disciplines', 'total', 'page', 'pages');
    }
}
