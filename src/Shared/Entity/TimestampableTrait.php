<?php

declare(strict_types=1);

namespace App\Shared\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajoute les colonnes d'audit createdAt / updatedAt à une entité.
 *
 * Utilisation dans une entité :
 *
 *     #[ORM\Entity]
 *     #[ORM\HasLifecycleCallbacks]
 *     class Student
 *     {
 *         use TimestampableTrait;
 *     }
 *
 * Les callbacks de cycle de vie ci-dessous renseignent automatiquement
 * les dates : aucune ligne à écrire dans les contrôleurs ou services.
 */
trait TimestampableTrait
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function initializeTimestamps(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
