<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        // This suite is restricted to its own disposable SQLite database.
        $connectionParameters = $entityManager->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $connectionParameters['driver']);
        self::assertStringContainsString('auth-test', $connectionParameters['path']);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = [$entityManager->getClassMetadata(User::class)];
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);
    }

    public function testPublicPagesAndProtectedAccount(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Увійдіть до кабінету');
        self::assertSelectorExists('input[name="_csrf_token"]');

        $this->client->request('GET', '/registration');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Створіть свій акаунт');

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testRegistrationHashesPasswordAndAllowsLoginAndLogout(): void
    {
        $this->register('  Student@Example.com  ');
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Обліковий запис створено');

        $user = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'student@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertNotSame('StudyPass123!', $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'StudyPass123!'));

        $this->client->submitForm('Увійти', ['email' => 'STUDENT@EXAMPLE.COM', 'password' => 'StudyPass123!']);
        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Олена Коваленко');
        self::assertSelectorTextContains('main', 'student@example.com');

        $this->client->request('GET', '/registration');
        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        $this->client->submitForm('Вийти з облікового запису');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testInvalidRegistrationReturns422AndDoesNotPersist(): void
    {
        $this->client->request('GET', '/registration');
        $this->client->submitForm('Створити акаунт', [
            'registration[fullName]' => '',
            'registration[email]' => 'invalid-email',
            'registration[plainPassword][first]' => 'short',
            'registration[plainPassword][second]' => 'short',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Вкажіть ваше ім’я');
        self::assertSelectorTextContains('main', 'коректну електронну адресу');
        self::assertSelectorTextContains('main', 'щонайменше 8');
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count([]));
    }

    public function testMismatchedPasswordsReturn422(): void
    {
        $this->register('student@example.com', 'AnotherPass123!');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Паролі не збігаються.');
        self::assertSelectorNotExists('input[type="password"][value]');
    }

    public function testDuplicateEmailReturns422(): void
    {
        $this->register('student@example.com');
        self::assertResponseRedirects('/login', 303);
        $this->register('STUDENT@example.com');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Ця електронна адреса вже зареєстрована.');
        self::assertSame(1, static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count([]));
    }

    public function testRegistrationRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/registration', ['registration' => [
            'fullName' => 'Олена Коваленко', 'email' => 'student@example.com',
            'plainPassword' => ['first' => 'StudyPass123!', 'second' => 'StudyPass123!'],
            '_token' => 'invalid',
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count([]));
    }

    public function testWrongPasswordShowsGenericError(): void
    {
        $this->register('student@example.com');
        $this->client->followRedirect();
        $this->client->submitForm('Увійти', ['email' => 'student@example.com', 'password' => 'WrongPassword']);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Неправильна електронна пошта або пароль.');
        self::assertInputValueSame('email', 'student@example.com');
        self::assertSelectorNotExists('input[name="password"][value]');
    }

    public function testLoginRejectsInvalidCsrfToken(): void
    {
        $this->register('student@example.com');
        $this->client->request('POST', '/login', [
            'email' => 'student@example.com', 'password' => 'StudyPass123!', '_csrf_token' => 'invalid',
        ]);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Сеанс форми закінчився');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testLogoutRejectsInvalidCsrfToken(): void
    {
        $this->register('student@example.com');
        $this->client->followRedirect();
        $this->client->submitForm('Увійти', ['email' => 'student@example.com', 'password' => 'StudyPass123!']);
        $this->client->request('POST', '/logout', ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
    }

    private function register(string $email, string $confirmation = 'StudyPass123!'): void
    {
        $this->client->request('GET', '/registration');
        $this->client->submitForm('Створити акаунт', [
            'registration[fullName]' => 'Олена Коваленко',
            'registration[email]' => $email,
            'registration[plainPassword][first]' => 'StudyPass123!',
            'registration[plainPassword][second]' => $confirmation,
        ]);
    }
}
