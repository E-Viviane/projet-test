<?php

namespace Tests\Feature;

use App\Models\Commentaire;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Commentaires, tags, pièces jointes, comptes, statistiques, supervision et format des erreurs. */
class AutresApiTest extends ApiTestCase
{
    // ------------------------------------------------------------ commentaires (ressource imbriquée)
    public function test_commentaires_creation_liste_et_notes_internes(): void
    {
        $marie = $this->utilisateur();
        $agent = $this->utilisateur('agent');
        $t = $this->ticket($marie);
        $url = self::API . "/tickets/{$t->id}/commentaires";

        $this->connecte($marie);
        $r = $this->postJson($url, ['contenu' => 'Toujours en panne ce matin'])->assertCreated();
        $r->assertHeader('Location', route('v1.tickets.commentaires.show', [$t->id, $r->json('data.id')]));
        // Une note interne est réservée aux agents
        $this->postJson($url, ['contenu' => 'Note secrète', 'interne' => true])->assertForbidden();

        $this->connecte($agent);
        $this->postJson($url, ['contenu' => 'À vérifier avec le fournisseur', 'interne' => true])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');

        $this->connecte($marie);
        $liste = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('interne', $liste->json('data.0')); // l'utilisateur ne voit même pas ce champ
    }

    public function test_un_commentaire_d_un_autre_ticket_donne_404_scoped_binding(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t1 = $this->ticket($u);
        $t2 = $this->ticket($u);
        $c = Commentaire::unguarded(fn () => Commentaire::create(['ticket_id' => $t2->id, 'auteur_id' => $u->id, 'contenu' => 'sur le ticket 2']));

        $this->getJson(self::API . "/tickets/{$t2->id}/commentaires/{$c->id}")->assertOk();
        $this->getJson(self::API . "/tickets/{$t1->id}/commentaires/{$c->id}")->assertNotFound();
    }

    public function test_modifier_supprimer_un_commentaire_auteur_ou_admin(): void
    {
        $marie = $this->utilisateur();
        $paul = $this->utilisateur();
        $t = $this->ticket($marie);
        $c = Commentaire::unguarded(fn () => Commentaire::create(['ticket_id' => $t->id, 'auteur_id' => $marie->id, 'contenu' => 'Mon message']));
        $url = self::API . "/tickets/{$t->id}/commentaires/{$c->id}";

        $this->connecte($this->utilisateur('agent'));
        $this->patchJson($url, ['contenu' => 'Modifié par un agent'])->assertForbidden();

        $this->connecte($marie);
        $this->patchJson($url, ['contenu' => 'Message corrigé'])->assertOk()->assertJsonPath('data.contenu', 'Message corrigé');
        $this->deleteJson($url)->assertNoContent();
        $this->assertDatabaseMissing('commentaires', ['id' => $c->id]);
    }

    public function test_pas_de_commentaire_sur_un_ticket_ferme(): void
    {
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u, ['statut' => 'ferme']);

