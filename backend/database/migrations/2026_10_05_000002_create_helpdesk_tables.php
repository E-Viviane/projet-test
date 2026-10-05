<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('titre', 150);
            $table->text('description');
            $table->string('statut', 20)->default('nouveau');
            $table->string('priorite', 20)->default('normale');
            // restrictOnDelete : on ne peut pas supprimer un utilisateur qui a des tickets (intégrité référentielle)
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            // nullOnDelete : si l'agent disparaît, le ticket redevient « non assigné »
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('echeance')->nullable();
            $table->timestamp('resolu_le')->nullable();
            $table->timestamps();
            $table->softDeletes();   // deleted_at : suppression « douce », récupérable

            // Index choisis d'après les filtres de l'API (cf. GET /tickets)
            $table->index(['statut', 'priorite']);
            $table->index('created_at');
            $table->index('echeance');
        });

        Schema::create('commentaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->text('contenu');
            $table->boolean('interne')->default(false); // note visible des agents uniquement
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 50)->unique();
            $table->string('couleur', 7)->default('#888888');
            $table->timestamps();
        });

        // Table pivot many-to-many ticket <-> tag
        Schema::create('ticket_tag', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['ticket_id', 'tag_id']);
        });

        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depose_par')->constrained('users')->restrictOnDelete();
            $table->string('nom_original');
            $table->string('chemin');            // chemin interne généré (jamais le nom envoyé par l'utilisateur)
            $table->string('mime', 100);
            $table->unsignedInteger('taille');   // octets
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
        Schema::dropIfExists('ticket_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('commentaires');
        Schema::dropIfExists('tickets');
    }
};
