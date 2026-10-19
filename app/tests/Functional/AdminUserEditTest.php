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

class AdminUserEditTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $admin;
    private User $target;

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
        $this->admin = $this->user('admin@example.com', UserRole::Admin);
        $this->target = $this->user('target@example.com', UserRole::Student);
    }

    private function manager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, UserRole $role): User
    {
        $user = (new User())->setEmail($email)->setFullName('Тестовий користувач')->setRole($role)->setPassword('fixture-hash');
        $this->manager()->persist($user);
        $this->manager()->flush();

        return $user;
    }

    private function values(array $changes = []): array
    {
        return array_replace([
            'user_edit[fullName]' => 'Олена Коваленко',
            'user_edit[email]' => 'target@example.com',
            'user_edit[phoneNumber]' => '+380 (67) 123-45-67',
            'user_edit[birthDate]' => '2005-04-15',
        ], $changes);
    }

    public static function nonAdminRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (UserRole::Admin !== $role) yield $role->value => [$role];
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function testOnlyAdministratorCanEditUsers(UserRole $role): void
    {
        $this->client->loginUser($this->user('actor@example.com', $role));
        $url = '/admin/users/'.$this->target->getId().'/edit';
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGuestCannotOpenEditor(): void
    {
        $this->client->request('GET', '/admin/users/'.$this->target->getId().'/edit');
        self::assertResponseRedirects('/login');
    }

    public function testEditActionLoadsModalAndSaveReturnsCatalogStreams(): void
    {
        $group = (new Group())->setCurator($this->admin)->setName('КН-21');
        $group->addStudent($this->target);
        $this->manager()->persist($group);
        $this->manager()->flush();
        $id = $this->target->getId();
        $groupId = $group->getId();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users', ['email' => 'target@', 'perPage' => 10]);
        self::assertSelectorExists('[data-edit-user-id="'.$id.'"][data-turbo-frame="user-edit-modal"]');
        $link = $this->client->getCrawler()->filter('[data-edit-user-id="'.$id.'"]')->link();
        $this->client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#user-edit-modal dialog[data-controller="modal"]');
        self::assertSelectorNotExists('select[name="user_edit[role]"]');
        self::assertSelectorNotExists('input[name="user_edit[password]"]');
        self::assertSelectorNotExists('input[name="user_edit[phoneNumber]"][required]');
        self::assertSelectorExists('div[data-controller="datepicker"] input[name="user_edit[birthDate]"][lang="uk"]');
        self::assertSelectorNotExists('input[data-controller="datepicker"]');
        $this->client->submitForm('Зберегти зміни', $this->values(), serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertSelectorExists('turbo-stream[action="replace"][target="user-catalog"]');
        self::assertSelectorExists('turbo-stream[action="update"][target="user-edit-modal"]');
        self::assertStringContainsString('Олена Коваленко', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Записи 1–1 з 1', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('fixture-hash', $this->client->getResponse()->getContent());
        $this->manager()->clear();
        $saved = $this->manager()->find(User::class, $id);
        self::assertSame('Олена Коваленко', $saved->getFullName());
        self::assertSame('+380671234567', $saved->getPhoneNumber());
        self::assertSame('2005-04-15', $saved->getBirthDate()?->format('Y-m-d'));
        self::assertSame(UserRole::Student, $saved->getRole());
        self::assertSame($groupId, $saved->getStudentGroup()->getId());
        self::assertSame('fixture-hash', $saved->getPassword());
    }

    public function testInvalidFormStaysInModalAndDoesNotPersist(): void
    {
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $this->client->request('GET', '/admin/users/'.$id.'/edit');
        $this->client->submitForm('Зберегти зміни', $this->values(['user_edit[email]' => 'admin@example.com']), serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#user-edit-modal dialog form');
        self::assertSelectorTextContains('[role="alert"]', 'Ця електронна адреса вже зареєстрована.');
        self::assertSelectorNotExists('turbo-stream');
        self::assertSame('target@example.com', $this->manager()->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$id]));
    }

    public static function invalidContacts(): iterable
    {
        yield 'invalid phone' => [['user_edit[phoneNumber]' => 'invalid']];
        yield 'incomplete mask' => [['user_edit[phoneNumber]' => '+380 (67) 123']];
        yield 'future birth date' => [['user_edit[birthDate]' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')]];
    }

    #[DataProvider('invalidContacts')]
    public function testInvalidContactValuesKeepModalOpen(array $changes): void
    {
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $this->client->request('GET', '/admin/users/'.$id.'/edit');
        $this->client->submitForm('Зберегти зміни', $this->values($changes), serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#user-edit-modal [role="alert"]');
        self::assertSelectorNotExists('turbo-stream');
        $this->manager()->clear();
        self::assertNull($this->manager()->find(User::class, $id)->getPhoneNumber());
    }

    public function testInvalidCsrfAndPrivilegeFieldsAreRejected(): void
    {
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $url = '/admin/users/'.$id.'/edit';
        $this->client->request('POST', $url, ['user_edit' => ['fullName' => 'Олена Коваленко', 'email' => 'changed@example.com', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="user_edit[_token]"]')->attr('value');
        $this->client->request('POST', $url, ['user_edit' => ['fullName' => 'Олена Коваленко', 'email' => 'changed@example.com', 'role' => 'ROLE_ADMIN', 'studentGroup' => null, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('target@example.com', $this->manager()->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$id]));
    }

    public function testOptionalFieldsCanBeClearedAndRegularPostRedirectsToCatalog(): void
    {
        $this->target->setPhoneNumber('+380671234567')->setBirthDate(new \DateTimeImmutable('2005-04-15'));
        $this->manager()->flush();
        $id = $this->target->getId();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$id.'/edit', ['catalog' => ['email' => 'target@', 'perPage' => 10, 'page' => 1]]);
        $this->client->submitForm('Зберегти зміни', $this->values(['user_edit[phoneNumber]' => '', 'user_edit[birthDate]' => '']));
        self::assertResponseStatusCodeSame(303);
        parse_str(parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame('target@', $query['email']);
        self::assertSame('10', $query['perPage']);
        $this->manager()->clear();
        $saved = $this->manager()->find(User::class, $id);
        self::assertNull($saved->getPhoneNumber());
        self::assertNull($saved->getBirthDate());
    }

    public function testSuccessfulEditReappliesFilterAndKeepsRequestedPage(): void
    {
        for ($index = 0; $index < 23; ++$index) $this->user('target'.$index.'@example.com', UserRole::User);
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $this->client->request('GET', '/admin/users/'.$id.'/edit', ['catalog' => ['email' => 'target', 'perPage' => 10, 'page' => 2]]);
        $this->client->submitForm('Зберегти зміни', $this->values(['user_edit[email]' => 'changed@example.com']), serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Записи 11–20 з 23', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('data-user-id="'.$id.'"', $this->client->getResponse()->getContent());
    }

    public function testRoleActionOpensModalAndUpdatesCatalogAfterSave(): void
    {
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $crawler = $this->client->request('GET', '/admin/users', ['email' => 'target@', 'perPage' => 10]);
        $link = $crawler->filter('[data-role-user-id="'.$id.'"][data-turbo-frame="user-edit-modal"]')->link();
        $this->client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#user-edit-modal dialog[data-modal-action-value="role"]');
        $this->client->submitForm('Зберегти роль', ['user_role[role]' => 'ROLE_TEACHER'], serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertSelectorExists('turbo-stream[action="replace"][target="user-catalog"]');
        self::assertSelectorExists('turbo-stream[action="update"][target="user-edit-modal"]');
        self::assertStringContainsString('Викладач', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Записи 1–1 з 1', $this->client->getResponse()->getContent());
        $this->manager()->clear();
        self::assertSame(UserRole::Teacher, $this->manager()->find(User::class, $id)->getRole());
    }

    public function testRoleModalKeepsFiltersAndPage(): void
    {
        for ($index = 0; $index < 23; ++$index) $this->user('student'.$index.'@example.com', UserRole::Student);
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $this->client->request('GET', '/admin/users/'.$id.'/role', ['catalog' => ['role' => 'ROLE_STUDENT', 'perPage' => 10, 'page' => 2]]);
        $this->client->submitForm('Зберегти роль', ['user_role[role]' => 'ROLE_TEACHER'], serverParameters: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Записи 11–20 з 23', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('data-user-id="'.$id.'"', $this->client->getResponse()->getContent());
    }

    public function testInvalidRoleKeepsModalOpen(): void
    {
        $this->client->loginUser($this->admin);
        $id = $this->target->getId();
        $url = '/admin/users/'.$id.'/role';
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="user_role[_token]"]')->attr('value');
        $this->client->request('POST', $url, ['user_role' => ['role' => 'ROLE_UNKNOWN', '_token' => $token]], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#user-edit-modal dialog form');
        self::assertSelectorNotExists('turbo-stream');
        $this->manager()->clear();
        self::assertSame(UserRole::Student, $this->manager()->find(User::class, $id)->getRole());
    }

    public function testGroupedStudentRoleRemainsLockedInModal(): void
    {
        $group = (new Group())->setName('КН-21')->setCurator($this->admin);
        $group->addStudent($this->target);
        $this->manager()->persist($group);
        $this->manager()->flush();
        $id = $this->target->getId();
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$id.'/role', ['user_role' => ['role' => 'ROLE_TEACHER']], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('turbo-frame#user-edit-modal dialog [role="alert"]');
        self::assertSelectorNotExists('select[name="user_role[role]"]');
        self::assertSelectorNotExists('turbo-stream');
        $this->manager()->clear();
        self::assertSame(UserRole::Student, $this->manager()->find(User::class, $id)->getRole());
    }

    public function testInvalidSelfEditDoesNotInvalidateAdministratorSession(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$this->admin->getId().'/edit');
        $this->client->submitForm('Зберегти зміни', $this->values(['user_edit[fullName]' => '']));
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }
}
