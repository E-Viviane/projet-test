<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence des créations : si le client renvoie la MÊME requête avec le MÊME en-tête
 *   Idempotency-Key: <valeur unique>
 * (réseau coupé, double clic, nouvel essai automatique), on rejoue la réponse mémorisée au lieu de créer un doublon.
 * Seules les requêtes POST / PATCH portant l'en-tête sont concernées.
 */
class IdempotenceApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $cle = $request->header('Idempotency-Key');
        if ($cle === null || ! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return $next($request);
        }
        if (! is_string($cle) || ! preg_match('/^[A-Za-z0-9\-_]{8,100}$/', $cle)) {
            throw new ErreurApi(400, 'cle_idempotence_invalide', 'Idempotency-Key : 8 à 100 caractères (lettres, chiffres, - et _).');
        }

        $empreinte = sha1($request->method() . '|' . $request->path() . '|' . $request->getContent());
        $clef = 'idem:' . ($request->user()?->getAuthIdentifier() ?? $request->ip()) . ':' . sha1($cle);

        try {
            // Le verrou évite que deux requêtes simultanées avec la même clé s'exécutent toutes les deux.
            return Cache::lock($clef . ':verrou', 10)->block(5, function () use ($request, $next, $clef, $empreinte) {
                $memo = Cache::get($clef);
                if (is_array($memo)) {
                    if ($memo['empreinte'] !== $empreinte) {
                        throw new ErreurApi(422, 'cle_idempotence_reutilisee', 'Cette Idempotency-Key a déjà servi pour une requête différente.');
                    }
                    $rejeu = response($memo['contenu'], $memo['statut'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
                    if ($memo['location']) {
                        $rejeu->header('Location', $memo['location']);
                    }

                    return $rejeu;
                }

                $reponse = $next($request);
                // Seules les réussites sont mémorisées : après une erreur 4xx, le client peut corriger sa requête et réessayer avec la même clé
                if ($reponse->isSuccessful()) {
                    Cache::put($clef, [
                        'empreinte' => $empreinte,
                        'statut' => $reponse->getStatusCode(),
                        'contenu' => $reponse->getContent(),
                        'location' => $reponse->headers->get('Location'),
                    ], now()->addHours(24));
                }

                return $reponse;
            });
        } catch (LockTimeoutException) {
            throw new ErreurApi(409, 'requete_en_cours', 'Une requête identique est déjà en cours de traitement.');
        }
    }
}
