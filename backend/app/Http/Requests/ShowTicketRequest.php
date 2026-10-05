<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Support\Parametres;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** GET /tickets/{id}?inclure=auteur,tags,commentaires */
class ShowTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inclure' => ['nullable', 'string', function (string $attribut, mixed $valeur, Closure $echec) {
                foreach (Parametres::liste((string) $valeur) as $element) {
                    if (! in_array($element, Ticket::INCLUSIONS_AUTORISEES, true)) {
                        $echec("La valeur « {$element} » n'est pas valide pour :attribute. Valeurs possibles : " . implode(', ', Ticket::INCLUSIONS_AUTORISEES) . '.');
                    }
                }
            }],
        ];
    }
}
