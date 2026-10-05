<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// Laravel 11+ fournit un Controller vide : on y ajoute $this->authorize(...) pour utiliser les Policies.
abstract class Controller
{
    use AuthorizesRequests;
}
