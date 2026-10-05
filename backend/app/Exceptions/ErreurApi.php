<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Erreur « métier » rendue au format JSON uniforme de l'API :
 *   { "message": "...", "code": "transition_invalide", ...détails }
 * Le champ « code » est stable (le client peut s'y fier) ; « message » est pour l'humain.
 */
class ErreurApi extends RuntimeException
{
    public function __construct(
        public readonly int $statut,
        public readonly string $codeErreur,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->codeErreur] + $this->details,
            $this->statut
        );
    }
}
