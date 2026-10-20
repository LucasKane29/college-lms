<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'schedule')]
#[ORM\UniqueConstraint(name: 'UNIQ_SCHEDULE_SLOT', columns: ['lesson_date', 'group_id', 'pair_id'])]
class Schedule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private \DateTimeImmutable $lessonDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Group $group;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Pair $pair;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?Discipline $discipline = null;

    public function getId(): ?int { return $this->id; }
    public function getLessonDate(): \DateTimeImmutable { return $this->lessonDate; }
    public function setLessonDate(\DateTimeImmutable $date): static { $this->lessonDate = $date->setTime(0, 0); return $this; }
    public function getGroup(): Group { return $this->group; }
    public function setGroup(Group $group): static { $this->group = $group; return $this; }
    public function getPair(): Pair { return $this->pair; }
    public function setPair(Pair $pair): static { $this->pair = $pair; return $this; }
    public function getDiscipline(): ?Discipline { return $this->discipline; }
    public function setDiscipline(?Discipline $discipline): static { $this->discipline = $discipline; return $this; }
}
