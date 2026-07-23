<?php

declare(strict_types=1);

namespace App\Grading\Security;

use App\Scheduling\Entity\Attribution;
use App\Security\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorise la gestion des évaluations/notes d'une Attribution (enseignant × matière ×
 * classe) : un administrateur peut toujours corriger, un enseignant uniquement sur ses
 * propres attributions. Premier Voter de l'application — jusqu'ici le contrôle d'accès
 * se limitait aux préfixes d'URL (`/admin`, `/enseignant`), insuffisant ici puisque
 * "ROLE_ENSEIGNANT" seul ne dit pas QUELLE attribution lui appartient.
 */
class AttributionVoter extends Voter
{
    public const string GERER_NOTES = 'GERER_NOTES';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::GERER_NOTES && $subject instanceof Attribution;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Attribution $attribution */
        $attribution = $subject;

        if (in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $enseignant = $utilisateur->getEnseignant();

        return $enseignant !== null && $enseignant->getId() === $attribution->getEnseignant()->getId();
    }
}
