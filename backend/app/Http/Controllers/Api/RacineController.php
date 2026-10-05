<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** GET /api/v1 : point d'entrée qui liste les ressources (découvrabilité, « HATEOAS » simplifié). */
class RacineController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'nom' => 'API Helpdesk',
            'version' => 'v1',
            'liens' => [
                'sante' => route('v1.sante'),
                'inscription' => route('v1.auth.inscription'),
                'connexion' => route('v1.auth.connexion'),
                'moi' => route('v1.auth.moi'),
                'tickets' => route('v1.tickets.index'),
                'tags' => route('v1.tags.index'),
                'statistiques' => route('v1.statistiques'),
                'documentation' => url('/openapi.yaml'),
            ],
        ]);
    }
}
