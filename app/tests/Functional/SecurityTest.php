<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
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
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
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
        self::assertSelectorExists('input[type="tel"][name="registration[phoneNumber]"][required][pattern][data-controller="phone-number"]');
        self::assertSelectorExists('div[data-controller="datepicker"] input[type="date"][name="registration[birthDate]"][required][max][lang="uk"]');
        self::assertSelectorNotExists('input[data-controller="datepicker"]');

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
        self::assertSame('+380671234567', $user->getPhoneNumber());
        self::assertSame('2005-04-15', $user->getBirthDate()?->format('Y-m-d'));
        self::assertSame(['ROLE_USER'], json_decode(static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT roles FROM users WHERE email = ?', ['student@example.com']), true, flags: JSON_THROW_ON_ERROR));
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
            'phoneNumber' => '+380671234567', 'birthDate' => '2005-04-15',
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

    public function testAnonymousCannotManageRoles(): void
    {
        $this->client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
        $this->client->request('POST', '/admin/users/1/role', ['user_role' => ['role' => 'ROLE_ADMIN']]);
        self::assertResponseRedirects('/login');
    }

    public static function nonAdminRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (UserRole::Admin !== $role) {
                yield $role->value => [$role];
            }
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function testOnlyAdminCanManageRoles(UserRole $role): void
    {
        $actor = $this->createUser('actor@example.com', $role);
        $target = $this->createUser('target@example.com');
        $this->client->loginUser($actor);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/users"]');
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/users/'.$target->getId().'/role');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/users/'.$target->getId().'/role', ['user_role' => ['role' => 'ROLE_ADMIN']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(UserRole::User, $this->reloadUser('target@example.com')->getRole());
    }

    public static function allRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('allRoles')]
    public function testAdminCanAssignEveryRole(UserRole $role): void
    {
        $admin = $this->createUser('admin@example.com', UserRole::Admin);
        $target = $this->createUser('target@example.com', UserRole::Teacher);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/account');
        self::assertSelectorExists('a[href="/admin/users"]');
        $this->client->clickLink('Користувачі');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/users/'.$target->getId().'/role');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Зберегти роль', ['user_role[role]' => $role->value]);
        self::assertResponseRedirects('/admin/users', 303);
        $updated = $this->reloadUser('target@example.com');
        self::assertSame($role, $updated->getRole());
        self::assertSame(array_values(array_unique(['ROLE_USER', $role->value])), $updated->getRoles());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'Роль користувача оновлено.');
    }

    public function testRoleChangeRejectsInvalidCsrfAndUnknownRole(): void
    {
        $admin = $this->createUser('admin@example.com', UserRole::Admin);
        $target = $this->createUser('target@example.com');
        $this->client->loginUser($admin);
        $url = '/admin/users/'.$target->getId().'/role';

        $this->client->request('POST', $url, ['user_role' => ['role' => 'ROLE_ADMIN', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(UserRole::User, $this->reloadUser('target@example.com')->getRole());

        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('input[name="user_role[_token]"]')->attr('value');
        $this->client->request('POST', $url, ['user_role' => ['role' => 'ROLE_SUPER_ADMIN', '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(UserRole::User, $this->reloadUser('target@example.com')->getRole());
    }

    public function testRegistrationCannotAssignPrivilegedRole(): void
    {
        $crawler = $this->client->request('GET', '/registration');
        $token = $crawler->filter('input[name="registration[_token]"]')->attr('value');
        $this->client->request('POST', '/registration', ['registration' => [
            'fullName' => 'Олена Коваленко',
            'email' => 'student@example.com',
            'phoneNumber' => '+380671234567', 'birthDate' => '2005-04-15',
            'plainPassword' => ['first' => 'StudyPass123!', 'second' => 'StudyPass123!'],
            'roles' => ['ROLE_ADMIN'],
            '_token' => $token,
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count([]));
    }

    public function testAdminInheritsAllRoles(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', UserRole::Admin));
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        $security = static::getContainer()->get(Security::class);

        foreach (UserRole::cases() as $role) {
            self::assertTrue($security->isGranted($role->value), $role->value);
        }
    }

    public function testRevokedAdminRoleInvalidatesExistingSession(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', UserRole::Admin));
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();

        $this->reloadUser('admin@example.com')->setRole(UserRole::User);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
    }

    public function testMigrationCreatesAdminWithUsableHashedPassword(): void
    {
        $migration = $this->prepareAdminMigration('  ADMIN@Example.com  ', 'InitialAdminPass123!');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $admin = $this->reloadUser('admin@example.com');
        self::assertSame(UserRole::Admin, $admin->getRole());
        self::assertNotSame('InitialAdminPass123!', $admin->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($admin, 'InitialAdminPass123!'));

        $this->client->request('GET', '/login');
        $this->client->submitForm('Увійти', ['email' => 'admin@example.com', 'password' => 'InitialAdminPass123!']);
        self::assertResponseRedirects('/account', 303);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public static function invalidAdminCredentials(): iterable
    {
        yield 'missing email' => ['', 'InitialAdminPass123!'];
        yield 'invalid email' => ['invalid', 'InitialAdminPass123!'];
        yield 'missing password' => ['admin@example.com', ''];
        yield 'short password' => ['admin@example.com', 'short'];
        yield 'bcrypt length limit' => ['admin@example.com', str_repeat('a', 73)];
    }

    #[DataProvider('invalidAdminCredentials')]
    public function testMigrationRejectsInvalidCredentials(string $email, string $password): void
    {
        $this->expectException(AbortMigration::class);
        $this->prepareAdminMigration($email, $password);
    }

    public function testMigrationDoesNotPromoteExistingAccount(): void
    {
        $existing = $this->createUser('admin@example.com');
        $passwordHash = $existing->getPassword();

        try {
            $this->prepareAdminMigration('admin@example.com', 'InitialAdminPass123!');
            self::fail('An existing account must not be promoted by the migration.');
        } catch (AbortMigration) {
            $unchanged = $this->reloadUser('admin@example.com');
            self::assertSame(UserRole::User, $unchanged->getRole());
            self::assertSame($passwordHash, $unchanged->getPassword());
        }
    }

    private function prepareAdminMigration(string $email, string $password): \DoctrineMigrations\Version20261019100000
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261019100000.php';
        $original = [$_SERVER['ADMIN_EMAIL'] ?? null, $_SERVER['ADMIN_PASSWORD'] ?? null];
        $_SERVER['ADMIN_EMAIL'] = $email;
        $_SERVER['ADMIN_PASSWORD'] = $password;

        try {
            $migration = new \DoctrineMigrations\Version20261019100000(
                static::getContainer()->get(EntityManagerInterface::class)->getConnection(),
                new NullLogger(),
            );
            $migration->up(new Schema());

            return $migration;
        } finally {
            foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $index => $name) {
                if (null === $original[$index]) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $original[$index];
                }
            }
        }
    }

    #[DataProvider('allRoles')]
    public function testAuthenticatedMenuMatchesAssignedRole(UserRole $role): void
    {
        $this->client->loginUser($this->createUser('menu@example.com', $role));
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('aside');
        self::assertSelectorExists('[data-controller="sidebar"]');
        self::assertSelectorExists('button[aria-controls="workspace-menu"][aria-expanded="true"]');
        self::assertSelectorExists('.workspace-menu a[href="/account"][aria-current="page"]');
        $expected = match ($role) {
            UserRole::Student => ['/account', '/student/group', '/student/disciplines', '/student/teachers'],
            UserRole::Teacher => ['/account', '/teacher/groups', '/teacher/disciplines'],
            UserRole::Curator => ['/account', '/curator/groups', '/curator/students'],
            UserRole::Admin => ['/account', '/admin/users', '/curator/groups', '/curator/students', '/teacher/disciplines'],
            default => ['/account'],
        };
        $actual = $this->client->getCrawler()->filter('.workspace-menu a')->each(
            static fn ($link): string => $link->attr('href'),
        );
        self::assertSame($expected, $actual);
        self::assertSelectorExists('form[action="/logout"] input[name="_csrf_token"]');
    }

    #[DataProvider('allRoles')]
    public function testLearningRoutesEnforceRolePermissions(UserRole $role): void
    {
        $this->client->loginUser($this->createUser('learning@example.com', $role));
        $routes = [
            '/student/group' => UserRole::Student,
            '/student/disciplines' => UserRole::Student,
            '/student/teachers' => UserRole::Student,
            '/teacher/groups' => UserRole::Teacher,
            '/teacher/disciplines' => UserRole::Teacher,
        ];
        foreach ($routes as $url => $requiredRole) {
            $this->client->request('GET', $url);
            if (UserRole::Admin === $role || $requiredRole === $role) {
                self::assertResponseIsSuccessful();
                self::assertSelectorNotExists('aside');
                if (UserRole::Admin !== $role) {
                    self::assertSelectorExists('.workspace-menu a[href="'.$url.'"][aria-current="page"]');
                }
            } else {
                self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testPublicAuthenticationPagesKeepTheirLayout(): void
    {
        foreach (['/login', '/registration'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('aside');
            self::assertSelectorNotExists('#workspace-navigation');
        }
        $this->client->request('GET', '/student/group');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/teacher/groups');
        self::assertResponseRedirects('/login');
    }

    private function createUser(string $email, UserRole $role = UserRole::User): User
    {
        $user = (new User())->setEmail($email)->setFullName('Тестовий користувач')->setRole($role);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'StudyPass123!'));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function reloadUser(string $email): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public static function invalidRegistrationContacts(): iterable
    {
        yield 'missing phone' => [['phoneNumber' => null], 'registration_phoneNumber_errors'];
        yield 'blank phone' => [['phoneNumber' => ''], 'registration_phoneNumber_errors'];
        yield 'local phone without country code' => [['phoneNumber' => '0671234567'], 'registration_phoneNumber_errors'];
        yield 'phone letters' => [['phoneNumber' => '+38067abc4567'], 'registration_phoneNumber_errors'];
        yield 'short phone' => [['phoneNumber' => '+123'], 'registration_phoneNumber_errors'];
        yield 'incomplete mask' => [['phoneNumber' => '+380 (67) 123'], 'registration_phoneNumber_errors'];
        yield 'long phone' => [['phoneNumber' => '+1234567890123456'], 'registration_phoneNumber_errors'];
        yield 'missing birth date' => [['birthDate' => null], 'registration_birthDate_errors'];
        yield 'blank birth date' => [['birthDate' => ''], 'registration_birthDate_errors'];
        yield 'nonexistent date' => [['birthDate' => '2005-02-30'], 'registration_birthDate_errors'];
        yield 'future date' => [['birthDate' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')], 'registration_birthDate_errors'];
    }

    #[DataProvider('invalidRegistrationContacts')]
    public function testRegistrationRequiresValidPhoneAndBirthDate(array $changes, string $errorId): void
    {
        $crawler = $this->client->request('GET', '/registration');
        $values = array_replace([
            'fullName' => 'Олена Коваленко', 'email' => 'student@example.com',
            'phoneNumber' => '+380671234567', 'birthDate' => '2005-04-15',
            'plainPassword' => ['first' => 'StudyPass123!', 'second' => 'StudyPass123!'],
            '_token' => $crawler->filter('input[name="registration[_token]"]')->attr('value'),
        ], $changes);
        $values = array_filter($values, static fn ($value): bool => null !== $value);
        // Raw requests bypass HTML validation and check the server boundary.
        $this->client->request('POST', '/registration', ['registration' => $values]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#'.$errorId.'[role="alert"]');
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->count([]));
    }

    public function testRegistrationAcceptsMaskedPhoneAndStoresCanonicalNumber(): void
    {
        $this->client->request('GET', '/registration');
        $this->client->submitForm('Створити акаунт', [
            'registration[fullName]' => 'Олена Коваленко',
            'registration[email]' => 'masked@example.com',
            'registration[phoneNumber]' => '+380 (67) 123-45-67',
            'registration[birthDate]' => '2005-04-15',
            'registration[plainPassword][first]' => 'StudyPass123!',
            'registration[plainPassword][second]' => 'StudyPass123!',
        ]);
        self::assertResponseRedirects('/login', 303);
        self::assertSame('+380671234567', $this->reloadUser('masked@example.com')->getPhoneNumber());
    }

    private function register(string $email, string $confirmation = 'StudyPass123!'): void
    {
        $this->client->request('GET', '/registration');
        $this->client->submitForm('Створити акаунт', [
            'registration[fullName]' => 'Олена Коваленко',
            'registration[email]' => $email,
            'registration[phoneNumber]' => '+380671234567',
            'registration[birthDate]' => '2005-04-15',
            'registration[plainPassword][first]' => 'StudyPass123!',
            'registration[plainPassword][second]' => $confirmation,
        ]);
    }
}
