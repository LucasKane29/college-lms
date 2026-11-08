<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'attachments')]
class LessonAttachment
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
    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: ['note', 'link', 'file'])]
    private string $kind = 'note';
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 100000)]
    private ?string $content = null;
    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 2048)]
    private ?string $url = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $storageName = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getLesson(): LessonTopic { return $this->lesson; }
    public function setLesson(LessonTopic $lesson): static { $this->lesson = $lesson; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $content): static { $this->content = $content; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): static { $this->url = $url; return $this; }
    public function getStorageName(): ?string { return $this->storageName; }
    public function setStorageName(?string $name): static { $this->storageName = $name; return $this; }
    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $name): static { $this->originalName = $name; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->kind === 'note' && '' === trim($this->content ?? '')) {
            $context->buildViolation('Введіть текст конспекту.')->atPath('content')->addViolation();
        }
        if ($this->kind === 'link' && '' === trim($this->url ?? '')) {
            $context->buildViolation('Вкажіть посилання.')->atPath('url')->addViolation();
        }
    }
}
