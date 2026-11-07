<?php

declare(strict_types=1);

namespace App\Core\Security;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMXPath;

/**
 * Nettoyeur HTML basé sur une allow-list, pour l'affichage de contenu
 * enrichi saisi côté admin (descriptions d'événements, pages CMS…).
 *
 * Le parsing est entièrement délégué à DOMDocument (aucune regex sur le
 * HTML) : seuls les éléments et attributs explicitement autorisés sont
 * conservés, tout le reste est supprimé ou « défait » (les enfants sont
 * remontés au niveau parent).
 *
 * Encodage : chaque caractère non-ASCII est converti en entité numérique
 * via mb_encode_numericentity avant le parsing, ce qui rend la lecture
 * indépendante du charset détecté par libxml (la technique classique
 * mb_convert_encoding(..., 'HTML-ENTITIES') est dépréciée en PHP 8.2).
 */
final class HtmlSanitizer
{
    /**
     * Balises autorisées (tout le reste est retiré).
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'u', 's', 'b', 'i',
        'blockquote', 'ul', 'ol', 'li', 'a', 'img',
        'table', 'thead', 'tbody', 'tr', 'td', 'th',
        'code', 'pre', 'span', 'div', 'figure', 'figcaption',
        'small', 'sub', 'sup',
    ];

    /**
     * Éléments supprimés AVEC leur contenu : aucun texte visible à préserver
     * (code exécutable, embarqué, métadonnées…). Les autres éléments non
     * autorisés sont simplement « défaits » (enfants remontés au parent).
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'noscript', 'template', 'title', 'meta', 'link', 'base', 'head',
        'frame', 'frameset', 'applet',
    ];

    /**
     * Attributs autorisés par balise (aucun attribut global autorisé :
     * class, style, id et les gestionnaires on* sont donc toujours retirés).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
    ];

    /**
     * Nettoie une chaîne HTML et renvoie le fragment assaini.
     */
    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Conversion de tout caractère non-ASCII en entité numérique :
        // le document reste de l'ASCII pur, quel que soit l'encodage source.
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $ascii, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        self::sanitizeDocument($dom);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        // Sérialisation : uniquement les enfants de <body>, sans les balises
        // <html>/<body> ajoutées automatiquement par le parsing.
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    /**
     * Parcourt tout le document et nettoie éléments, attributs et nœuds
     * non texte (commentaires, instructions de traitement).
     */
    private static function sanitizeDocument(DOMDocument $dom): void
    {
        // Instantané de la liste : la suppression / le déplacement de nœuds
        // pendant la boucle ne perturbe pas le parcours.
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*');
        if ($elements !== false) {
            foreach ($elements as $element) {
                if ($element instanceof DOMElement) {
                    self::sanitizeElement($element);
                }
            }
        }

        foreach (['//comment()', '//processing-instruction()'] as $expr) {
            $nodes = $xpath->query($expr);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /**
     * Traite un élément : suppression, défausse (unwrap) ou filtrage des
     * attributs. Les éléments structurels html/body ne sont jamais touchés.
     */
    private static function sanitizeElement(DOMElement $node): void
    {
        $tag = strtolower($node->tagName);

        if ($tag === 'html' || $tag === 'body') {
            return;
        }

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            // Élément non autorisé mais anodin (form, section, article…) :
            // on remonte ses enfants au parent puis on le retire.
            $parent = $node->parentNode;
            if ($parent !== null) {
                foreach (iterator_to_array($node->childNodes, false) as $child) {
                    $parent->insertBefore($child, $node);
                }
                $parent->removeChild($node);
            }

            return;
        }

        self::sanitizeAttributes($node);
    }

    /**
     * Retire tous les attributs non autorisés, contrôle les URL des
     * attributs href/src et garantit rel="noopener noreferrer" sur les
     * liens ouverts dans un nouvel onglet.
     */
    private static function sanitizeAttributes(DOMElement $node): void
    {
        $tag = strtolower($node->tagName);
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        $attributes = $node->attributes;
        if ($attributes !== null) {
            foreach (iterator_to_array($attributes, false) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                    $node->removeAttribute($attribute->name);
                    continue;
                }
                if (($name === 'href' || $name === 'src') && !self::isSafeUrl($attribute->value)) {
                    $node->removeAttribute($attribute->name);
                }
            }
        }

        if ($tag === 'a' && strtolower($node->getAttribute('target')) === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * N'accepte que http, https, mailto et les chemins relatifs commençant
     * par « / ». Les espaces et caractères de contrôle sont retirés AVANT la
     * validation (les navigateurs les ignorent : « java\tscript: » est
     * exécutable côté client). Casse normalisée : le schéma peut être
     * mélangé (« JAVASCRIPT: », « JaVaScRiPt: »…).
     */
    private static function isSafeUrl(string $url): bool
    {
        $normalized = preg_replace('/[\x00-\x20\x7F]/', '', $url) ?? '';

        if ($normalized === '') {
            return false;
        }

        if ($normalized[0] === '/') {
            return true;
        }

        $lower = strtolower($normalized);

        return str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'mailto:');
    }
}
