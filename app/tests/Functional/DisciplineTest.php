<?php

namespace App\Tests\Functional;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DisciplineTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $manager = $this->manager();
        $parameters = $manager->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $parameters['driver']);
        self::assertStringContainsString('auth-test', $parameters['path']);
        $schema = new SchemaTool($manager);
        $schema->dropDatabase();
        $schema->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, UserRole $role): User
    {
        $user = (new User())->setEmail($email)->setFullName($email)->setRole($role)->setPassword('fixture-hash');
        $this->manager()->persist($user);
        $this->manager()->flush();

        return $user;
    }

    private function group(User $curator, string $name): Group
    {
        $group = (new Group())->setName($name)->setCurator($curator);
        $this->manager()->persist($group);
        $this->manager()->flush();

        return $group;
    }

    private function discipline(User $teacher, string $name, array $groups = []): Discipline
    {
        $discipline = (new Discipline())->setName($name)->setTeacher($teacher);
        foreach ($groups as $group) $discipline->addGroup($group);
        $this->manager()->persist($discipline);
        $this->manager()->flush();

        return $discipline;
    }

    public static function forbiddenRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (!in_array($role, [UserRole::Teacher, UserRole::Admin], true)) yield $role->value => [$role];
        }
    }

    #[DataProvider('forbiddenRoles')]
    public function testOtherRolesCannotManageDisciplines(UserRole $role): void
    {
        $this->client->loginUser($this->user('actor@example.com', $role));
        foreach (['/teacher/disciplines', '/teacher/disciplines/new', '/teacher/disciplines/1', '/teacher/disciplines/1/edit', '/teacher/groups'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/teacher/disciplines/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testGuestCannotManageDisciplines(): void
    {
        $this->client->request('GET', '/teacher/disciplines/new');
        self::assertResponseRedirects('/login');
    }

    public function testTeacherCanCreateDisciplineForMultipleGroups(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $teacherId = $teacher->getId();
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $first = $this->group($curator, 'КН-21');
        $second = $this->group($curator, 'КН-22');
        $firstId = $first->getId();
        $secondId = $second->getId();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/teacher/disciplines/new');
        $this->client->submitForm('Створити дисципліну', [
            'discipline[name]' => '  Математика  ', 'discipline[description]' => 'Основи математичного аналізу',
            'discipline[groups]' => [$firstId, $secondId],
        ]);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        $saved = $this->manager()->getRepository(Discipline::class)->findOneBy(['name' => 'Математика']);
        self::assertNotNull($saved);
        self::assertSame($teacherId, $saved->getTeacher()->getId());
        self::assertCount(2, $saved->getGroups());
        self::assertSame(2, (int) $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM discipline_groups WHERE discipline_id = ?', [$saved->getId()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Математика');
        self::assertSelectorCount(2, 'li[data-group-id]');
        $this->client->request('GET', '/teacher/groups');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tr[data-group-id]');
    }

    public function testGroupLinksCanBeChangedWithoutDeletingGroups(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $first = $this->group($curator, 'КН-21');
        $second = $this->group($curator, 'КН-22');
        $discipline = $this->discipline($teacher, 'Математика', [$first, $second]);
        $this->discipline($teacher, 'Алгебра', [$second]);
        $id = $discipline->getId();
        $secondId = $second->getId();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/teacher/disciplines/'.$id.'/edit');
        $this->client->submitForm('Зберегти дисципліну', ['discipline[groups]' => [$secondId]]);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        $saved = $this->manager()->find(Discipline::class, $id);
        self::assertCount(1, $saved->getGroups());
        self::assertSame($secondId, $saved->getGroups()->first()->getId());
        self::assertSame(2, $this->manager()->getRepository(Group::class)->count([]));
        $this->client->request('GET', '/teacher/groups');
        self::assertSelectorCount(1, 'tr[data-group-id]');
    }

    public function testTeacherCannotViewOrEditAnotherTeachersDiscipline(): void
    {
        $owner = $this->user('owner@example.com', UserRole::Teacher);
        $foreign = $this->discipline($owner, 'Чужа дисципліна');
        $id = $foreign->getId();
        $actor = $this->user('actor@example.com', UserRole::Teacher);
        $this->discipline($actor, 'Власна дисципліна');
        $this->client->loginUser($actor);
        $this->client->request('GET', '/teacher/disciplines');
        self::assertSelectorCount(1, 'tr[data-discipline-id]');
        self::assertSelectorTextNotContains('tbody', 'Чужа дисципліна');
        foreach (['/teacher/disciplines/'.$id, '/teacher/disciplines/'.$id.'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/teacher/disciplines/'.$id.'/edit', ['discipline' => ['name' => 'Підміна']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFormRejectsBlankNamesInvalidGroupsAndOwnerInjection(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $other = $this->user('other@example.com', UserRole::Teacher);
        $otherId = $other->getId();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/teacher/disciplines/new');
        $this->client->submitForm('Створити дисципліну', ['discipline[name]' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->request('GET', '/teacher/disciplines/new');
        $token = $crawler->filter('input[name="discipline[_token]"]')->attr('value');
        $this->client->request('POST', '/teacher/disciplines/new', ['discipline' => ['name' => 'Тест', 'groups' => [99999], '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/teacher/disciplines/new', ['discipline' => ['name' => 'Тест', 'teacher' => $otherId, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/teacher/disciplines/new', ['discipline' => ['name' => 'Тест', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager()->getRepository(Discipline::class)->count([]));
    }

    public function testStudentSeesOnlyOwnGroupDisciplinesAndDistinctTeachers(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $other = $this->user('other@example.com', UserRole::Teacher);
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $own = $this->group($curator, 'КН-21');
        $foreign = $this->group($curator, 'КН-22');
        $this->discipline($teacher, 'Математика', [$own]);
        $this->discipline($teacher, 'Алгебра', [$own, $foreign]);
        $this->discipline($other, 'Чужа дисципліна', [$foreign]);
        $student = $this->user('student@example.com', UserRole::Student);
        $own->addStudent($student);
        $this->manager()->flush();
        $this->client->loginUser($student);
        $this->client->request('GET', '/student/disciplines');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'tr[data-discipline-id]');
        self::assertSelectorTextNotContains('tbody', 'Чужа дисципліна');
        $this->client->request('GET', '/student/teachers');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tr[data-teacher-id]');
        self::assertSelectorTextContains('tbody', 'teacher@example.com');
        self::assertSelectorTextNotContains('tbody', 'other@example.com');
    }

    public function testStudentWithoutGroupSeesEmptyLists(): void
    {
        $this->client->loginUser($this->user('student@example.com', UserRole::Student));
        foreach (['/student/disciplines', '/student/teachers'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Вас ще не додано до групи');
        }
    }

    public function testAdminCanManageAnotherTeachersDiscipline(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $teacherId = $teacher->getId();
        $discipline = $this->discipline($teacher, 'Математика');
        $id = $discipline->getId();
        $this->client->loginUser($this->user('admin@example.com', UserRole::Admin));
        $this->client->request('GET', '/teacher/disciplines/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Зберегти дисципліну', ['discipline[name]' => 'Оновлена дисципліна']);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        $saved = $this->manager()->find(Discipline::class, $id);
        self::assertSame('Оновлена дисципліна', $saved->getName());
        self::assertSame($teacherId, $saved->getTeacher()->getId());
    }

    public function testPaginationPreservesAllGroupLinks(): void
    {
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $first = $this->group($curator, 'КН-21');
        $second = $this->group($curator, 'КН-22');
        for ($index = 0; $index < 21; ++$index) $this->discipline($teacher, sprintf('Дисципліна %02d', $index), [$first, $second]);
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/teacher/disciplines');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(20, 'tr[data-discipline-id]');
        self::assertSelectorTextContains('tbody tr:first-child', 'КН-21');
        self::assertSelectorTextContains('tbody tr:first-child', 'КН-22');
        $this->client->request('GET', '/teacher/disciplines', ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tr[data-discipline-id]');
        self::assertSelectorTextContains('tbody', 'КН-21');
        self::assertSelectorTextContains('tbody', 'КН-22');
    }
}
