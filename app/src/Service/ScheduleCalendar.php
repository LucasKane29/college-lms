<?php

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ScheduleCalendar
{
    public function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Kyiv'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Некоректна дата. Використовуйте формат РРРР-ММ-ДД.');
        }

        return $date;
    }

    /** @return array<string, mixed> */
    public function build(array $input): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Kyiv'));
        $from = $this->date($input['from'] ?? $today->format('Y-m-d'));
        $to = $this->date($input['to'] ?? $from->modify('+6 days')->format('Y-m-d'));
        if ($to < $from) {
            throw new BadRequestHttpException('Кінцева дата має бути не раніше початкової.');
        }
        $length = (int) $from->diff($to)->days + 1;
        if ($length > 366) {
            throw new BadRequestHttpException('Оберіть діапазон не більше 366 днів.');
        }
        // Clamp the month boundary, including ranges beginning on the 29th–31st.
        $nextMonth = $from->modify('first day of next month');
        $monthEnd = $nextMonth->setDate((int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), min((int) $from->format('d'), (int) $nextMonth->format('t')))->modify('-1 day');
        $mode = $length <= 7 ? 'day' : ($to <= $monthEnd ? 'week' : 'month');
        $cursor = isset($input['cursor']) ? $this->date($input['cursor']) : $from;
        $cursor = max($from, min($to, $cursor));
        $start = match ($mode) {
            'day' => $cursor,
            'week' => $cursor->modify('monday this week'),
            'month' => $cursor->modify('first day of this month'),
        };
        $end = match ($mode) {
            'day' => $start,
            'week' => $start->modify('+6 days'),
            'month' => $start->modify('last day of this month'),
        };
        $visibleFrom = max($from, $start);
        $visibleTo = min($to, $end);
        $days = [];
        for ($day = $visibleFrom; $day <= $visibleTo; $day = $day->modify('+1 day')) $days[] = $day;
        $selected = isset($input['day']) ? $this->date($input['day']) : $visibleFrom;
        $selected = max($visibleFrom, min($visibleTo, $selected));
        $query = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'cursor' => $cursor->format('Y-m-d')];
        if ($mode === 'month') $query['day'] = $selected->format('Y-m-d');

        return [
            'from' => $from, 'to' => $to, 'mode' => $mode, 'days' => $days,
            'visibleFrom' => $visibleFrom, 'visibleTo' => $visibleTo, 'selected' => $selected,
            'tableDays' => $mode === 'month' ? [$selected] : $days,
            'previous' => $start > $from ? $start->modify('-1 day')->format('Y-m-d') : null,
            'next' => $end < $to ? $end->modify('+1 day')->format('Y-m-d') : null,
            'query' => $query,
        ];
    }
}
