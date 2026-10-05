<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\MoiResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Authentification par jetons (Bearer) avec Laravel Sanctum. */
class AuthController extends Controller
{
    /** POST /auth/inscription -> 201 + jeton */
    public function inscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'appareil' => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
        $user->refresh(); // récupère la valeur par défaut du rôle (« user ») posée par la base

        return $this->reponseJeton($user, $data['appareil'] ?? 'api', 201);
    }

    /** POST /auth/connexion -> 200 + jeton, 401 si identifiants invalides */
    public function connexion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'appareil' => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        // Hash::check est TOUJOURS exécuté, même si l'email n'existe pas : le temps de réponse ne révèle pas si le compte existe
        $hash = $user?->password ?? '$2y$12$QIDuhHByE4voGA9gdylYxu07z5Q0LIoVC1C4xGlYehBD7YL1NICM6';
        $valide = Hash::check($data['password'], $hash);

        if (! $user || ! $valide) {
            // Même message dans les deux cas : on ne dit pas « email inconnu » ou « mot de passe faux »
            throw new ErreurApi(401, 'identifiants_invalides', 'Identifiants invalides.');
        }

        return $this->reponseJeton($user, $data['appareil'] ?? 'api', 200);
    }

    /** GET /auth/moi */
    public function moi(Request $request): MoiResource
    {
        return new MoiResource($request->user());
    }

    /** PATCH /auth/moi : modifie nom / email */
    public function modifierMoi(Request $request): MoiResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }
        $user->update($data);

        return new MoiResource($user->refresh());
    }

    /** PUT /auth/mot-de-passe -> 204 ; révoque tous les AUTRES jetons */
    public function changerMotDePasse(Request $request): Response
    {
        $user = $request->user();
        $data = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:mot_de_passe_actuel', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($data['mot_de_passe_actuel'], $user->password)) {
            throw ValidationException::withMessages(['mot_de_passe_actuel' => 'Le mot de passe actuel est incorrect.']);
        }

        $user->update(['password' => $data['password']]);

        // Un mot de passe changé = les autres appareils doivent se reconnecter
        $courant = $user->currentAccessToken()->id ?? null;
        $user->tokens()->when($courant, fn ($q) => $q->where('id', '!=', $courant))->delete();

        return response()->noContent();
    }

    /** POST /auth/deconnexion : révoque LE jeton utilisé -> 204 */
    public function deconnexion(Request $request): Response
    {
        $jeton = $request->user()->currentAccessToken();
        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        return response()->noContent();
    }

    /** POST /auth/deconnexion-totale : révoque TOUS les jetons du compte -> 204 */
    public function deconnexionTotale(Request $request): Response
    {
        $request->user()->tokens()->delete();

        return response()->noContent();
    }

    /** GET /auth/jetons : mes appareils / sessions actifs */
    public function jetons(Request $request): JsonResponse
    {
        $courant = $request->user()->currentAccessToken()->id ?? null;

        $liste = $request->user()->tokens()->orderByDesc('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'nom' => $t->name,
            'capacites' => $t->abilities,
            'derniere_utilisation' => $t->last_used_at?->toIso8601String(),
            'expire_le' => $t->expires_at?->toIso8601String(),
            'courant' => $t->id === $courant,
        ]);

        return response()->json(['data' => $liste]);
    }

    /** DELETE /auth/jetons/{id} : révoque un de MES jetons (404 si ce n'est pas le mien) -> 204 */
    public function revoquerJeton(Request $request, int $jeton): Response
    {
        $request->user()->tokens()->whereKey($jeton)->firstOrFail()->delete();

        return response()->noContent();
    }

    private function reponseJeton(User $user, string $appareil, int $statut): JsonResponse
    {
        $expire = now()->addHours(8);
        $jeton = $user->createToken($appareil, $user->capacites(), $expire);

        return response()->json([
            'data' => new MoiResource($user),
            'token' => $jeton->plainTextToken,   // affiché UNE seule fois : seul son hash est stocké en base
            'type' => 'Bearer',
            'capacites' => $user->capacites(),
            'expire_le' => $expire->toIso8601String(),
        ], $statut);
    }
}
