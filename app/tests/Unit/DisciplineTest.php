<?php

namespace App\Tests\Unit;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class DisciplineTest extends TestCase
{
    public function testTeacherAssignmentsDoNotDuplicateAndCanBeRemoved(): void
    {
        $first = (new User())->setFullName('Перший викладач');
        $second = (new User())->setFullName('Другий викладач');
        $discipline = (new Discipline())->addTeacher($first)->addTeacher($first)->addTeacher($second);
        self::assertCount(2, $discipline->getTeachers());
        self::assertTrue($discipline->hasTeacher($second));
        self::assertSame('Перший викладач, Другий викладач', $discipline->getTeacherNames());
        $discipline->removeTeacher($first);
        self::assertFalse($discipline->hasTeacher($first));
        self::assertTrue($discipline->hasTeacher($second));
    }
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
