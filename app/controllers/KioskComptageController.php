<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Compta\CashLedger;
use App\Core\Compta\ProductAutoSync;
use App\Core\Compta\StockPublic;
use App\Core\Compta\SumUpCsvParser;
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
     * Identité OBLIGATOIRE du membre kiosque : prénom, nom et rôle (ex.
     * « vice-trésorier »), saisis via la pastille profil. Les trois parties
     * sont exigées — sans elles, tout enregistrement est refusé. La trace
     * générée ressemble à « Jean Dupont (vice-trésorier) ».
     */
    private function whoFromPost(): string
    {
        $p = trim(strip_tags((string) ($_POST['who_prenom'] ?? '')));
        $n = trim(strip_tags((string) ($_POST['who_nom'] ?? '')));
        $a = trim(strip_tags((string) ($_POST['who_alias'] ?? '')));

        if ($p === '' || $n === '' || $a === '') {
            return '';
        }

        return mb_substr($p . ' ' . $n . ' (' . $a . ')', 0, 200);
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
     * Récap du jour (kiosque, lecture seule) : CA, bénéfice, ventes de la
     * journée en cours (heure de Paris) — mêmes calculs que le dashboard
     * analytics (montants personnalisés inclus dans le CA, exclus du
     * bénéfice). Auto-actualisé par la vue via jourData().
     */
    public function jour(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $stats = $this->jourStats();
        $this->renderKiosk('admin/compta/kiosk-jour', [
            'title' => 'Récap du jour',
            'token' => $token,
            'stats' => $stats,
        ]);
    }

    /** Données du récap du jour (JSON) — consommées par l'auto-refresh. */
    public function jourData(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->tokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false}';

            return;
        }

        echo json_encode(['ok' => true] + $this->jourStats());
    }

    /**
     * Récap du jour version MEMBRES (partagée sur le hub) : uniquement le
     * CA et les produits vendus — ni bénéfice, ni paiements, ni CA/produit.
     */
    public function jourMembre(string $token): void
    {
        if (!$this->tokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/compta/kiosk-jour-membre', [
            'title' => 'Ventes du jour',
            'token' => $token,
            'stats' => $this->jourMembreStats(),
        ]);
    }

    /** Données du récap membres (JSON) — consommées par l'auto-refresh. */
    public function jourMembreData(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->tokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false}';

            return;
        }

        echo json_encode(['ok' => true] + $this->jourMembreStats());
    }

    /**
     * Agrégats du jour (Europe/Paris, bornes 00:00 → 23:59 locales).
     *
     * @return array<string,mixed>
     */
    private function jourStats(): array
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $agg = Sale::aggregatesBetween($today, $today);
        $split = Sale::paymentSplitBetween($today, $today);
        $top = Sale::topProductsBetween($today, $today, 5);
        $tx = Sale::transactionsBetween($today, $today);

        return [
            'date'         => $today,
            'ca'           => round($agg['ca'], 2),
            'profit'       => round($agg['profit'], 2),
            'qty'          => $agg['qty'],
            'ca_products'  => round($agg['ca_products'], 2),
            'transactions' => $tx,
            'liquide'      => round((float) ($split['LIQUIDE'] ?? 0), 2),
            'carte'        => round((float) ($split['CARTE'] ?? 0), 2),
            'top'          => $top,
            'computed_at'  => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i:s'),
        ];
    }

    /**
     * Version restreinte pour les membres : CA global et produits vendus
     * (quantités), sans montants par produit ni bénéfice.
     *
     * @return array<string,mixed>
     */
    private function jourMembreStats(): array
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $agg = Sale::aggregatesBetween($today, $today);
        $topQty = array_map(
            static fn (array $t): array => ['label' => (string) $t['label'], 'qty' => (int) $t['qty']],
            Sale::topProductsBetween($today, $today, 10)
        );

        return [
            'date'        => $today,
            'ca'          => round($agg['ca'], 2),
            'qty'         => $agg['qty'],
            'top'         => $topQty,
            'computed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i:s'),
        ];
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

        $who = $this->whoFromPost();
        if ($who === '') {
            $this->setFlash('error', 'Indique qui tu es (prénom, nom et rôle) via la pastille en haut à droite avant d\'enregistrer.');
            redirect($back);
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $res   = CashLedger::recordCount($counted, $label, $who, null);
        AuditLog::log('cash.count', null, 'cash', $res['count_id'], [
            'counted'     => $counted,
            'theoretical' => round($counted - $res['ecart'], 2),
            'ecart'       => $res['ecart'],
            'via'         => 'kiosque',
            'who'         => $who,
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
        $parser = new SumUpCsvParser();

        // Catégorie de chaque clé produit (la plus fréquente dans les ventes)
        // pour trier la liste par rayon.
        $catByKey = [];
        try {
            $catRows = InventoryCount::connection()->query(
                "SELECT COALESCE(NULLIF(TRIM(product_key), ''), TRIM(description)) AS k,
                        MAX(COALESCE(category, '')) AS cat
                 FROM sales
                 WHERE category IS NOT NULL AND category <> ''
                 GROUP BY k"
            )->fetchAll();
            foreach ($catRows as $row) {
                $catByKey[strtolower(trim((string) $row['k']))] = trim((string) $row['cat']);
            }
        } catch (\Throwable $e) {
            // Pas de catégories disponibles : tri alphabétique simple.
            error_log('[kiosque] catégories indisponibles : ' . $e->getMessage());
        }

        $active = [];
        $paused = [];
        foreach (Sale::distinctProducts() as $key) {
            $k = trim((string) $key);
            // Artefact SumUp (« custom amount », « montant personnalisé ») :
            // jamais un produit à compter.
            if ($k === '' || $parser->isCustomAmount($k)) {
                continue;
            }
            $cat = $catByKey[strtolower($k)] ?? 'Divers';
            if ($cat === '') {
                $cat = 'Divers';
            }
            if (isset($pausedKeys[$k])) {
                $paused[] = ['key' => $k, 'cat' => $cat, 'paused' => true];
            } else {
                $active[] = ['key' => $k, 'cat' => $cat, 'paused' => false];
            }
        }
        $byCat = static fn(array $a, array $b): int =>
            strcasecmp($a['cat'], $b['cat']) ?: strcasecmp($a['key'], $b['key']);
        usort($active, $byCat);
        usort($paused, $byCat);

        $this->renderKiosk('admin/compta/kiosk-inventaire', [
            'title' => 'Comptage inventaire',
            'token' => $token,
            'active' => $active,
            'paused' => $paused,
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
        $who = $this->whoFromPost();
        if ($who === '') {
            $this->setFlash('error', 'Indique qui tu es (prénom, nom et rôle) via la pastille en haut à droite avant d\'enregistrer.');
            redirect($back);
        }
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

            InventoryCount::record($productKey, $counted, null, $who);
            $done++;
        }

        if ($done === 0) {
            $this->setFlash('error', 'Aucun comptage saisi.');
            redirect($back);
        }

        AuditLog::log('compta.inventory.count', null, 'inventory_count', null, [
            'products' => $done,
            'via'      => 'kiosque',
            'who'      => $who,
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

    /**
     * Met en pause / réactive un produit DEPUIS le kiosque (réponse JSON,
     * consommée par la page de comptage qui déplace la ligne sans recharger).
     * Mêmes mécaniques que l'admin : drapeau product_discontinued, aucune
     * suppression de données, traçabilité (updated_by = identité kiosque).
     */
    public function inventairePause(string $token): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->tokenOk($token)) {
            http_response_code(403);
            echo '{"ok":false,"error":"lien invalide"}';

            return;
        }

        $key   = trim((string) ($_POST['key'] ?? ''));
        $state = ($_POST['state'] ?? '') === 'resume' ? 'resume' : 'pause';
        $who   = $this->whoFromPost();

        if ($who === '') {
            http_response_code(422);
            echo '{"ok":false,"error":"identite requise (prenom, nom, role)"}';

            return;
        }

        $parser = new SumUpCsvParser();
        if ($key === '' || $parser->isCustomAmount($key)) {
            http_response_code(422);
            echo '{"ok":false,"error":"produit invalide"}';

            return;
        }

        // La clé doit exister (produit déjà vendu) ou être déjà en pause :
        // impossible de créer une entrée parasite via le kiosque.
        $known = ProductDiscontinued::isDiscontinued($key);
        if (!$known) {
            foreach (Sale::distinctProducts() as $k) {
                if (trim((string) $k) === $key) {
                    $known = true;
                    break;
                }
            }
        }
        if (!$known) {
            http_response_code(422);
            echo '{"ok":false,"error":"produit inconnu"}';

            return;
        }

        if ($state === 'pause') {
            ProductDiscontinued::mark($key, $who);
        } else {
            ProductDiscontinued::resume($key);
        }

        AuditLog::log(
            $state === 'pause' ? 'compta.product.pause' : 'compta.product.resume',
            null,
            'product_discontinued',
            $key,
            ['via' => 'kiosque', 'who' => $who]
        );

        try {
            ProductAutoSync::sync();
        } catch (\Throwable) {
            // Jamais bloquant.
        }
        StockPublic::invalidate();

        echo json_encode(['ok' => true, 'key' => $key, 'paused' => $state === 'pause']);
    }
}
