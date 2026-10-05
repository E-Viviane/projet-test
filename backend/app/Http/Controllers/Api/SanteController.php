<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** GET /sante : sonde de supervision (monitoring, Docker healthcheck, load balancer). 200 si tout va bien, 503 sinon. */
class SanteController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $base = 'ok';
        } catch (Throwable) {
            $base = 'indisponible';
        }

        // Aucun détail interne (version, chemin, message d'erreur) n'est exposé publiquement
        return response()->json(
            ['statut' => $base === 'ok' ? 'ok' : 'degrade', 'base_de_donnees' => $base, 'heure' => now()->toIso8601String()],
            $base === 'ok' ? 200 : 503
        );
    }
}
