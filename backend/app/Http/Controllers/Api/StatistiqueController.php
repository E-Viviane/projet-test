<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

/** GET /statistiques : agrégats calculés par la base (GROUP BY), pas en PHP. */
class StatistiqueController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $parStatut = Ticket::query()->selectRaw('statut, COUNT(*) AS total')->groupBy('statut')->pluck('total', 'statut');
        $parPriorite = Ticket::query()->selectRaw('priorite, COUNT(*) AS total')->groupBy('priorite')->pluck('total', 'priorite');

        // Statuts / priorités sans ticket : on renvoie 0 plutôt que d'omettre la clé (contrat stable pour le client)
        $parStatut = array_replace(array_fill_keys(Ticket::STATUTS, 0), $parStatut->map(fn ($n) => (int) $n)->all());
        $parPriorite = array_replace(array_fill_keys(Ticket::PRIORITES, 0), $parPriorite->map(fn ($n) => (int) $n)->all());

        // Créations par jour sur 30 jours. Borne ouverte sur created_at (index utilisable) ; GROUP BY sur l'alias
        $parJour = Ticket::query()
            ->selectRaw('DATE(created_at) AS jour, COUNT(*) AS total')
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupBy('jour')->orderBy('jour')
            ->get()->map(fn ($l) => ['jour' => $l->jour, 'total' => (int) $l->total]);

        // Charge de travail : tickets ouverts par agent
        $charge = Ticket::query()
            ->selectRaw('agent_id, COUNT(*) AS total')
            ->whereNotNull('agent_id')->whereNotIn('statut', ['resolu', 'ferme'])
            ->groupBy('agent_id')->orderByDesc('total')->orderBy('agent_id')->limit(5)
            ->with('agent:id,name')->get()
            ->map(fn ($l) => ['agent' => $l->agent?->name, 'tickets_ouverts' => (int) $l->total]);

        $enRetard = Ticket::query()->whereNotNull('echeance')
            ->where('echeance', '<', now()->toDateString())->whereNotIn('statut', ['resolu', 'ferme'])->count();

        return response()->json(['data' => [
            'total' => array_sum($parStatut),
            'par_statut' => $parStatut,
            'par_priorite' => $parPriorite,
            'en_retard' => $enRetard,
            'crees_30_jours' => $parJour,
            'charge_agents' => $charge,
        ]]);
    }
}
