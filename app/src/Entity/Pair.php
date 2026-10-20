<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'pairs')]
#[UniqueEntity(fields: ['number'], message: 'Пара з таким номером вже існує.')]
class Pair
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(99)]
    private int $number = 1;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $weekdayStart;

    #[ORM\Column(type: 'time_immutable')]
    #[Assert\GreaterThan(propertyPath: 'weekdayStart', message: 'Завершення має бути пізніше початку.')]
    private \DateTimeImmutable $weekdayEnd;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $weekendStart;

    #[ORM\Column(type: 'time_immutable')]
    #[Assert\GreaterThan(propertyPath: 'weekendStart', message: 'Завершення має бути пізніше початку.')]
    private \DateTimeImmutable $weekendEnd;

    public function __construct()
    {
        $this->weekdayStart = $this->weekendStart = new \DateTimeImmutable('1970-01-01 08:30:00');
        $this->weekdayEnd = $this->weekendEnd = new \DateTimeImmutable('1970-01-01 10:30:00');
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): int { return $this->number; }
    public function setNumber(int $number): static { $this->number = $number; return $this; }
    public function getWeekdayStart(): \DateTimeImmutable { return $this->weekdayStart; }
    public function setWeekdayStart(\DateTimeImmutable $time): static { $this->weekdayStart = $time; return $this; }
    public function getWeekdayEnd(): \DateTimeImmutable { return $this->weekdayEnd; }
    public function setWeekdayEnd(\DateTimeImmutable $time): static { $this->weekdayEnd = $time; return $this; }
    public function getWeekendStart(): \DateTimeImmutable { return $this->weekendStart; }
    public function setWeekendStart(\DateTimeImmutable $time): static { $this->weekendStart = $time; return $this; }
    public function getWeekendEnd(): \DateTimeImmutable { return $this->weekendEnd; }
    public function setWeekendEnd(\DateTimeImmutable $time): static { $this->weekendEnd = $time; return $this; }
    public function startOn(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return (int) $date->format('N') >= 6 ? $this->weekendStart : $this->weekdayStart;
    }
    public function endOn(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return (int) $date->format('N') >= 6 ? $this->weekendEnd : $this->weekdayEnd;
    }
}
