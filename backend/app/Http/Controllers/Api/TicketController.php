<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTicketsRequest;
use App\Http\Requests\ShowTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Csv;
use App\Support\Parametres;
use App\Support\Tri;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    /**
     * GET /tickets
     *   filtres : ?statut=nouveau,en_cours &priorite=haute &agent_id=3|aucun &auteur_id= &q=imprimante &tag=reseau
     *             &cree_apres=2026-10-01 &cree_avant=2026-10-31 &en_retard=1
     *   tri     : ?tri=-priorite,created_at
     *   pages   : ?page=2&per_page=20     ou     ?pagination=curseur&cursor=...
     *   inclure : ?inclure=auteur,agent,tags,commentaires,pieces_jointes
     */
    public function index(IndexTicketsRequest $request)
    {
        $this->authorize('viewAny', Ticket::class);
        $user = $request->user();

        $tri = Tri::analyser($request->validated('tri'), Ticket::TRIS_AUTORISES, '-created_at');

        $requete = Ticket::query()
            ->visiblePour($user)
            ->filtrer($this->filtres($request))
            ->trier($tri)
            ->withCount(['commentaires' => fn ($q) => $this->commentairesVisibles($q, $user)])
            ->with($this->relations(Parametres::liste($request->validated('inclure')), $user));

        $parPage = Parametres::parPage($request->validated('per_page'));

        $page = $request->validated('pagination') === 'curseur'
            ? $requete->cursorPaginate($parPage)->withQueryString()   // stable même si des lignes sont insérées pendant la lecture
            : $requete->paginate($parPage)->withQueryString();        // total + numéros de page

        return TicketResource::collection($page);
    }

    /** POST /tickets -> 201 + en-tête Location */
    public function store(StoreTicketRequest $request)
    {
        $this->authorize('create', Ticket::class);

        $ticket = DB::transaction(function () use ($request) {
            // Les valeurs null (ex. priorite: null) sont écartées pour laisser jouer la valeur par défaut
            $donnees = array_filter($request->safe()->only(['titre', 'description', 'priorite', 'echeance']), fn ($v) => $v !== null);
            $t = new Ticket(array_merge(['priorite' => 'normale'], $donnees));
            $t->reference = Ticket::genererReference();
            $t->statut = 'nouveau';
            $t->auteur_id = $request->user()->id;
            $t->save();

            if ($request->filled('tags')) {
                $t->tags()->sync($request->validated('tags'));
            }

            return $t;
        });

        $ticket->load(['auteur', 'agent', 'tags']);

        return (new TicketResource($ticket))
            ->response()                                    // 201 automatique : le modèle vient d'être créé
            ->header('Location', route('v1.tickets.show', $ticket));
    }

    /** GET /tickets/{ticket}?inclure=... (ETag : 304 si rien n'a changé) */
    public function show(ShowTicketRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('view', $ticket);
        $user = $request->user();

        $ticket->load($this->relations(Parametres::liste($request->validated('inclure')), $user));
        $ticket->loadCount(['commentaires' => fn ($q) => $this->commentairesVisibles($q, $user)]);

        return new TicketResource($ticket);
    }

    /** PUT (remplacement) ou PATCH (partiel) /tickets/{ticket} */
    public function update(UpdateTicketRequest $request, Ticket $ticket): TicketResource
    {
        $this->authorize('update', $ticket);

        if ($ticket->statut === 'ferme') {
            throw new ErreurApi(409, 'ticket_ferme', 'Un ticket fermé ne peut plus être modifié.');
        }

        DB::transaction(function () use ($request, $ticket) {
            $ticket->update($request->safe()->only(['titre', 'description', 'priorite', 'echeance']));

            if ($request->has('tags')) {
                $ticket->tags()->sync($request->validated('tags') ?? []);
            }
        });

        return new TicketResource($ticket->refresh()->load(['auteur', 'agent', 'tags']));
    }

    /** DELETE /tickets/{ticket} -> 204 (suppression douce : récupérable) */
    public function destroy(Ticket $ticket): Response
    {
        $this->authorize('delete', $ticket);
        $ticket->delete();

        return response()->noContent();
    }

    /** GET /tickets/corbeille (admin) : tickets supprimés */
    public function corbeille(Request $request)
    {
        $this->authorize('viewTrash', Ticket::class);

        return TicketResource::collection(
            Ticket::onlyTrashed()->orderByDesc('deleted_at')->orderByDesc('id')
                ->paginate(Parametres::parPage($request->query('per_page')))->withQueryString()
        );
    }

    /** POST /tickets/{ticket}/restauration (admin) */
    public function restaurer(Ticket $ticket): TicketResource
    {
        $this->authorize('restore', $ticket);

        if (! $ticket->trashed()) {
            throw new ErreurApi(409, 'non_supprime', "Ce ticket n'est pas dans la corbeille.");
        }
        $ticket->restore();

        return new TicketResource($ticket->refresh());
    }

    /** DELETE /tickets/{ticket}/definitif (admin) : irréversible, uniquement depuis la corbeille -> 204 */
    public function supprimerDefinitivement(Ticket $ticket): Response
    {
        $this->authorize('forceDelete', $ticket);

        if (! $ticket->trashed()) {
            throw new ErreurApi(409, 'non_supprime', "Mettez d'abord le ticket à la corbeille (DELETE /tickets/{id}).");
        }

        // Les fichiers ne sont pas supprimés par la base : on les efface nous-mêmes
        $ticket->piecesJointes()->get()->each(fn ($p) => Storage::disk('local')->delete($p->chemin));
        $ticket->forceDelete(); // les lignes liées partent via ON DELETE CASCADE

        return response()->noContent();
    }

    /** GET /tickets/export : CSV en flux (mêmes filtres que la liste). Neutralise l'injection de formules. */
    public function export(IndexTicketsRequest $request): StreamedResponse
    {
        $this->authorize('viewAny', Ticket::class);

        $requete = Ticket::query()->visiblePour($request->user())->filtrer($this->filtres($request))->with(['auteur:id,name', 'agent:id,name']);

        return response()->streamDownload(function () use ($requete) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM : Excel lit correctement l'UTF-8
            fputcsv($sortie, ['reference', 'titre', 'statut', 'priorite', 'auteur', 'agent', 'echeance', 'cree_le'], ';', '"', '\\');

            // chunkById : mémoire constante même avec 1 million de tickets
            $requete->chunkById(500, function ($lot) use ($sortie) {
                foreach ($lot as $t) {
                    fputcsv($sortie, Csv::ligne([
                        $t->reference, $t->titre, $t->statut, $t->priorite,
                        $t->auteur?->name, $t->agent?->name,
                        $t->echeance?->toDateString(), $t->created_at?->toDateTimeString(),
                    ]), ';', '"', '\\');
                }
            });
            fclose($sortie);
        }, 'tickets-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------

    private function filtres(Request $request): array
    {
        $v = $request->validated();

        return [
            'statut' => Parametres::liste($v['statut'] ?? null),
            'priorite' => Parametres::liste($v['priorite'] ?? null),
            'agent_id' => $v['agent_id'] ?? null,
            'auteur_id' => $v['auteur_id'] ?? null,
            'q' => $v['q'] ?? null,
            'tag' => $v['tag'] ?? null,
            'cree_apres' => $v['cree_apres'] ?? null,
            'cree_avant' => $v['cree_avant'] ?? null,
            'en_retard' => $request->boolean('en_retard'),
        ];
    }

    /** Un utilisateur simple ne compte/voit pas les notes internes. */
    private function commentairesVisibles($requete, User $user)
    {
        return $user->estAgent() ? $requete : $requete->where('interne', false);
    }

    /** Traduit ?inclure=... en relations Eloquent (chargement groupé : pas de N+1). */
    private function relations(array $inclusions, User $user): array
    {
        $noms = ['auteur' => 'auteur', 'agent' => 'agent', 'tags' => 'tags', 'pieces_jointes' => 'piecesJointes', 'commentaires' => 'commentaires'];
        $with = [];
        foreach ($inclusions as $i) {
            if ($i === 'commentaires') {
                $with['commentaires'] = fn ($q) => $this->commentairesVisibles($q, $user)->orderBy('created_at');
            } else {
                $with[] = $noms[$i];
            }
        }

        return $with;
    }
}
