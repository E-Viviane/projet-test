<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommentaireResource;
use App\Models\Commentaire;
use App\Models\Ticket;
use App\Support\Parametres;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ressource IMBRIQUÉE : /tickets/{ticket}/commentaires/{commentaire}
 * Le « scoped binding » (routes) garantit que le commentaire appartient bien à ce ticket (sinon 404) :
 * impossible de lire le commentaire 7 d'un autre ticket en changeant l'URL.
 */
class CommentaireController extends Controller
{
    public function index(Request $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);
        $user = $request->user();

        $commentaires = $ticket->commentaires()
            ->with('auteur')
            ->when(! $user->estAgent(), fn ($q) => $q->where('interne', false))
            ->orderBy('created_at')->orderBy('id')
            ->paginate(Parametres::parPage($request->query('per_page'), 20))
            ->withQueryString();

        return CommentaireResource::collection($commentaires);
    }

    public function store(Request $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'contenu' => ['required', 'string', 'min:1', 'max:2000'],
            'interne' => ['sometimes', 'boolean'],
        ]);

        if (($data['interne'] ?? false) && ! $request->user()->estAgent()) {
            throw new ErreurApi(403, 'acces_refuse', 'Seuls les agents peuvent écrire une note interne.');
        }
        if ($ticket->statut === 'ferme') {
            throw new ErreurApi(409, 'ticket_ferme', 'Un ticket fermé ne peut plus recevoir de commentaires.');
        }

        $commentaire = $ticket->commentaires()->make($data);
        $commentaire->auteur_id = $request->user()->id;
        $commentaire->save();

        return (new CommentaireResource($commentaire->load('auteur')))
            ->response()
            ->header('Location', route('v1.tickets.commentaires.show', [$ticket->id, $commentaire->id]));
    }

    public function show(Ticket $ticket, Commentaire $commentaire): CommentaireResource
    {
        $this->authorize('view', $ticket);
        $this->authorize('view', $commentaire);   // note interne : agents seulement

        return new CommentaireResource($commentaire->load('auteur'));
    }

    public function update(Request $request, Ticket $ticket, Commentaire $commentaire): CommentaireResource
    {
        $this->authorize('view', $ticket);
        $this->authorize('update', $commentaire);

        $data = $request->validate(['contenu' => ['required', 'string', 'min:1', 'max:2000']]);
        $commentaire->update($data);

        return new CommentaireResource($commentaire->load('auteur'));
    }

    public function destroy(Ticket $ticket, Commentaire $commentaire): Response
    {
        $this->authorize('view', $ticket);
        $this->authorize('delete', $commentaire);
        $commentaire->delete();

        return response()->noContent();
    }
}
