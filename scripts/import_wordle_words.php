<?php

/**
 * AEIC — Import des mots Wordle depuis les dictionnaires système.
 *
 * Usage (depuis la racine du projet) :
 *   sudo apt install -y wamerican wfrench
 *   php scripts/import_wordle_words.php
 *
 * Lit /usr/share/dict/american-english et /usr/share/dict/french,
 * filtre les mots de 5 à 7 lettres (A-Z uniquement, sans accents),
 * et les insère dans la table wordle_words avec leur difficulté :
 *   5 lettres → facile
 *   6 lettres → moyen
 *   7 lettres → difficile
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Charge la config (DB).
$appConfig = __DIR__ . '/../config.env';
if (is_file($appConfig)) {
    $lines = file($appConfig, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
            putenv(trim($k) . '=' . trim($v));
        }
    }
}

/**
 * Normalise un mot : majuscules, sans accents, A-Z uniquement.
 * Retourne '' si le mot contient des caractères non supportés.
 */
function normalizeWord(string $word): string
{
    $word = trim($word);
    if ($word === '') {
        return '';
    }
    // Supprime les accents (décomposition NFD).
    $normalized = \Normalizer::normalize($word, \Normalizer::FORM_D);
    if ($normalized !== false) {
        $word = preg_replace('/\p{M}/u', '', $normalized) ?? $word;
    }
    $word = strtoupper($word);

    // Refuse tout ce qui n'est pas A-Z (apostrophes, tirets, chiffres, espaces).
    if (!preg_match('/^[A-Z]+$/', $word)) {
        return '';
    }

    return $word;
}

/**
 * Difficulté selon la longueur.
 */
function difficultyForLength(int $length): ?string
{
    return match ($length) {
        5 => 'facile',
        6 => 'moyen',
        7 => 'difficile',
        default => null,
    };
}

/**
 * Lit un fichier dictionnaire et retourne les mots valides (5-7 lettres).
 *
 * @return array<string,bool>  mot => true (pour dédupliquer)
 */
function readDictionary(string $path, string $language): array
{
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "AVERTISSEMENT : fichier introuvable ou illisible : $path\n");
        fwrite(STDERR, "Installez le paquet correspondant (sudo apt install wamerican wfrench).\n");
        return [];
    }

    $words = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    while (($line = fgets($handle)) !== false) {
        $raw = trim($line);

        // Ignore les entrées en majuscules (noms propres, abréviations).
        if ($raw !== strtolower($raw)) {
            continue;
        }

        $word = normalizeWord($raw);
        if ($word === '') {
            continue;
        }

        $len = strlen($word);
        if ($len < 5 || $len > 7) {
            continue;
        }

        // Clé unique par langue.
        $words[$word] = true;
    }

    fclose($handle);

    fwrite(STDERR, "Lu " . count($words) . " mots valides ($language) depuis $path\n");
    return $words;
}

// ===================== MAIN =====================

$pdo = db();

// Vidage de la table existante.
$pdo->exec('TRUNCATE TABLE wordle_words');
fwrite(STDERR, "Table wordle_words vidée.\n");

$sources = [
    'fr' => ['/usr/share/dict/french', '/usr/share/dict/fr'],
    'en' => ['/usr/share/dict/american-english', '/usr/share/dict/british-english', '/usr/share/dict/words'],
];

$insertSql = 'INSERT IGNORE INTO wordle_words (word, language, length, difficulty)
              VALUES (:word, :lang, :length, :difficulty)';
$stmt = $pdo->prepare($insertSql);

$total = 0;
$stats = ['fr' => ['facile' => 0, 'moyen' => 0, 'difficile' => 0],
          'en' => ['facile' => 0, 'moyen' => 0, 'difficile' => 0]];

foreach (['fr', 'en'] as $language) {
    // Trouve le premier fichier dictionnaire disponible.
    $dictPath = null;
    foreach ($sources[$language] as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $dictPath = $candidate;
            break;
        }
    }

    if ($dictPath === null) {
        fwrite(STDERR, "Aucun dictionnaire trouvé pour '$language'. Paquets suggérés : sudo apt install wamerican wfrench\n");
        continue;
    }

    $words = readDictionary($dictPath, $language);

    // Insertion par lots.
    $batch = 0;
    foreach (array_keys($words) as $word) {
        $len = strlen($word);
        $difficulty = difficultyForLength($len);
        if ($difficulty === null) {
            continue;
        }

        $stmt->execute([
            ':word' => $word,
            ':lang' => $language,
            ':length' => $len,
            ':difficulty' => $difficulty,
        ]);

        $stats[$language][$difficulty]++;
        $total++;
        $batch++;

        if ($batch % 5000 === 0) {
            fwrite(STDERR, "  ... $total mots insérés\n");
        }
    }
}

fwrite(STDERR, "\nImport terminé : $total mots au total.\n");
fwrite(STDERR, "Récapitulatif :\n");
foreach ($stats as $lang => $diffs) {
    foreach ($diffs as $diff => $count) {
        fwrite(STDERR, "  $lang / $diff : $count mots\n");
    }
}