        $this->postJson(self::API . "/tickets/{$t->id}/commentaires", ['contenu' => 'Trop tard'])->assertStatus(409);
    }

    // ------------------------------------------------------------ tags
    public function test_crud_des_tags_et_droits(): void
    {
        $this->connecte($this->utilisateur());
        $this->postJson(self::API . '/tags', ['nom' => 'reseau'])->assertForbidden();

        $this->connecte($this->utilisateur('agent'));
        $id = $this->postJson(self::API . '/tags', ['nom' => 'reseau', 'couleur' => '#2563eb'])
            ->assertCreated()->assertHeader('Location')->json('data.id');
        $this->postJson(self::API . '/tags', ['nom' => 'reseau'])->assertStatus(422)->assertJsonValidationErrors(['nom']);   // unicité
        $this->postJson(self::API . '/tags', ['nom' => 'autre', 'couleur' => 'rouge'])->assertStatus(422)->assertJsonValidationErrors(['couleur']);
        $this->patchJson(self::API . "/tags/{$id}", ['couleur' => '#000000'])->assertOk()->assertJsonPath('data.couleur', '#000000');
        $this->deleteJson(self::API . "/tags/{$id}")->assertForbidden();   // suppression : admin seulement

        $this->connecte($this->utilisateur('admin'));
        $this->deleteJson(self::API . "/tags/{$id}")->assertNoContent();
    }

    public function test_liste_des_tags_avec_recherche_et_compteur(): void
    {
        $u = $this->connecte($this->utilisateur());
        $reseau = Tag::create(['nom' => 'reseau']);
        Tag::create(['nom' => 'materiel']);
        $this->ticket($u)->tags()->attach($reseau->id);

        $this->getJson(self::API . '/tags?q=res')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.tickets_count', 1);
        $this->getJson(self::API . '/tags')->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------ pièces jointes
    public function test_envoi_telechargement_et_suppression_d_un_fichier(): void
    {
        Storage::fake('local');
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);

        $r = $this->post(self::API . "/tickets/{$t->id}/pieces-jointes", ['fichier' => UploadedFile::fake()->create('rapport.pdf', 100, 'application/pdf')])
            ->assertCreated();
        $id = $r->json('data.id');
        $this->assertSame('rapport.pdf', $r->json('data.nom'));
        Storage::disk('local')->assertExists(\App\Models\PieceJointe::find($id)->chemin);

        $this->get(self::API . "/pieces-jointes/{$id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->deleteJson(self::API . "/pieces-jointes/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('pieces_jointes', ['id' => $id]);
    }

    public function test_fichier_dangereux_ou_trop_gros_refuse_422(): void
    {
        Storage::fake('local');
        $u = $this->connecte($this->utilisateur());
        $t = $this->ticket($u);
        $url = self::API . "/tickets/{$t->id}/pieces-jointes";

        $this->post($url, ['fichier' => UploadedFile::fake()->create('shell.php', 10)])->assertStatus(422)->assertJsonValidationErrors(['fichier']);
        $this->post($url, ['fichier' => UploadedFile::fake()->create('gros.pdf', 6000, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors(['fichier']);
        $this->post($url, [])->assertStatus(422)->assertJsonValidationErrors(['fichier']);
    }

    public function test_un_admin_telecharge_le_fichier_d_un_ticket_en_corbeille(): void
    {
        Storage::fake('local');
        $marie = $this->connecte($this->utilisateur());
        $t = $this->ticket($marie);
        $id = $this->post(self::API . "/tickets/{$t->id}/pieces-jointes", ['fichier' => UploadedFile::fake()->create('trace.pdf', 10, 'application/pdf')])->json('data.id');
        $t->delete();

        $this->connecte($this->utilisateur('admin'));
        $this->get(self::API . "/pieces-jointes/{$id}")->assertOk();
    }

    public function test_un_autre_utilisateur_ne_peut_pas_telecharger_le_fichier(): void
    {
        Storage::fake('local');
        $marie = $this->connecte($this->utilisateur());
        $t = $this->ticket($marie);
        $id = $this->post(self::API . "/tickets/{$t->id}/pieces-jointes", ['fichier' => UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf')])->json('data.id');

        $this->connecte($this->utilisateur());
        $this->get(self::API . "/pieces-jointes/{$id}")->assertForbidden();
    }

    // ------------------------------------------------------------ comptes (admin)
    public function test_gestion_des_comptes_reservee_a_l_admin(): void
    {
        $cible = $this->utilisateur('user');

        $this->connecte($this->utilisateur('agent'));
        $this->getJson(self::API . '/utilisateurs')->assertForbidden();

        $admin = $this->connecte($this->utilisateur('admin'));
        $this->getJson(self::API . '/utilisateurs?role=user&q=' . substr($cible->name, 0, 4))->assertOk();
        $this->patchJson(self::API . "/utilisateurs/{$cible->id}", ['role' => 'agent'])->assertOk()->assertJsonPath('data.role', 'agent');
        $this->patchJson(self::API . "/utilisateurs/{$cible->id}", ['role' => 'super-dieu'])->assertStatus(422);

        // Garde-fous : pas d'auto-rétrogradation ni d'auto-suppression
        $this->patchJson(self::API . "/utilisateurs/{$admin->id}", ['role' => 'user'])->assertStatus(409)->assertJsonPath('code', 'auto_modification');
        $this->deleteJson(self::API . "/utilisateurs/{$admin->id}")->assertStatus(409)->assertJsonPath('code', 'auto_suppression');
    }

    public function test_on_ne_supprime_pas_un_utilisateur_qui_a_des_tickets_409(): void
    {
        $avecTickets = $this->utilisateur();
        $this->ticket($avecTickets);
        $sansRien = $this->utilisateur();

        $this->connecte($this->utilisateur('admin'));
        $this->deleteJson(self::API . "/utilisateurs/{$avecTickets->id}")->assertStatus(409)->assertJsonPath('code', 'utilisateur_lie');
        $this->deleteJson(self::API . "/utilisateurs/{$sansRien->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $sansRien->id]);
    }

    public function test_liste_des_agents_pour_l_assignation(): void
    {
        $this->utilisateur('agent');
        $this->utilisateur('admin');
        $this->utilisateur('user');

        $this->connecte($this->utilisateur('user'));
        $this->getJson(self::API . '/agents')->assertForbidden();

        $this->connecte($this->utilisateur('agent'));
        $r = $this->getJson(self::API . '/agents')->assertOk();
        $this->assertCount(3, $r->json('data'));                  // 2 agents + 1 admin, aucun simple utilisateur
        $this->assertSame(['id', 'name'], array_keys($r->json('data.0'))); // pas d'email : minimisation des données
    }

    // ------------------------------------------------------------ statistiques
    public function test_statistiques_agents_et_admins_seulement_avec_zeros_explicites(): void
    {
        $u = $this->utilisateur();
        $agent = $this->utilisateur('agent');
        $this->ticket($u, ['statut' => 'en_cours', 'priorite' => 'haute', 'agent_id' => $agent->id]);
        $this->ticket($u, ['statut' => 'nouveau']);

        $this->connecte($u);
        $this->getJson(self::API . '/statistiques')->assertForbidden();

        $this->connecte($agent);
        $this->getJson(self::API . '/statistiques')->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.par_statut.nouveau', 1)
            ->assertJsonPath('data.par_statut.en_cours', 1)
            ->assertJsonPath('data.par_statut.ferme', 0)          // 0 plutôt qu'une clé absente
            ->assertJsonPath('data.par_priorite.critique', 0)
            ->assertJsonPath('data.charge_agents.0.tickets_ouverts', 1)
            ->assertJsonStructure(['data' => ['en_retard', 'crees_30_jours']]);
    }

    // ------------------------------------------------------------ supervision, format d'erreur, en-têtes
    public function test_sante_et_racine_sont_publiques(): void
    {
        $this->getJson(self::API . '/sante')->assertOk()->assertJsonPath('statut', 'ok')->assertJsonPath('base_de_donnees', 'ok');
        $this->getJson(self::API)->assertOk()->assertJsonStructure(['nom', 'version', 'liens' => ['tickets', 'connexion', 'documentation']]);
    }

    public function test_erreurs_toujours_en_json_meme_sans_en_tete_accept(): void
    {
        $this->get(self::API . '/route-inconnue')->assertNotFound()->assertJsonPath('code', 'route_introuvable');
    }

    public function test_405_methode_non_autorisee_avec_en_tete_allow(): void
    {
        $this->postJson(self::API . '/sante')->assertStatus(405)->assertJsonPath('code', 'methode_non_autorisee')->assertHeader('Allow');
    }

    public function test_page_de_demonstration_des_codes_http(): void
    {
        $this->getJson(self::API . '/demo/codes/200')->assertOk();
        $this->getJson(self::API . '/demo/codes/201')->assertCreated()->assertHeader('Location');
        $this->getJson(self::API . '/demo/codes/204')->assertNoContent();
        $this->getJson(self::API . '/demo/codes/401')->assertUnauthorized();
        $this->getJson(self::API . '/demo/codes/403')->assertForbidden();
        $this->getJson(self::API . '/demo/codes/404')->assertNotFound();
        $this->getJson(self::API . '/demo/codes/409')->assertStatus(409)->assertJsonPath('code', 'conflit');
        $this->getJson(self::API . '/demo/codes/422')->assertStatus(422)->assertJsonValidationErrors(['champ']);
        $this->getJson(self::API . '/demo/codes/429')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson(self::API . '/demo/codes/503')->assertStatus(503);
        $this->getJson(self::API . '/demo/codes/418')->assertStatus(400)->assertJsonPath('code', 'code_non_gere');
    }

    public function test_erreur_serveur_500_sans_fuite_de_details(): void
    {
        config(['app.debug' => false]);

        $r = $this->getJson(self::API . '/demo/codes/500')->assertStatus(500);
        $this->assertStringNotContainsString('Erreur de démonstration', $r->getContent());   // le message interne n'est pas exposé
        $this->assertStringNotContainsString('DemoController', $r->getContent());            // ni la trace
    }

    public function test_chaque_reponse_porte_un_identifiant_de_requete(): void
    {
        $this->getJson(self::API . '/sante')->assertHeader('X-Request-Id');
        $this->withHeader('X-Request-Id', 'mon-identifiant-123')->getJson(self::API . '/sante')->assertHeader('X-Request-Id', 'mon-identifiant-123');
    }

    public function test_cors_la_requete_de_pre_verification_autorise_le_frontend_angular(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options(self::API . '/tickets')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');

        // Une origine inconnue n'est pas autorisée
        $this->withHeaders(['Origin' => 'http://site-malveillant.example', 'Access-Control-Request-Method' => 'POST'])
            ->options(self::API . '/tickets')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
