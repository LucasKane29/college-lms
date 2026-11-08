<?php

namespace App\Tests\Functional;

use App\Entity\{User, Group, Discipline, LessonTopic, Schedule, Pair, LessonAttachment, LessonQuiz, QuizQuestion, QuizAnswer, QuizAttempt};
use App\Security\UserRole;
use App\Service\LessonFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class LessonClassroomTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $actors = [];
    private int $lessonId;
    private int $foreignLessonId;
    private int $unassignedId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $manager = $this->manager(); $params = $manager->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $params['driver']); self::assertStringContainsString('auth-test', $params['path']);
        $schema = new SchemaTool($manager); $schema->dropDatabase(); $schema->createSchema($manager->getMetadataFactory()->getAllMetadata());
        foreach (['teacher' => UserRole::Teacher, 'co' => UserRole::Teacher, 'foreign' => UserRole::Teacher, 'head' => UserRole::HeadTeacher, 'admin' => UserRole::Admin, 'student' => UserRole::Student, 'peer' => UserRole::Student, 'outsider' => UserRole::Student, 'curator' => UserRole::Curator, 'director' => UserRole::Director] as $name => $role) {
            $user = (new User())->setEmail($name.'@example.test')->setFullName($name)->setRole($role)->setPassword('fixture');
            $manager->persist($user); $this->actors[$name] = $user;
        }
        $group = (new Group())->setName('КН-21')->setCurator($this->actors['curator']);
        $other = (new Group())->setName('КН-22')->setCurator($this->actors['curator']);
        $discipline = (new Discipline())->setName('Математика')->addTeacher($this->actors['teacher'])->addTeacher($this->actors['co'])->addGroup($group);
        $foreignDiscipline = (new Discipline())->setName('Фізика')->addTeacher($this->actors['foreign'])->addGroup($other);
        $lesson = (new LessonTopic())->setName('Похідна функції')->setDiscipline($discipline)->setDescription('Опис для студентів');
        $foreignLesson = (new LessonTopic())->setName('Чуже заняття')->setDiscipline($foreignDiscipline);
        $unassigned = (new LessonTopic())->setName('Ще не призначено')->setDiscipline($discipline);
        $pair = new Pair();
        foreach ([$group, $other, $discipline, $foreignDiscipline, $lesson, $foreignLesson, $unassigned, $pair] as $entity) $manager->persist($entity);
        $this->actors['student']->setStudentGroup($group); $this->actors['peer']->setStudentGroup($group); $this->actors['outsider']->setStudentGroup($other);
        $manager->persist((new Schedule())->setLessonDate(new \DateTimeImmutable('2026-11-09'))->setGroup($group)->setPair($pair)->setDiscipline($discipline)->setTopic($lesson));
        $manager->persist((new Schedule())->setLessonDate(new \DateTimeImmutable('2026-11-09'))->setGroup($other)->setPair($pair)->setDiscipline($foreignDiscipline)->setTopic($foreignLesson));
        $manager->flush();
        foreach ($this->actors as $name => $user) $this->actors[$name] = $user->getId();
        $this->lessonId = $lesson->getId(); $this->foreignLessonId = $foreignLesson->getId(); $this->unassignedId = $unassigned->getId();
    }

    private function manager(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function login(string $name): void { $this->client->loginUser($this->manager()->find(User::class, $this->actors[$name])); }
    private function lesson(): LessonTopic { return $this->manager()->find(LessonTopic::class, $this->lessonId); }

    private function quiz(bool $published = true): LessonQuiz
    {
        $quiz = (new LessonQuiz())->setLesson($this->lesson())->setTitle('Перевірка знань')->setPublished($published);
        $single = (new QuizQuestion())->setText('Оберіть одну')->setPoints(2)
            ->addAnswer((new QuizAnswer())->setText('Варіант А')->setCorrect(true))->addAnswer((new QuizAnswer())->setText('Варіант Б'));
        $multi = (new QuizQuestion())->setText('Оберіть декілька')->setKind('multiple')->setPoints(3)->setPosition(1)
            ->addAnswer((new QuizAnswer())->setText('Варіант В')->setCorrect(true))->addAnswer((new QuizAnswer())->setText('Варіант Г')->setCorrect(true))->addAnswer((new QuizAnswer())->setText('Варіант Д'));
        $quiz->addQuestion($single)->addQuestion($multi); $this->manager()->persist($quiz); $this->manager()->flush();
        return $quiz;
    }

    public static function lessonRoles(): iterable
    {
        yield ['teacher', 200, 200]; yield ['co', 200, 200]; yield ['admin', 200, 200];
        yield ['head', 200, 403]; yield ['student', 200, 403]; yield ['foreign', 403, 403];
        yield ['outsider', 403, 403]; yield ['curator', 403, 403]; yield ['director', 403, 403];
    }

    #[DataProvider('lessonRoles')]
    public function testLessonAccessIsEnforced(string $actor, int $view, int $edit): void
    {
        $this->login($actor);
        $this->client->request('GET', '/lessons/'.$this->lessonId); self::assertResponseStatusCodeSame($view);
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/edit'); self::assertResponseStatusCodeSame($edit);
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new'); self::assertResponseStatusCodeSame($edit);
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/tests/new'); self::assertResponseStatusCodeSame($edit);
        $this->client->request('POST', '/lessons/'.$this->lessonId.'/edit', ['lesson_content' => ['name' => 'Зміна']]);
        self::assertResponseStatusCodeSame($edit === 200 ? 422 : 403);
    }

    public function testStudentListIncludesOnlyAssignedLessonsAndFilesAreNotPublic(): void
    {
        $this->login('student'); $this->client->request('GET', '/lessons'); self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'article[data-lesson-id]'); self::assertSelectorTextContains('main', 'Похідна функції');
        self::assertSelectorTextNotContains('main', 'Чуже заняття'); self::assertSelectorTextNotContains('main', 'Ще не призначено');
        $this->client->request('GET', '/lessons/'.$this->unassignedId); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/lessons/'.$this->foreignLessonId); self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherCanCreateNotesLinksEditDescriptionAndAdminCanEdit(): void
    {
        $this->login('teacher');
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/edit');
        $this->client->submitForm('Зберегти заняття', ['lesson_content[description]' => 'Новий опис']); self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new');
        $this->client->submitForm('Зберегти матеріал', ['lesson_attachment[title]' => 'Конспект', 'lesson_attachment[kind]' => 'note', 'lesson_attachment[content]' => '<script>alert(1)</script>']);
        self::assertResponseStatusCodeSame(303); $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Новий опис'); self::assertSelectorTextContains('article', '<script>alert(1)</script>');
        self::assertStringNotContainsString('<script>alert(1)</script>', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new');
        $this->client->submitForm('Зберегти матеріал', ['lesson_attachment[title]' => 'Джерело', 'lesson_attachment[kind]' => 'link', 'lesson_attachment[url]' => 'https://example.org/lecture']);
        self::assertResponseStatusCodeSame(303); $this->client->followRedirect(); self::assertSelectorExists('a[href="https://example.org/lecture"][rel="noopener noreferrer"]');
        $this->login('admin'); $this->client->request('GET', '/lessons/'.$this->lessonId.'/edit');
        $this->client->submitForm('Зберегти заняття', ['lesson_content[name]' => 'Оновив адміністратор']); self::assertResponseStatusCodeSame(303);
        $this->login('head'); $this->client->request('GET', '/lessons/'.$this->lessonId); self::assertSelectorNotExists('a[href$="/materials/new"]');
    }

    public function testInvalidLinksMissingFilesAndForgedCsrfAreRejected(): void
    {
        $this->login('teacher');
        foreach ([['link', 'javascript:alert(1)'], ['file', ''], ['note', '']] as [$kind, $url]) {
            $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new');
            $this->client->submitForm('Зберегти матеріал', ['lesson_attachment[title]' => 'Невалідний', 'lesson_attachment[kind]' => $kind, 'lesson_attachment[url]' => $url]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->request('POST', '/lessons/'.$this->lessonId.'/materials/new', ['lesson_attachment' => ['title' => 'Без CSRF', 'kind' => 'note', 'content' => 'Текст', '_token' => 'bad']]);
        self::assertResponseStatusCodeSame(422); self::assertSame(0, $this->manager()->getRepository(LessonAttachment::class)->count([]));
    }

    public function testFileUploadDownloadPermissionsAndRemoval(): void
    {
        $this->login('teacher'); $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new');
        $token = $this->client->getCrawler()->filter('input[name="lesson_attachment[_token]"]')->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'classroom-'); file_put_contents($path, 'Lecture notes for classroom.');
        $this->client->request('POST', '/lessons/'.$this->lessonId.'/materials/new', ['lesson_attachment' => ['title' => 'Документ', 'kind' => 'file', '_token' => $token]], ['lesson_attachment' => ['upload' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true)]]);
        self::assertResponseStatusCodeSame(303);
        $material = $this->manager()->getRepository(LessonAttachment::class)->findOneBy(['title' => 'Документ']);
        $id = $material->getId(); $storedPath = static::getContainer()->get(LessonFileStorage::class)->path($material->getStorageName()); self::assertFileExists($storedPath);
        $this->login('student'); $this->client->request('GET', '/lesson-materials/'.$id.'/download'); self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream'); self::assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->login('outsider'); $this->client->request('GET', '/lesson-materials/'.$id.'/download'); self::assertResponseStatusCodeSame(403);
        $this->login('teacher'); $this->client->request('POST', '/lesson-materials/'.$id.'/delete', ['_token' => 'invalid']); self::assertResponseStatusCodeSame(403); self::assertFileExists($storedPath);
        $this->client->request('GET', '/lessons/'.$this->lessonId); $this->client->submitForm('Видалити матеріал'); self::assertResponseStatusCodeSame(303); self::assertFileDoesNotExist($storedPath);
    }

    public function testTeacherQuizEditorCreatesNestedQuestionsAndValidatesCorrectAnswers(): void
    {
        $this->login('teacher'); $this->client->request('GET', '/lessons/'.$this->lessonId.'/tests/new'); self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-form-collection-placeholder-value="__question__"]');
        $token = $this->client->getCrawler()->filter('input[name="lesson_quiz[_token]"]')->attr('value');
        $data = ['title' => 'Створений через редактор', 'maxAttempts' => 2, 'published' => '1', '_token' => $token, 'questions' => [
            ['text' => 'Скільки буде 2+2?', 'kind' => 'single', 'points' => 3, 'answers' => [['text' => '4', 'correct' => '1'], ['text' => '5']]],
        ]];
        $this->client->request('POST', '/lessons/'.$this->lessonId.'/tests/new', ['lesson_quiz' => $data]); self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect(); self::assertSelectorTextContains('main', 'Скільки буде 2+2?');
        $quiz = $this->manager()->getRepository(LessonQuiz::class)->findOneBy(['title' => 'Створений через редактор']); self::assertCount(1, $quiz->getQuestions());
        $id = $quiz->getId(); $this->client->request('GET', '/lesson-tests/'.$id.'/edit');
        $this->client->submitForm('Зберегти тест', ['lesson_quiz[title]' => 'Оновив викладач']); self::assertResponseStatusCodeSame(303);
        $this->client->request('GET', '/lesson-tests/'.$id.'/edit');
        $data['_token'] = $this->client->getCrawler()->filter('input[name="lesson_quiz[_token]"]')->attr('value');
        $data['revision'] = $this->client->getCrawler()->filter('input[name="lesson_quiz[revision]"]')->attr('value');
        unset($data['questions'][0]['answers'][0]['correct']);
        $this->client->request('POST', '/lesson-tests/'.$id.'/edit', ['lesson_quiz' => $data]); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'рівно одну правильну відповідь');
        $this->login('head'); $this->client->request('GET', '/lesson-tests/'.$id.'/edit'); self::assertResponseStatusCodeSame(403);
    }

    public function testStudentPassesSingleAndMultipleChoiceWithoutLeakedAnswers(): void
    {
        $quiz = $this->quiz(); $id = $quiz->getId(); $first = $quiz->getQuestions()->first(); $second = $quiz->getQuestions()->last();
        $values = ['q_'.$first->getId() => (string) $first->getAnswers()->first()->getId(), 'q_'.$second->getId() => [(string) $second->getAnswers()->get(0)->getId(), (string) $second->getAnswers()->get(1)->getId()]];
        $this->login('student'); $this->client->request('GET', '/lesson-tests/'.$id); self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'Запитання та правильні відповіді');
        $this->client->request('GET', '/lesson-tests/'.$id.'/take'); self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('✓ Правильна', $this->client->getResponse()->getContent());
        $token = $this->client->getCrawler()->filter('input[name="quiz_submission[_token]"]')->attr('value');
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => $values + ['revision' => (string) $quiz->getRevision(), '_token' => $token]]);
        self::assertResponseStatusCodeSame(303); $this->client->followRedirect(); self::assertSelectorTextContains('[data-result]', '5/5');
        $attempt = $this->manager()->getRepository(QuizAttempt::class)->findOneBy(['quiz' => $id]); $attemptId = $attempt->getId();
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => $values + ['revision' => '1', '_token' => $token]]);
        self::assertResponseRedirects('/lesson-tests/'.$id); self::assertSame(1, $this->manager()->getRepository(QuizAttempt::class)->count([]));
        $this->login('peer'); $this->client->request('GET', '/test-results/'.$attemptId); self::assertResponseStatusCodeSame(403);
        foreach (['teacher', 'co', 'head', 'admin'] as $actor) { $this->login($actor); $this->client->request('GET', '/test-results/'.$attemptId); self::assertResponseIsSuccessful(); }
        $this->login('foreign'); $this->client->request('GET', '/test-results/'.$attemptId); self::assertResponseStatusCodeSame(403);
    }

    public function testQuizDraftWrongGroupTamperingAndStaleForms(): void
    {
        $quiz = $this->quiz(false); $id = $quiz->getId();
        $this->login('student'); $this->client->request('GET', '/lesson-tests/'.$id.'/take'); self::assertResponseStatusCodeSame(403);
        $quiz = $this->manager()->find(LessonQuiz::class, $id); $quiz->setPublished(true); $this->manager()->flush();
        $this->login('outsider'); $this->client->request('GET', '/lesson-tests/'.$id.'/take'); self::assertResponseStatusCodeSame(403);
        $this->login('teacher'); $this->client->request('GET', '/lesson-tests/'.$id.'/take'); self::assertResponseStatusCodeSame(403);
        $this->login('student'); $this->client->request('GET', '/lesson-tests/'.$id.'/take');
        $token = $this->client->getCrawler()->filter('input[name="quiz_submission[_token]"]')->attr('value');
        $quiz = $this->manager()->find(LessonQuiz::class, $id); $values = [];
        foreach ($quiz->getQuestions() as $question) $values['q_'.$question->getId()] = $question->getKind() === 'single' ? (string) $question->getAnswers()->first()->getId() : [(string) $question->getAnswers()->first()->getId()];
        $base = $values + ['revision' => (string) $quiz->getRevision(), '_token' => $token];
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => $base + ['score' => 999]]); self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => array_replace($base, ['_token' => 'bad'])]); self::assertResponseStatusCodeSame(422);
        $quiz = $this->manager()->find(LessonQuiz::class, $id); $quiz->revise(); $this->manager()->flush();
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => $base]); self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Тест змінився'); self::assertSame(0, $this->manager()->getRepository(QuizAttempt::class)->count([]));
    }

    public function testPartialMultipleChoiceAndImmutableResultSnapshot(): void
    {
        $quiz = $this->quiz(); $quiz->setMaxAttempts(2); $this->manager()->flush();
        $id = $quiz->getId(); $first = $quiz->getQuestions()->first(); $second = $quiz->getQuestions()->last();
        $values = ['q_'.$first->getId() => (string) $first->getAnswers()->first()->getId(), 'q_'.$second->getId() => [(string) $second->getAnswers()->first()->getId()]];
        $this->login('student'); $this->client->request('GET', '/lesson-tests/'.$id.'/take');
        $token = $this->client->getCrawler()->filter('input[name="quiz_submission[_token]"]')->attr('value');
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => $values + ['revision' => (string) $quiz->getRevision(), '_token' => $token]]);
        self::assertResponseStatusCodeSame(303); $this->client->followRedirect(); self::assertSelectorTextContains('[data-result]', '2/5');
        $attempt = $this->manager()->getRepository(QuizAttempt::class)->findOneBy(['quiz' => $id]); $attemptId = $attempt->getId();
        $quiz = $this->manager()->find(LessonQuiz::class, $id); $quiz->setTitle('Оновлена назва')->revise(); $quiz->getQuestions()->first()->setText('Змінене запитання'); $this->manager()->flush();
        $this->client->request('GET', '/test-results/'.$attemptId); self::assertSelectorTextContains('h1', 'Перевірка знань');
        self::assertSelectorTextContains('main', 'Оберіть одну'); self::assertSelectorTextNotContains('main', 'Змінене запитання');
    }

    public function testExecutableUploadAndForeignAnswerIdsAreRejected(): void
    {
        $this->login('teacher'); $this->client->request('GET', '/lessons/'.$this->lessonId.'/materials/new');
        $token = $this->client->getCrawler()->filter('input[name="lesson_attachment[_token]"]')->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'classroom-'); file_put_contents($path, '<?php echo "not allowed";');
        $this->client->request('POST', '/lessons/'.$this->lessonId.'/materials/new', ['lesson_attachment' => ['title' => 'Небезпечний', 'kind' => 'file', '_token' => $token]], ['lesson_attachment' => ['upload' => new UploadedFile($path, 'script.php', null, null, true)]]);
        self::assertResponseStatusCodeSame(422); if (is_file($path)) unlink($path);
        self::assertSame(0, $this->manager()->getRepository(LessonAttachment::class)->count([]));
        $quiz = $this->quiz(); $id = $quiz->getId(); $first = $quiz->getQuestions()->first(); $second = $quiz->getQuestions()->last();
        $this->login('student'); $this->client->request('GET', '/lesson-tests/'.$id.'/take');
        $token = $this->client->getCrawler()->filter('input[name="quiz_submission[_token]"]')->attr('value');
        $this->client->request('POST', '/lesson-tests/'.$id.'/take', ['quiz_submission' => ['revision' => (string) $quiz->getRevision(), '_token' => $token,
            'q_'.$first->getId() => (string) $second->getAnswers()->first()->getId(), 'q_'.$second->getId() => [(string) $second->getAnswers()->first()->getId()],
        ]]);
        self::assertResponseStatusCodeSame(422); self::assertSame(0, $this->manager()->getRepository(QuizAttempt::class)->count([]));
    }

    public function testQuizEditorRejectsStaleRevision(): void
    {
        $quiz = $this->quiz(); $id = $quiz->getId();
        $this->login('teacher'); $this->client->request('GET', '/lesson-tests/'.$id.'/edit');
        $token = $this->client->getCrawler()->filter('input[name="lesson_quiz[_token]"]')->attr('value');
        $oldRevision = $this->client->getCrawler()->filter('input[name="lesson_quiz[revision]"]')->attr('value');
        $quiz = $this->manager()->find(LessonQuiz::class, $id); $quiz->revise(); $this->manager()->flush();
        $this->client->request('POST', '/lesson-tests/'.$id.'/edit', ['lesson_quiz' => ['title' => 'Застаріла зміна', 'maxAttempts' => 1, 'published' => '1', 'revision' => $oldRevision, '_token' => $token, 'questions' => [
            ['text' => 'Нове запитання', 'kind' => 'single', 'points' => 1, 'answers' => [['text' => 'Так', 'correct' => '1'], ['text' => 'Ні']]],
        ]]]);
        self::assertResponseStatusCodeSame(422); self::assertSelectorTextContains('main', 'Тест уже змінив інший викладач');
        $this->manager()->clear(); self::assertSame('Перевірка знань', $this->manager()->find(LessonQuiz::class, $id)->getTitle());
    }
}
