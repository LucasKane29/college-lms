<?php

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`groups`')]
#[ORM\Index(name: 'IDX_GROUP_CURATOR', columns: ['curator_id'])]
class Group
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Вкажіть назву групи.')]
    #[Assert\Length(max: 100, maxMessage: 'Назва групи має містити до {{ limit }} символів.')]
    private string $name = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $curator = null;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(mappedBy: 'studentGroup', targetEntity: User::class, fetch: 'EXTRA_LAZY')]
    private Collection $students;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->students = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCurator(): ?User
    {
        return $this->curator;
    }

    public function setCurator(User $curator): static
    {
        $this->curator = $curator;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, User> */
    public function getStudents(): Collection
    {
        return $this->students;
    }

    public function addStudent(User $student): static
    {
        $student->setStudentGroup($this);

        return $this;
    }

    public function removeStudent(User $student): static
    {
        if ($student->getStudentGroup() === $this) {
            $student->setStudentGroup(null);
        }

        return $this;
    }
}
