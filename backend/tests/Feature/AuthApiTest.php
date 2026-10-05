<?php

namespace Tests\Feature;

use App\Models\User;

class AuthApiTest extends ApiTestCase
{
    private function donneesInscription(array $extra = []): array
    {
        return array_merge([
            'name' => 'Nouvelle Personne', 'email' => 'nouvelle@test.test',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ], $extra);
    }

    public function test_inscription_cree_un_compte_et_renvoie_un_jeton(): void
    {
        $this->postJson(self::API . '/auth/inscription', $this->donneesInscription())
            ->assertCreated()
            ->assertJsonPath('data.email', 'nouvelle@test.test')
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('type', 'Bearer')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role'], 'token', 'expire_le', 'capacites']);
    }

    public function test_on_ne_peut_pas_se_donner_le_role_admin_a_l_inscription(): void
    {
        $this->postJson(self::API . '/auth/inscription', $this->donneesInscription(['role' => 'admin']))->assertCreated();

        $this->assertSame('user', User::where('email', 'nouvelle@test.test')->value('role'));
    }

    public function test_inscription_invalide_renvoie_422_au_format_uniforme(): void
    {
        $this->postJson(self::API . '/auth/inscription', ['email' => 'pas-un-email', 'password' => 'court', 'password_confirmation' => 'autre'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_echouee')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_connexion_puis_utilisation_du_jeton(): void
    {
        $u = $this->utilisateur('user', ['email' => 'marie@test.test']);

        $jeton = $this->postJson(self::API . '/auth/connexion', ['email' => 'marie@test.test', 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $jeton)
            ->getJson(self::API . '/auth/moi')
            ->assertOk()->assertJsonPath('data.id', $u->id);
    }

    public function test_identifiants_invalides_donnent_le_meme_message_que_l_email_soit_connu_ou_non(): void
    {
        $this->utilisateur('user', ['email' => 'marie@test.test']);

        $a = $this->postJson(self::API . '/auth/connexion', ['email' => 'marie@test.test', 'password' => 'faux'])
            ->assertStatus(401)->assertJsonPath('code', 'identifiants_invalides');
        $b = $this->postJson(self::API . '/auth/connexion', ['email' => 'inconnu@test.test', 'password' => 'faux'])
            ->assertStatus(401)->assertJsonPath('code', 'identifiants_invalides');

        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_sans_jeton_401_json_avec_en_tete_www_authenticate(): void
    {
        $this->getJson(self::API . '/tickets')
            ->assertStatus(401)
            ->assertJsonPath('code', 'non_authentifie')
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_deconnexion_revoque_le_jeton(): void
    {
        $this->utilisateur('user', ['email' => 'marie@test.test']);
        $jeton = $this->postJson(self::API . '/auth/connexion', ['email' => 'marie@test.test', 'password' => 'password'])->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer ' . $jeton)
            ->postJson(self::API . '/auth/deconnexion')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_liste_et_revocation_de_ses_jetons_seulement(): void
    {
        $moi = $this->utilisateur();
        $autre = $this->utilisateur();
        $moi->createToken('telephone');
        $moi->createToken('portable');
        $jetonAutre = $autre->createToken('autre')->accessToken;

        $this->connecte($moi);
        $this->getJson(self::API . '/auth/jetons')->assertOk()->assertJsonCount(2, 'data');
        // Le jeton d'un autre compte est invisible : 404, pas 403 (on ne révèle pas son existence)
        $this->deleteJson(self::API . '/auth/jetons/' . $jetonAutre->id)->assertNotFound()->assertJsonPath('code', 'ressource_introuvable');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $jetonAutre->id]);
    }

    public function test_changement_de_mot_de_passe(): void
    {
        $u = $this->utilisateur('user', ['email' => 'marie@test.test']);
        $this->connecte($u);

        $this->putJson(self::API . '/auth/mot-de-passe', ['mot_de_passe_actuel' => 'faux', 'password' => 'nouveau123', 'password_confirmation' => 'nouveau123'])
            ->assertStatus(422)->assertJsonValidationErrors(['mot_de_passe_actuel']);

        $this->putJson(self::API . '/auth/mot-de-passe', ['mot_de_passe_actuel' => 'password', 'password' => 'nouveau123', 'password_confirmation' => 'nouveau123'])
            ->assertNoContent();

        $this->postJson(self::API . '/auth/connexion', ['email' => 'marie@test.test', 'password' => 'nouveau123'])->assertOk();
    }

    public function test_modification_du_profil_et_unicite_de_l_email(): void
    {
        $this->utilisateur('user', ['email' => 'pris@test.test']);
        $u = $this->connecte($this->utilisateur());

        $this->patchJson(self::API . '/auth/moi', ['name' => 'Nouveau Nom'])->assertOk()->assertJsonPath('data.name', 'Nouveau Nom');
        $this->patchJson(self::API . '/auth/moi', ['email' => 'pris@test.test'])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_la_connexion_est_limitee_a_5_essais_par_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API . '/auth/connexion', ['email' => 'x@test.test', 'password' => 'faux'])->assertStatus(401);
        }

        $this->postJson(self::API . '/auth/connexion', ['email' => 'x@test.test', 'password' => 'faux'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'trop_de_requetes')
            ->assertHeader('Retry-After');
    }
}
