<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Ticket;

class TicketsApiTest extends ApiTestCase
{
    private const T = self::API . '/tickets';

    // ------------------------------------------------------------ création
    public function test_un_utilisateur_cree_un_ticket_201_avec_location(): void
    {
        $u = $this->connecte($this->utilisateur());

        $r = $this->postJson(self::T, ['titre' => 'Écran noir', 'description' => 'Le poste ne démarre plus', 'priorite' => 'haute'])
            ->assertCreated()
            ->assertJsonPath('data.statut', 'nouveau')
            ->assertJsonPath('data.priorite', 'haute')
            ->assertJsonPath('data.auteur.id', $u->id);

        $id = $r->json('data.id');
        $r->assertHeader('Location', route('v1.tickets.show', $id));
        $this->assertStringStartsWith('TK-', $r->json('data.reference'));
        $this->assertDatabaseHas('tickets', ['id' => $id, 'auteur_id' => $u->id]);
    }

    public function test_creation_invalide_422_avec_details_par_champ(): void
    {
        $this->connecte($this->utilisateur());

        $this->postJson(self::T, ['titre' => 'ab', 'description' => 'x', 'priorite' => 'urgentissime', 'echeance' => '2020-01-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_echouee')
            ->assertJsonValidationErrors(['titre', 'description', 'priorite', 'echeance']);
    }

    public function test_les_champs_proteges_sont_ignores_mass_assignment(): void
    {
        $u = $this->connecte($this->utilisateur());
        $autre = $this->utilisateur();

        $id = $this->postJson(self::T, [
            'titre' => 'Test', 'description' => 'Description valide',
            'statut' => 'resolu', 'auteur_id' => $autre->id, 'agent_id' => $autre->id, 'reference' => 'PIRATE',
        ])->assertCreated()->json('data.id');

        $t = Ticket::find($id);
        $this->assertSame('nouveau', $t->statut);
        $this->assertSame($u->id, $t->auteur_id);
        $this->assertNull($t->agent_id);
        $this->assertNotSame('PIRATE', $t->reference);
    }

    public function test_idempotence_meme_cle_meme_requete_un_seul_ticket(): void
    {
        $this->connecte($this->utilisateur());
        $corps = ['titre' => 'Double clic', 'description' => 'Ne doit exister qu\'une fois'];
        $h = ['Idempotency-Key' => 'cle-unique-0001'];

        $a = $this->postJson(self::T, $corps, $h)->assertCreated();
        $b = $this->postJson(self::T, $corps, $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_idempotence_meme_cle_requete_differente_422_et_cle_invalide_400(): void
    {
        $this->connecte($this->utilisateur());
        $h = ['Idempotency-Key' => 'cle-unique-0002'];

        $this->postJson(self::T, ['titre' => 'Premier', 'description' => 'Description 1'], $h)->assertCreated();
        $this->postJson(self::T, ['titre' => 'Autre', 'description' => 'Description 2'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'cle_idempotence_reutilisee');
        $this->postJson(self::T, ['titre' => 'Autre', 'description' => 'Description 2'], ['Idempotency-Key' => 'x'])
            ->assertStatus(400)->assertJsonPath('code', 'cle_idempotence_invalide');
    }

    // ------------------------------------------------------------ lecture / droits
    public function test_un_utilisateur_ne_voit_que_ses_tickets_un_agent_voit_tout(): void
    {
        $marie = $this->utilisateur();
        $paul = $this->utilisateur();
        $this->ticket($marie);
        $this->ticket($marie);
        $this->ticket($paul);

        $this->connecte($marie);
        $this->getJson(self::T)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);

        $this->connecte($this->utilisateur('agent'));
        $this->getJson(self::T)->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_consulter_le_ticket_d_un_autre_403_un_agent_peut(): void
    {
        $ticket = $this->ticket($this->utilisateur());

        $this->connecte($this->utilisateur());
        $this->getJson(self::T . '/' . $ticket->id)->assertForbidden()->assertJsonPath('code', 'acces_refuse');

        $this->connecte($this->utilisateur('agent'));
        $this->getJson(self::T . '/' . $ticket->id)->assertOk()->assertJsonPath('data.id', $ticket->id);
    }

    public function test_404_ressource_et_404_route_ont_des_codes_differents(): void
    {
        $this->connecte($this->utilisateur());

        $this->getJson(self::T . '/999999')->assertNotFound()->assertJsonPath('code', 'ressource_introuvable');
        $this->getJson(self::API . '/nimporte-quoi')->assertNotFound()->assertJsonPath('code', 'route_introuvable');
    }

    public function test_etag_304_si_rien_n_a_change(): void
    {
        $u = $this->connecte($this->utilisateur());
        $ticket = $this->ticket($u);

        $r = $this->getJson(self::T . '/' . $ticket->id)->assertOk();
        $etag = $r->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])->getJson(self::T . '/' . $ticket->id)->assertStatus(304);
    }

    public function test_inclusions_chargent_les_relations_demandees_seulement(): void
    {
        $u = $this->connecte($this->utilisateur());
        $this->ticket($u)->tags()->attach(Tag::create(['nom' => 'reseau'])->id);

        $r = $this->getJson(self::T . '?inclure=auteur,tags')->assertOk();
        $r->assertJsonPath('data.0.auteur.id', $u->id)->assertJsonPath('data.0.tags.0.nom', 'reseau');
        $this->assertArrayNotHasKey('commentaires', $r->json('data.0'));

        $this->getJson(self::T . '?inclure=mot_de_passe')->assertStatus(422)->assertJsonValidationErrors(['inclure']);
    }

    // ------------------------------------------------------------ filtres / tri / pagination
    public function test_filtres_statut_priorite_recherche_assignation(): void
    {
        $agent = $this->utilisateur('agent');
        $u = $this->utilisateur();
        $this->ticket($u, ['titre' => 'Imprimante en panne', 'statut' => 'nouveau', 'priorite' => 'haute']);
        $this->ticket($u, ['titre' => 'Clavier cassé', 'statut' => 'en_cours', 'priorite' => 'basse', 'agent_id' => $agent->id]);
        $this->ticket($u, ['titre' => 'Écran flou', 'statut' => 'resolu', 'priorite' => 'basse', 'agent_id' => $agent->id]);

        $this->connecte($agent);
        $this->getJson(self::T . '?statut=nouveau,en_cours')->assertJsonPath('meta.total', 2);
        $this->getJson(self::T . '?priorite=basse')->assertJsonPath('meta.total', 2);
        $this->getJson(self::T . '?q=imprimante')->assertJsonPath('meta.total', 1);
        $this->getJson(self::T . '?agent_id=aucun')->assertJsonPath('meta.total', 1);
        $this->getJson(self::T . '?agent_id=' . $agent->id . '&statut=resolu')->assertJsonPath('meta.total', 1);
        $this->getJson(self::T . '?statut=inconnu')->assertStatus(422)->assertJsonValidationErrors(['statut']);
    }

    public function test_filtre_par_date_inclut_toute_la_journee_de_fin(): void
    {
        $u = $this->utilisateur('agent');
        $this->ticket($u, ['titre' => 'Avant minuit', 'created_at' => '2026-03-31 23:30:00']);
        $this->ticket($u, ['titre' => 'Après minuit', 'created_at' => '2026-04-01 00:10:00']);

        $this->connecte($u);
        $r = $this->getJson(self::T . '?cree_apres=2026-03-31&cree_avant=2026-03-31')->assertOk();
        $this->assertSame(['Avant minuit'], collect($r->json('data'))->pluck('titre')->all());
    }

    public function test_filtre_en_retard_exclut_les_tickets_termines(): void
    {
        $u = $this->utilisateur('agent');
        $this->ticket($u, ['titre' => 'En retard', 'statut' => 'en_cours', 'echeance' => now()->subDay()->toDateString()]);
        $this->ticket($u, ['titre' => 'Résolu en retard', 'statut' => 'resolu', 'echeance' => now()->subDay()->toDateString()]);
        $this->ticket($u, ['titre' => 'Dans les temps', 'statut' => 'en_cours', 'echeance' => now()->addDay()->toDateString()]);

        $this->connecte($u);
        $r = $this->getJson(self::T . '?en_retard=1')->assertOk();
        $this->assertSame(['En retard'], collect($r->json('data'))->pluck('titre')->all());
        $this->assertTrue($r->json('data.0.en_retard'));
    }

    public function test_tri_par_priorite_suit_la_gravite_pas_l_alphabet(): void
    {
        $u = $this->connecte($this->utilisateur('agent'));
        foreach (['basse', 'critique', 'normale', 'haute'] as $p) {
            $this->ticket($u, ['priorite' => $p]);
        }

        $r = $this->getJson(self::T . '?tri=-priorite')->assertOk();
        $this->assertSame(['critique', 'haute', 'normale', 'basse'], collect($r->json('data'))->pluck('priorite')->all());

        $r = $this->getJson(self::T . '?tri=priorite')->assertOk();
        $this->assertSame(['basse', 'normale', 'haute', 'critique'], collect($r->json('data'))->pluck('priorite')->all());
    }

    public function test_tri_invalide_ou_malveillant_refuse_422(): void
    {
        $this->connecte($this->utilisateur());

        $this->getJson(self::T . '?tri=password')->assertStatus(422)->assertJsonValidationErrors(['tri']);
        $this->getJson(self::T . '?tri=' . urlencode('titre; DROP TABLE tickets'))->assertStatus(422)->assertJsonValidationErrors(['tri']);
    }

    public function test_pagination_par_pages_meta_et_liens(): void
    {
        $u = $this->connecte($this->utilisateur());
        for ($i = 0; $i < 25; $i++) {
            $this->ticket($u);
        }

        $this->getJson(self::T . '?per_page=10&page=3')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next'], 'meta' => ['per_page']]);

        // Garde-fou : on ne peut pas demander 1 000 000 de lignes d'un coup
        $this->getJson(self::T . '?per_page=1000')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_pagination_par_curseur(): void
    {
        $u = $this->connecte($this->utilisateur());
        for ($i = 0; $i < 12; $i++) {
            $this->ticket($u);
        }

        $p1 = $this->getJson(self::T . '?pagination=curseur&per_page=5')->assertOk()->assertJsonCount(5, 'data');
        $curseur = $p1->json('meta.next_cursor');
        $this->assertNotNull($curseur);

        $p2 = $this->getJson(self::T . '?pagination=curseur&per_page=5&cursor=' . urlencode($curseur))->assertOk()->assertJsonCount(5, 'data');
        $this->assertEmpty(array_intersect(array_column($p1->json('data'), 'id'), array_column($p2->json('data'), 'id')));

        $this->getJson(self::T . '?pagination=curseur&tri=priorite')->assertStatus(422)->assertJsonValidationErrors(['tri']);
    }

    // ------------------------------------------------------------ modification
    public function test_put_exige_tous_les_champs_patch_accepte_un_champ(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);

        $this->putJson(self::T . '/' . $t->id, ['titre' => 'Nouveau titre'])->assertStatus(422)->assertJsonValidationErrors(['description', 'priorite', 'echeance', 'tags']);

        $this->patchJson(self::T . '/' . $t->id, ['titre' => 'Titre corrigé'])
            ->assertOk()->assertJsonPath('data.titre', 'Titre corrigé')->assertJsonPath('data.description', $t->description);

        $this->putJson(self::T . '/' . $t->id, ['titre' => 'Remplacé', 'description' => 'Tout est remplacé', 'priorite' => 'basse', 'echeance' => null, 'tags' => []])
            ->assertOk()->assertJsonPath('data.priorite', 'basse');
    }

    public function test_un_utilisateur_ne_modifie_plus_son_ticket_une_fois_pris_en_charge(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u, ['statut' => 'en_cours']);

        $this->patchJson(self::T . '/' . $t->id, ['titre' => 'Trop tard'])->assertForbidden();
    }

    public function test_un_ticket_ferme_n_est_plus_modifiable_409(): void
    {
        $t = $this->ticket($this->utilisateur(), ['statut' => 'ferme']);
        $this->connecte($this->utilisateur('agent'));

        $this->patchJson(self::T . '/' . $t->id, ['titre' => 'Modifié'])->assertStatus(409)->assertJsonPath('code', 'ticket_ferme');
    }

    // ------------------------------------------------------------ suppression / corbeille
    public function test_suppression_douce_puis_404(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);

        $this->deleteJson(self::T . '/' . $t->id)->assertNoContent();
        $this->assertSoftDeleted('tickets', ['id' => $t->id]);
        $this->getJson(self::T . '/' . $t->id)->assertNotFound();
    }

