<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Permissions;
use App\Models\Promotion;

/**
 * CRUD des promotions (promos & ventes spéciales de la cafétéria).
 */
final class AdminPromotionController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $this->renderAdmin('admin/promotions/index', [
            'title' => 'Promotions',
            'promotions' => Promotion::allForAdmin(),
        ]);
    }

    public function form(?string $id = null): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $promo = ['is_active' => 1, 'badge' => 'PROMO'];

        if ($id !== null) {
            $found = Promotion::find($id);
            if ($found !== null) {
                $promo = $found;
            }
        }

        $this->renderAdmin('admin/promotions/form', [
            'title' => isset($promo['id']) ? 'Modifier la promotion' : 'Nouvelle promotion',
            'promo' => $promo,
        ]);
    }

    public function save(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $data  = $_POST;
        $isNew = empty($data['id']);

        $id = Promotion::save($data);

        $this->audit($isNew ? 'promotion.create' : 'promotion.update', 'promotion', $id);

        $this->setFlash('success', 'Promotion enregistrée.');
        redirect(url('/admin/promotions'));
    }

    public function delete(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $promo = Promotion::find($id);
        if ($promo !== null) {
            Promotion::deleteRow($id);
            $this->audit('promotion.delete', 'promotion', $id);
            $this->setFlash('success', 'Promotion supprimée.');
        }

        redirect(url('/admin/promotions'));
    }
}
