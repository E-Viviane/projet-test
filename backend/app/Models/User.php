<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['user', 'agent', 'admin'];

    // « role » n'est PAS dans $fillable : personne ne peut se promouvoir admin via un formulaire (mass assignment)
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Agent ou admin */
    public function estAgent(): bool
    {
        return in_array($this->role, ['agent', 'admin'], true);
    }

    /**
     * Capacités (« abilities ») inscrites dans le jeton à la connexion : le jeton ne peut jamais faire plus
     * que ce que le rôle permet, et on peut en émettre de plus limités.
     *
     * @return list<string>
     */
    public function capacites(): array
    {
        return match ($this->role) {
            'admin' => ['*'],
            'agent' => ['tickets:lire', 'tickets:ecrire', 'tickets:gerer', 'statistiques:lire'],
            default => ['tickets:lire', 'tickets:ecrire'],
        };
    }

    public function ticketsCrees(): HasMany
    {
        return $this->hasMany(Ticket::class, 'auteur_id');
    }
}
