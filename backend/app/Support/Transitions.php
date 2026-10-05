<?php

namespace App\Support;

/**
 * Machine à états d'un ticket. Classe « pure » (aucune dépendance Laravel) : testable seule.
 * nouveau -> en_cours -> en_attente <-> en_cours -> resolu -> ferme   (resolu peut être rouvert)
 */
final class Transitions
{
    public const MATRICE = [
        'nouveau'    => ['en_cours', 'ferme'],
        'en_cours'   => ['en_attente', 'resolu'],
        'en_attente' => ['en_cours', 'resolu'],
        'resolu'     => ['ferme', 'en_cours'],
        'ferme'      => [],
    ];

    /** @return list<string> */
    public static function permises(string $de): array
    {
        return self::MATRICE[$de] ?? [];
    }

    public static function autorise(string $de, string $vers): bool
    {
        return in_array($vers, self::permises($de), true);
    }

    public static function estTermine(string $statut): bool
    {
        return in_array($statut, ['resolu', 'ferme'], true);
    }
}
