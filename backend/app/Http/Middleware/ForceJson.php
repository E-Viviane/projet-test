<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1. Une API répond toujours en JSON (même sans en-tête Accept).
 * 2. Chaque requête reçoit un identifiant (X-Request-Id), renvoyé au client et inscrit dans les logs :
 *    c'est ce qui permet à un utilisateur de dire « voici l'identifiant de mon erreur » et au support de retrouver les lignes.
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $id = $request->header('X-Request-Id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $id)) {
            $id = (string) Str::uuid();
        }
        Log::withContext(['request_id' => $id]);

        $reponse = $next($request);
        $reponse->headers->set('X-Request-Id', $id);

        return $reponse;
    }
}
