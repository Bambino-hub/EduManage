<?php

declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Scheduling\Entity\Seance;
use App\Scheduling\Repository\CreneauRepository;
use App\Scheduling\Repository\RegroupementClasseRepository;
use App\Scheduling\Repository\SeanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sert la page « Personnaliser » (emploi du temps d'UN enseignant) : déplacer librement
 * une de ses séances vers n'importe quel créneau, puis la verrouiller pour que
 * EmploiDuTempsGenerator::reorganiser() la respecte ensuite.
 *
 * Différence volontaire avec EmploiDuTempsPermutationService (utilisé par la vue
 * globale) : ici, **aucune vérification de conflit** enseignant/salle/classe — la page
 * Personnaliser est un brouillon délibérément libre ("je place cette séance où je veux,
 * sans me soucier de ce qui s'y trouve déjà") ; c'est reorganiser() qui rétablit ensuite
 * la cohérence globale en replaçant tout le reste AUTOUR des séances verrouillées. Deux
 * séances peuvent donc temporairement se chevaucher entre le moment où on les déplace et
 * le moment où on clique « Réorganiser » — c'est un état transitoire accepté, pas un bug
 * (les vues d'affichage gèrent déjà plusieurs séances par case, cf. `seancesCase` dans
 * les templates).
 *
 * Le verrou, lui, est toujours appliqué en CASCADE à toutes les séances du même créneau
 * qui appartiennent à la même "unité" de génération (cf. EmploiDuTempsGenerator::construireUnites()) :
 * classes fusionnées (RegroupementClasse) ou matières parallèles (Matiere::groupeOptionnel,
 * ex. Allemand/Espagnol). Ces séances DOIVENT toujours partager le même créneau — un
 * verrou partiel (une seule des deux verrouillée) romprait cet appariement dès la
 * prochaine réorganisation, sans qu'aucun garde-fou du générateur ne puisse s'en
 * rendre compte après coup. Le déplacement suit la même cascade, pour la même raison :
 * déplacer une seule moitié d'une paire fusionnée/parallèle désynchroniserait leur
 * créneau commun.
 *
 * **Le verrouillage, contrairement au déplacement, EST validé** : il refuse de
 * verrouiller une séance qui entrerait en conflit (enseignant, salle ou classe partagés
 * au même créneau, hors cascade fusion/parallèle légitime) avec une séance DÉJÀ
 * verrouillée. Raison : un chevauchement avec une séance pas-encore-verrouillée est un
 * état transitoire normal (reorganiser() la replacera ailleurs) ; mais verrouiller DEUX
 * séances qui se chevauchent créerait un conflit PERMANENT — reorganiser() ne peut par
 * définition jamais déplacer une séance verrouillée, donc un tel conflit resterait figé
 * pour toujours dans l'emploi du temps final, invisible dans le rapport de génération
 * (qui compte les heures verrouillées comme "placées" sans vérifier qu'elles sont
 * mutuellement cohérentes). Bug réel trouvé le 2026-09-12 : deux séances verrouillées
 * l'une sur l'autre (même salle, même créneau, classes différentes) — reorganiser()
 * avait raison de ne pas y toucher, c'est le verrouillage en amont qui aurait dû refuser.
 */
final class EmploiDuTempsPersonnalisationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeanceRepository $seanceRepo,
        private readonly RegroupementClasseRepository $regroupementRepo,
        private readonly CreneauRepository $creneauRepo,
    ) {
    }

    /**
     * @return array{succes: bool, erreurs: string[], seances: Seance[]}
     */
    public function basculerVerrou(Seance $seance, bool $verrouille): array
    {
        $groupe = $this->groupeDeCascade($seance);

        if ($verrouille) {
            $erreurs = $this->validerCoherenceAvecVerrouillees($groupe);
            if ($erreurs !== []) {
                return ['succes' => false, 'erreurs' => $erreurs, 'seances' => []];
            }
        }

        foreach ($groupe as $s) {
            $s->setVerrouille($verrouille);
        }
        $this->em->flush();

        return ['succes' => true, 'erreurs' => [], 'seances' => $groupe];
    }

    /**
     * Déplace librement une séance (et sa cascade fusion/parallèle) vers un nouveau
     * créneau — voir la docblock de la classe pour l'absence volontaire de vérification
     * de conflit. Seules des vérifications STRUCTURELLES s'appliquent : le créneau doit
     * exister et ne pas être un bloc réservé (DEVOIR/PLEINAIRE — pas un horaire de cours
     * assignable) ; une séance déjà verrouillée (ou une de ses sœurs de cascade) doit
     * d'abord être déverrouillée avant de pouvoir être redéplacée.
     *
     * @return array{succes: bool, erreurs: string[], seances: Seance[]}
     */
    public function deplacer(Seance $seance, int $nouveauCreneauId): array
    {
        if ($seance->isVerrouille()) {
            return ['succes' => false, 'erreurs' => ['Séance verrouillée : déverrouillez-la d\'abord (cadenas) pour la déplacer.'], 'seances' => []];
        }

        $creneau = $this->creneauRepo->find($nouveauCreneauId);
        if ($creneau === null) {
            return ['succes' => false, 'erreurs' => ['Créneau introuvable.'], 'seances' => []];
        }
        if ($creneau->isReserve()) {
            return ['succes' => false, 'erreurs' => [\sprintf('Le créneau %s est réservé (%s) : ce n\'est pas un horaire de cours.', $creneau->getLabel(), $creneau->getLibelleReserve())], 'seances' => []];
        }
        if ($seance->getCreneau()?->getId() === $creneau->getId()) {
            return ['succes' => false, 'erreurs' => ['Cette séance est déjà sur ce créneau.'], 'seances' => []];
        }

        $groupe = $this->groupeDeCascade($seance);
        foreach ($groupe as $s) {
            if ($s->isVerrouille()) {
                return ['succes' => false, 'erreurs' => ['Une séance liée (classes fusionnées / matière parallèle) est verrouillée : déverrouillez-la d\'abord.'], 'seances' => []];
            }
        }

        foreach ($groupe as $s) {
            $s->setCreneau($creneau);
        }
        $this->em->flush();

        return ['succes' => true, 'erreurs' => [], 'seances' => $groupe];
    }

    /**
     * Séances qui doivent partager le même sort (verrou ou déplacement) que $seance :
     * elle-même, plus toute séance du même créneau appartenant à la même unité de
     * génération (fusion de classes ou matière parallèle).
     *
     * @return Seance[]
     */
    private function groupeDeCascade(Seance $seance): array
    {
        $attribution = $seance->getAttribution();
        $classeId    = $attribution->getClasse()->getId();
        $matiereId   = $attribution->getMatiere()->getId();
        $anneeId     = $attribution->getClasse()->getAnneeScolaire()?->getId();

        if ($anneeId === null) {
            return [$seance];
        }

        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();
        $regroupementId                 = $regroupementParClasseEtMatiere[$classeId][$matiereId] ?? null;
        $groupeOptionnel                = $attribution->getMatiere()->getGroupeOptionnel();

        if ($regroupementId === null && $groupeOptionnel === null) {
            // Attribution isolée (cas le plus fréquent) : pas de sœur possible, on
            // s'épargne la requête.
            return [$seance];
        }

        $candidates = $this->seanceRepo->findByCreneauEtAnnee((int) $seance->getCreneau()->getId(), (int) $anneeId);

        $affectees = [];
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $seance->getId()) {
                $affectees[] = $candidate;
                continue;
            }

            $a = $candidate->getAttribution();

            $memeFusion = $regroupementId !== null
                && ($regroupementParClasseEtMatiere[$a->getClasse()->getId()][$a->getMatiere()->getId()] ?? null) === $regroupementId
                && $a->getMatiere()->getId() === $matiereId;

            $memeParallele = $groupeOptionnel !== null
                && $a->getClasse()->getId() === $classeId
                && $a->getMatiere()->getGroupeOptionnel() === $groupeOptionnel;

            if ($memeFusion || $memeParallele) {
                $affectees[] = $candidate;
            }
        }

        return $affectees;
    }

    /**
     * Vérifie qu'aucune séance du groupe à verrouiller n'entre en conflit avec une
     * séance DÉJÀ verrouillée — voir la docblock de la classe pour le pourquoi. Même
     * logique de "cohérence fusion/parallèle" que EmploiDuTempsPermutationService
     * (un enseignant/une salle partagés entre les 2 moitiés d'une même paire
     * fusionnée/parallèle n'est PAS un conflit, c'est la même séance pédagogique).
     *
     * @param Seance[] $groupe
     * @return string[]
     */
    private function validerCoherenceAvecVerrouillees(array $groupe): array
    {
        $anneeId = $groupe[0]->getAttribution()->getClasse()->getAnneeScolaire()?->getId();
        if ($anneeId === null) {
            return [];
        }

        $groupeIds        = array_map(static fn (Seance $s) => $s->getId(), $groupe);
        $dejaVerrouillees = array_values(array_filter(
            $this->seanceRepo->findVerrouilleesByAnneeScolaire((int) $anneeId),
            static fn (Seance $s) => !in_array($s->getId(), $groupeIds, true),
        ));

        if ($dejaVerrouillees === []) {
            return [];
        }

        $regroupementParClasseEtMatiere = $this->regroupementRepo->indexerParClasseEtMatiere();

        $estCoherente = static function (Seance $a, Seance $b) use ($regroupementParClasseEtMatiere): bool {
            $aa = $a->getAttribution();
            $ab = $b->getAttribution();

            $ridA = $regroupementParClasseEtMatiere[$aa->getClasse()->getId()][$aa->getMatiere()->getId()] ?? null;
            $ridB = $regroupementParClasseEtMatiere[$ab->getClasse()->getId()][$ab->getMatiere()->getId()] ?? null;
            if ($ridA !== null && $ridA === $ridB) {
                return true; // classes fusionnées, même matière
            }

            $goA = $aa->getMatiere()->getGroupeOptionnel();
            $goB = $ab->getMatiere()->getGroupeOptionnel();

            return $goA !== null && $goA === $goB && $aa->getClasse()->getId() === $ab->getClasse()->getId();
        };

        $erreurs = [];
        foreach ($groupe as $s) {
            $attribution = $s->getAttribution();

            foreach ($dejaVerrouillees as $autre) {
                if ($autre->getCreneau()->getId() !== $s->getCreneau()->getId() || $estCoherente($s, $autre)) {
                    continue;
                }

                $autreAttribution = $autre->getAttribution();
                $memeClasse       = $autreAttribution->getClasse()->getId() === $attribution->getClasse()->getId();

                if (!$memeClasse && $autreAttribution->getEnseignant()->getId() === $attribution->getEnseignant()->getId()) {
                    $erreurs[] = \sprintf(
                        '%s serait dans deux classes différentes (%s et %s) au même créneau : « %s » y est déjà verrouillée.',
                        $attribution->getEnseignant()->getNomComplet(),
                        $attribution->getClasse()->getNom(),
                        $autreAttribution->getClasse()->getNom(),
                        $autreAttribution->getMatiere()->getNom(),
                    );
                }
                if (!$memeClasse && $autre->getSalle()->getId() === $s->getSalle()->getId()) {
                    $erreurs[] = \sprintf(
                        'La salle %s serait utilisée par deux classes différentes (%s et %s) au même créneau : « %s » y est déjà verrouillée.',
                        $s->getSalle()->getNom(),
                        $attribution->getClasse()->getNom(),
                        $autreAttribution->getClasse()->getNom(),
                        $autreAttribution->getMatiere()->getNom(),
                    );
                }
                if ($memeClasse) {
                    // Même prof et/ou même salle : peu importe le détail, pour CETTE
                    // classe le message "deux séances non liées" seul est plus clair
                    // qu'une répétition "classes différentes (X et X)".
                    $erreurs[] = \sprintf(
                        '%s aurait deux séances non liées au même créneau : « %s » y est déjà verrouillée.',
                        $attribution->getClasse()->getNom(),
                        $autreAttribution->getMatiere()->getNom(),
                    );
                }
            }
        }

        return array_values(array_unique($erreurs));
    }
}
