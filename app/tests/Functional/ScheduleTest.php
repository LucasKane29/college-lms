<?php

namespace App\Tests\Functional;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\Pair;
use App\Entity\Schedule;
use App\Entity\User;
use App\Security\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScheduleTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $groupId;
    private int $secondGroupId;
    private int $pairId;
    private int $disciplineId;
    private int $otherDisciplineId;
    private int $foreignDisciplineId;
    private int $headId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $manager = $this->manager();
        $params = $manager->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $params['driver']);
        self::assertStringContainsString('auth-test', $params['path']);
        $schema = new SchemaTool($manager);
        $schema->dropDatabase();
        $schema->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $head = $this->user('head@example.com', UserRole::HeadTeacher);
        $teacher = $this->user('teacher@example.com', UserRole::Teacher);
        $curator = $this->user('curator@example.com', UserRole::Curator);
        $first = (new Group())->setName('КН-21')->setCurator($curator);
        $second = (new Group())->setName('КН-22')->setCurator($curator);
        $pair = (new Pair())->setWeekendStart(new \DateTimeImmutable('1970-01-01 09:00'))->setWeekendEnd(new \DateTimeImmutable('1970-01-01 11:00'));
        $discipline = (new Discipline())->setName('Математика')->setTeacher($teacher)->addGroup($first)->addGroup($second);
        $other = (new Discipline())->setName('Алгебра')->setTeacher($teacher)->addGroup($first);
        $foreign = (new Discipline())->setName('Чужа дисципліна')->setTeacher($teacher)->addGroup($second);
        foreach ([$first, $second, $pair, $discipline, $other, $foreign] as $entity) $manager->persist($entity);
        $manager->flush();
        $this->groupId = $first->getId();
        $this->secondGroupId = $second->getId();
        $this->pairId = $pair->getId();
        $this->disciplineId = $discipline->getId();
        $this->otherDisciplineId = $other->getId();
        $this->foreignDisciplineId = $foreign->getId();
        $this->headId = $head->getId();
        $this->client->loginUser($head);
    }

    private function manager(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }

    private function user(string $email, UserRole $role): User
    {
        $user = (new User())->setEmail($email)->setFullName($email)->setRole($role)->setPassword('fixture-hash');
        $this->manager()->persist($user);
        $this->manager()->flush();
        return $user;
    }

    private function cell(?int $group = null): string { return '/schedule/cell/2026-10-24/'.($group ?? $this->groupId).'/'.$this->pairId; }

    private function createLesson(): void
    {
        $this->client->request('GET', $this->cell());
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->disciplineId]);
        self::assertResponseStatusCodeSame(303);
    }

    public function testHeadCanCreateEditAndDeleteThroughTableModal(): void
    {
        $query = '?from=2026-10-24&to=2026-10-30';
        $this->client->request('GET', '/account'.$query);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.schedule-cell');
        self::assertSelectorTextContains('thead', '09:00–11:00');
        $this->client->request('GET', $this->cell().$query, server: ['HTTP_TURBO_FRAME' => 'schedule-modal']);
        self::assertSelectorExists('turbo-frame#schedule-modal dialog');
        self::assertSelectorCount(3, 'select[name="schedule[discipline]"] option');
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->disciplineId]);
        self::assertResponseRedirects('/account'.$query, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.schedule-cell.filled', 'Математика');
        $this->client->request('GET', $this->cell().$query);
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->otherDisciplineId]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', $this->cell().$query);
        self::assertSelectorExists('button.schedule-delete');
        $this->client->submitForm('Видалити заняття');
        self::assertResponseStatusCodeSame(303);
        self::assertSame(0, $this->manager()->getRepository(Schedule::class)->count([]));
    }

    public function testTeacherCannotBeScheduledInTwoGroupsOnSamePair(): void
    {
        $this->createLesson();
        $this->client->request('GET', $this->cell($this->secondGroupId));
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->disciplineId]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Викладач вже має заняття');
        self::assertSame(1, $this->manager()->getRepository(Schedule::class)->count([]));
    }

    public function testForeignDisciplineAndInvalidCsrfAreRejected(): void
    {
        $this->client->request('GET', $this->cell());
        $token = $this->client->getCrawler()->filter('input[name="schedule[_token]"]')->attr('value');
        $this->client->request('POST', $this->cell(), ['schedule' => ['discipline' => $this->foreignDisciplineId, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', $this->cell(), ['schedule' => ['discipline' => $this->disciplineId, '_token' => 'bad']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->manager()->getRepository(Schedule::class)->count([]));
        $this->createLesson();
        $id = $this->manager()->getRepository(Schedule::class)->findOneBy([])->getId();
        $this->client->request('POST', '/schedule/'.$id.'/delete', ['_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
    }

    public static function forbiddenRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if (!in_array($role, [UserRole::HeadTeacher, UserRole::Admin], true)) yield [$role];
        }
    }

    #[DataProvider('forbiddenRoles')]
    public function testOnlyHeadAndAdminCanManageSchedule(UserRole $role): void
    {
        $this->client->loginUser($this->user('actor@example.com', $role));
        foreach ([$this->cell(), '/schedule/pairs', '/schedule/pairs/new', '/schedule/pairs/'.$this->pairId.'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', $url);
            self::assertContains($this->client->getResponse()->getStatusCode(), [403, 405]);
        }
        $this->client->request('POST', '/schedule/1/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStudentOnlySeesOwnGroupWithoutEditingControls(): void
    {
        $student = $this->user('student@example.com', UserRole::Student);
        $student->setStudentGroup($this->manager()->find(Group::class, $this->groupId));
        $this->manager()->flush();
        $this->client->loginUser($student);
        $this->client->request('GET', '/account?from=2026-10-24&to=2026-10-24');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'КН-21');
        self::assertSelectorTextNotContains('tbody', 'КН-22');
        self::assertSelectorNotExists('a.schedule-cell');
    }

    public function testAdminCanManagePairsAndSchedule(): void
    {
        $this->client->loginUser($this->user('admin@example.com', UserRole::Admin));
        $this->client->request('GET', '/schedule/pairs');
        self::assertResponseIsSuccessful();
        $this->createLesson();
    }

    public function testPairTimesCanBeEditedSeparatelyAndNewPairsAdded(): void
    {
        $this->client->request('GET', '/schedule/pairs/'.$this->pairId.'/edit');
        $this->client->submitForm('Зберегти пару', ['pair[weekdayStart]' => '08:00', 'pair[weekdayEnd]' => '10:00', 'pair[weekendStart]' => '09:30', 'pair[weekendEnd]' => '11:30']);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/schedule/pairs/new');
        $this->client->submitForm('Зберегти пару', ['pair[number]' => 2, 'pair[weekdayStart]' => '10:00', 'pair[weekdayEnd]' => '12:00', 'pair[weekendStart]' => '11:30', 'pair[weekendEnd]' => '13:30']);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorCount(2, 'tr[data-pair-id]');
        self::assertSelectorTextContains('tbody', '08:00–10:00');
        self::assertSelectorTextContains('tbody', '09:30–11:30');
        $this->client->request('GET', '/account?from=2026-10-26&to=2026-10-26');
        self::assertSelectorTextContains('thead', '08:00–10:00');
    }

    public function testOverlappingPairAndEndBeforeStartAreRejected(): void
    {
        $this->client->request('GET', '/schedule/pairs/new');
        $this->client->submitForm('Зберегти пару', ['pair[number]' => 2, 'pair[weekdayStart]' => '09:00', 'pair[weekdayEnd]' => '11:00', 'pair[weekendStart]' => '12:00', 'pair[weekendEnd]' => '14:00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Час перетинається');
        $this->client->request('GET', '/schedule/pairs/new');
        $this->client->submitForm('Зберегти пару', ['pair[number]' => 2, 'pair[weekdayStart]' => '12:00', 'pair[weekdayEnd]' => '11:00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->manager()->getRepository(Pair::class)->count([]));
    }

    public function testCalendarModesAndBadFilters(): void
    {
        foreach (['2026-10-26' => 'День', '2026-11-05' => 'Тиждень', '2026-12-05' => 'Місяць'] as $to => $label) {
            $this->client->request('GET', '/account', ['from' => '2026-10-20', 'to' => $to]);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.schedule-paging', $label);
        }
        self::assertSelectorExists('.schedule-month');
        self::assertSelectorCount(1, '.schedule-day');
        $this->client->request('GET', '/account?from=2026-02-30');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/account', ['from' => ['bad']]);
        self::assertResponseStatusCodeSame(400);
    }
}
