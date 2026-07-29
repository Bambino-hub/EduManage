<?php

declare(strict_types=1);

namespace App\Economat\Service;

use App\Academic\Entity\AnneeScolaire;
use App\Academic\Entity\Niveau;
use App\Economat\Dto\SoldeInfo;
use App\Economat\Repository\RecuRepository;
use App\Economat\Repository\TarifRepository;
use App\Student\Entity\Eleve;

/**
 * Centralise le calcul montant dû / payé / solde, utilisé par la liste des soldes,
 * l'historique élève et le PDF du reçu — pour ne pas dupliquer la logique à 3 endroits.
 */
class SoldeCalculator
{
    public function __construct(
        private readonly TarifRepository $tarifRepo,
        private readonly RecuRepository $recuRepo,
    ) {
    }

    public function calculer(Eleve $eleve, Niveau $niveau, AnneeScolaire $anneeScolaire): SoldeInfo
    {
        $tarif = $this->tarifRepo->findOneByNiveauEtAnnee($niveau, $anneeScolaire);

        return new SoldeInfo(
            montantDu: $tarif?->getMontantAnnuel(),
            montantPaye: $this->recuRepo->sommeLignesPourEleveEtAnnee($eleve, $anneeScolaire),
        );
    }
}
