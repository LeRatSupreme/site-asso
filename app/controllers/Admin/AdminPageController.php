<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Permissions;
use App\Models\Page;

/**
 * Gestion des pages CMS.
 */
final class AdminPageController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $this->renderAdmin('admin/pages/index', [
            'title' => 'Pages',
            'pages' => Page::allForAdmin(),
        ]);
    }

    public function form(?string $slug = null): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

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

    /**
     * Aperçu live de la page en cours d'édition (POST /admin/pages/preview).
     *
     * Rend EXACTEMENT le même gabarit que la page publique /p/{slug}
     * (vue pages/page + layout public) à partir des champs du formulaire
     * d'édition (title, slug, content…), SANS AUCUNE persistance : ni
     * INSERT ni UPDATE, la table pages n'est jamais écrite. Le contenu
     * transite par HtmlSanitizer::clean dans la vue, exactement comme le
     * rendu public — un <script> saisi y est donc neutralisé aussi.
     *
     * Garde et CSRF identiques aux autres POST admin : guardModule(CONTENT)
     * côté contrôleur, jeton vérifié par le routeur (Csrf::checkRequest :
     * champ _csrf OU en-tête X-CSRF-Token). L'aperçu est affiché dans une
     * iframe same-origin côté admin : les en-têtes globaux
     * X-Frame-Options: SAMEORIGIN et CSP frame-ancestors 'self'
     * l'autorisent déjà, aucune dérogation n'est posée ici.
     */
    public function preview(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        // Même forme de données que PageController::show() : la vue
        // générique n'utilise que title/content, avec repli du titre sur
        // le slug si le titre est vide (comportement du rendu public).
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug'] ?? ''));

        $this->render('pages/page', [
            'title'       => $title !== '' ? $title : $slug,
            'description' => trim((string) ($_POST['meta_description'] ?? '')),
            'page'        => [
                'title'   => $title,
                'content' => (string) ($_POST['content'] ?? ''),
            ],
        ]);
    }

    public function save(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $isNew = empty($_POST['id']);
        $id = Page::save($_POST);

        $this->audit($isNew ? 'page.create' : 'page.update', 'page', $id);
        $this->setFlash('success', 'Page enregistrée.');
        redirect(url('/admin/pages'));
    }

    public function delete(string $slug): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $page = Page::findBySlugAny($slug);
        if ($page !== null) {
            Page::deleteRow((string) $page['id']);
            $this->audit('page.delete', 'page', (string) $page['id']);
            $this->setFlash('success', 'Page supprimée.');
        }

        redirect(url('/admin/pages'));
    }
}
