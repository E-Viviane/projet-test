<?php

namespace App\Support;

/** Petits utilitaires de lecture de paramètres d'URL (purs, testés). */
final class Parametres
{
    /** « a, b,,a » -> ['a','b'] */
    public static function liste(?string $valeur): array
    {
        if ($valeur === null || trim($valeur) === '') {
            return [];
        }
        $elements = array_map('trim', explode(',', $valeur));

        return array_values(array_unique(array_filter($elements, fn ($e) => $e !== '')));
    }

    /** Nombre d'éléments par page, toujours borné (jamais ?per_page=1000000). */
    public static function parPage(mixed $valeur, int $defaut = 15, int $max = 100): int
    {
        if (! is_numeric($valeur)) {
            return $defaut;
        }

        return max(1, min($max, (int) $valeur));
    }
}
