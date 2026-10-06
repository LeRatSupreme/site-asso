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
        $pages = [
            [
                'emoji' => '🏠',
                'label' => 'Hub kiosque — tout-en-un',
                'desc'  => "LA page à partager aux membres : comptage caisse, comptage inventaire, liste de courses et récap du jour. L'identité (prénom, nom, rôle) y est obligatoire et tracée.",
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
                'label' => 'Récap du jour',
                'desc'  => "CA, bénéfice et top produits du jour, auto-actualisé toutes les 60 s.",
                'path'  => '/kiosque/comptage/jour/',
            ],
            [
                'emoji' => '📈',
                'label' => 'Réappro (téléphone)',
                'desc'  => 'Réapprovisionnement complet, en lecture seule.',
                'path'  => '/kiosque/reappro/',
            ],
        ];
        foreach ($pages as &$p) {
            $p['url'] = Kiosk::url($p['path']);
        }
        unset($p);

        $this->renderAdmin('admin/kiosques/index', [
            'title' => 'Kiosques',
            'user'  => Auth::user(),
            'pages' => $pages,
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
