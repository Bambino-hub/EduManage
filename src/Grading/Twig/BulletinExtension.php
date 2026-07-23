<?php

declare(strict_types=1);

namespace App\Grading\Twig;

use App\Grading\Enum\MentionConseil;
use App\Grading\Service\AppreciationScale;
use App\Staff\Enum\Sexe;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Filtres/fonctions Twig propres au rendu du bulletin PDF (voir admin/bulletin/pdf/bulletin.html.twig). */
final class BulletinExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('moyenne_en_lettres', $this->moyenneEnLettres(...)),
            new TwigFilter('appreciation', AppreciationScale::pour(...)),
            new TwigFilter('mentions_auto', $this->mentionsAutomatiques(...)),
            new TwigFilter('rang_ordinal', $this->rangOrdinal(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('qr_data_uri', $this->qrDataUri(...)),
        ];
    }

    /**
     * "15.43" -> "quinze virgule quarante-trois". Épelle séparément la partie entière et la
     * partie décimale (comme sur le bulletin papier), via le spellout ICU français — ext-intl
     * est disponible sur le serveur.
     */
    public function moyenneEnLettres(?string $moyenne): ?string
    {
        if ($moyenne === null) {
            return null;
        }

        [$entier, $decimale] = array_pad(explode('.', $moyenne), 2, '0');

        $formatter = new \NumberFormatter('fr', \NumberFormatter::SPELLOUT);

        return $formatter->format((int) $entier).' virgule '.$formatter->format((int) $decimale);
    }

    /**
     * Mentions du conseil déduites automatiquement de la moyenne générale (seuils confirmés
     * avec le collège) : Félicitations + Tableau d'honneur à partir de 16, Tableau d'honneur
     * seul à partir de 14, Encouragements à partir de 12. Avertissement et Blâme restent
     * purement manuels (discipline, pas déductible des notes) — voir ComplementBulletinType,
     * qui ne propose plus que ces deux-là ; celles-ci continuent d'être combinées avec le
     * résultat de ce filtre dans le template (`mention in mentionsAuto or mention in
     * bulletin.mentions`).
     *
     * @return MentionConseil[]
     */
    public function mentionsAutomatiques(?string $moyenne): array
    {
        if ($moyenne === null) {
            return [];
        }

        $valeur = (float) $moyenne;

        return match (true) {
            $valeur >= 16 => [MentionConseil::FELICITATIONS, MentionConseil::TABLEAU_HONNEUR],
            $valeur >= 14 => [MentionConseil::TABLEAU_HONNEUR],
            $valeur >= 12 => [MentionConseil::ENCOURAGEMENTS],
            default => [],
        };
    }

    /**
     * "1er"/"1ère" (selon le sexe de l'élève, masculin par défaut si inconnu), "2ème",
     * "3ème"... Null si aucun rang (élève non classé).
     */
    public function rangOrdinal(?int $rang, ?Sexe $sexe): ?string
    {
        if ($rang === null) {
            return null;
        }

        if ($rang === 1) {
            return $sexe === Sexe::F ? '1ère' : '1er';
        }

        return $rang.'ème';
    }

    /**
     * QR code encodé en data URI (utilisable directement en src d'un <img>, dompdf n'a donc
     * pas besoin d'accéder à un fichier). Contenu actuel : une référence texte du bulletin
     * (pas de portail de vérification en ligne pour l'instant) — à faire pointer vers une
     * vraie page de vérification le jour où elle existera.
     */
    public function qrDataUri(string $contenu, int $taille = 50): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $contenu,
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: $taille,
            margin: 0,
        );

        return $builder->build()->getDataUri();
    }
}
