<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Support\Parametres;
use App\Support\Tri;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/** Validation des paramètres d'URL de GET /tickets : filtres, tri, pagination, inclusions. */
class IndexTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // l'accès est géré par la Policy + visiblePour()
    }

    public function rules(): array
    {
        return [
            'statut' => ['nullable', 'string', $this->dansListe(Ticket::STATUTS)],
            'priorite' => ['nullable', 'string', $this->dansListe(Ticket::PRIORITES)],
            'agent_id' => ['nullable', 'regex:/^(aucun|\d+)$/'],
            'auteur_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:50'],
            'cree_apres' => ['nullable', 'date_format:Y-m-d'],
            'cree_avant' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:cree_apres'],
            'en_retard' => ['nullable', 'boolean'],
            'tri' => ['nullable', 'string', 'max:100', function (string $attribut, mixed $valeur, Closure $echec) {
                try {
                    $tri = Tri::analyser((string) $valeur, Ticket::TRIS_AUTORISES);
                } catch (InvalidArgumentException $e) {
                    $echec($e->getMessage());

                    return;
                }
                // La pagination par curseur ne sait pas trier sur une expression calculée (priorité = CASE ...)
                if ($this->input('pagination') === 'curseur' && in_array('priorite', array_column($tri, 0), true)) {
                    $echec('Le tri par priorité n\'est pas disponible avec pagination=curseur.');
                }
            }],
            'pagination' => ['nullable', 'in:page,curseur'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:500'],
            'inclure' => ['nullable', 'string', $this->dansListe(Ticket::INCLUSIONS_AUTORISEES)],
        ];
    }

    private function dansListe(array $autorises): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($autorises) {
            foreach (Parametres::liste((string) $valeur) as $element) {
                if (! in_array($element, $autorises, true)) {
                    $echec("La valeur « {$element} » n'est pas valide pour :attribute. Valeurs possibles : " . implode(', ', $autorises) . '.');
                }
            }
        };
    }
}
