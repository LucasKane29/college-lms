<?php

namespace App\Entity;

use App\Repository\DisciplineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DisciplineRepository::class)]
#[ORM\Table(name: 'disciplines')]
class Discipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Вкажіть назву дисципліни.')]
    #[Assert\Length(max: 150, maxMessage: 'Назва має містити до {{ limit }} символів.')]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000, maxMessage: 'Опис має містити до {{ limit }} символів.')]
    private ?string $description = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'discipline_teachers')]
    #[ORM\JoinColumn(name: 'discipline_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    #[Assert\Count(min: 1, minMessage: 'Оберіть хоча б одного викладача.')]
    #[ORM\OrderBy(['fullName' => 'ASC', 'id' => 'ASC'])]
    private Collection $teachers;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'disciplines')]
    #[ORM\JoinTable(name: 'discipline_groups')]
    #[ORM\JoinColumn(name: 'discipline_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $groups;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
        $this->teachers = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getTeachers(): Collection
    {
        return $this->teachers;
    }

    public function addTeacher(User $teacher): static
    {
        if (!$this->teachers->contains($teacher)) {
            $this->teachers->add($teacher);
        }

        return $this;
    }

    public function removeTeacher(User $teacher): static
    {
        $this->teachers->removeElement($teacher);

        return $this;
    }

    public function hasTeacher(User $teacher): bool
    {
        return $this->teachers->exists(static fn (int $key, User $assigned): bool => $assigned === $teacher
            || (null !== $teacher->getId() && $assigned->getId() === $teacher->getId()));
    }

    public function getTeacherNames(): string
    {
        return implode(', ', $this->teachers->map(static fn (User $teacher): string => $teacher->getFullName())->toArray());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->addDiscipline($this);
        }

        return $this;
    }

    public function removeGroup(Group $group): static
    {
        if ($this->groups->removeElement($group)) {
            $group->removeDiscipline($this);
        }

        return $this;
    }
}
