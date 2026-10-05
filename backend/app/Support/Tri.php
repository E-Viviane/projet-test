<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Lit le paramètre de tri d'une URL : ?tri=-created_at,priorite
 *   « - » devant = décroissant ; sinon croissant. Seules les colonnes de la liste blanche sont acceptées
 *   (jamais de nom de colonne venant de l'utilisateur directement dans une requête SQL).
 */
final class Tri
{
    public const MAX_CRITERES = 4;

    /**
     * @param  list<string>  $autorises
     * @return list<array{0:string,1:string}>  ex. [['created_at','desc'], ['titre','asc']]
     */
    public static function analyser(?string $param, array $autorises, ?string $defaut = null): array
    {
        $param = trim((string) $param);
        if ($param === '') {
            $param = (string) $defaut;
        }

        $resultat = [];
        $vus = [];
        foreach (explode(',', $param) as $element) {
            $element = trim($element);
            if ($element === '') {
                continue;
            }
            $sens = 'asc';
            if ($element[0] === '-') {
                $sens = 'desc';
                $element = substr($element, 1);
            } elseif ($element[0] === '+') {      // dans une URL, « + » peut arriver comme un espace
                $element = substr($element, 1);
            }
            if (! in_array($element, $autorises, true)) {
                throw new InvalidArgumentException(
                    "Tri non autorisé : « {$element} ». Valeurs possibles : " . implode(', ', $autorises) . '.'
                );
            }
            if (isset($vus[$element])) {
                continue;
            }
            $vus[$element] = true;
            $resultat[] = [$element, $sens];
        }

        if (count($resultat) > self::MAX_CRITERES) {
            throw new InvalidArgumentException('Trop de critères de tri (maximum ' . self::MAX_CRITERES . ').');
        }

        return $resultat;
    }
}
