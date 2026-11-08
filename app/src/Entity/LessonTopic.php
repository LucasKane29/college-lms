<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'lessons')]
#[ORM\Index(name: 'IDX_LESSONS_DISCIPLINE', columns: ['discipline_id'])]
class LessonTopic
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Discipline $discipline;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Вкажіть назву заняття.')]
    #[Assert\Length(max: 255, maxMessage: 'Назва заняття має містити до {{ limit }} символів.')]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 20000)]
    private ?string $description = null;

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getDiscipline(): Discipline { return $this->discipline; }
    public function setDiscipline(Discipline $discipline): static { $this->discipline = $discipline; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }
}
