<?php

namespace App\Tests\Functional;

use App\Entity\Group;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CuratorTest extends WebTestCase
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

    private function membership(int $studentId): ?int
    {
        $id = $this->manager()->getConnection()->fetchOne('SELECT group_id FROM users WHERE id = ?', [$studentId]);

        return null === $id ? null : (int) $id;
    }

    public static function forbiddenRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (!in_array($role, [UserRole::Curator, UserRole::Admin], true)) {
                yield $role->value => [$role];
            }
        }
    }

    #[DataProvider('forbiddenRoles')]
    public function testOtherRolesCannotUseCuratorRoutes(UserRole $role): void
    {
        $this->client->loginUser($this->user('actor@example.com', $role));
        foreach (['/curator/students', '/curator/groups', '/curator/groups/new', '/curator/groups/1', '/curator/students/1/assign'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/curator/groups/new', ['group' => ['name' => 'Forbidden']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/curator/students');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/curator/groups/new');
        self::assertResponseRedirects('/login');
    }

    public function testCuratorCanCreateGroupAndAssignStudent(): void
    {
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $curatorId = $curator->getId();
        $student = $this->user('student@example.com', UserRole::Student);
        $studentId = $student->getId();
        $this->user('teacher@example.com', UserRole::Teacher);
        $this->client->loginUser($curator);

        $this->client->request('GET', '/curator/groups/new');
        $this->client->submitForm('Створити групу', ['group[name]' => '  КН-21  ']);
        self::assertResponseStatusCodeSame(303);
        $group = $this->manager()->getRepository(Group::class)->findOneBy(['name' => 'КН-21']);
        self::assertNotNull($group);
        $groupId = $group->getId();
        self::assertSame($curatorId, $group->getCurator()->getId());

        $this->client->request('GET', '/curator/students', ['role' => 'ROLE_ADMIN']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tr[data-student-id]');
        self::assertSelectorTextContains('tbody', 'student@example.com');
        self::assertSelectorTextNotContains('tbody', 'teacher@example.com');
        self::assertStringNotContainsString('fixture-hash', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/curator/students/'.$studentId.'/assign');
        $this->client->submitForm('Додати до групи', ['assign_student_group[group]' => $groupId]);
        self::assertResponseRedirects('/curator/groups/'.$groupId, 303);
        self::assertSame($groupId, $this->membership($studentId));
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'КН-21');
        self::assertSelectorExists('tr[data-student-id="'.$studentId.'"]');
    }

    public function testGroupCreationValidatesNameAndCsrf(): void
    {
        $this->client->loginUser($this->user('curator@example.com', UserRole::Curator));
        $this->client->request('GET', '/curator/groups/new');
        $this->client->submitForm('Створити групу', ['group[name]' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/curator/groups/new', ['group' => ['name' => 'КН-21', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager()->getRepository(Group::class)->count([]));
    }

    public function testCuratorCannotManageAnotherCuratorsGroup(): void
    {
        $actor = $this->user('actor@example.com', UserRole::Curator);
        $owner = $this->user('owner@example.com', UserRole::Curator);
        $own = $this->group($actor, 'Власна група');
        $foreign = $this->group($owner, 'Чужа група');
        $student = $this->user('student@example.com', UserRole::Student);
        $foreignId = $foreign->getId();
        $studentId = $student->getId();
        $this->client->loginUser($actor);

        $this->client->request('GET', '/curator/groups');
        self::assertSelectorTextContains('tbody', 'Власна група');
        self::assertSelectorTextNotContains('tbody', 'Чужа група');
        $this->client->request('GET', '/curator/groups/'.$foreignId);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/curator/students/'.$studentId.'/assign');
        self::assertSelectorNotExists('option[value="'.$foreignId.'"]');
        $token = $crawler->filter('input[name="assign_student_group[_token]"]')->attr('value');
        $this->client->request('POST', '/curator/students/'.$studentId.'/assign', ['assign_student_group' => ['group' => $foreignId, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->membership($studentId));
        $this->client->request('POST', '/curator/groups/'.$foreignId.'/students/'.$studentId.'/remove');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStudentCannotBeSilentlyMovedToSecondGroup(): void
    {
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $first = $this->group($curator, 'Перша');
        $second = $this->group($curator, 'Друга');
        $student = $this->user('student@example.com', UserRole::Student);
        $studentId = $student->getId();
        $firstId = $first->getId();
        $secondId = $second->getId();
        $this->client->loginUser($curator);
        foreach ([$firstId, $secondId] as $index => $groupId) {
            $this->client->request('GET', '/curator/students/'.$studentId.'/assign');
            $this->client->submitForm('Додати до групи', ['assign_student_group[group]' => $groupId]);
            self::assertResponseStatusCodeSame(0 === $index ? 303 : 422);
        }
        self::assertSame($firstId, $this->membership($studentId));
        $this->client->request('GET', '/curator/students', ['withoutGroup' => 1]);
        self::assertSelectorNotExists('tr[data-student-id="'.$studentId.'"]');
    }

    public function testInvalidAssignmentCsrfAndNonStudentsAreRejected(): void
    {
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $group = $this->group($curator, 'КН-21');
        $student = $this->user('student@example.com', UserRole::Student);
        $studentId = $student->getId();
        $groupId = $group->getId();
        $curatorId = $curator->getId();
        $this->client->loginUser($curator);
        $this->client->request('POST', '/curator/students/'.$studentId.'/assign', ['assign_student_group' => ['group' => $groupId, '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->membership($studentId));
        $this->client->request('GET', '/curator/students/'.$curatorId.'/assign');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovalNeedsValidCsrfAndOnlyChangesMembership(): void
    {
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $group = $this->group($curator, 'КН-21');
        $student = $this->user('student@example.com', UserRole::Student);
        $group->addStudent($student);
        $this->manager()->flush();
        $studentId = $student->getId();
        $groupId = $group->getId();
        $this->client->loginUser($curator);
        $this->client->request('POST', '/curator/groups/'.$groupId.'/students/'.$studentId.'/remove', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($groupId, $this->membership($studentId));
        $this->client->request('GET', '/curator/groups/'.$groupId);
        $this->client->submitForm('Вилучити з групи');
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->membership($studentId));
        self::assertNotNull($this->manager()->getRepository(User::class)->find($studentId));
    }

    public function testStudentSeesOwnGroup(): void
    {
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $group = $this->group($curator, 'КН-21');
        $student = $this->user('student@example.com', UserRole::Student);
        $group->addStudent($student);
        $this->manager()->flush();
        $this->client->loginUser($student);
        $this->client->request('GET', '/student/group');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'КН-21');
        self::assertSelectorTextContains('main', 'curator@example.com');
        self::assertSelectorTextContains('tbody', 'student@example.com');
    }

    public function testAdministratorCanManageAllGroupsAndRoleChangeRemovesMembership(): void
    {
        $owner = $this->user('owner@example.com', UserRole::Curator);
        $group = $this->group($owner, 'КН-21');
        $student = $this->user('student@example.com', UserRole::Student);
        $studentId = $student->getId();
        $groupId = $group->getId();
        $admin = $this->user('admin@example.com', UserRole::Admin);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/curator/groups/'.$groupId);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/curator/students/'.$studentId.'/assign');
        $this->client->submitForm('Додати до групи', ['assign_student_group[group]' => $groupId]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($groupId, $this->membership($studentId));
        $this->client->request('GET', '/admin/users', ['studentGroup' => $groupId]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tr[data-user-id]');
        $this->client->request('GET', '/admin/users/'.$studentId.'/role');
        $this->client->submitForm('Зберегти роль', ['user_role[role]' => 'ROLE_TEACHER']);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->membership($studentId));
    }
}
