<?php

namespace App\Tests\Unit;

use App\Entity\Group;
use App\Entity\User;
use App\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public static function otherRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (UserRole::Student !== $role) {
                yield $role->value => [$role];
            }
        }
    }

    #[DataProvider('otherRoles')]
    public function testGroupedStudentCannotChangeRole(UserRole $role): void
    {
        $group = new Group();
        $student = (new User())->setRole(UserRole::Student)->setStudentGroup($group);
        self::assertTrue($student->isRoleLocked());
        try {
            $student->setRole($role);
            self::fail('A grouped student must retain their role.');
        } catch (\DomainException) {
            self::assertSame(UserRole::Student, $student->getRole());
            self::assertSame($group, $student->getStudentGroup());
            self::assertTrue($group->getStudents()->contains($student));
        }
    }

    public function testRemovingStudentFromGroupUnlocksRoleChange(): void
    {
        $group = new Group();
        $student = (new User())->setRole(UserRole::Student)->setStudentGroup($group);
        $group->removeStudent($student);
        self::assertFalse($student->isRoleLocked());
        $student->setRole(UserRole::Teacher);
        self::assertSame(UserRole::Teacher, $student->getRole());
        self::assertNull($student->getStudentGroup());
    }
}
