<?php

declare(strict_types=1);

namespace App\ExamenNational\Enum;

/** BROUILLON : import en cours ou en attente de vérification, invisible dans les statistiques. */
enum StatutSessionExamenNational: string
{
    case BROUILLON = 'brouillon';
    case VALIDE = 'valide';
}
