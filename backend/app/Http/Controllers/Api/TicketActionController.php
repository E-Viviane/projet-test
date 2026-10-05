<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Support\Transitions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Actions métier sur un ticket, exposées comme des SOUS-RESSOURCES (noms, pas verbes) :
 *   POST   /tickets/{id}/assignation   -> assigner      DELETE /tickets/{id}/assignation -> désassigner
 *   POST   /tickets/{id}/transitions   -> changer de statut (règles de la machine à états)
 *   PUT    /tickets/{id}/tags          -> remplacer l'ensemble des tags (idempotent)
 *   POST   /tickets/actions-groupees   -> changer le statut de plusieurs tickets (207)
 */
class TicketActionController extends Controller
{
    public function assigner(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            // On ne peut assigner qu'à un agent ou un admin
            'agent_id' => ['required', 'integer', Rule::exists('users', 'id')->whereIn('role', ['agent', 'admin'])],
        ]);

        if ($ticket->statut === 'ferme') {
            throw new ErreurApi(409, 'ticket_ferme', 'Un ticket fermé ne peut plus être assigné.');
        }

        $ticket->agent_id = $data['agent_id'];
        $ticket->save();

        return new TicketResource($ticket->load(['auteur', 'agent', 'tags']));
    }

    public function desassigner(Ticket $ticket): Response
    {
        $this->authorize('assign', $ticket);
        $ticket->agent_id = null;
        $ticket->save();

        return response()->noContent();
    }

    public function transition(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('transition', $ticket);

        $data = $request->validate(['statut' => ['required', Rule::in(Ticket::STATUTS)]]);

        if (! Transitions::autorise($ticket->statut, $data['statut'])) {
            // 409 Conflict : la requête est valide, mais incompatible avec l'ÉTAT actuel de la ressource
            throw new ErreurApi(
                409,
                'transition_invalide',
                "Impossible de passer de « {$ticket->statut} » à « {$data['statut']} ».",
                ['statut_actuel' => $ticket->statut, 'transitions_permises' => Transitions::permises($ticket->statut)]
            );
        }

        $this->appliquerStatut($ticket, $data['statut']);

        return new TicketResource($ticket->load(['auteur', 'agent', 'tags']));
    }

    public function synchroniserTags(Request $request, Ticket $ticket): TicketResource
    {
        $this->authorize('update', $ticket);

        $data = $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['integer', 'distinct', 'exists:tags,id'],
        ]);

        $ticket->tags()->sync($data['tags']);

        return new TicketResource($ticket->load(['auteur', 'agent', 'tags']));
    }

    /** Succès partiel : 207 Multi-Status avec le résultat de CHAQUE ticket. */
    public function actionsGroupees(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'statut' => ['required', Rule::in(Ticket::STATUTS)],
        ]);

        $tickets = Ticket::whereIn('id', $data['ids'])->get()->keyBy('id');
        $details = [];
        $ok = 0;

        foreach ($data['ids'] as $id) {
            $ticket = $tickets->get($id);
            if (! $ticket) {
                $details[] = ['id' => $id, 'resultat' => 'introuvable'];
            } elseif (! Transitions::autorise($ticket->statut, $data['statut'])) {
                $details[] = ['id' => $id, 'resultat' => 'ignore', 'raison' => "transition {$ticket->statut} -> {$data['statut']} interdite"];
            } else {
                $this->appliquerStatut($ticket, $data['statut']);
                $details[] = ['id' => $id, 'resultat' => 'ok'];
                $ok++;
            }
        }

        return response()->json([
            'data' => ['mis_a_jour' => $ok, 'ignores' => count($details) - $ok, 'details' => $details],
        ], 207);
    }

    private function appliquerStatut(Ticket $ticket, string $statut): void
    {
        $ticket->statut = $statut;
        // resolu_le : date de résolution (effacée si le ticket est rouvert)
        $ticket->resolu_le = in_array($statut, ['resolu', 'ferme'], true) ? ($ticket->resolu_le ?? now()) : null;
        $ticket->save();
    }
}
