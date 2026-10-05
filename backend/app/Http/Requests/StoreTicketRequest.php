<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /tickets : création. Corps JSON. */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titre' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'priorite' => ['nullable', Rule::in(Ticket::PRIORITES)],
            'echeance' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer', 'distinct', 'exists:tags,id'],
        ];
    }
}
