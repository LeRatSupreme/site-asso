<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Setting;

/**
 * Paramètres du site (regroupés par groupe).
 */
final class AdminSettingController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $settings = Setting::all();

        // Regroupement par `group`.
        $groups = [];
        foreach ($settings as $s) {
            $groups[(string) ($s['group'] ?: 'general')][] = $s;
        }

        $this->renderAdmin('admin/settings/index', [
            'title'   => 'Paramètres',
            'groups'  => $groups,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        foreach (($_POST['settings'] ?? []) as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            Setting::set($key, (string) $value);
        }

        $this->audit('settings.update', 'settings', null);
        $this->setFlash('success', 'Paramètres enregistrés.');
        redirect(url('/admin/settings'));
    }
}
