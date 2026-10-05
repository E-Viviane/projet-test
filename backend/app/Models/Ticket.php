<?php

namespace App\Models;

use App\Support\Transitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use SoftDeletes;

    public const STATUTS = ['nouveau', 'en_cours', 'en_attente', 'resolu', 'ferme'];
    public const PRIORITES = ['basse', 'normale', 'haute', 'critique'];
    public const TRIS_AUTORISES = ['created_at', 'updated_at', 'priorite', 'statut', 'echeance', 'titre'];
    public const INCLUSIONS_AUTORISEES = ['auteur', 'agent', 'tags', 'commentaires', 'pieces_jointes'];

    // statut, auteur_id, agent_id, reference ne sont PAS modifiables en masse : ils passent par des actions dédiées
    protected $fillable = ['titre', 'description', 'priorite', 'echeance'];

    protected $casts = [
        'echeance' => 'date',
        'resolu_le' => 'datetime',
    ];

    // ---------- relations ----------
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function commentaires(): HasMany
    {
        return $this->hasMany(Commentaire::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_tag');
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class);
    }

    // ---------- logique ----------
    public static function genererReference(): string
    {
        do {
            $reference = 'TK-' . Str::upper(Str::random(7));
        } while (static::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    public function estEnRetard(): bool
    {
        return $this->echeance !== null
            && ! Transitions::estTermine($this->statut)
            && $this->echeance->lt(Carbon::today());
    }

    // ---------- scopes (requêtes réutilisables) ----------

    /** Un simple utilisateur ne voit que ses propres tickets ; agents et admins voient tout. */
    public function scopeVisiblePour(Builder $requete, User $utilisateur): Builder
    {
        return $utilisateur->estAgent() ? $requete : $requete->where('tickets.auteur_id', $utilisateur->id);
    }

    /**
     * Filtres (déjà validés en amont). Clés : statut[], priorite[], agent_id, auteur_id, q, tag,
     * cree_apres, cree_avant, en_retard.
     */
    public function scopeFiltrer(Builder $requete, array $f): Builder
    {
        return $requete
            ->when(! empty($f['statut']), fn ($q) => $q->whereIn('tickets.statut', $f['statut']))
            ->when(! empty($f['priorite']), fn ($q) => $q->whereIn('tickets.priorite', $f['priorite']))
            ->when(isset($f['agent_id']), function ($q) use ($f) {
                // agent_id=aucun : tickets non assignés
                return $f['agent_id'] === 'aucun'
                    ? $q->whereNull('tickets.agent_id')
                    : $q->where('tickets.agent_id', (int) $f['agent_id']);
            })
            ->when(isset($f['auteur_id']), fn ($q) => $q->where('tickets.auteur_id', (int) $f['auteur_id']))
            ->when(! empty($f['q']), function ($q) use ($f) {
                // On échappe % et _ : « 100% » doit chercher le texte « 100% », pas un joker
                $motif = '%' . addcslashes($f['q'], '%_\\') . '%';

                return $q->where(fn ($w) => $w->where('tickets.titre', 'like', $motif)
                    ->orWhere('tickets.description', 'like', $motif)
                    ->orWhere('tickets.reference', 'like', $motif));
            })
            ->when(! empty($f['tag']), fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.nom', $f['tag'])))
            // Dates : bornes ouvertes (>= début du jour, < lendemain) plutôt que BETWEEN ou DATE(colonne)
            // -> inclut toute la journée de fin ET reste compatible avec l'index sur created_at
            ->when(! empty($f['cree_apres']), fn ($q) => $q->where('tickets.created_at', '>=', Carbon::parse($f['cree_apres'])->startOfDay()))
            ->when(! empty($f['cree_avant']), fn ($q) => $q->where('tickets.created_at', '<', Carbon::parse($f['cree_avant'])->addDay()->startOfDay()))
            ->when(! empty($f['en_retard']), fn ($q) => $q
                ->whereNotNull('tickets.echeance')
                ->where('tickets.echeance', '<', Carbon::today()->toDateString())
                ->whereNotIn('tickets.statut', ['resolu', 'ferme']));
    }

    /**
     * Tri : $tri = résultat de Tri::analyser() (colonnes déjà validées).
     * La priorité est triée par GRAVITÉ (basse < normale < haute < critique), pas par ordre alphabétique.
     * « id » en dernier critère : ordre toujours déterministe (indispensable pour la pagination).
     */
    public function scopeTrier(Builder $requete, array $tri): Builder
    {
        foreach ($tri as [$colonne, $sens]) {
            if ($colonne === 'priorite') {
                $requete->orderByRaw(
                    "CASE tickets.priorite WHEN 'basse' THEN 1 WHEN 'normale' THEN 2 WHEN 'haute' THEN 3 ELSE 4 END " . ($sens === 'desc' ? 'DESC' : 'ASC')
                );
            } else {
                $requete->orderBy($colonne, $sens); // non qualifié : requis par la pagination par curseur
            }
        }

        return $requete->orderBy('id', 'desc');
    }
}
