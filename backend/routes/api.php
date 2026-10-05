<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentaireController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\PieceJointeController;
use App\Http\Controllers\Api\RacineController;
use App\Http\Controllers\Api\SanteController;
use App\Http\Controllers\Api\StatistiqueController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TicketActionController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UtilisateurController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  —  préfixe automatique /api  =>  URL finales : /api/v1/...
|--------------------------------------------------------------------------
| Versionner dans l'URL (/v1) permet de faire évoluer l'API (/v2) sans casser les clients existants.
| Les noms de route (v1.tickets.show...) servent à générer les liens (route('v1.tickets.show', $id)).
*/
Route::prefix('v1')->name('v1.')->group(function () {

    // ---------- Public ----------
    Route::get('/', RacineController::class)->name('racine');
    Route::get('sante', SanteController::class)->name('sante');
    Route::get('demo/codes/{code}', [DemoController::class, 'codes'])->whereNumber('code')->name('demo.codes');

    // ---------- Authentification ----------
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('inscription', [AuthController::class, 'inscription'])->middleware('throttle:connexion')->name('inscription');
        Route::post('connexion', [AuthController::class, 'connexion'])->middleware('throttle:connexion')->name('connexion');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('moi', [AuthController::class, 'moi'])->name('moi');
            Route::patch('moi', [AuthController::class, 'modifierMoi'])->name('moi.modifier');
            Route::put('mot-de-passe', [AuthController::class, 'changerMotDePasse'])->name('mot-de-passe');
            Route::post('deconnexion', [AuthController::class, 'deconnexion'])->name('deconnexion');
            Route::post('deconnexion-totale', [AuthController::class, 'deconnexionTotale'])->name('deconnexion-totale');
            Route::get('jetons', [AuthController::class, 'jetons'])->name('jetons');
            Route::delete('jetons/{jeton}', [AuthController::class, 'revoquerJeton'])->whereNumber('jeton')->name('jetons.revoquer');
        });
    });

    // ---------- Routes protégées : en-tête  Authorization: Bearer <jeton> ----------
    // 'idempotence' : rejoue la réponse si le client renvoie la même création avec le même Idempotency-Key
    Route::middleware(['auth:sanctum', 'idempotence'])->group(function () {

        // --- Tickets : les routes « littérales » AVANT {ticket}, sinon « export » serait pris pour un identifiant ---
        Route::get('tickets/export', [TicketController::class, 'export'])->name('tickets.export');
        Route::get('tickets/corbeille', [TicketController::class, 'corbeille'])->name('tickets.corbeille');
        Route::post('tickets/actions-groupees', [TicketActionController::class, 'actionsGroupees'])
            ->middleware('ability:tickets:gerer')->name('tickets.actions-groupees');

        // GET /tickets/{ticket} : ETag + If-None-Match -> 304 Not Modified si rien n'a changé (économise la bande passante)
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])
            ->middleware('cache.headers:private;max_age=0;etag')->name('tickets.show');
        Route::apiResource('tickets', TicketController::class)->except('show');

        // Corbeille : withTrashed() autorise le binding sur un ticket supprimé
        Route::post('tickets/{ticket}/restauration', [TicketController::class, 'restaurer'])->withTrashed()->name('tickets.restauration');
        Route::delete('tickets/{ticket}/definitif', [TicketController::class, 'supprimerDefinitivement'])->withTrashed()->name('tickets.definitif');

        // Actions métier (sous-ressources)
        Route::middleware('ability:tickets:gerer')->group(function () {
            Route::post('tickets/{ticket}/assignation', [TicketActionController::class, 'assigner'])->name('tickets.assignation');
            Route::delete('tickets/{ticket}/assignation', [TicketActionController::class, 'desassigner'])->name('tickets.assignation.retirer');
            Route::post('tickets/{ticket}/transitions', [TicketActionController::class, 'transition'])->name('tickets.transitions');
        });
        Route::put('tickets/{ticket}/tags', [TicketActionController::class, 'synchroniserTags'])->name('tickets.tags');

        // Commentaires imbriqués (scoped : le commentaire doit appartenir au ticket de l'URL)
        Route::apiResource('tickets.commentaires', CommentaireController::class)
            ->parameters(['commentaires' => 'commentaire'])->scoped();

        // Pièces jointes (upload multipart, limité à 10 envois / minute)
        Route::post('tickets/{ticket}/pieces-jointes', [PieceJointeController::class, 'store'])
            ->middleware('throttle:envois')->name('pieces.store');
        Route::get('pieces-jointes/{piece}', [PieceJointeController::class, 'telecharger'])->name('pieces.telecharger');
        Route::delete('pieces-jointes/{piece}', [PieceJointeController::class, 'destroy'])->name('pieces.destroy');

        // Tags : CRUD complet
        Route::apiResource('tags', TagController::class);

        // Agents assignables (liste minimale, pour les agents)
        Route::get('agents', [UtilisateurController::class, 'agents'])->middleware('ability:tickets:gerer')->name('agents');

        // Comptes (admin) : liste, détail, modification, suppression
        Route::apiResource('utilisateurs', UtilisateurController::class)->except('store')
            ->parameters(['utilisateurs' => 'utilisateur']);

        // Statistiques : jeton avec la capacité « statistiques:lire »
        Route::get('statistiques', StatistiqueController::class)->middleware('ability:statistiques:lire')->name('statistiques');
    });
});
