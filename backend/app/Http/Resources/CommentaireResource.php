<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agent = (bool) $request->user()?->estAgent();

        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'auteur' => new UserResource($this->whenLoaded('auteur')),
            'contenu' => $this->contenu,
            'interne' => $this->when($agent, $this->interne),
            'cree_le' => $this->created_at?->toIso8601String(),
            'modifie_le' => $this->updated_at?->toIso8601String(),
        ];
    }
}
