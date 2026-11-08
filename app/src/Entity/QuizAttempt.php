<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tries')]
#[ORM\UniqueConstraint(name: 'UNIQ_TRY_NUMBER', columns: ['test_id', 'student_id', 'number'])]
class QuizAttempt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'test_id', nullable: false, onDelete: 'RESTRICT')]
    private LessonQuiz $quiz;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $student;
    #[ORM\Column]
    private int $number;
    #[ORM\Column]
    private int $score;
    #[ORM\Column]
    private int $maxScore;
    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $responses;
    #[ORM\Column(length: 255)]
    private string $testTitle;
    #[ORM\Column]
    private \DateTimeImmutable $submittedAt;

    /** @param list<array<string, mixed>> $responses */
    public function __construct(LessonQuiz $quiz, User $student, int $number, int $score, int $maxScore, array $responses)
    {
        $this->quiz = $quiz; $this->student = $student; $this->number = $number;
        $this->score = $score; $this->maxScore = $maxScore; $this->responses = $responses;
        $this->testTitle = $quiz->getTitle(); $this->submittedAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getQuiz(): LessonQuiz { return $this->quiz; }
    public function getStudent(): User { return $this->student; }
    public function getNumber(): int { return $this->number; }
    public function getScore(): int { return $this->score; }
    public function getMaxScore(): int { return $this->maxScore; }
    public function getPercent(): int { return $this->maxScore > 0 ? (int) round(100 * $this->score / $this->maxScore) : 0; }
    /** @return list<array<string, mixed>> */
    public function getResponses(): array { return $this->responses; }
    public function getTestTitle(): string { return $this->testTitle; }
    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
}
