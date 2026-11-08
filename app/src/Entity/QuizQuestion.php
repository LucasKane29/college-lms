<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'questions')]
class QuizQuestion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(name: 'test_id', nullable: false, onDelete: 'CASCADE')]
    private LessonQuiz $quiz;
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    private string $text = '';
    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: ['single', 'multiple'])]
    private string $kind = 'single';
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 100)]
    private int $points = 1;
    #[ORM\Column]
    private int $position = 0;
    /** @var Collection<int, QuizAnswer> */
    #[ORM\OneToMany(mappedBy: 'question', targetEntity: QuizAnswer::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Count(min: 2, max: 10)]
    #[Assert\Valid]
    private Collection $answers;

    public function __construct() { $this->answers = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getQuiz(): LessonQuiz { return $this->quiz; }
    public function setQuiz(LessonQuiz $quiz): static { $this->quiz = $quiz; return $this; }
    public function getText(): string { return $this->text; }
    public function setText(string $text): static { $this->text = trim($text); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $points): static { $this->points = $points; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    /** @return Collection<int, QuizAnswer> */
    public function getAnswers(): Collection { return $this->answers; }
    public function addAnswer(QuizAnswer $answer): static { if (!$this->answers->contains($answer)) { $this->answers->add($answer); $answer->setQuestion($this); } return $this; }
    public function removeAnswer(QuizAnswer $answer): static { $this->answers->removeElement($answer); return $this; }

    #[Assert\Callback]
    public function validateCorrectAnswers(ExecutionContextInterface $context): void
    {
        $correct = $this->answers->filter(static fn (QuizAnswer $answer): bool => $answer->isCorrect())->count();
        if ($correct < 1 || ($this->kind === 'single' && $correct !== 1)) {
            $context->buildViolation('Для одного вибору позначте рівно одну правильну відповідь, для кількох — щонайменше одну.')->atPath('answers')->addViolation();
        }
    }
}
