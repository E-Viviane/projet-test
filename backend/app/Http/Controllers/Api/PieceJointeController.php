<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\PieceJointeResource;
use App\Models\PieceJointe;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Envoi de fichiers (multipart/form-data). Règles de sécurité :
 *  - type et taille vérifiés côté serveur (le type est DÉTECTÉ, pas celui annoncé par le client)
 *  - nom de stockage généré (jamais le nom envoyé : pas de ../../ ni de .php)
 *  - stockage hors du dossier public ; téléchargement uniquement via l'API, après contrôle d'accès
 */
class PieceJointeController extends Controller
{
    private const MAX_PAR_TICKET = 10;

    public function store(Request $request, Ticket $ticket)
    {
        $this->authorize('view', $ticket);

        $request->validate([
            'fichier' => ['required', 'file', 'max:5120', 'mimes:pdf,png,jpg,jpeg,txt,docx,xlsx'], // 5 Mo
        ]);

        if ($ticket->piecesJointes()->count() >= self::MAX_PAR_TICKET) {
            throw new ErreurApi(409, 'limite_atteinte', 'Maximum ' . self::MAX_PAR_TICKET . ' pièces jointes par ticket.');
        }

        $fichier = $request->file('fichier');
        $chemin = $fichier->store("pieces-jointes/{$ticket->id}", 'local'); // nom aléatoire généré par Laravel

        $piece = $ticket->piecesJointes()->make([
            'nom_original' => Str::limit(basename($fichier->getClientOriginalName()), 200, ''),
            'chemin' => $chemin,
            'mime' => $fichier->getMimeType() ?? 'application/octet-stream',
            'taille' => $fichier->getSize(),
        ]);
        $piece->depose_par = $request->user()->id;
        $piece->save();

        return (new PieceJointeResource($piece))
            ->response()
            ->header('Location', route('v1.pieces.telecharger', $piece->id));
    }

    public function telecharger(PieceJointe $piece): StreamedResponse
    {
        $this->authorize('view', $piece);

        if (! Storage::disk('local')->exists($piece->chemin)) {
            throw new ErreurApi(404, 'fichier_manquant', 'Le fichier est introuvable sur le serveur.');
        }

        return Storage::disk('local')->download($piece->chemin, $piece->nom_original, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(PieceJointe $piece): Response
    {
        $this->authorize('delete', $piece);

        Storage::disk('local')->delete($piece->chemin);
        $piece->delete();

        return response()->noContent();
    }
}
