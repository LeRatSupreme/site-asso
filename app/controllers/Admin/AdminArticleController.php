<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Discord;
use App\Models\Article;

/**
 * CRUD des articles de blog (espace admin).
 */
final class AdminArticleController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/articles/index', [
            'title'    => 'Articles',
            'articles' => Article::allForAdmin(),
        ]);
    }

    public function form(?string $id = null): void
    {
        $this->guard();

        $article = ['is_published' => 0];
        if ($id !== null) {
            $found = Article::find($id);
            if ($found !== null) {
                $article = $found;
            }
        }

        $this->renderAdmin('admin/articles/form', [
            'title'   => isset($article['id']) ? 'Modifier l\'article' : 'Nouvel article',
            'article' => $article,
        ]);
    }

    public function save(): void
    {
        $this->guard();

        $data = $_POST;
        $isNew = empty($data['id']);
        $isPublished = !empty($data['is_published']);

        $id = Article::save($data);

        $this->audit($isNew ? 'article.create' : 'article.update', 'article', $id);

        // Annonce Discord (non bloquante) — uniquement à la création d'un article publié.
        if ($isNew && $isPublished) {
            try {
                Discord::send('📰 Nouvel article AEIC : ' . (string) ($data['title'] ?? ''));
            } catch (\Throwable) {
                // Silencieux : un échec Discord ne doit pas casser l'enregistrement.
            }
        }

        $this->setFlash('success', 'Article enregistré.');
        redirect(url('/admin/articles'));
    }

    public function delete(string $id): void
    {
        $this->guard();

        $article = Article::find($id);
        if ($article !== null) {
            Article::deleteRow((string) $article['id']);
            $this->audit('article.delete', 'article', (string) $article['id']);
            $this->setFlash('success', 'Article supprimé.');
        }

        redirect(url('/admin/articles'));
    }
}
