<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** CRUD simple d'une petite ressource (liste non paginée : quelques dizaines de tags au plus). */
class TagController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:50']]);

        $tags = Tag::query()
            ->withCount('tickets')
            ->when($request->query('q'), fn ($q, $v) => $q->where('nom', 'like', '%' . addcslashes($v, '%_\\') . '%'))
            ->orderBy('nom')
            ->get();

        return TagResource::collection($tags);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Tag::class);

        $data = $request->validate([
            'nom' => ['required', 'string', 'min:2', 'max:50', 'unique:tags,nom'],
            'couleur' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $tag = Tag::create($data);

        return (new TagResource($tag))->response()->header('Location', route('v1.tags.show', $tag->id));
    }

    public function show(Tag $tag): TagResource
    {
        return new TagResource($tag->loadCount('tickets'));
    }

    public function update(Request $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $data = $request->validate([
            'nom' => [$request->isMethod('PUT') ? 'required' : 'sometimes', 'string', 'min:2', 'max:50', Rule::unique('tags', 'nom')->ignore($tag->id)],
            'couleur' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $tag->update($data);

        return new TagResource($tag);
    }

    public function destroy(Tag $tag): Response
    {
        $this->authorize('delete', $tag);
        $tag->delete(); // les liaisons ticket_tag sont supprimées par la base (cascade)

        return response()->noContent();
    }
}
