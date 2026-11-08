<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'tests')]
class LessonQuiz
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LessonTopic $lesson;
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 10000)]
    private ?string $description = null;
    #[ORM\Column]
    private bool $published = false;
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 10)]
    private int $maxAttempts = 1;
    #[ORM\Column]
    private int $revision = 1;
    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $lockVersion = 1;
    /** @var Collection<int, QuizQuestion> */
    #[ORM\OneToMany(mappedBy: 'quiz', targetEntity: QuizQuestion::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Count(min: 1, max: 50)]
    #[Assert\Valid]
    private Collection $questions;

    public function __construct() { $this->questions = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getLesson(): LessonTopic { return $this->lesson; }
    public function setLesson(LessonTopic $lesson): static { $this->lesson = $lesson; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): static { $this->published = $published; return $this; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $max): static { $this->maxAttempts = $max; return $this; }
    public function getRevision(): int { return $this->revision; }
    public function revise(): void { ++$this->revision; }
    /** @return Collection<int, QuizQuestion> */
    public function getQuestions(): Collection { return $this->questions; }
    public function addQuestion(QuizQuestion $question): static { if (!$this->questions->contains($question)) { $this->questions->add($question); $question->setQuiz($this); } return $this; }
    public function removeQuestion(QuizQuestion $question): static { $this->questions->removeElement($question); return $this; }
}
