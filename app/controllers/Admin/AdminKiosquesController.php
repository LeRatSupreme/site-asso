<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\Kiosk;
use App\Core\Auth;
use App\Models\Setting;

/**
 * Page « Kiosques » (groupe Système) : tous les liens kiosque au même
 * endroit — hub tout-en-un, comptages, liste de courses, récap du jour,
 * réappro — avec copie en un clic et régénération du jeton (qui révoque
 * TOUS les liens d'un coup).
 */
final class AdminKiosquesController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardSystemOrPage('kiosques');

        $token = Kiosk::token();

        // Liens à partager aux MEMBRES : saisie et suivi, sans données
        // financières sensibles (le récap membres n'affiche que le CA).
        $memberPages = [
            [
                'emoji' => '🏠',
                'label' => 'Hub kiosque — tout-en-un',
                'desc'  => "LA page à partager aux membres : comptage caisse, comptage inventaire, liste de courses et ventes du jour. L'identité (prénom, nom, rôle) y est obligatoire et tracée.",
                'path'  => '/kiosque/comptage/',
                'main'  => true,
            ],
            [
                'emoji' => '💵',
                'label' => 'Comptage caisse',
                'desc'  => 'Raccourci direct : compter le liquide présent dans la caisse.',
                'path'  => '/kiosque/comptage/caisse/',
            ],
            [
                'emoji' => '📦',
                'label' => 'Comptage inventaire',
                'desc'  => "Comptage à l'aveugle des produits, pause/reprise dynamique.",
                'path'  => '/kiosque/comptage/inventaire/',
            ],
            [
                'emoji' => '🛒',
                'label' => 'Liste de courses',
                'desc'  => "Ce qu'il faut racheter — cases cochées partagées en temps réel entre tous.",
                'path'  => '/kiosque/liste/',
            ],
            [
                'emoji' => '📊',
                'label' => 'Ventes du jour (membres)',
                'desc'  => 'Récap limité : CA du jour et produits vendus, sans bénéfice ni paiements.',
                'path'  => '/kiosque/comptage/jour-membre/',
            ],
        ];
        foreach ($memberPages as &$p) {
            $p['url'] = Kiosk::url($p['path']);
        }
        unset($p);

        // Liens ADMINS : données financières complètes, pour ton téléphone.
        // Les pages /admin/* demandent la connexion admin (une seule fois
        // par appareil) — les liens kiosque marchent sans connexion.
        $adminPages = [
            [
                'emoji' => '📊',
                'label' => 'Récap du jour — complet',
                'desc'  => 'CA, bénéfice, liquide/carte, top produits du jour. Lien kiosque, sans connexion.',
                'url'   => Kiosk::url('/kiosque/comptage/jour/'),
                'kiosk' => true,
            ],
            [
                'emoji' => '📈',
                'label' => 'Analytics',
                'desc'  => 'Tableaux de bord analytiques complets (CA, marges, heures, produits). Connexion admin requise.',
                'url'   => url('/admin/analytics'),
            ],
            [
                'emoji' => '🧮',
                'label' => 'Dashboard compta',
                'desc'  => "Vue d'ensemble de la période : CA, bénéfice, alertes, derniers imports. Connexion admin requise.",
                'url'   => url('/admin/compta'),
            ],
            [
                'emoji' => '📜',
                'label' => 'Journal des ventes',
                'desc'  => 'Toutes les ventes ligne par ligne, filtrables. Connexion admin requise.',
                'url'   => url('/admin/compta/ventes'),
            ],
            [
                'emoji' => '📅',
                'label' => 'Bilan annuel',
                'desc'  => 'Bilan mois par mois (CA, bénéfice, TVA). Connexion admin requise.',
                'url'   => url('/admin/compta/annuel'),
            ],
            [
                'emoji' => '📈',
                'label' => 'Réappro (téléphone)',
                'desc'  => 'Réapprovisionnement complet (coûts, packs, fournisseurs). Lien kiosque, sans connexion.',
                'url'   => Kiosk::url('/kiosque/reappro/'),
                'kiosk' => true,
            ],
        ];

        $this->renderAdmin('admin/kiosques/index', [
            'title'       => 'Kiosques',
            'user'        => Auth::user(),
            'memberPages' => $memberPages,
            'adminPages'  => $adminPages,
        ]);
    }

    /**
     * Régénère le jeton partagé : tous les liens kiosque existants cessent
     * de fonctionner (il faut redistribuer le nouveau).
     */
    public function regenerate(): void
    {
        $this->guardSystemOrPage('kiosques');

        Setting::set('reappro_kiosk_token', bin2hex(random_bytes(20)));
        $this->audit('kiosque.token.regenerate', 'setting', 'reappro_kiosk_token', ['regenerated' => true]);
        $this->setFlash('success', 'Lien kiosque régénéré — tous les anciens liens ne fonctionnent plus, redistribue le nouveau.');

        redirect(url('/admin/kiosques'));
    }
}
