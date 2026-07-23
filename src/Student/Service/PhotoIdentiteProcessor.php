<?php

declare(strict_types=1);

namespace App\Student\Service;

/**
 * Recadre une photo uploadée au format "photo d'identité" (portrait, ratio 3:4) avant de
 * l'enregistrer. Nécessaire côté serveur : dompdf ne respecte pas fiablement `object-fit:
 * cover` en CSS (il étire l'image entière dans la boîte plutôt que de la recadrer), donc le
 * recadrage doit être fait une fois pour toutes sur le fichier stocké plutôt que délégué à
 * l'affichage.
 */
final class PhotoIdentiteProcessor
{
    private const LARGEUR_SORTIE = 300;
    private const HAUTEUR_SORTIE = 400; // ratio 3:4, format photo d'identité
    private const QUALITE_JPEG   = 85;

    /** Toujours enregistrée en JPEG (ré-encodée au recadrage), quel que soit le format d'origine. */
    public function traiter(string $cheminSource, string $cheminDestination): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($cheminSource));
        if ($source === false) {
            copy($cheminSource, $cheminDestination); // image illisible par GD : on garde l'original tel quel
            return;
        }

        $largeurSource = imagesx($source);
        $hauteurSource = imagesy($source);
        $ratioCible    = self::LARGEUR_SORTIE / self::HAUTEUR_SORTIE;

        if ($largeurSource / $hauteurSource > $ratioCible) {
            // Image trop large pour le ratio cible : on recadre les côtés (garde toute la hauteur).
            $hauteurRecadree = $hauteurSource;
            $largeurRecadree = (int) round($hauteurSource * $ratioCible);
        } else {
            // Image trop haute : on recadre en haut/bas (garde toute la largeur).
            $largeurRecadree = $largeurSource;
            $hauteurRecadree = (int) round($largeurSource / $ratioCible);
        }

        $decalageX = (int) (($largeurSource - $largeurRecadree) / 2);
        $decalageY = (int) (($hauteurSource - $hauteurRecadree) / 2);

        $sortie = imagecreatetruecolor(self::LARGEUR_SORTIE, self::HAUTEUR_SORTIE);
        imagecopyresampled(
            $sortie,
            $source,
            0,
            0,
            $decalageX,
            $decalageY,
            self::LARGEUR_SORTIE,
            self::HAUTEUR_SORTIE,
            $largeurRecadree,
            $hauteurRecadree,
        );

        imagejpeg($sortie, $cheminDestination, self::QUALITE_JPEG);

        imagedestroy($source);
        imagedestroy($sortie);
    }
}
