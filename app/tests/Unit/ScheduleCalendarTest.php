<?php

namespace App\Tests\Unit;

use App\Entity\Pair;
use App\Service\ScheduleCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ScheduleCalendarTest extends TestCase
{
    public static function ranges(): iterable
    {
        yield 'seven days' => ['2026-10-20', '2026-10-26', 'day', 1];
        yield 'eight days' => ['2026-10-20', '2026-10-27', 'week', 6];
        yield 'calendar month' => ['2026-10-20', '2026-11-19', 'week', 6];
        yield 'over calendar month' => ['2026-10-20', '2026-11-20', 'month', 12];
        yield 'short February' => ['2026-02-01', '2026-02-28', 'week', 1];
        yield 'over February' => ['2026-02-01', '2026-03-01', 'month', 28];
    }

    #[DataProvider('ranges')]
    public function testRangeDeterminesNavigation(string $from, string $to, string $mode, int $days): void
    {
        $view = (new ScheduleCalendar())->build(compact('from', 'to'));
        self::assertSame($mode, $view['mode']);
        self::assertCount($days, $view['days']);
        self::assertNull($view['previous']);
        self::assertSame($from, $view['visibleFrom']->format('Y-m-d'));
    }

    public function testPagingIsClampedToFilterAndSelectedDay(): void
    {
        $view = (new ScheduleCalendar())->build(['from' => '2026-10-20', 'to' => '2026-12-10', 'cursor' => '2026-12-01', 'day' => '2027-01-01']);
        self::assertNull($view['next']);
        self::assertSame('2026-11-30', $view['previous']);
        self::assertSame('2026-12-10', $view['selected']->format('Y-m-d'));
        self::assertCount(1, $view['tableDays']);
    }

    public static function invalidRanges(): iterable
    {
        yield [['from' => '2026-02-30']];
        yield [['from' => '2026-10-20', 'to' => '2026-10-19']];
        yield [['from' => '2026-01-01', 'to' => '2027-01-02']];
        yield [['from' => 'invalid']];
    }

    #[DataProvider('invalidRanges')]
    public function testInvalidRangesAreRejected(array $query): void
    {
        $this->expectException(BadRequestHttpException::class);
        (new ScheduleCalendar())->build($query);
    }

    public function testPairUsesWeekendTimesOnSaturdayAndSunday(): void
    {
        $pair = (new Pair())->setWeekendStart(new \DateTimeImmutable('09:00'))->setWeekendEnd(new \DateTimeImmutable('11:00'));
        self::assertSame('08:30', $pair->startOn(new \DateTimeImmutable('2026-10-23'))->format('H:i'));
        foreach (['2026-10-24', '2026-10-25'] as $date) {
            self::assertSame('09:00', $pair->startOn(new \DateTimeImmutable($date))->format('H:i'));
            self::assertSame('11:00', $pair->endOn(new \DateTimeImmutable($date))->format('H:i'));
        }
    }
}
