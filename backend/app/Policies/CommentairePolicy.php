<?php

namespace App\Policies;

use App\Models\Commentaire;
use App\Models\User;

class CommentairePolicy
{
    /** Un commentaire interne n'est visible que des agents. */
    public function view(User $u, Commentaire $c): bool
    {
        return ! $c->interne || $u->estAgent();
    }

    public function update(User $u, Commentaire $c): bool
    {
        return $c->auteur_id === $u->id || $u->estAdmin();
    }

    public function delete(User $u, Commentaire $c): bool
    {
        return $c->auteur_id === $u->id || $u->estAdmin();
    }
}
