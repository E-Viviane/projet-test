<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PieceJointeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom_original,
            'mime' => $this->mime,
            'taille' => $this->taille,
            'depose_par' => $this->depose_par,
            'cree_le' => $this->created_at?->toIso8601String(),
            'liens' => ['telecharger' => route('v1.pieces.telecharger', $this->id)],
        ];
    }
}
