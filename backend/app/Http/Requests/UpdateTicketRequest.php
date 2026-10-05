<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT   /tickets/{id} : REMPLACEMENT complet -> champs obligatoires.
 * PATCH /tickets/{id} : modification PARTIELLE -> seuls les champs envoyés sont validés (« sometimes »).
 * Le statut n'est pas modifiable ici : il passe par POST /tickets/{id}/transitions (règles métier).
 */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $put = $this->isMethod('PUT');
        $presence = $put ? 'required' : 'sometimes';
        // PUT = remplacement COMPLET : échéance et tags doivent aussi être présents (éventuellement null / vide)
        $presentSiPut = $put ? 'present' : 'sometimes';

        return [
            'titre' => [$presence, 'string', 'min:3', 'max:150'],
            'description' => [$presence, 'string', 'min:5', 'max:5000'],
            'priorite' => [$presence, Rule::in(Ticket::PRIORITES)],
            'echeance' => [$presentSiPut, 'nullable', 'date_format:Y-m-d'],
            'tags' => [$presentSiPut, 'nullable', 'array', 'max:10'],
            'tags.*' => ['integer', 'distinct', 'exists:tags,id'],
        ];
    }
}
