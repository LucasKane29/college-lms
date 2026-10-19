<?php

namespace App\Tests\Unit;

use App\Entity\Discipline;
use App\Entity\Group;
use PHPUnit\Framework\TestCase;

class DisciplineTest extends TestCase
{
    public function testGroupLinksStaySynchronizedAndDoNotDuplicate(): void
    {
        $discipline = new Discipline();
        $first = new Group();
        $second = new Group();
        $discipline->addGroup($first)->addGroup($first);
        $second->addDiscipline($discipline);
        self::assertCount(2, $discipline->getGroups());
        self::assertCount(1, $first->getDisciplines());
        $first->removeDiscipline($discipline);
        self::assertFalse($discipline->getGroups()->contains($first));
        self::assertCount(0, $first->getDisciplines());
        $discipline->removeGroup($second);
        self::assertCount(0, $second->getDisciplines());
        self::assertCount(0, $discipline->getGroups());
    }
}
