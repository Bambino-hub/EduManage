<?php

declare(strict_types=1);

namespace App\Scheduling\Repository;

use App\Academic\Entity\Classe;
use App\Academic\Entity\Matiere;
use App\Scheduling\Entity\Attribution;
use App\Staff\Entity\Enseignant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Attribution>
 */
class AttributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attribution::class);
    }

    /** @return Attribution[] */
    public function findByClasse(int $classeId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.matiere', 'm')
            ->where('a.classe = :classeId')
            ->setParameter('classeId', $classeId)
            ->orderBy('m.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Exclut les attributions d'une classe désactivée (niveau sans cohorte cette année) :
     * elles ne doivent ni être plannifiées par le générateur d'emploi du temps, ni compter
     * dans les indicateurs (dashboard, vérification de complétude).
     *
     * @return Attribution[]
     */
    public function findByAnneeScolaire(int $anneeScolaireId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('cl', 'm', 'e')
            ->join('a.classe', 'cl')
            ->join('a.matiere', 'm')
            ->join('a.enseignant', 'e')
            ->where('cl.anneeScolaire = :anneeId')
            ->andWhere('cl.active = true')
            ->setParameter('anneeId', $anneeScolaireId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Une classe ne peut avoir qu'un seul enseignant pour une matière donnée.
     * Retourne l'attribution qui bloquerait cette règle si elle existe déjà
     * (pour un autre enseignant que celui en cours d'affectation), sinon null.
     */
    public function findConflitMatiereClasse(Matiere $matiere, Classe $classe, ?int $excludeAttributionId = null): ?Attribution
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.matiere = :matiere')
            ->andWhere('a.classe = :classe')
            ->setParameter('matiere', $matiere)
            ->setParameter('classe', $classe)
            ->setMaxResults(1);

        if ($excludeAttributionId !== null) {
            $qb->andWhere('a.id != :excludeId')
                ->setParameter('excludeId', $excludeAttributionId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Charge horaire hebdomadaire totale par enseignant (toutes classes/matières confondues),
     * pour visualiser la charge globale sur la liste des attributions.
     *
     * @return list<array{enseignant: \App\Staff\Entity\Enseignant, total: int}>
     */
    public function totalHeuresParEnseignant(): array
    {
        // Doctrine exige que l'entité sélectionnée (ici Enseignant) soit l'alias racine de la
        // requête pour pouvoir la retourner telle quelle aux côtés d'un agrégat — on part donc
        // de Enseignant plutôt que d'Attribution, en joignant sa collection d'attributions.
        $resultats = $this->getEntityManager()->createQueryBuilder()
            ->select('e AS enseignant', 'SUM(a.volumeHoraireHebdo) AS total')
            ->from(Enseignant::class, 'e')
            ->join('e.attributions', 'a')
            ->groupBy('e.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('e.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $r) => ['enseignant' => $r['enseignant'], 'total' => (int) $r['total']],
            $resultats,
        );
    }
}
