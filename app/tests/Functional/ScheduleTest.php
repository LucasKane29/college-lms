<?php

namespace App\Tests\Functional;

use App\Entity\Discipline;
use App\Entity\Group;
use App\Entity\Pair;
use App\Entity\Schedule;
use App\Entity\User;
use App\Entity\LessonTopic;
use App\Security\UserRole;
use App\Service\ScheduleManager;
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
        $discipline = (new Discipline())->setName('Математика')->addTeacher($teacher)->addGroup($first)->addGroup($second);
        $other = (new Discipline())->setName('Алгебра')->addTeacher($teacher)->addGroup($first);
        $foreign = (new Discipline())->setName('Чужа дисципліна')->addTeacher($teacher)->addGroup($second);
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

    public function testEditorDefaultsToWeekAndFiltersArePreservedWhileEditing(): void
    {
        $this->client->request('GET', '/schedule');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Розклад');
        self::assertSelectorCount(7, '[data-ui~="schedule-day"]');
        self::assertSelectorExists('[data-ui~="schedule-paging"] a[rel="prev"]');
        self::assertSelectorExists('[data-ui~="schedule-paging"] a[rel="next"]');

        $query = '?from=2026-10-19&to=2026-11-01&group='.$this->groupId.'&discipline='.$this->disciplineId.'&view=schedule';
        $this->client->request('GET', '/schedule'.$query);
        self::assertSelectorCount(7, '[data-ui~="schedule-day"]');
        self::assertSelectorCount(7, '[data-ui~="schedule-table"] tbody tr');
        self::assertSelectorNotExists('[data-ui~="schedule-paging"] a[rel="prev"]');
        $this->client->clickLink('Наступний тиждень →');
        self::assertSelectorTextContains('[data-ui~="schedule-paging"]', '26.10.2026 — 01.11.2026');
        self::assertSelectorExists('select[name="group"] option[value="'.$this->groupId.'"][selected]');
        self::assertSelectorExists('select[name="discipline"] option[value="'.$this->disciplineId.'"][selected]');

        $this->client->request('GET', $this->cell().$query);
        self::assertSelectorExists('select[name="schedule[discipline]"] option[value="'.$this->disciplineId.'"][selected]');
        $this->client->submitForm('Зберегти заняття');
        self::assertResponseRedirects('/schedule'.$query, 303);
        $this->client->followRedirect();
        self::assertSelectorCount(1, '[data-ui~="schedule-cell"][data-ui~="filled"]');
        self::assertSelectorTextContains('[data-ui~="schedule-cell"][data-ui~="filled"]', 'Математика');
        $this->client->request('GET', $this->cell().$query);
        $this->client->submitForm('Видалити заняття');
        self::assertResponseRedirects('/schedule'.$query, 303);
    }

    public function testDisciplineFilterDoesNotExposeOccupiedSlotsAsFree(): void
    {
        $this->createLesson();
        $this->client->request('GET', '/schedule?from=2026-10-24&to=2026-10-24&discipline='.$this->otherDisciplineId.'&group='.$this->groupId);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-ui~="schedule-occupied"]');
        self::assertSelectorNotExists('a[data-ui~="schedule-cell"]');
        self::assertSelectorTextNotContains('[data-ui~="schedule-table"]', 'Математика');
    }

    public function testEditorPermissionsAndInvalidFilters(): void
    {
        foreach (['from[]=2026-10-01', 'group[]=1', 'discipline=-1', 'group=abc', 'from=2026-02-30', 'from=2026-10-25&to=2026-10-24'] as $query) {
            $this->client->request('GET', '/schedule?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/schedule?group=999999');
        self::assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->user('editor-admin@example.com', UserRole::Admin));
        $this->client->request('GET', '/schedule');
        self::assertResponseIsSuccessful();
        foreach ([UserRole::Student, UserRole::Curator, UserRole::User] as $role) {
            $this->client->loginUser($this->user('editor-'.$role->name.'@example.com', $role));
            $this->client->request('GET', '/schedule');
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testLessonTopicMustBelongToSelectedDiscipline(): void
    {
        $topic = (new LessonTopic())->setName('Похідна')->setDiscipline($this->manager()->find(Discipline::class, $this->disciplineId));
        $other = (new LessonTopic())->setName('Рівняння')->setDiscipline($this->manager()->find(Discipline::class, $this->otherDisciplineId));
        $this->manager()->persist($topic);
        $this->manager()->persist($other);
        $this->manager()->flush();
        $topicId = $topic->getId();
        $otherId = $other->getId();
        $this->createLesson();
        $lessonId = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId])->getId();
        $this->client->loginUser($this->manager()->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com']));
        $this->client->request('GET', '/schedule/'.$lessonId.'/topic');
        self::assertSelectorNotExists('select[name="schedule[discipline]"]');
        self::assertSelectorNotExists('button[data-ui~="schedule-delete"]');
        self::assertSelectorNotExists('select[name="schedule_topic[topic]"] option[value="'.$otherId.'"]');
        $token = $this->client->getCrawler()->filter('input[name="schedule_topic[_token]"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$lessonId.'/topic', ['schedule_topic' => ['topic' => $otherId, '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Зберегти заняття', ['schedule_topic[topic]' => $topicId]);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        $saved = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId]);
        self::assertSame($topicId, $saved->getTopic()->getId());
        self::assertSame($this->disciplineId, $saved->getDiscipline()->getId());
        self::assertSame($this->pairId, $saved->getPair()->getId());
        self::assertSame('2026-10-24', $saved->getLessonDate()->format('Y-m-d'));
        $this->client->request('GET', '/teacher/disciplines/'.$this->disciplineId);
        self::assertSelectorTextContains('tr[data-lesson-id]', 'Похідна');
        foreach ([UserRole::Teacher, UserRole::HeadTeacher, UserRole::Director, UserRole::Admin] as $role) {
            $actor = UserRole::Teacher === $role
                ? $this->manager()->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com'])
                : $this->user('viewer-'.$role->name.'@example.com', $role);
            $this->client->loginUser($actor);
            foreach (['/schedule', '/account'] as $url) {
                $this->client->request('GET', $url.'?from=2026-10-24&to=2026-10-24');
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('[data-ui~="schedule-cell"][data-ui~="filled"]', 'Похідна');
            }
        }
    }

    public function testOnlyOwningTeacherCanAssignTopicAndCannotChangeLessonFields(): void
    {
        $this->createLesson();
        $id = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId])->getId();
        foreach ([UserRole::HeadTeacher, UserRole::Admin, UserRole::Director, UserRole::Student, UserRole::Curator, UserRole::User, UserRole::Teacher] as $role) {
            $this->client->loginUser($this->user('forbidden-'.$role->name.'@example.com', $role));
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, '/schedule/'.$id.'/topic');
                self::assertResponseStatusCodeSame(403);
            }
        }
        $teacher = $this->manager()->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com']);
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/schedule/'.$id.'/topic');
        $token = $this->client->getCrawler()->filter('input[name="schedule_topic[_token]"]')->attr('value');
        $this->client->request('POST', '/schedule/'.$id.'/topic', ['schedule_topic' => [
            'topic' => '', '_token' => $token, 'discipline' => $this->otherDisciplineId, 'group' => $this->secondGroupId,
            'pair' => 999, 'lessonDate' => '2027-01-01',
        ]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/schedule/'.$id.'/topic', ['schedule_topic' => ['topic' => '', '_token' => 'invalid']]);
        self::assertResponseStatusCodeSame(422);
        $this->manager()->clear();
        $saved = $this->manager()->find(Schedule::class, $id);
        self::assertNull($saved->getTopic());
        self::assertSame($this->disciplineId, $saved->getDiscipline()->getId());
        self::assertSame($this->groupId, $saved->getGroup()->getId());
        self::assertSame($this->pairId, $saved->getPair()->getId());
        $this->client->request('POST', $this->cell());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/schedule/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffCannotSubmitTopicsAndDisciplineChangeClearsOldTopic(): void
    {
        $this->createLesson();
        $lesson = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId]);
        $id = $lesson->getId();
        $topic = (new LessonTopic())->setName('Обрана викладачем')->setDiscipline($lesson->getDiscipline());
        $lesson->setTopic($topic);
        $this->manager()->persist($topic);
        $this->manager()->flush();
        $topicId = $topic->getId();
        foreach ([UserRole::HeadTeacher, UserRole::Admin] as $role) {
            $this->client->loginUser($this->user('staff-'.$role->name.'@example.com', $role));
            $this->client->request('GET', $this->cell());
            self::assertSelectorNotExists('select[name="schedule[topic]"]');
            $token = $this->client->getCrawler()->filter('input[name="schedule[_token]"]')->attr('value');
            $this->client->request('POST', $this->cell(), ['schedule' => ['discipline' => $this->disciplineId, 'topic' => '', '_token' => $token]]);
            self::assertResponseStatusCodeSame(422);
            $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->disciplineId]);
            self::assertResponseStatusCodeSame(303);
            $this->manager()->clear();
            self::assertSame($topicId, $this->manager()->find(Schedule::class, $id)->getTopic()->getId());
        }
        $this->client->request('GET', $this->cell());
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->otherDisciplineId]);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        self::assertNull($this->manager()->find(Schedule::class, $id)->getTopic());
        self::assertSame($this->otherDisciplineId, $this->manager()->find(Schedule::class, $id)->getDiscipline()->getId());
    }

    public function testTeacherCalendarIsScopedAndDirectorIsReadOnly(): void
    {
        $this->createLesson();
        $id = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId])->getId();
        $teacher = $this->manager()->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com']);
        $other = $this->user('different-teacher@example.com', UserRole::Teacher);
        $manager = $this->manager();
        $curator = $manager->getRepository(User::class)->findOneBy(['email' => 'curator@example.com']);
        $group = (new Group())->setName('Чужа група')->setCurator($curator);
        $discipline = (new Discipline())->setName('Стороння дисципліна')->addTeacher($other)->addGroup($group);
        $lesson = (new Schedule())->setDiscipline($discipline)->setGroup($group)->setPair($manager->find(Pair::class, $this->pairId))->setLessonDate(new \DateTimeImmutable('2026-10-24'));
        foreach ([$group, $discipline, $lesson] as $entity) $manager->persist($entity);
        $manager->flush();
        $foreignGroup = $group->getId();
        $foreignDiscipline = $discipline->getId();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/schedule?from=2026-10-24&to=2026-10-24');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Чужа група');
        self::assertSelectorTextNotContains('main', 'Стороння дисципліна');
        self::assertSelectorExists('a[data-turbo-frame="schedule-modal"][href*="/schedule/'.$id.'/topic"]');
        self::assertSelectorNotExists('a[href*="/schedule/cell/"]');
        self::assertSelectorNotExists('a[href*="/schedule/pairs"]');
        foreach (['group='.$foreignGroup, 'discipline='.$foreignDiscipline] as $filter) {
            $this->client->request('GET', '/schedule?'.$filter);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->loginUser($this->user('director-calendar@example.com', UserRole::Director));
        $this->client->request('GET', '/schedule?from=2026-10-24&to=2026-10-24');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Стороння дисципліна');
        self::assertSelectorNotExists('a[data-ui~="schedule-cell"]');
        self::assertSelectorNotExists('a[href*="/schedule/pairs"]');
    }

    public function testTopicModalSavesAndCanClearTopic(): void
    {
        $this->createLesson();
        $lesson = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId]);
        $id = $lesson->getId();
        $topic = (new LessonTopic())->setName('Тема в модальному вікні')->setDiscipline($lesson->getDiscipline());
        $this->manager()->persist($topic);
        $this->manager()->flush();
        $topicId = $topic->getId();
        $this->client->loginUser($this->manager()->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com']));
        $this->client->setServerParameter('HTTP_TURBO_FRAME', 'schedule-modal');
        $this->client->request('GET', '/schedule/'.$id.'/topic?from=2026-10-24&to=2026-10-24&view=schedule');
        self::assertSelectorExists('turbo-frame#schedule-modal dialog[aria-labelledby="topic-title"]');
        self::assertSelectorExists('form[data-turbo-frame="schedule-modal"]');
        $this->client->submitForm('Зберегти заняття', ['schedule_topic[topic]' => $topicId]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[action="refresh"]');
        $this->client->request('GET', '/schedule/'.$id.'/topic');
        self::assertSelectorExists('option[value="'.$topicId.'"][selected]');
        $this->client->submitForm('Зберегти заняття', ['schedule_topic[topic]' => '']);
        self::assertResponseIsSuccessful();
        $this->manager()->clear();
        self::assertNull($this->manager()->find(Schedule::class, $id)->getTopic());
    }

    public function testStaleTopicFormCannotAssignAfterDisciplineChanges(): void
    {
        $this->createLesson();
        $manager = $this->manager();
        $lesson = $manager->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId]);
        $topic = (new LessonTopic())->setName('Попередня дисципліна')->setDiscipline($lesson->getDiscipline());
        $manager->persist($topic);
        $manager->flush();
        $teacher = $manager->getRepository(User::class)->findOneBy(['email' => 'teacher@example.com']);
        $manager->getConnection()->executeStatement('UPDATE schedule SET discipline_id = ? WHERE id = ?', [$this->otherDisciplineId, $lesson->getId()]);
        self::assertFalse(static::getContainer()->get(ScheduleManager::class)->assignTopic($lesson, $topic, $teacher));
        self::assertNull($manager->getConnection()->fetchOne('SELECT topic_id FROM schedule WHERE id = ?', [$lesson->getId()]));
    }

    public function testHeadCanCreateEditAndDeleteThroughTableModal(): void
    {
        $query = '?from=2026-10-24&to=2026-10-30';
        $this->client->request('GET', '/account'.$query);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-ui~="schedule-cell"]');
        self::assertSelectorTextContains('thead', '09:00–11:00');
        $this->client->request('GET', $this->cell().$query, server: ['HTTP_TURBO_FRAME' => 'schedule-modal']);
        self::assertSelectorExists('turbo-frame#schedule-modal dialog');
        self::assertSelectorCount(3, 'select[name="schedule[discipline]"] option');
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->disciplineId]);
        self::assertResponseRedirects('/account'.$query, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-ui~="schedule-cell"][data-ui~="filled"]', 'Математика');
        $this->client->request('GET', $this->cell().$query);
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $this->otherDisciplineId]);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', $this->cell().$query);
        self::assertSelectorExists('button[data-ui~="schedule-delete"]');
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
        self::assertSelectorNotExists('a[data-ui~="schedule-cell"]');
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

    public function testPairModalCanBeOpenedFromCalendarAndSaved(): void
    {
        $query = '?from=2026-10-19&to=2026-10-25&group='.$this->groupId;
        $this->client->request('GET', '/schedule'.$query);
        self::assertSelectorExists('thead a[data-turbo-frame="schedule-modal"][aria-label="Редагувати пару 1"]');
        $url = $this->client->getCrawler()->filter('thead a[aria-label="Редагувати пару 1"]')->first()->attr('href');
        $this->client->setServerParameter('HTTP_TURBO_FRAME', 'schedule-modal');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#schedule-modal dialog[aria-labelledby="pair-title"]');
        self::assertSelectorExists('form[data-turbo-frame="schedule-modal"]');
        self::assertSelectorExists('a[aria-label="Закрити"][href="/schedule?from=2026-10-19&to=2026-10-25&cursor=2026-10-19&group='.$this->groupId.'&view=schedule"]');
        $this->client->submitForm('Зберегти пару', ['pair[weekdayStart]' => '08:00', 'pair[weekdayEnd]' => '10:00']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertSelectorExists('turbo-stream[action="update"][target="schedule-modal"]');
        self::assertSelectorExists('turbo-stream[action="refresh"]');
        $this->client->setServerParameter('HTTP_TURBO_FRAME', '');
        $this->client->request('GET', '/schedule'.$query);
        self::assertSelectorTextContains('thead', '08:00–10:00');
    }

    public function testPairModalKeepsValidationErrorsAndSupportsCreation(): void
    {
        $this->client->request('GET', '/schedule/pairs');
        self::assertSelectorExists('turbo-frame#schedule-modal');
        self::assertSelectorExists('a[data-turbo-frame="schedule-modal"][href="/schedule/pairs/'.$this->pairId.'/edit"]');
        $this->client->setServerParameter('HTTP_TURBO_FRAME', 'schedule-modal');
        $this->client->request('GET', '/schedule/pairs/new');
        $this->client->submitForm('Зберегти пару', ['pair[number]' => 2, 'pair[weekdayStart]' => '09:00', 'pair[weekdayEnd]' => '11:00']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#schedule-modal dialog');
        self::assertSelectorTextContains('[role="alert"]', 'Час перетинається');
        self::assertSame(1, $this->manager()->getRepository(Pair::class)->count([]));
        $this->client->submitForm('Зберегти пару', ['pair[weekdayStart]' => '11:00', 'pair[weekdayEnd]' => '13:00', 'pair[weekendStart]' => '11:00', 'pair[weekendEnd]' => '13:00']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-stream[action="refresh"]');
        self::assertSame(2, $this->manager()->getRepository(Pair::class)->count([]));
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
            self::assertSelectorTextContains('[data-ui~="schedule-paging"]', $label);
        }
        self::assertSelectorExists('[data-ui~="schedule-month"]');
        self::assertSelectorCount(1, '[data-ui~="schedule-day"]');
        $this->client->request('GET', '/account?from=2026-02-30');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/account', ['from' => ['bad']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAdditionalTeacherSeesScheduleAndCanAssignTopicsUntilRemoved(): void
    {
        $second = $this->user('co-teacher@example.com', UserRole::Teacher);
        $secondId = $second->getId();
        $discipline = $this->manager()->find(Discipline::class, $this->disciplineId);
        $discipline->addTeacher($second);
        $topic = (new LessonTopic())->setName('Спільна тема')->setDiscipline($discipline);
        $this->manager()->persist($topic);
        $this->manager()->flush();
        $topicId = $topic->getId();
        $this->createLesson();
        $id = $this->manager()->getRepository(Schedule::class)->findOneBy(['group' => $this->groupId])->getId();
        $this->client->loginUser($this->manager()->find(User::class, $secondId));
        $this->client->request('GET', '/schedule?from=2026-10-24&to=2026-10-24&discipline='.$this->disciplineId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-ui~="schedule-cell"][data-ui~="filled"]', 'Математика');
        self::assertSelectorTextContains('[data-ui~="schedule-cell"][data-ui~="filled"]', 'co-teacher@example.com');
        $this->client->request('GET', '/schedule/'.$id.'/topic');
        $this->client->submitForm('Зберегти заняття', ['schedule_topic[topic]' => $topicId]);
        self::assertResponseStatusCodeSame(303);
        $this->manager()->clear();
        self::assertSame($topicId, $this->manager()->find(Schedule::class, $id)->getTopic()->getId());
        $discipline = $this->manager()->find(Discipline::class, $this->disciplineId);
        $discipline->removeTeacher($this->manager()->find(User::class, $secondId));
        $this->manager()->flush();
        $this->client->request('GET', '/schedule/'.$id.'/topic');
        self::assertResponseStatusCodeSame(403);
    }

    public function testConflictsIncludeEveryAssignedTeacher(): void
    {
        $second = $this->user('co-teacher@example.com', UserRole::Teacher);
        $discipline = $this->manager()->find(Discipline::class, $this->disciplineId);
        $discipline->addTeacher($second);
        $other = (new Discipline())->setName('Предмет другого викладача')->addTeacher($second)
            ->addGroup($this->manager()->find(Group::class, $this->secondGroupId));
        $this->manager()->persist($other);
        $this->manager()->flush();
        $otherId = $other->getId();
        $this->createLesson();
        $this->client->request('GET', $this->cell($this->secondGroupId));
        $this->client->submitForm('Зберегти заняття', ['schedule[discipline]' => $otherId]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'Викладач вже має заняття');
    }
}
