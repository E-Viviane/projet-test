<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * GET /demo/codes/{code} : provoque volontairement un code HTTP pour s'entraîner à lire les réponses.
 * Désactivé hors environnements local / testing.
 */
class DemoController extends Controller
{
    public function codes(int $code)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return match ($code) {
            200 => response()->json(['message' => 'OK : la requête a réussi.']),
            201 => response()->json(['message' => 'Created : une ressource a été créée.'], 201, ['Location' => url('/api/v1/demo/ressource/1')]),
            204 => response()->noContent(),
            400 => throw new ErreurApi(400, 'requete_invalide', 'Bad Request : la requête est mal formée.'),
            401 => throw new AuthenticationException(),
            403 => throw new AuthorizationException(),
            404 => throw new NotFoundHttpException(),
            409 => throw new ErreurApi(409, 'conflit', 'Conflict : incompatible avec l\'état actuel de la ressource.'),
            422 => throw ValidationException::withMessages(['champ' => 'Unprocessable Entity : donnée invalide.']),
            429 => throw new TooManyRequestsHttpException(30),
            500 => throw new RuntimeException('Erreur de démonstration'),
            503 => throw new ServiceUnavailableHttpException(30, 'Service indisponible.'),
            default => throw new ErreurApi(400, 'code_non_gere', 'Codes disponibles : 200, 201, 204, 400, 401, 403, 404, 409, 422, 429, 500, 503.'),
        };
    }
}
