<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Outils communs aux tests de l'API. Base de test MySQL (cf. phpunit.xml configuré par le script d'installation). */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const API = '/api/v1';

    protected function utilisateur(string $role = 'user', array $extra = []): User
    {
        return User::forceCreate(array_merge([
            'name' => ucfirst($role) . ' ' . Str::random(4),
            'email' => strtolower(Str::random(10)) . '@test.test',
            'password' => 'password',
            'role' => $role,
        ], $extra));
    }

    /** Authentifie la requête suivante avec les capacités du rôle (comme un vrai jeton). */
    protected function connecte(User $u): User
    {
        Sanctum::actingAs($u, $u->capacites());

        return $u;
    }

    protected function ticket(User $auteur, array $attrs = []): Ticket
    {
        return Ticket::unguarded(fn () => Ticket::create(array_merge([
            'reference' => Ticket::genererReference(),
            'titre' => 'Imprimante en panne',
            'description' => 'Plus de papier détecté par le capteur.',
            'priorite' => 'normale',
            'statut' => 'nouveau',
            'auteur_id' => $auteur->id,
        ], $attrs)));
    }
}
