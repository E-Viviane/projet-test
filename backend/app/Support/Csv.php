<?php

namespace App\Support;

/**
 * Export CSV sûr. Un tableur interprète une cellule commençant par = + - @ comme une FORMULE
 * (« injection CSV ») : on la neutralise en préfixant par une apostrophe.
 */
final class Csv
{
    public static function cellule(string|int|float|bool|null $valeur): string
    {
        if ($valeur === null) {
            return '';
        }
        if (is_bool($valeur)) {
            return $valeur ? '1' : '0';
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (string) $valeur;
        }
        if ($valeur !== '' && in_array($valeur[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $valeur;
        }

        return $valeur;
    }

    /** @param list<string|int|float|bool|null> $ligne */
    public static function ligne(array $ligne): array
    {
        return array_map(fn ($v) => self::cellule($v), $ligne);
    }
}
