<?php

use App\Exceptions\ErreurApi;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\IdempotenceApi;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exécuté en premier sur toutes les routes /api : JSON forcé + X-Request-Id
        $middleware->api(prepend: [ForceJson::class]);
        // Limite de débit « api » (voir AppServiceProvider) sur toutes les routes /api
        $middleware->throttleApi();
        // Alias utilisables dans les routes : ->middleware('ability:statistiques:lire')
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'idempotence' => IdempotenceApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Les erreurs métier prévues (4xx) ne remplissent pas le fichier de logs de stack traces
        $exceptions->dontReport(ErreurApi::class);

        // Toute erreur sur /api répond en JSON
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Format d'erreur uniforme : { "message": "...", "code": "...", (+ détails éventuels) }
        $erreur = fn (string $code, string $message, int $statut, array $details = [], array $entetes = []) =>
            response()->json(['message' => $message, 'code' => $code] + $details, $statut, $entetes);

        // 401 : pas de jeton, jeton invalide ou expiré
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('non_authentifie', 'Authentification requise ou jeton invalide.', 401, [], ['WWW-Authenticate' => 'Bearer']);
            }
        });

        // 403 : le jeton n'a pas la capacité (« ability ») demandée
        $exceptions->render(function (MissingAbilityException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('capacite_manquante', "Ce jeton n'a pas la capacité requise pour cette action.", 403);
            }
        });

        // 403 : authentifié mais pas le droit (Policy / Gate)
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                // Selon la version de Sanctum, l'exception « capacité manquante » est convertie en 403 générique : on la reconnaît ici
                return $e->getPrevious() instanceof MissingAbilityException
                    ? $erreur('capacite_manquante', "Ce jeton n'a pas la capacité requise pour cette action.", 403)
                    : $erreur('acces_refuse', "Vous n'avez pas le droit d'effectuer cette action.", 403);
            }
        });

        // 404 : route inconnue OU ressource inexistante (le binding de modèle lève ModelNotFoundException)
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $e->getPrevious() instanceof ModelNotFoundException
                    ? $erreur('ressource_introuvable', 'Ressource introuvable.', 404)
                    : $erreur('route_introuvable', 'Route introuvable.', 404);
            }
        });

        // 405 : mauvaise méthode HTTP (en-tête Allow fourni)
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('methode_non_autorisee', 'Méthode HTTP non autorisée sur cette route.', 405, [], $e->getHeaders());
            }
        });

        // 422 : validation (on ajoute le champ « code » au format standard de Laravel)
        $exceptions->render(function (ValidationException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('validation_echouee', $e->getMessage(), $e->status, ['errors' => $e->errors()]);
            }
        });

        // 429 : trop de requêtes (en-tête Retry-After fourni par Laravel)
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('trop_de_requetes', 'Trop de requêtes. Réessayez plus tard.', 429, [], $e->getHeaders());
            }
        });

        // Autres erreurs HTTP (abort(409), 413, 503...)
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($erreur) {
            if ($request->is('api/*')) {
                return $erreur('erreur_http', $e->getMessage() !== '' ? $e->getMessage() : 'Erreur.', $e->getStatusCode(), [], $e->getHeaders());
            }
        });

        // Les 500 : laissées à Laravel (détails masqués si APP_DEBUG=false)
    })->create();
