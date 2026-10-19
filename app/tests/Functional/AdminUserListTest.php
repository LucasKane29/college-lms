<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminUserListTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $parameters = $manager->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $parameters['driver']);
        self::assertStringContainsString('auth-test', $parameters['path']);
        $schema = new SchemaTool($manager);
        $schema->dropDatabase();
        $schema->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $admin = (new User())->setEmail('admin@example.com')->setFullName('Адміністратор')
            ->setRole(UserRole::Admin)->setPassword('fixture-admin-hash');
        $manager->persist($admin);
        $manager->flush();
        $this->client->loginUser($admin);
    }

    private function seedUsers(int $count = 25): int
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        for ($index = 1; $index <= $count; ++$index) {
            $connection->insert('users', [
                'email' => 1 === $index ? 'needle@example.test' : sprintf('person%03d@example.test', $index),
                'phone_number' => 1 === $index ? '+380671234567' : null,
                'birth_date' => 1 === $index ? '2005-04-15' : null,
                'full_name' => 1 === $index ? 'Олена Коваленко' : 'Тестовий користувач '.$index,
                'roles' => json_encode(['ROLE_USER', match ($index) {
                    1 => 'ROLE_STUDENT', 2 => 'ROLE_USER', default => 'ROLE_TEACHER',
                }], JSON_THROW_ON_ERROR),
                'password' => 3 === $index ? '' : 'fixture-user-hash',
                'created_at' => 1 === $index ? '2026-10-10 23:59:59' : '2026-10-11 00:00:00',
            ]);
        }

        return (int) $connection->fetchOne('SELECT id FROM users WHERE email = ?', ['needle@example.test']);
    }

    public static function pageSizes(): iterable
    {
        foreach ([10, 20, 50, 100] as $size) {
            yield (string) $size => [$size];
        }
    }

    #[DataProvider('pageSizes')]
    public function testEveryPageSizeLimitsResults(int $size): void
    {
        $this->seedUsers(105);
        $this->client->request('GET', '/admin/users', ['perPage' => $size]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount($size, 'tbody tr[data-user-id]');
        self::assertSelectorCount(4, 'select[name="perPage"] option');
        self::assertSelectorCount(3, 'div[data-controller="datepicker"]');
        self::assertSelectorNotExists('input[data-controller="datepicker"]');
        self::assertSelectorTextContains('.admin-summary', 'Знайдено: 106');
        self::assertSelectorExists('select[name="perPage"] option[value="'.$size.'"][selected]');
        self::assertStringNotContainsString('fixture-user-hash', $this->client->getResponse()->getContent());
    }

    public function testPaginationKeepsFiltersAndUsesCorrectOffsets(): void
    {
        $this->seedUsers();
        $crawler = $this->client->request('GET', '/admin/users', [
            'email' => '@example.test', 'perPage' => 10, 'page' => 2,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(10, 'tbody tr[data-user-id]');
        self::assertSelectorTextContains('.admin-summary', 'Записи 11–20 з 25');
        self::assertSelectorTextContains('tbody tr:first-child', 'person011@example.test');
        $next = $crawler->filter('a[rel="next"]')->link();
        parse_str(parse_url($next->getUri(), PHP_URL_QUERY), $parameters);
        self::assertSame('@example.test', $parameters['email']);
        self::assertSame('10', $parameters['perPage']);
        self::assertSame('3', $parameters['page']);
        $this->client->click($next);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(5, 'tbody tr[data-user-id]');
        self::assertSelectorNotExists('a[rel="next"]');
    }

    public static function filters(): iterable
    {
        yield 'name fragment' => [['fullName' => 'Коваленко'], 1, 'needle@example.test'];
        yield 'email fragment' => [['email' => 'needle@'], 1, 'needle@example.test'];
        yield 'phone fragment' => [['phoneNumber' => '671234'], 1, 'needle@example.test'];
        yield 'exact birth date' => [['birthDate' => '2005-04-15'], 1, 'needle@example.test'];
        yield 'student role' => [['role' => 'ROLE_STUDENT'], 1, 'needle@example.test'];
        yield 'basic assigned role' => [['role' => 'ROLE_USER'], 1, 'person002@example.test'];
        yield 'empty password' => [['password' => 'empty'], 1, 'person003@example.test'];
        yield 'password set' => [['password' => 'set', 'email' => '@example.test', 'perPage' => 100], 24, 'needle@example.test'];
        yield 'inclusive end of day' => [['createdAtFrom' => '2026-10-10', 'createdAtTo' => '2026-10-10'], 1, 'needle@example.test'];
        yield 'lower date boundary' => [['createdAtFrom' => '2026-10-11', 'email' => '@example.test', 'perPage' => 100], 24, 'person002@example.test'];
        yield 'no results' => [['email' => 'missing@'], 0, null];
        yield 'literal wildcard' => [['email' => '%'], 0, null];
        yield 'SQL-like search text' => [['email' => "' OR 1=1 --"], 0, null];
    }

    #[DataProvider('filters')]
    public function testFilters(array $filters, int $expected, ?string $email): void
    {
        $this->seedUsers();
        $this->client->request('GET', '/admin/users', $filters);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount($expected, 'tbody tr[data-user-id]');
        self::assertSelectorTextContains('.admin-summary', 'Знайдено: '.$expected.' ·');
        if (null !== $email) {
            self::assertSelectorTextContains('tbody', $email);
        } else {
            self::assertSelectorTextContains('tbody', 'Користувачів за обраними фільтрами не знайдено.');
        }
    }

    public function testAllFiltersCanBeCombinedAndIdIsExact(): void
    {
        $id = $this->seedUsers();
        $filters = [
            'id' => $id, 'email' => 'needle', 'fullName' => 'Коваленко', 'role' => 'ROLE_STUDENT',
            'password' => 'set', 'createdAtFrom' => '2026-10-10', 'createdAtTo' => '2026-10-10', 'perPage' => 10,
        ];
        $this->client->request('GET', '/admin/users', $filters);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tbody tr[data-user-id]');
        self::assertSelectorExists('tr[data-user-id="'.$id.'"]');

        $this->client->request('GET', '/admin/users', array_replace($filters, ['id' => $id + 1]));
        self::assertSelectorCount(0, 'tbody tr[data-user-id]');
    }

    public static function invalidFilters(): iterable
    {
        yield 'unsupported page size' => [['perPage' => 15]];
        yield 'negative id' => [['id' => -1]];
        yield 'non-numeric id' => [['id' => 'invalid']];
        yield 'unknown role' => [['role' => 'ROLE_SUPER_ADMIN']];
        yield 'unknown password state' => [['password' => 'invalid']];
        yield 'invalid date' => [['createdAtFrom' => '2026-02-30']];
        yield 'invalid birth date' => [['birthDate' => '2005-02-30']];
        yield 'reversed dates' => [['createdAtFrom' => '2026-10-12', 'createdAtTo' => '2026-10-10']];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersReturn422(array $filters): void
    {
        $this->seedUsers();
        $this->client->request('GET', '/admin/users', $filters);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('table');
    }

    public function testDefaultPageSizeAndOutOfRangePages(): void
    {
        $this->seedUsers();
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(20, 'tbody tr[data-user-id]');
        $this->client->request('GET', '/admin/users', ['page' => 999, 'perPage' => 10]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(6, 'tbody tr[data-user-id]');
        self::assertSelectorTextContains('.admin-pagination', 'Сторінка 3 з 3');
        $this->client->request('GET', '/admin/users', ['page' => -1, 'perPage' => 10]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-pagination', 'Сторінка 1 з 3');
    }
}
