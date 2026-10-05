<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointe extends Model
{
    protected $table = 'pieces_jointes';

    protected $fillable = ['nom_original', 'chemin', 'mime', 'taille'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function deposePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depose_par');
    }
}
