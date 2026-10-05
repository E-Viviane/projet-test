<?php

namespace App\Policies;

use App\Models\PieceJointe;
use App\Models\User;

class PieceJointePolicy
{
    /** Voir / télécharger : qui peut voir le ticket parent. */
    public function view(User $u, PieceJointe $p): bool
    {
        // withTrashed : le ticket parent peut être dans la corbeille (belongsTo l'ignorerait et renverrait null)
        $ticket = $p->ticket()->withTrashed()->first();

        return $ticket !== null && $u->can('view', $ticket);
    }

    public function delete(User $u, PieceJointe $p): bool
    {
        return $p->depose_par === $u->id || $u->estAdmin();
    }
}
