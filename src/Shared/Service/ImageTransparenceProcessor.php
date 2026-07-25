<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Retire le fond blanc d'un scan (signature au stylo ou cachet encré sur papier) pour
 * produire un PNG à fond transparent, sans passer par un service en ligne (image sensible :
 * sert à authentifier des documents officiels — bulletins). Le seuillage se fait en douceur
 * (alpha progressif entre les deux bornes) pour éviter un contour crénelé autour du trait
 * d'encre, et la couleur d'origine du trait est conservée (un cachet est souvent encré en
 * bleu/violet, pas en noir).
 */
final class ImageTransparenceProcessor
{
    private const SEUIL_BLANC     = 235; // luminosité au-dessus : transparent
    private const SEUIL_ENCRE     = 130; // luminosité en-dessous : totalement opaque
    private const DIMENSION_MAX   = 1000; // redimensionne avant traitement : largement assez pour une signature/cachet imprimé en petit sur un bulletin, et bien plus rapide (boucle pixel par pixel)

    public function traiter(string $cheminSource, string $cheminDestination): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($cheminSource));
        if ($source === false) {
            throw new \RuntimeException('Image illisible.');
        }

        $source = $this->redimensionnerSiNecessaire($source);

        $largeur = imagesx($source);
        $hauteur = imagesy($source);

        $sortie = imagecreatetruecolor($largeur, $hauteur);
        imagealphablending($sortie, false);
        imagesavealpha($sortie, true);

        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                $rgb = imagecolorat($source, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $luminosite = (int) round(($r + $g + $b) / 3);

                if ($luminosite >= self::SEUIL_BLANC) {
                    $alpha = 127; // GD : 127 = totalement transparent, 0 = opaque
                } elseif ($luminosite <= self::SEUIL_ENCRE) {
                    $alpha = 0;
                } else {
                    $ratio = ($luminosite - self::SEUIL_ENCRE) / (self::SEUIL_BLANC - self::SEUIL_ENCRE);
                    $alpha = (int) round($ratio * 127);
                }

                $couleur = imagecolorallocatealpha($sortie, $r, $g, $b, $alpha);
                imagesetpixel($sortie, $x, $y, $couleur);
            }
        }

        imagepng($sortie, $cheminDestination);

        imagedestroy($source);
        imagedestroy($sortie);
    }

    /** @param \GdImage $source */
    private function redimensionnerSiNecessaire(\GdImage $source): \GdImage
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $plusGrandCote = max($largeur, $hauteur);

        if ($plusGrandCote <= self::DIMENSION_MAX) {
            return $source;
        }

        $echelle = self::DIMENSION_MAX / $plusGrandCote;
        $nouvelleLargeur = (int) round($largeur * $echelle);
        $nouvelleHauteur = (int) round($hauteur * $echelle);

        $redimensionnee = imagecreatetruecolor($nouvelleLargeur, $nouvelleHauteur);
        imagecopyresampled($redimensionnee, $source, 0, 0, 0, 0, $nouvelleLargeur, $nouvelleHauteur, $largeur, $hauteur);
        imagedestroy($source);

        return $redimensionnee;
    }
}
