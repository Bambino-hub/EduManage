<?php

declare(strict_types=1);

namespace App\Salaire\Service;

use Doctrine\DBAL\Connection;

/**
 * Génère le numéro séquentiel des paiements de salaire (commence à 1, séquence propre —
 * indépendante de celle des reçus d'écolage). Même mécanisme que
 * Economat\Service\NumeroRecuGenerator (table de compteur dédiée, hors mapping Doctrine,
 * incrémentation atomique via INSERT ... ON DUPLICATE KEY UPDATE) — dupliqué plutôt que
 * factorisé : deux domaines/tables distincts, évite un nom de table interpolé dans du SQL.
 */
class NumeroPaiementSalaireGenerator
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function generer(): int
    {
        $this->connection->executeStatement(
            'INSERT INTO caisse_compteur_paiement_salaire (id, dernier_numero) VALUES (1, 1)
             ON DUPLICATE KEY UPDATE dernier_numero = dernier_numero + 1',
        );

        return (int) $this->connection->fetchOne(
            'SELECT dernier_numero FROM caisse_compteur_paiement_salaire WHERE id = 1',
        );
    }
}
