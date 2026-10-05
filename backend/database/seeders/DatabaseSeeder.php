<?php

namespace Database\Seeders;

use App\Models\Commentaire;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Données de démonstration, reproductibles (graine fixe). Mot de passe de tous les comptes : « password ». */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42);

        // forceCreate : « role » n'est pas $fillable (protection contre le mass assignment) ; le mot de passe est haché par le cast
        $admin = User::forceCreate(['name' => 'Admin Demo', 'email' => 'admin@demo.test', 'password' => 'password', 'role' => 'admin']);
        $agents = [
            User::forceCreate(['name' => 'Agent Koffi', 'email' => 'agent@demo.test', 'password' => 'password', 'role' => 'agent']),
            User::forceCreate(['name' => 'Agent Awa', 'email' => 'agent2@demo.test', 'password' => 'password', 'role' => 'agent']),
        ];
        $users = [
            User::forceCreate(['name' => 'Utilisateur Marie', 'email' => 'user@demo.test', 'password' => 'password', 'role' => 'user']),
            User::forceCreate(['name' => 'Utilisateur Paul', 'email' => 'user2@demo.test', 'password' => 'password', 'role' => 'user']),
        ];

        $tags = collect([['reseau', '#2563eb'], ['imprimante', '#16a34a'], ['logiciel', '#9333ea'], ['materiel', '#ea580c'], ['securite', '#dc2626'], ['acces', '#0891b2']])
            ->map(fn ($t) => Tag::create(['nom' => $t[0], 'couleur' => $t[1]]));

        $sujets = [
            'Imprimante du 2e étage en panne', 'Impossible de se connecter au VPN', 'Écran qui clignote', 'Demande d\'accès au dossier partagé',
            'Mise à jour du logiciel de paie', 'Courriels non reçus depuis ce matin', 'Poste très lent au démarrage', 'Mot de passe expiré',
            'Installer le logiciel de statistiques', 'Wi-Fi instable en salle de réunion', 'Clavier défectueux', 'Erreur 500 sur le portail interne',
            'Sauvegarde non terminée cette nuit', 'Nouvelle recrue : créer les accès', 'Téléphone IP sans tonalité', 'Souris sans fil ne répond plus',
            'Certificat HTTPS expiré', 'Lenteur de la base de données', 'Scanner ne détecte plus le réseau', 'Demande de second écran',
        ];
        $statuts = ['nouveau', 'en_cours', 'en_attente', 'resolu', 'ferme'];
        $priorites = ['basse', 'normale', 'normale', 'haute', 'critique'];

        for ($i = 0; $i < 40; $i++) {
            $statut = $statuts[mt_rand(0, 4)];
            $date = now()->subDays(mt_rand(0, 24))->subMinutes(mt_rand(0, 1000));

            $ticket = new Ticket([
                'titre' => $sujets[$i % count($sujets)] . ($i >= count($sujets) ? ' (suite)' : ''),
                'description' => 'Description détaillée du problème n°' . ($i + 1) . ' : voir les étapes pour reproduire et les messages d\'erreur observés.',
                'priorite' => $priorites[mt_rand(0, 4)],
                'echeance' => mt_rand(0, 2) === 0 ? now()->addDays(mt_rand(-5, 10))->toDateString() : null,
            ]);
            $ticket->forceFill([
                'reference' => 'TK-' . sprintf('%07d', 1000 + $i),
                'statut' => $statut,
                'auteur_id' => $users[$i % 2]->id,
                'agent_id' => $statut === 'nouveau' ? null : $agents[mt_rand(0, 1)]->id,
                'resolu_le' => in_array($statut, ['resolu', 'ferme'], true) ? $date->copy()->addDay() : null,
                'created_at' => $date,
                'updated_at' => $date,
            ])->save();

            $ticket->tags()->sync($tags->shuffle()->take(mt_rand(0, 2))->pluck('id')->all());

            for ($c = 0; $c < mt_rand(0, 3); $c++) {
                $interne = $c === 1;
                $commentaire = new Commentaire(['contenu' => $interne ? 'Note interne : vérifier la garantie du matériel.' : 'Merci de préciser depuis quand le problème est apparu.', 'interne' => $interne]);
                $commentaire->forceFill([
                    'ticket_id' => $ticket->id,
                    'auteur_id' => $interne || $c === 0 ? $agents[0]->id : $ticket->auteur_id,
                    'created_at' => $date->copy()->addHours($c + 1),
                    'updated_at' => $date->copy()->addHours($c + 1),
                ])->save();
            }
        }

        // Quelques tickets dans la corbeille pour tester GET /tickets/corbeille
        Ticket::query()->orderBy('id')->limit(2)->get()->each->delete();
    }
}
