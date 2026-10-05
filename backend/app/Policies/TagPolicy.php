<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function create(User $u): bool
    {
        return $u->estAgent();
    }

    public function update(User $u, Tag $t): bool
    {
        return $u->estAgent();
    }

    public function delete(User $u, Tag $t): bool
    {
        return $u->estAdmin();
    }
}
