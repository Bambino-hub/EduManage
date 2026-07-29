<?php

declare(strict_types=1);

namespace App\Economat\Service;

use Doctrine\DBAL\Connection;

/**
 * Génère le numéro séquentiel des reçus (commence à 1, propre à l'appli — indépendant des
 * anciens reçus papier). Passe par une table de compteur dédiée (economat_compteur_recu,
 * hors mapping Doctrine) plutôt qu'un MAX(numero)+1 sur `recu` : l'incrémentation atomique
 * (INSERT ... ON DUPLICATE KEY UPDATE) est protégée par le row-lock InnoDB, sans code de
 * verrouillage applicatif à écrire.
 */
class NumeroRecuGenerator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function generer(): int
    {
        $this->connection->executeStatement(
            'INSERT INTO economat_compteur_recu (id, dernier_numero) VALUES (1, 1)
             ON DUPLICATE KEY UPDATE dernier_numero = dernier_numero + 1',
        );

        return (int) $this->connection->fetchOne(
            'SELECT dernier_numero FROM economat_compteur_recu WHERE id = 1',
        );
    }
}
