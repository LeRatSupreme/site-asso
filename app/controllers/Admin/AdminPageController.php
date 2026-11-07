<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
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

    /**
     * AJAX : réordonne les pages CMS (drag & drop de la liste admin,
     * même mécanique que l'ordre de la sidebar).
     *
     * Corps JSON {"ids": ["page_b", "page_a", …]} — la liste COMPLÈTE des
     * ids dans l'ordre souhaité — ou le même champ encodé en formulaire
     * classique (ids[]=page_b&ids[]=page_a). Réponse minimale {ok:true} ;
     * erreurs 400/403 en JSON, jamais de HTML pour ce endpoint.
     *
     * Garde : même accès que les autres actions pages ci-dessus
     * (guardModule(CONTENT)) — répliquée ici en réponse JSON, car la
     * garde standard redirige ou rend du HTML. CSRF : vérifié en amont
     * par le routeur pour tout POST /admin/* (Csrf::checkRequest, header
     * X-CSRF-Token ou champ _csrf).
     */
    public function reorder(): void
    {
        $role = Auth::check() ? Auth::role() : null;
        $moduleOk = Permissions::userHasExtraPage(Permissions::MODULE_CONTENT)
            ? in_array($role, Permissions::adminRoles(), true)
            : in_array($role, Permissions::rolesForModule(Permissions::MODULE_CONTENT), true);

        if (!$moduleOk) {
            $this->json(['ok' => false, 'error' => 'Accès refusé.'], 403);
        }

        // JSON body (fetch) ou formulaire classique ($_POST) : même
        // contrat que les check-in événements.
        $corps = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($corps)) {
            $corps = $_POST;
        }

        $ids = $corps['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            $this->json(['ok' => false, 'error' => 'Payload « ids » manquant ou invalide.'], 400);
        }

        $n = Page::reorder($ids);

        $this->audit('page.reorder', 'page', null, ['count' => $n]);
        $this->json(['ok' => true]);
    }
}