    public function test_un_agent_ne_supprime_pas_le_ticket_d_un_autre(): void
    {
        $t = $this->ticket($this->utilisateur());
        $this->connecte($this->utilisateur('agent'));

        $this->deleteJson(self::T . '/' . $t->id)->assertForbidden();
    }

    public function test_corbeille_restauration_et_suppression_definitive_admin_seulement(): void
    {
        $u = $this->utilisateur();
        $t = $this->ticket($u);
        $t->delete();

        $this->connecte($this->utilisateur('agent'));
        $this->getJson(self::T . '/corbeille')->assertForbidden();

        $this->connecte($this->utilisateur('admin'));
        $this->getJson(self::T . '/corbeille')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $t->id);

        $this->postJson(self::T . '/' . $t->id . '/restauration')->assertOk();
        $this->getJson(self::T . '/' . $t->id)->assertOk();

        // Restaurer un ticket qui n'est pas supprimé, ou supprimer définitivement sans passer par la corbeille : 409
        $this->postJson(self::T . '/' . $t->id . '/restauration')->assertStatus(409)->assertJsonPath('code', 'non_supprime');
        $this->deleteJson(self::T . '/' . $t->id . '/definitif')->assertStatus(409);

        $t->delete();
        $this->deleteJson(self::T . '/' . $t->id . '/definitif')->assertNoContent();
        $this->assertDatabaseMissing('tickets', ['id' => $t->id]);
    }

    // ------------------------------------------------------------ actions métier
    public function test_transition_valide_et_resolu_le(): void
    {
        $t = $this->ticket($this->utilisateur(), ['statut' => 'en_cours']);
        $this->connecte($this->utilisateur('agent'));

        $this->postJson(self::T . '/' . $t->id . '/transitions', ['statut' => 'resolu'])
            ->assertOk()->assertJsonPath('data.statut', 'resolu');
        $this->assertNotNull($t->refresh()->resolu_le);

        // Réouverture : resolu_le est effacé
        $this->postJson(self::T . '/' . $t->id . '/transitions', ['statut' => 'en_cours'])->assertOk();
        $this->assertNull($t->refresh()->resolu_le);
    }

    public function test_transition_interdite_409_avec_les_transitions_permises(): void
    {
        $t = $this->ticket($this->utilisateur(), ['statut' => 'nouveau']);
        $this->connecte($this->utilisateur('agent'));

        $this->postJson(self::T . '/' . $t->id . '/transitions', ['statut' => 'resolu'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'transition_invalide')
            ->assertJsonPath('statut_actuel', 'nouveau')
            ->assertJsonPath('transitions_permises', ['en_cours', 'ferme']);

        $this->postJson(self::T . '/' . $t->id . '/transitions', ['statut' => 'nimporte'])->assertStatus(422);
    }

    public function test_un_utilisateur_simple_ne_peut_pas_changer_le_statut_403_capacite_manquante(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);

        $this->postJson(self::T . '/' . $t->id . '/transitions', ['statut' => 'en_cours'])
            ->assertForbidden()->assertJsonPath('code', 'capacite_manquante');
    }

    public function test_assignation_et_retrait(): void
    {
        $t = $this->ticket($this->utilisateur());
        $agent = $this->utilisateur('agent');
        $simple = $this->utilisateur();
        $this->connecte($this->utilisateur('agent'));

        $this->postJson(self::T . '/' . $t->id . '/assignation', ['agent_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.agent.id', $agent->id);
        // On ne peut pas assigner un ticket à un simple utilisateur
        $this->postJson(self::T . '/' . $t->id . '/assignation', ['agent_id' => $simple->id])->assertStatus(422)->assertJsonValidationErrors(['agent_id']);

        $this->deleteJson(self::T . '/' . $t->id . '/assignation')->assertNoContent();
        $this->assertNull($t->refresh()->agent_id);
    }

    public function test_remplacement_des_tags_put_idempotent(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);
        $a = Tag::create(['nom' => 'reseau']);
        $b = Tag::create(['nom' => 'materiel']);

        $this->putJson(self::T . '/' . $t->id . '/tags', ['tags' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data.tags');
        $this->putJson(self::T . '/' . $t->id . '/tags', ['tags' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data.tags'); // même résultat
        $this->putJson(self::T . '/' . $t->id . '/tags', ['tags' => [$b->id]])->assertOk()->assertJsonCount(1, 'data.tags');
        $this->putJson(self::T . '/' . $t->id . '/tags', ['tags' => []])->assertOk()->assertJsonCount(0, 'data.tags');
        $this->putJson(self::T . '/' . $t->id . '/tags', ['tags' => [999]])->assertStatus(422)->assertJsonValidationErrors(['tags.0']);
        $this->putJson(self::T . '/' . $t->id . '/tags', [])->assertStatus(422)->assertJsonValidationErrors(['tags']);
    }

    public function test_actions_groupees_207_resultat_par_ticket(): void
    {
        $u = $this->utilisateur();
        $ok = $this->ticket($u, ['statut' => 'nouveau']);
        $interdit = $this->ticket($u, ['statut' => 'ferme']);

        $this->connecte($this->utilisateur('agent'));
        $this->postJson(self::T . '/actions-groupees', ['ids' => [$ok->id, $interdit->id, 999999], 'statut' => 'en_cours'])
            ->assertStatus(207)
            ->assertJsonPath('data.mis_a_jour', 1)
            ->assertJsonPath('data.ignores', 2)
            ->assertJsonPath('data.details.0.resultat', 'ok')
            ->assertJsonPath('data.details.1.resultat', 'ignore')
            ->assertJsonPath('data.details.2.resultat', 'introuvable');

        $this->connecte($this->utilisateur());
        $this->postJson(self::T . '/actions-groupees', ['ids' => [$ok->id], 'statut' => 'en_cours'])->assertForbidden();
    }

    // ------------------------------------------------------------ export
    public function test_export_csv_neutralise_les_formules(): void
    {
        $this->ticket($this->utilisateur(), ['titre' => "=cmd|' /C calc'!A0"]);
        $this->connecte($this->utilisateur('agent'));

        $r = $this->get(self::T . '/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $r->streamedContent();

        $this->assertStringContainsString('reference;titre;statut', $csv);
        $this->assertStringContainsString("'=cmd", $csv);   // préfixé par une apostrophe : le tableur n'exécute rien
    }
}
