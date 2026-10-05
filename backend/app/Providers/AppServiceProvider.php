<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        app()->setLocale('fr'); // messages de validation en français (lang/fr/validation.php)

        // Limite générale : 60 requêtes / minute par utilisateur (ou par IP si anonyme).
        // user('sanctum') : on nomme la garde explicitement, le limiteur ne dépend pas de l'ordre des middlewares.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)->by($r->user('sanctum')?->id ?: $r->ip()));

        // Connexion / inscription : 5 essais / minute par couple (email + IP) -> freine la force brute
        RateLimiter::for('connexion', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')) . '|' . $r->ip()));

        // Envoi de fichiers : plus restrictif (10 / minute)
        RateLimiter::for('envois', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
    }
}
