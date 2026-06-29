<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Page;
use App\Models\TeamMember;

/**
 * Pages éditoriales (L'association, Équipe) et CMS (legal, privacy, génériques).
 */
final class PageController extends Controller
{
    public function presentation(): void
    {
        $page = Page::findBySlug('presentation');

        $this->render('pages/presentation', [
            'title'       => 'L\'association — AEIC',
            'description' => 'Qui sommes-nous ? Mission, valeurs et chiffres clés de l\'AEIC.',
            'page'        => $page,
        ]);
    }

    public function team(): void
    {
        $members = TeamMember::active();

        $this->render('pages/team', [
            'title'       => 'L\'équipe — AEIC',
            'description' => 'Le bureau de l\'AEIC : les étudiants qui font vivre l\'association.',
            'members'     => $members,
        ]);
    }

    public function legal(): void
    {
        $page = Page::findBySlug('legal');

        $this->render('pages/legal', [
            'title'       => 'Mentions légales — AEIC',
            'description' => 'Mentions légales de l\'Association Étudiante Informatique de Calais.',
            'page'        => $page,
        ]);
    }

    public function privacy(): void
    {
        $page = Page::findBySlug('privacy');

        $this->render('pages/privacy', [
            'title'       => 'Politique de confidentialité — AEIC',
            'description' => 'Politique de confidentialité et protection des données (RGPD).',
            'page'        => $page,
        ]);
    }

    /**
     * Page CMS générique (/p/{slug}).
     */
    public function show(string $slug): void
    {
        $page = Page::findBySlug($slug);

        if ($page === null) {
            $this->abort(404);
        }

        $this->render('pages/page', [
            'title'       => $page['title'] ?? $slug,
            'description' => $page['meta_description'] ?? '',
            'page'        => $page,
        ]);
    }

    /**
     * Page 404.
     */
    public function notFound(): void
    {
        $this->abort(404);
    }

    /**
     * Page 405.
     */
    public function methodNotAllowed(): void
    {
        $this->abort(405);
    }
}
