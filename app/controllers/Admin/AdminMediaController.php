<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Permissions;
use App\Models\Media;

/**
 * Bibliothèque de médias : upload d'images et suppression.
 *
 * Upload sécurisé : validation MIME réelle (finfo), extension autorisée,
 * taille limitée, renommage du fichier, stockage hors webroot inaccessible
 * directement (servi via /assets/uploads). Pour Phase 5 on stocke dans le
 * dossier public uploads (servable) avec un nom aléatoire.
 */
final class AdminMediaController extends AdminBaseController
{
    private const MAX_SIZE = 5_242_880; // 5 Mo.

    /** @var array<string, string> extension => type */
    private const ALLOWED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    /**
     * Segments de nom de fichier interdits hors extension finale (anti
     * double extension : « photo.php.jpg », « shell.phar.png »…).
     *
     * @var list<string>
     */
    private const FORBIDDEN_SEGMENTS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht',
        'phar', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp',
        'exe', 'bat', 'cmd', 'js', 'html', 'htm', 'svg',
    ];

    public function index(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $this->renderAdmin('admin/media/index', [
            'title'  => 'Médias',
            'medias' => Media::recent(),
            // media_id => produits utilisant l'image (badges + modale « pourquoi »).
            'usage'  => Media::usageMap(),
        ]);
    }

    public function upload(): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $file = $_FILES['file'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Aucun fichier reçu ou erreur d\'envoi.');
            redirect(url('/admin/media'));
        }

        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            $this->setFlash('error', 'Fichier trop volumineux (5 Mo max).');
            redirect(url('/admin/media'));
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            $this->setFlash('error', 'Type de fichier non autorisé.');
            redirect(url('/admin/media'));
        }

        // Anti double extension : aucun segment du nom ne doit correspondre
        // à un type exécutable ou actif.
        $segments = explode('.', strtolower(pathinfo((string) $file['name'], PATHINFO_FILENAME)));
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_SEGMENTS, true)) {
                $this->setFlash('error', 'Nom de fichier invalide (double extension suspecte).');
                redirect(url('/admin/media'));
            }
        }

        // Validation MIME réelle — obligatoire : sans outil de détection
        // disponible, l'upload est refusé (l'extension déclarée n'est jamais
        // une preuve du contenu réel).
        $detected = null;
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type((string) $file['tmp_name']);
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, (string) $file['tmp_name']);
                finfo_close($finfo);
            }
        }
        if (!is_string($detected) || $detected === '') {
            $this->setFlash('error', 'Impossible de vérifier le type réel du fichier : upload refusé.');
            redirect(url('/admin/media'));
        }
        if ($detected !== self::ALLOWED[$ext]) {
            $this->setFlash('error', 'Le type MIME ne correspond pas à l\'extension.');
            redirect(url('/admin/media'));
        }

        // Renommage sécurisé (jamais le nom d'origine).
        $newName = bin2hex(random_bytes(12)) . '.' . $ext;
        $uploadDir = AEIC_PUBLIC . '/assets/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0o775, true);
        }

        $dest = $uploadDir . '/' . $newName;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            $this->setFlash('error', 'Échec de l\'enregistrement du fichier.');
            redirect(url('/admin/media'));
        }

        Media::create([
            'name'      => $file['name'],
            'url'       => 'uploads/' . $newName,
            'type'      => 'image',
            'mime_type' => $detected,
            'alt'       => (string) ($_POST['alt'] ?? ''),
            'size'      => (int) ($file['size'] ?? 0),
        ]);

        $this->audit('media.upload', 'media', null);
        $this->setFlash('success', 'Média ajouté.');
        redirect(url('/admin/media'));
    }

    public function delete(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $media = Media::find($id);
        if ($media === null) {
            $this->setFlash('error', 'Média introuvable.');
            redirect(url('/admin/media'));
        }

        // Un média référencé par un produit (products.image) ne doit pas
        // disparaître silencieusement : on explique POURQUOI (quels produits)
        // et on renvoie vers la suppression en cascade si besoin.
        $usedBy = Media::usedBy($id);
        if ($usedBy !== []) {
            $names = implode(', ', array_map(
                static fn (array $p): string => '« ' . $p['name'] . ' »',
                array_slice($usedBy, 0, 5)
            ));
            $this->setFlash(
                'error',
                sprintf(
                    'Suppression impossible : ce média est utilisé par %d produit(s) — %s%s Utilisez « Supprimer et détacher » pour une suppression en cascade.',
                    count($usedBy),
                    $names,
                    count($usedBy) > 5 ? '…' : ''
                )
            );
            redirect(url('/admin/media'));
        }

        // La ligne d'abord (source de vérité) ; le fichier ensuite, un échec
        // d'effacement disque ne doit pas bloquer la suppression.
        if (Media::deleteRow($id) < 1) {
            $this->setFlash('error', 'Média introuvable.');
            redirect(url('/admin/media'));
        }

        $path = AEIC_PUBLIC . '/assets/' . (string) $media['url'];
        if (is_file($path)) {
            @unlink($path);
        }

        $this->audit('media.delete', 'media', $id);
        $this->setFlash('success', 'Média supprimé.');
        redirect(url('/admin/media'));
    }

    /**
     * Suppression en cascade : détache d'abord les produits (image remise à
     * NULL — aucune autre donnée produit n'est perdue, l'image peut être
     * réaffectée plus tard), puis supprime le média. Les métadonnées du média
     * sont conservées dans le journal d'audit pour pouvoir le recréer à
     * l'identique si besoin.
     */
    public function deleteCascade(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $media = Media::find($id);
        if ($media === null) {
            $this->setFlash('error', 'Média introuvable.');
            redirect(url('/admin/media'));
        }

        $detached = Media::detachProducts($id);

        if (Media::deleteRow($id) < 1) {
            $this->setFlash('error', 'Média introuvable.');
            redirect(url('/admin/media'));
        }

        $path = AEIC_PUBLIC . '/assets/' . (string) $media['url'];
        if (is_file($path)) {
            @unlink($path);
        }

        $this->audit('media.delete_cascade', 'media', $id, [
            'name'      => (string) ($media['name'] ?? ''),
            'url'       => (string) ($media['url'] ?? ''),
            'alt'       => (string) ($media['alt'] ?? ''),
            'mime_type' => (string) ($media['mime_type'] ?? ''),
            'size'      => (int) ($media['size'] ?? 0),
            'detached'  => $detached,
        ]);
        $this->setFlash(
            'success',
            sprintf(
                'Média supprimé — %d produit(s) détaché(s) (données conservées, image à réaffecter).',
                $detached
            )
        );
        redirect(url('/admin/media'));
    }

    public function update(string $id): void
    {
        $this->guardModule(Permissions::MODULE_CONTENT);

        $alt = trim((string) ($_POST['alt'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));

        try {
            $stmt = \db()->prepare('UPDATE media SET alt = ?, name = ? WHERE id = ?');
            $stmt->execute([$alt, $name, $id]);
            $this->setFlash('success', 'Média mis à jour.');
        } catch (\Throwable) {
            $this->setFlash('error', 'Erreur lors de la mise à jour.');
        }

        redirect(url('/admin/media'));
    }
}
