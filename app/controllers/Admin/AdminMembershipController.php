<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Setting;
use App\Models\User;

/**
 * Gestion des adhésions / cotisations annuelles (Fonctionnalité 13).
 */
final class AdminMembershipController extends AdminBaseController
{
    /**
     * Tableau des adhésions (filtre par saison possible via ?season=).
     */
    public function index(): void
    {
        $this->guard();

        Membership::expireOld();

        $seasonParam = trim((string) ($_GET['season'] ?? ''));
        $memberships = $seasonParam !== ''
            ? Membership::allForAdminBySeason($seasonParam)
            : Membership::allForAdmin();

        $currentSeason = Membership::currentSeason();
        $seasons = Membership::seasons();
        if (!in_array($currentSeason, $seasons, true)) {
            array_unshift($seasons, $currentSeason);
        }

        $this->renderAdmin('admin/memberships/index', [
            'title'        => 'Adhésions',
            'memberships'  => $memberships,
            'seasons'      => $seasons,
            'currentSeason'=> $currentSeason,
            'seasonFilter' => $seasonParam,
            'stats'        => Membership::stats(),
            'price'        => Setting::get('membership_price', '5.00'),
            'enabled'      => Setting::getBool('membership_enabled', true),
        ]);
    }

    /**
     * Marque une adhésion comme payée (montant saisi, référence SumUp optionnelle).
     */
    public function markPaid(string $id): void
    {
        $this->guard();

        $membership = Membership::find($id);
        if ($membership === null) {
            $this->abort(404);
        }

        $amount = parseFrenchFloat((string) ($_POST['amount'] ?? ''));
        if ($amount <= 0) {
            $amount = (float) Setting::get('membership_price', '5.00');
        }

        $sumupRef = trim((string) ($_POST['sumup_ref'] ?? ''));

        Membership::markPaid($id, $amount, $sumupRef !== '' ? $sumupRef : null);

        AuditLog::log('membership.mark_paid', Auth::id(), 'membership', $id, [
            'season'  => $membership['season'] ?? null,
            'amount'  => $amount,
            'user_id' => $membership['user_id'] ?? null,
        ]);

        $this->setFlash('success', sprintf('Adhésion %s marquée comme payée (%s).',
            e((string) ($membership['season'] ?? '')), formatPrice($amount)));
        redirect(url('/admin/memberships'));
    }

    /**
     * Crée une adhésion PENDING pour un utilisateur (depuis /admin/users).
     */
    public function createForMember(string $userId): void
    {
        $this->guard();

        $target = User::find($userId);
        if ($target === null) {
            $this->abort(404);
        }

        $membershipId = Membership::ensureForCurrentSeason($userId);

        if ($membershipId === null) {
            $this->setFlash('error', 'Impossible de créer l\'adhésion.');
            redirect(url('/admin/users'));
        }

        // Si un montant est fourni, on marque directement comme payée.
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        if ($amountRaw !== '' && parseFrenchFloat($amountRaw) > 0) {
            Membership::markPaid($membershipId, parseFrenchFloat($amountRaw), null);
            $this->setFlash('success', sprintf(
                'Adhésion %s créée et marquée payée pour %s (%s).',
                e(Membership::currentSeason()),
                e(trim((string) $target['prenom'] . ' ' . (string) $target['nom'])),
                formatPrice(parseFrenchFloat($amountRaw))
            ));
        } else {
            $this->setFlash('success', sprintf(
                'Adhésion %s créée (en attente de paiement) pour %s.',
                e(Membership::currentSeason()),
                e(trim((string) $target['prenom'] . ' ' . (string) $target['nom']))
            ));
        }

        AuditLog::log('membership.create', Auth::id(), 'membership', $membershipId, [
            'user_id' => $userId,
            'season'  => Membership::currentSeason(),
        ]);

        redirect(url('/admin/users'));
    }
}
