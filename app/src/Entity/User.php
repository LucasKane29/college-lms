<?php

namespace App\Entity;

use App\Repository\UserRepository;
use App\Security\UserRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_EMAIL', fields: ['email'])]
#[ORM\Index(name: 'IDX_USER_GROUP', columns: ['group_id'])]
#[UniqueEntity(fields: ['email'], message: 'Ця електронна адреса вже зареєстрована.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Вкажіть електронну адресу.')]
    #[Assert\Email(message: 'Вкажіть коректну електронну адресу.')]
    #[Assert\Length(max: 180, maxMessage: 'Електронна адреса має містити до {{ limit }} символів.')]
    private string $email = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Вкажіть ваше ім’я та прізвище.')]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Вкажіть щонайменше {{ limit }} символи.', maxMessage: 'Ім’я має містити до {{ limit }} символів.')]
    private string $fullName = '';

    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\NotBlank(message: 'Вкажіть номер телефона.', groups: ['registration'])]
    #[Assert\Regex(pattern: '/^\+(?:380[0-9]{9}|(?!380)[1-9][0-9]{7,14})$/D', message: 'Вкажіть повний телефон у міжнародному форматі, наприклад +380671234567.')]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\NotBlank(message: 'Вкажіть дату народження.', groups: ['registration'])]
    #[Assert\LessThanOrEqual('today', message: 'Дата народження не може бути в майбутньому.')]
    private ?\DateTimeImmutable $birthDate = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = ['ROLE_USER'];

    #[ORM\ManyToOne(inversedBy: 'students')]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'SET NULL')]
    private ?Group $studentGroup = null;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = trim($fullName);

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $phoneNumber = null === $phoneNumber ? null : preg_replace('/[\s()\-]+/u', '', trim($phoneNumber));
        $this->phoneNumber = '' === $phoneNumber ? null : $phoneNumber;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate?->setTime(0, 0);

        return $this;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getRole(): UserRole
    {
        foreach (array_reverse(UserRole::cases()) as $role) {
            if (in_array($role->value, $this->roles, true)) {
                return $role;
            }
        }

        return UserRole::User;
    }

    public function setRole(UserRole $role): static
    {
        if ($role !== $this->getRole() && $this->isRoleLocked()) {
            throw new \DomainException('Спочатку вилучіть студента з групи, щоб змінити його роль.');
        }
        $this->roles = array_values(array_unique(['ROLE_USER', $role->value]));

        return $this;
    }

    public function isRoleLocked(): bool
    {
        return UserRole::Student === $this->getRole() && null !== $this->studentGroup;
    }

    public function getStudentGroup(): ?Group
    {
        return $this->studentGroup;
    }

    public function setStudentGroup(?Group $group): static
    {
        if (null !== $group && UserRole::Student !== $this->getRole()) {
            throw new \InvalidArgumentException('Only students can join a group.');
        }
        if (null !== $group && null !== $this->studentGroup && $this->studentGroup !== $group) {
            throw new \DomainException('A student already belongs to another group.');
        }
        if ($this->studentGroup === $group) {
            return $this;
        }
        $previous = $this->studentGroup;
        $this->studentGroup = $group;
        $previous?->getStudents()->removeElement($this);
        if (null !== $group && !$group->getStudents()->contains($this)) {
            $group->getStudents()->add($this);
        }

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);
        // Reload group membership from the provider instead of serializing its members into the session.
        $data["\0".self::class."\0studentGroup"] = null;

        return $data;
    }
}
