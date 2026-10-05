<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\CashLedger;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\StockPublic;
use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\InventoryCount;
use App\Models\ProductDiscontinued;
use App\Models\Setting;
use App\Models\Sale;

/**
 * Comptage « kiosque » par lien secret (même jeton que Réappro/Liste) :
 * hub avec deux saisies — comptage de caisse et comptage inventaire à
 * l'aveugle. Mêmes mécaniques serveur que les pages admin (écarts
 * calculés côté serveur, historique complet), sans connexion : le jeton
 * secret EST l'authentification. Toutes les traces sont marquées
 * « kiosque » (created_by / audit).
 */
final class KioskComptageController extends Controller
{
    private function tokenOk(string $token): bool
    {
        $expected = trim((string) Setting::get('reappro_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    private function deny(): void
    {
        http_response_code(403);
        echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';
    }

    /**
     * Hub : les deux comptages accessibles.
     */
    public function hub(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-comptage', [
            'title' => 'Comptage',
            'token' => $token,
        ]);
    }

    /**
     * Comptage de caisse (à l'aveugle : le théorique n'est pas affiché).
     */
    public function caisse(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-caisse', [
            'title' => 'Comptage caisse',
            'token' => $token,
        ]);
    }

    /**
     * Enregistre le comptage de caisse saisi en kiosque.
     */
    public function caisseSave(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $back = url('/kiosque/comptage/caisse/' . $token);

        $counted = parseFrenchFloat((string) ($_POST['counted'] ?? ''));
        if ($counted < 0) {
            $this->setFlash('error', 'Montant compté invalide.');
            redirect($back);
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $res   = CashLedger::recordCount($counted, $label, 'kiosque', null);
        AuditLog::log('cash.count', null, 'cash', $res['count_id'], [
            'counted'     => $counted,
            'theoretical' => round($counted - $res['ecart'], 2),
            'ecart'       => $res['ecart'],
            'via'         => 'kiosque',
        ]);

        if ($res['ecart'] < 0) {
            $this->setFlash('error', sprintf('Manquant de %s constaté — caisse réalignée.', formatPrice(abs($res['ecart']))));
        } elseif ($res['ecart'] > 0) {
            $this->setFlash('success', sprintf('Surplus de %s constaté — caisse réalignée.', formatPrice($res['ecart'])));
        } else {
            $this->setFlash('success', 'Comptage exact : aucun écart.');
        }

        redirect($back);
    }

    /**
     * Comptage inventaire « à l'aveugle » (théoriques non affichés,
     * produits en pause listés sans saisie).
     */
    public function inventaire(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $pausedKeys = array_flip(ProductDiscontinued::keys());
        $active = [];
        $paused = [];
        foreach (Sale::distinctProducts() as $key) {
            $k = (string) $key;
            if ($k === '') {
                continue;
            }
            if (isset($pausedKeys[$k])) {
                $paused[] = ['key' => $k, 'paused' => true];
            } else {
                $active[] = ['key' => $k, 'paused' => false];
            }
        }
        $byName = static fn(array $a, array $b): int => strcasecmp($a['key'], $b['key']);
        usort($active, $byName);
        usort($paused, $byName);

        $this->renderKiosk('admin/compta/kiosk-inventaire', [
            'title' => 'Comptage inventaire',
            'token' => $token,
            'rows'  => array_merge($active, $paused),
        ]);
    }

    /**
     * Enregistre le comptage inventaire saisi en kiosque : même mécanique
     * que les pages admin (théorique côté serveur, écarts historisés,
     * synchro carte non bloquante). Les clés en pause sont ignorées.
     */
    public function inventaireSave(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $back = url('/kiosque/comptage/inventaire/' . $token);

        $counts = $_POST['count'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $pausedKeys = array_flip(ProductDiscontinued::keys());
        $done = 0;
        $gaps = 0;

        foreach ($counts as $key => $value) {
            $productKey = trim((string) $key);
            if ($productKey === '' || trim((string) $value) === '' || isset($pausedKeys[$productKey])) {
                continue;
            }

            $counted = (int) $value;
            if ($counted < 0) {
                continue;
            }

            InventoryCount::record($productKey, $counted, null, 'kiosque');
            $done++;
        }

        if ($done === 0) {
            $this->setFlash('error', 'Aucun comptage saisi.');
            redirect($back);
        }

        AuditLog::log('compta.inventory.count', null, 'inventory_count', null, [
            'products' => $done,
            'via'      => 'kiosque',
        ]);

        try {
            ProductAutoSync::sync();
        } catch (\Throwable) {
            // Jamais bloquant.
        }
        StockPublic::invalidate();

        $this->setFlash('success', sprintf('%d produit(s) compté(s).', $done));

        redirect($back);
    }
}
