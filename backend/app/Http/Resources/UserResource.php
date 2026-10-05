<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Un utilisateur vu par les autres : email et rôle réservés aux agents/admins (minimisation des données). */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $demandeur = $request->user();
        $detail = $demandeur && ($demandeur->estAgent() || $demandeur->id === $this->id);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when($detail, $this->email),
            'role' => $this->when($detail, $this->role),
            'tickets_count' => $this->whenCounted('ticketsCrees'),
        ];
    }
}
