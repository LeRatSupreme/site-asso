<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Event;
use App\Models\Page;
use App\Models\TeamMember;
use App\Models\User;

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
            'usersCount'  => User::countActive(),
            'eventsCount' => Event::count(),
        ]);
    }

    public function team(): void
    {
        $highlighted = TeamMember::highlighted();
        $members = TeamMember::active();

        // La grille principale n'affiche pas les membres déjà mis en avant en haut.
        $highlightedIds = array_map(
            static fn (array $m): string => (string) $m['id'],
            $highlighted
        );
        $others = array_values(array_filter(
            $members,
            static fn (array $m): bool => !in_array((string) $m['id'], $highlightedIds, true)
        ));

        $this->render('pages/team', [
            'title'       => 'L\'équipe — AEIC',
            'description' => 'Le bureau de l\'AEIC : les étudiants qui font vivre l\'association.',
            'highlighted' => $highlighted,
            'members'     => $others,
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
