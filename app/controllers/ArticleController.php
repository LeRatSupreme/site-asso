<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Article;

/**
 * Liste et détail des articles de blog (actualités AEIC).
 */
final class ArticleController extends Controller
{
    /**
     * Liste des articles publiés.
     */
    public function index(): void
    {
        $articles = Article::published();

        $this->render('articles/index', [
            'title'       => 'Blog — AEIC',
            'description' => 'Actualités, billets et coulisses de l\'Association Étudiante Informatique de Calais.',
            'articles'    => $articles,
            'count'       => count($articles),
        ]);
    }

    /**
     * Détail d'un article publié par son slug.
     */
    public function show(string $slug): void
    {
        $article = Article::findBySlug($slug);

        if ($article === null) {
            $this->abort(404);
        }

        $this->render('articles/show', [
            'title'       => ($article['title'] ?? 'Article') . ' — AEIC',
            'description' => $article['excerpt'] ?? '',
            'ogType'      => 'article',
            'ogImage'     => $article['image'] ?? '',
            'article'     => $article,
        ]);
    }
}
