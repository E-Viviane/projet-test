<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Commentaire;
use App\Models\PieceJointe;
use App\Models\User;
use App\Support\Parametres;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Gestion des comptes (admin). Pas de POST ici : les comptes se créent par /auth/inscription. */
class UtilisateurController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $f = $request->validate([
            'role' => ['nullable', Rule::in(User::ROLES)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $utilisateurs = User::query()
            ->withCount('ticketsCrees')
            ->when($f['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $motif = '%' . addcslashes($v, '%_\\') . '%';

                return $q->where(fn ($w) => $w->where('name', 'like', $motif)->orWhere('email', 'like', $motif));
            })
            ->orderBy('name')->orderBy('id')
            ->paginate(Parametres::parPage($f['per_page'] ?? null))
            ->withQueryString();

        return UserResource::collection($utilisateurs);
    }

    /**
     * GET /agents : liste minimale (id, nom) des agents et admins, pour l'assignation d'un ticket.
     * Accessible aux agents (jeton avec la capacité tickets:gerer) : la liste complète des comptes reste réservée à l'admin.
     */
    public function agents(): \Illuminate\Http\JsonResponse
    {
        $agents = User::query()->whereIn('role', ['agent', 'admin'])->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return response()->json(['data' => $agents]);
    }

    public function show(User $utilisateur): UserResource
    {
        $this->authorize('view', $utilisateur);

        return new UserResource($utilisateur->loadCount('ticketsCrees'));
    }

    public function update(Request $request, User $utilisateur): UserResource
    {
        $this->authorize('update', $utilisateur);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utilisateur->id)],
            'role' => ['sometimes', Rule::in(User::ROLES)],
        ]);

        if (isset($data['role']) && $data['role'] !== $utilisateur->role && $utilisateur->id === $request->user()->id) {
            // Garde-fou : un admin ne se rétrograde pas lui-même (sinon plus personne ne peut administrer)
            throw new ErreurApi(409, 'auto_modification', 'Vous ne pouvez pas modifier votre propre rôle.');
        }

        $utilisateur->fill(collect($data)->only(['name', 'email'])->all());
        if (isset($data['role'])) {
            $utilisateur->role = $data['role']; // « role » n'est pas $fillable : affectation explicite et volontaire
        }
        $utilisateur->save();

        return new UserResource($utilisateur->refresh());
    }

    public function destroy(Request $request, User $utilisateur): Response
    {
        $this->authorize('delete', $utilisateur);

        if ($utilisateur->id === $request->user()->id) {
            throw new ErreurApi(409, 'auto_suppression', 'Vous ne pouvez pas supprimer votre propre compte.');
        }
        // Intégrité référentielle : on explique le refus au lieu de laisser la base répondre par une erreur 500
        if ($utilisateur->ticketsCrees()->exists()
            || Commentaire::where('auteur_id', $utilisateur->id)->exists()
            || PieceJointe::where('depose_par', $utilisateur->id)->exists()) {
            throw new ErreurApi(409, 'utilisateur_lie', 'Cet utilisateur a des tickets, commentaires ou fichiers : suppression impossible.');
        }

        $utilisateur->tokens()->delete();
        $utilisateur->delete();

        return response()->noContent();
    }
}
