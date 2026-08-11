<?php

declare(strict_types=1);

/**
 * Inventariază clasele `wk-*` (marcajul temei vechi de WordPress) folosite în
 * conținutul migrat și verifică ce acoperă foaia de compatibilitate.
 *
 *   php scripts/scan-legacy-classes.php            # doar clasele neacoperite
 *   php scripts/scan-legacy-classes.php --all      # toate, cu frecvențe
 *
 * Util după fiecare migrare: dacă apar clase noi neacoperite, se adaugă în
 * public/assets/css/wk-compat.css.
 */

if (PHP_SAPI !== 'cli') {
    exit("Acest script se rulează doar din CLI.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$showAll = in_array('--all', array_slice($argv, 1), true);

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă.\n");
}

/** Sursele de conținut: tabelă => [coloană text, coloană nume]. */
$sources = [
    'pages' => ['html_content', 'slug'],
    'blog_posts' => ['content', 'slug'],
];

$counts = [];
$byPage = [];

foreach ($sources as $table => [$column, $label]) {
    try {
        $rows = $db->query("SELECT {$label} AS ref, {$column} AS body FROM {$table}")->fetchAll() ?: [];
    } catch (Throwable $e) {
        echo "[!] Nu am putut citi din {$table}: " . $e->getMessage() . "\n";
        continue;
    }
    foreach ($rows as $row) {
        $body = (string) ($row['body'] ?? '');
        if ($body === '' || !str_contains($body, 'wk-')) {
            continue;
        }
        if (!preg_match_all('~\bclass="([^"]*)"~i', $body, $m)) {
            continue;
        }
        foreach ($m[1] as $classAttr) {
            foreach (preg_split('~\s+~', trim($classAttr)) ?: [] as $class) {
                if ($class === '' || !str_starts_with($class, 'wk-')) {
                    continue;
                }
                $counts[$class] = ($counts[$class] ?? 0) + 1;
                $byPage[$class][(string) ($row['ref'] ?? '?')] = true;
            }
        }
    }
}

if ($counts === []) {
    exit("Nu am găsit clase `wk-*` în conținut. Nimic de acoperit.\n");
}

// Ce acoperă foaia de compatibilitate.
$cssPath = __DIR__ . '/../public/assets/css/wk-compat.css';
$covered = [];
$css = @file_get_contents($cssPath);
if ($css === false) {
    echo "[!] Lipsește {$cssPath}; tratez toate clasele ca neacoperite.\n\n";
} else {
    if (preg_match_all('~\.(wk-[A-Za-z0-9_-]+(?:\\\\@[a-z]+)?)~', $css, $m)) {
        foreach ($m[1] as $selector) {
            $covered[str_replace('\\@', '@', $selector)] = true;
        }
    }
}

arsort($counts);
$missing = array_filter($counts, static fn(int $n, string $c): bool => !isset($covered[$c]), ARRAY_FILTER_USE_BOTH);

echo "Clase `wk-*` distincte: " . count($counts) . " | acoperite: "
    . (count($counts) - count($missing)) . " | neacoperite: " . count($missing) . "\n";
echo str_repeat('=', 62) . "\n";

if ($showAll) {
    foreach ($counts as $class => $n) {
        printf("%-42s %5d  %s\n", $class, $n, isset($covered[$class]) ? 'ok' : 'LIPSA');
    }
} elseif ($missing === []) {
    echo "Toate clasele folosite sunt acoperite de wk-compat.css.\n";
} else {
    echo "Neacoperite (adaugă-le în wk-compat.css):\n\n";
    foreach ($missing as $class => $n) {
        $pages = array_slice(array_keys($byPage[$class] ?? []), 0, 3);
        printf("%-42s %5d apariții   ex: %s\n", $class, $n, implode(', ', $pages));
    }
}
