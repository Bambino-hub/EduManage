<?php

declare(strict_types=1);

namespace App\Security\Entity;

use App\Security\Repository\UtilisateurRepository;
use App\Shared\Entity\TimestampableTrait;
use App\Staff\Entity\Enseignant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\HasLifecycleCallbacks]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /** @var string[] */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column]
    private bool $actif = true;

    /** Incite (sans bloquer) à changer le mot de passe temporaire généré par l'admin. */
    #[ORM\Column]
    private bool $doitChangerMotDePasse = true;

    /** Null pour un compte admin sans fiche personnel. */
    #[ORM\OneToOne(targetEntity: Enseignant::class)]
    #[ORM\JoinColumn(name: 'enseignant_id', nullable: true, onDelete: 'SET NULL')]
    private ?Enseignant $enseignant = null;

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
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
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

    public function eraseCredentials(): void
    {
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        return $this;
    }

    public function isDoitChangerMotDePasse(): bool
    {
        return $this->doitChangerMotDePasse;
    }

    public function setDoitChangerMotDePasse(bool $doitChangerMotDePasse): static
    {
        $this->doitChangerMotDePasse = $doitChangerMotDePasse;
        return $this;
    }

    public function getEnseignant(): ?Enseignant
    {
        return $this->enseignant;
    }

    public function setEnseignant(?Enseignant $enseignant): static
    {
        $this->enseignant = $enseignant;
        return $this;
    }
}
