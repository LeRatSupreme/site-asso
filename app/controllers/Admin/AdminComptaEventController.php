<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Models\ComptaEvent;
use App\Models\ComptaEventCost;
use App\Models\Sale;

/**
 * Événements de trésorerie : un événement porte le nom du bouton SumUp,
 * ses ventes importées s'y rattachent, les coûts y sont saisis (et
 * deviennent des dépenses Événements). Réservé aux rôles ADMIN et
 * TRESORERIE.
 */
final class AdminComptaEventController extends AdminBaseController
{
    // -----------------------------------------------------------------
    //  Événements de trésorerie
    // -----------------------------------------------------------------

    public function index(): void
    {
        $user = $this->guardCompta();

        $period = ComptaCalc::resolvePeriod($_GET['period'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
        $events = ComptaEvent::listBetween($period['from'], $period['to']);

        // KPIs agrégés de la période, calculés en PHP.
        $caT = 0.0;
        $costsT = 0.0;
        $profitT = 0.0;
        foreach ($events as $ev) {
            $caT += (float) $ev['ca'];
            $costsT += (float) $ev['costs'];
            $profitT += (float) $ev['profit'];
        }

        $this->renderAdmin('admin/compta/evenements', [
            'title'         => 'Événements',
            'user'          => $user,
            'period'        => $period,
            'periodOptions' => ComptaCalc::PERIOD_OPTIONS,
            'events'        => $events,
            'caT'           => $caT,
            'costsT'        => $costsT,
            'profitT'       => $profitT,
            'count'         => count($events),
        ]);
    }

    public function save(): void
    {
        $user = $this->guardCompta();

        $name = trim((string) ($_POST['name'] ?? ''));

        $id = ComptaEvent::create([
            'name'       => $name,
            'date_from'  => trim((string) ($_POST['date_from'] ?? '')),
            'date_to'    => trim((string) ($_POST['date_to'] ?? '')),
            'notes'      => trim((string) ($_POST['notes'] ?? '')),
            'created_by' => $user['id'] ?? null,
        ]);

        if ($id === '') {
            $this->setFlash('error', 'Nom et date requis.');
            redirect(url('/admin/compta/evenements'));
        }

        $this->audit('compta.event.create', 'compta_event', $id, ['name' => $name]);
        $this->setFlash('success', 'Événement créé — pense à utiliser ce nom exact comme bouton SumUp.');
        redirect(url('/admin/compta/evenements'));
    }

    public function delete(string $id): void
    {
        $user = $this->guardCompta();

        ComptaEvent::delete($id);

        $this->audit('compta.event.delete', 'compta_event', $id);
        $this->setFlash('success', 'Événement supprimé (coûts et dépenses liées également).');
        redirect(url('/admin/compta/evenements'));
    }

    public function detail(string $id): void
    {
        $user = $this->guardCompta();

        $event = ComptaEvent::findForDetail($id);
        if ($event === null) {
            $this->setFlash('error', 'Événement introuvable.');
            redirect(url('/admin/compta/evenements'));
        }

        $stats = ComptaEvent::stats($event);
        $costs = ComptaEventCost::forEvent($id);
        $costsTotal = ComptaEvent::costsTotal($id);
        $sales = Sale::journalForProductBetween(
            (string) $event['name'],
            (string) $event['date_from'],
            (string) $event['date_to']
        );

        // Seuil de rentabilité : nombre d'entrées à vendre pour couvrir
        // les coûts (coûts / prix moyen par entrée).
        $breakEven = $stats['avg'] > 0.0 ? (int) ceil($costsTotal / $stats['avg']) : null;

        $this->renderAdmin('admin/compta/evenement_detail', [
            'title'      => (string) $event['name'],
            'user'       => $user,
            'event'      => $event,
            'stats'      => $stats,
            'costs'      => $costs,
            'costsTotal' => $costsTotal,
            'sales'      => $sales,
            'breakEven'  => $breakEven,
        ]);
    }

    // -----------------------------------------------------------------
    //  Coûts des événements
    // -----------------------------------------------------------------

    public function saveCost(string $id): void
    {
        $user = $this->guardCompta();

        $event = ComptaEvent::findForDetail($id);
        if ($event === null) {
            $this->setFlash('error', 'Événement introuvable.');
            redirect(url('/admin/compta/evenements'));
        }

        $costId = ComptaEventCost::create($id, [
            'spent_at'          => trim((string) ($_POST['spent_at'] ?? '')),
            'label'             => trim((string) ($_POST['label'] ?? '')),
            'amount_ttc'        => parseFrenchFloat((string) ($_POST['amount'] ?? '')),
            'linked_event_name' => (string) $event['name'],
            'created_by'        => $user['id'] ?? null,
        ]);

        if ($costId === '') {
            $this->setFlash('error', 'Libellé et montant requis.');
            redirect(url('/admin/compta/evenements/' . rawurlencode($id)));
        }

        $this->audit('compta.event.cost.create', 'compta_event_cost', $costId, [
            'event_id' => $id,
            'label'    => trim((string) ($_POST['label'] ?? '')),
        ]);
        $this->setFlash('success', 'Coût enregistré (dépense Événements créée).');
        redirect(url('/admin/compta/evenements/' . rawurlencode($id)));
    }

    public function deleteCost(string $id, string $cid): void
    {
        $user = $this->guardCompta();

        ComptaEventCost::delete($cid);

        $this->audit('compta.event.cost.delete', 'compta_event_cost', $cid, ['event_id' => $id]);
        $this->setFlash('success', 'Coût supprimé (dépense liée également).');
        redirect(url('/admin/compta/evenements/' . rawurlencode($id)));
    }
}
