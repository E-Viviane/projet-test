<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation JSON d'un ticket. La Resource fait la frontière entre le modèle interne (colonnes de la base)
 * et le contrat public de l'API : on peut renommer une colonne sans casser les clients.
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $u = $request->user();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'titre' => $this->titre,
            'description' => $this->description,
            'statut' => $this->statut,
            'priorite' => $this->priorite,
            'echeance' => $this->echeance?->toDateString(),
            'en_retard' => $this->estEnRetard(),
            'resolu_le' => $this->resolu_le?->toIso8601String(),
            'cree_le' => $this->created_at?->toIso8601String(),
            'modifie_le' => $this->updated_at?->toIso8601String(),
            'supprime_le' => $this->when($this->deleted_at !== null, fn () => $this->deleted_at->toIso8601String()),

            // Relations : présentes seulement si chargées (?inclure=...) -> pas de N+1 caché
            'auteur' => new UserResource($this->whenLoaded('auteur')),
            'agent' => new UserResource($this->whenLoaded('agent')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'commentaires' => CommentaireResource::collection($this->whenLoaded('commentaires')),
            'pieces_jointes' => PieceJointeResource::collection($this->whenLoaded('piecesJointes')),
            'commentaires_count' => $this->whenCounted('commentaires'),

            // Ce que l'utilisateur courant a le droit de faire (le client peut masquer les boutons)
            'permissions' => $this->when($u !== null, fn () => [
                'modifier' => $u->can('update', $this->resource),
                'supprimer' => $u->can('delete', $this->resource),
            ]),

            // Liens (HATEOAS simplifié)
            'liens' => $this->when(! $this->deleted_at, fn () => [
                'self' => route('v1.tickets.show', $this->id),
                'commentaires' => route('v1.tickets.commentaires.index', $this->id),
            ]),
        ];
    }
}
