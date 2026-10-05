<?php

namespace App\Policies;

use App\Models\User;

/** Gestion des comptes : réservée aux administrateurs. */
class UserPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->estAdmin();
    }

    public function view(User $u, User $cible): bool
    {
        return $u->estAdmin();
    }

    public function update(User $u, User $cible): bool
    {
        return $u->estAdmin();
    }

    public function delete(User $u, User $cible): bool
    {
        return $u->estAdmin();
    }
}
