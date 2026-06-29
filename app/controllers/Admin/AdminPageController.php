<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Page;

/**
 * Gestion des pages CMS.
 */
final class AdminPageController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/pages/index', [
            'title' => 'Pages',
            'pages' => Page::allForAdmin(),
        ]);
    }

    public function form(?string $slug = null): void
    {
        $this->guard();

        $page = ['is_published' => 1];
        if ($slug !== null) {
            $found = Page::findBySlugAny($slug);
            if ($found !== null) {
                $page = $found;
            }
        }

        $this->renderAdmin('admin/pages/form', [
            'title' => isset($page['id']) ? 'Modifier la page' : 'Nouvelle page',
            'page'  => $page,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        $isNew = empty($_POST['id']);
        $id = Page::save($_POST);

        $this->audit($isNew ? 'page.create' : 'page.update', 'page', $id);
        $this->setFlash('success', 'Page enregistrée.');
        redirect(url('/admin/pages'));
    }

    public function delete(string $slug): void
    {
        $this->guard();

        $page = Page::findBySlugAny($slug);
        if ($page !== null) {
            Page::deleteRow((string) $page['id']);
            $this->audit('page.delete', 'page', (string) $page['id']);
            $this->setFlash('success', 'Page supprimée.');
        }

        redirect(url('/admin/pages'));
    }
}
