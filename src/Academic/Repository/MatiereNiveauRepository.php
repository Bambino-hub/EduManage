<?php

declare(strict_types=1);

namespace App\Academic\Repository;

use App\Academic\Entity\Matiere;
use App\Academic\Entity\MatiereNiveau;
use App\Academic\Entity\Niveau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatiereNiveau>
 */
class MatiereNiveauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatiereNiveau::class);
    }

    /**
     * Toutes les matières réellement enseignées (heuresParSemaine > 0), tous niveaux
     * confondus, matière déjà chargée pour éviter le N+1 lors d'un regroupement par niveau.
     *
     * @return MatiereNiveau[]
     */
    public function findToutesEnseignees(): array
    {
        return $this->createQueryBuilder('mn')
            ->addSelect('m')
            ->join('mn.matiere', 'm')
            ->where('mn.heuresParSemaine > 0')
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByMatiereEtNiveau(Matiere $matiere, Niveau $niveau): ?MatiereNiveau
    {
        return $this->findOneBy(['matiere' => $matiere, 'niveau' => $niveau]);
    }

    /**
     * Toutes les lignes indexées par [matiereId][niveauId], en une seule requête —
     * évite un findOneBy() par attribution (N+1) dans EmploiDuTempsGenerator, dont le
     * coût cumulé (une centaine d'attributions) rogne sur le budget de temps alloué
     * aux tentatives de génération, d'autant plus si la latence réseau vers la base
     * est plus élevée qu'en local.
     *
     * @return array<int, array<int, MatiereNiveau>>
     */
    public function findIndexeParMatiereEtNiveau(): array
    {
        $rows = $this->createQueryBuilder('mn')
            ->addSelect('m', 'n')
            ->join('mn.matiere', 'm')
            ->join('mn.niveau', 'n')
            ->getQuery()
            ->getResult();

        $index = [];
        foreach ($rows as $mn) {
            $index[$mn->getMatiere()->getId()][$mn->getNiveau()->getId()] = $mn;
        }

        return $index;
    }
}
