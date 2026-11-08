<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\Discipline;
use App\Entity\Pair;
use App\Entity\Schedule;
use App\Form\PairType;
use App\Form\ScheduleType;
use App\Service\ScheduleCalendar;
use App\Service\ScheduleManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ScheduleController extends AbstractController
{
    #[Route('/schedule', name: 'app_schedule', methods: ['GET'])]
    #[IsGranted('ROLE_HEAD_TEACHER')]
    public function index(Request $request, ScheduleCalendar $calendar, ScheduleManager $schedule, EntityManagerInterface $manager): Response
    {
        $input = $this->calendarQuery($request);
        $view = $calendar->buildEditor($input);
        $allGroups = $schedule->visibleGroups($this->getUser());
        $group = isset($input['group']) ? ($manager->find(Group::class, $input['group']) ?? throw $this->createNotFoundException()) : null;
        $discipline = isset($input['discipline']) ? ($manager->find(Discipline::class, $input['discipline']) ?? throw $this->createNotFoundException()) : null;
        $groups = $group ? [$group] : $allGroups;
        $grid = $counts = [];
        foreach ($schedule->lessons($view['visibleFrom'], $view['visibleTo'], $groups) as $lesson) {
            $date = $lesson->getLessonDate()->format('Y-m-d');
            // Keep occupied slots in the grid even when another discipline is filtered out.
            $grid[$date][$lesson->getGroup()->getId()][$lesson->getPair()->getId()] = $lesson;
            if (!$discipline || $lesson->getDiscipline()->getId() === $discipline->getId()) {
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }
        $view['query'] += array_intersect_key($input, array_flip(['group', 'discipline']));
        $view['query']['view'] = 'schedule';

        return $this->render('schedule/index.html.twig', $view + [
            'allGroups' => $allGroups, 'groups' => $groups, 'grid' => $grid, 'counts' => $counts,
            'selectedGroup' => $group, 'selectedDiscipline' => $discipline,
            'disciplines' => $manager->getRepository(Discipline::class)->createQueryBuilder('d')
                ->join('d.teacher', 'teacher')->addSelect('teacher')->orderBy('d.name', 'ASC')->addOrderBy('d.id', 'ASC')->getQuery()->getResult(),
            'pairs' => $manager->getRepository(Pair::class)->findBy([], ['number' => 'ASC']),
        ]);
    }

    #[Route('/account', name: 'app_account', methods: ['GET'])]
    public function account(Request $request, ScheduleCalendar $calendar, ScheduleManager $schedule, EntityManagerInterface $manager): Response
    {
        $view = $calendar->build($this->calendarQuery($request));
        $groups = $schedule->visibleGroups($this->getUser());
        $grid = $counts = [];
        foreach ($schedule->lessons($view['visibleFrom'], $view['visibleTo'], $groups) as $lesson) {
            $date = $lesson->getLessonDate()->format('Y-m-d');
            $grid[$date][$lesson->getGroup()->getId()][$lesson->getPair()->getId()] = $lesson;
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }

        return $this->render('security/account.html.twig', $view + [
            'groups' => $groups, 'grid' => $grid, 'counts' => $counts,
            'pairs' => $manager->getRepository(Pair::class)->findBy([], ['number' => 'ASC']),
        ]);
    }

    #[Route('/schedule/cell/{date}/{groupId}/{pairId}', name: 'app_schedule_cell', requirements: ['date' => '\\d{4}-\\d{2}-\\d{2}', 'groupId' => '\\d+', 'pairId' => '\\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_HEAD_TEACHER')]
    public function cell(string $date, int $groupId, int $pairId, Request $request, ScheduleCalendar $calendar, ScheduleManager $schedule, EntityManagerInterface $manager): Response
    {
        $day = $calendar->date($date);
        $group = $manager->find(Group::class, $groupId) ?? throw $this->createNotFoundException();
        $pair = $manager->find(Pair::class, $pairId) ?? throw $this->createNotFoundException();
        $lesson = $manager->getRepository(Schedule::class)->findOneBy(['lessonDate' => $day, 'group' => $group, 'pair' => $pair]);
        $existing = $lesson !== null;
        $lesson ??= (new Schedule())->setLessonDate($day)->setGroup($group)->setPair($pair);
        $query = $this->calendarQuery($request);
        if (!$existing && isset($query['discipline'])) {
            $selected = $manager->find(Discipline::class, $query['discipline']);
            if ($selected && $selected->getGroups()->contains($group)) {
                $lesson->setDiscipline($selected);
            }
        }
        $form = $this->createForm(ScheduleType::class, $lesson, [
            'group' => $group,
            'action' => $this->generateUrl('app_schedule_cell', ['date' => $date, 'groupId' => $groupId, 'pairId' => $pairId] + $query),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($error = $schedule->lessonError($lesson)) {
                $form->addError(new FormError($error));
            } else {
                try {
                    $manager->persist($lesson);
                    $manager->flush();
                    $this->addFlash('success', 'Заняття збережено.');

                    return $this->redirectToRoute($this->returnRoute($query), $query, Response::HTTP_SEE_OTHER);
                } catch (UniqueConstraintViolationException) {
                    $form->addError(new FormError('Цю клітинку вже заповнено. Оновіть розклад.'));
                }
            }
        }

        return $this->render('schedule/lesson_form.html.twig', [
            'lessonForm' => $form, 'lesson' => $lesson, 'existing' => $existing, 'query' => $query, 'returnRoute' => $this->returnRoute($query),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/schedule/{id}/delete', name: 'app_schedule_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_HEAD_TEACHER')]
    public function delete(int $id, Request $request, EntityManagerInterface $manager): Response
    {
        $lesson = $manager->find(Schedule::class, $id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete_schedule_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Недійсний токен форми.');
        }
        $query = $this->calendarQuery($request);
        $manager->remove($lesson);
        $manager->flush();
        $this->addFlash('success', 'Заняття видалено.');

        return $this->redirectToRoute($this->returnRoute($query), $query, Response::HTTP_SEE_OTHER);
    }

    #[Route('/schedule/pairs', name: 'app_schedule_pairs', methods: ['GET'])]
    #[IsGranted('ROLE_HEAD_TEACHER')]
    public function pairs(EntityManagerInterface $manager): Response
    {
        return $this->render('schedule/pairs.html.twig', ['pairs' => $manager->getRepository(Pair::class)->findBy([], ['number' => 'ASC'])]);
    }

    #[Route('/schedule/pairs/new', name: 'app_schedule_pair_new', methods: ['GET', 'POST'])]
    #[Route('/schedule/pairs/{id}/edit', name: 'app_schedule_pair_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_HEAD_TEACHER')]
    public function pair(Request $request, EntityManagerInterface $manager, ScheduleManager $schedule, ?int $id = null): Response
    {
        $pair = $id === null ? new Pair() : ($manager->find(Pair::class, $id) ?? throw $this->createNotFoundException());
        if ($id === null) {
            $last = $manager->getRepository(Pair::class)->findOneBy([], ['number' => 'DESC']);
            $pair->setNumber(($last?->getNumber() ?? 0) + 1);
            if ($last && $last->getWeekdayEnd()->format('H:i') <= '21:59' && $last->getWeekendEnd()->format('H:i') <= '21:59') {
                $pair->setWeekdayStart($last->getWeekdayEnd())->setWeekdayEnd($last->getWeekdayEnd()->modify('+120 minutes'))
                    ->setWeekendStart($last->getWeekendEnd())->setWeekendEnd($last->getWeekendEnd()->modify('+120 minutes'));
            }
        }
        $form = $this->createForm(PairType::class, $pair);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($error = $schedule->pairError($pair)) {
                $form->addError(new FormError($error));
            } else {
                try {
                    $manager->persist($pair);
                    $manager->flush();
                    $this->addFlash('success', 'Налаштування пари збережено.');

                    return $this->redirectToRoute('app_schedule_pairs', status: Response::HTTP_SEE_OTHER);
                } catch (UniqueConstraintViolationException) {
                    $form->addError(new FormError('Пара з таким номером вже існує.'));
                }
            }
        }

        return $this->render('schedule/pair_form.html.twig', ['pairForm' => $form, 'creating' => $id === null], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function calendarQuery(Request $request): array
    {
        $query = array_intersect_key($request->query->all(), array_flip(['from', 'to', 'cursor', 'day', 'group', 'discipline', 'view']));
        foreach ($query as $value) {
            if (!is_string($value)) throw new BadRequestHttpException('Некоректний фільтр дат.');
        }
        $query = array_filter($query, static fn (string $value): bool => '' !== $value);
        foreach (['group', 'discipline'] as $field) {
            if (isset($query[$field]) && !preg_match('/^[1-9][0-9]{0,9}$/D', $query[$field])) {
                throw new BadRequestHttpException('Некоректний фільтр групи або дисципліни.');
            }
        }

        return $query;
    }

    private function returnRoute(array $query): string
    {
        return ($query['view'] ?? null) === 'schedule' ? 'app_schedule' : 'app_account';
    }
}
