<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * Règles d'accès aux tickets (autorisation = « as-tu le droit ? », différent de l'authentification = « qui es-tu ? »).
 *   user  : voit/modifie/supprime SES tickets tant qu'ils sont « nouveau »
 *   agent : voit tout, modifie, assigne, change le statut
 *   admin : tout, y compris corbeille et suppression définitive
 */
class TicketPolicy
{
    public function viewAny(User $u): bool
    {
        return true; // la liste est filtrée par visiblePour()
    }

    public function view(User $u, Ticket $t): bool
    {
        return $u->estAgent() || $t->auteur_id === $u->id;
    }

    public function create(User $u): bool
    {
        return true;
    }

    public function update(User $u, Ticket $t): bool
    {
        return $u->estAgent() || ($t->auteur_id === $u->id && $t->statut === 'nouveau');
    }

    public function delete(User $u, Ticket $t): bool
    {
        return $u->estAdmin() || ($t->auteur_id === $u->id && $t->statut === 'nouveau');
    }

    public function assign(User $u, Ticket $t): bool
    {
        return $u->estAgent();
    }

    public function transition(User $u, Ticket $t): bool
    {
        return $u->estAgent();
    }

    public function viewTrash(User $u): bool
    {
        return $u->estAdmin();
    }

    public function restore(User $u, Ticket $t): bool
    {
        return $u->estAdmin();
    }

    public function forceDelete(User $u, Ticket $t): bool
    {
        return $u->estAdmin();
    }
}
