<?php

declare(strict_types=1);

/**
 * Migrare automată de conținut din site-ul WordPress/WooCommerce live
 * către backend-ul custom (pagini, articole blog, categorii, produse, imagini).
 *
 * Utilizare (CLI):
 *   php scripts/migrate-from-wordpress.php [optiuni]
 *
 * Opțiuni:
 *   --source=https://exemplu.ro   Sursa migrării (implicit: DEFAULT_SOURCE de mai jos)
 *   --only=pages,posts,products   Migrează doar tipurile precizate (implicit: toate)
 *   --limit=50                    Limitează numărul de elemente per tip (0 = fără limită)
 *   --dry-run                     Doar afișează ce s-ar importa, nu scrie nimic în DB
 *   --skip-images                 Nu descărca imaginile local (păstrează URL-urile originale)
 *   --wc-key=ck_xxx               WooCommerce REST API consumer key (opțional)
 *   --wc-secret=cs_xxx            WooCommerce REST API consumer secret (opțional)
 *   --force-crawl                 Sare peste API-uri și folosește direct fallback-ul pe sitemap
 *
 * Comportament:
 *   1. Încearcă WP REST API (/wp-json/wp/v2/...) pentru pagini + articole + categorii + autori.
 *   2. Pentru produse: WooCommerce REST v3 (dacă ai chei) sau Store API publică (/wp-json/wc/store/v1/products).
 *   3. Dacă API-urile sunt blocate, face fallback pe sitemap.xml + parsare HTML.
 *   4. Descarcă imaginile în public/uploads/migrated/ și rescrie URL-urile din conținut.
 *   5. Este idempotent: rulat de mai multe ori, actualizează după slug (nu duplică).
 */

const DEFAULT_SOURCE = 'https://vitalitatesiprotectie.ro';

if (PHP_SAPI !== 'cli') {
    exit("Acest script se rulează doar din CLI.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/app.php';

// ---------------------------------------------------------------------------
// Parsare argumente CLI
// ---------------------------------------------------------------------------

$options = [
    'source' => DEFAULT_SOURCE,
    'only' => ['pages', 'posts', 'products'],
    'limit' => 0,
    'dry_run' => false,
    'skip_images' => false,
    'wc_key' => (string) (getenv('WC_CONSUMER_KEY') ?: ''),
    'wc_secret' => (string) (getenv('WC_CONSUMER_SECRET') ?: ''),
    'force_crawl' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
    } elseif ($arg === '--skip-images') {
        $options['skip_images'] = true;
    } elseif ($arg === '--force-crawl') {
        $options['force_crawl'] = true;
    } elseif (str_starts_with($arg, '--source=')) {
        $options['source'] = rtrim(trim(substr($arg, 9)), '/');
    } elseif (str_starts_with($arg, '--only=')) {
        $options['only'] = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    } elseif (str_starts_with($arg, '--limit=')) {
        $options['limit'] = max(0, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--wc-key=')) {
        $options['wc_key'] = trim(substr($arg, 9));
    } elseif (str_starts_with($arg, '--wc-secret=')) {
        $options['wc_secret'] = trim(substr($arg, 12));
    } else {
        exit("Argument necunoscut: {$arg}\n");
    }
}

$source = rtrim($options['source'], '/');
if ($source === '' || !preg_match('~^https?://~i', $source)) {
    exit("Sursă invalidă: '{$source}'. Folosește --source=https://site-ul-vechi.ro\n");
}
$sourceHost = (string) parse_url($source, PHP_URL_HOST);

echo "==============================================\n";
echo "Migrare conținut din: {$source}\n";
echo 'Tipuri: ' . implode(', ', $options['only']) . ($options['dry_run'] ? ' (DRY-RUN, nu se scrie nimic)' : '') . "\n";
echo "==============================================\n\n";

$db = Database::connection($config['db']);
if (!$db instanceof PDO) {
    exit("Conexiunea la baza de date nu este disponibilă. Verifică .env.\n");
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensureMigrationSchema($db);

$report = [
    'pages' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
    'posts' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
    'products' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0],
    'images' => ['downloaded' => 0, 'reused' => 0, 'errors' => 0],
];

$useApi = !$options['force_crawl'] && wpRestAvailable($source);
if ($useApi) {
    echo "[OK] WP REST API detectat la {$source}/wp-json/\n\n";
} else {
    echo "[!] WP REST API indisponibil sau --force-crawl activ. Se folosește fallback: sitemap + parsare HTML.\n\n";
}

if (in_array('pages', $options['only'], true)) {
    $useApi ? migratePagesViaApi($db, $source, $options, $report) : migrateViaCrawl($db, $source, $options, $report, 'pages');
}
if (in_array('posts', $options['only'], true)) {
    $useApi ? migratePostsViaApi($db, $source, $options, $report) : migrateViaCrawl($db, $source, $options, $report, 'posts');
}
if (in_array('products', $options['only'], true)) {
    $ok = false;
    if (!$options['force_crawl']) {
        $ok = migrateProductsViaApi($db, $source, $options, $report);
    }
    if (!$ok) {
        migrateViaCrawl($db, $source, $options, $report, 'products');
    }
}

echo "\n==============================================\n";
echo "RAPORT FINAL\n";
foreach ($report as $type => $counts) {
    $parts = [];
    foreach ($counts as $k => $v) {
        $parts[] = "{$k}: {$v}";
    }
    echo str_pad($type, 10) . implode(', ', $parts) . "\n";
}
echo "==============================================\n";
echo $options['dry_run'] ? "DRY-RUN: nu s-a scris nimic în baza de date.\n" : "Migrare finalizată.\n";

// ---------------------------------------------------------------------------
// Schema minimă necesară (aceleași definiții ca în aplicație)
// ---------------------------------------------------------------------------

function ensureMigrationSchema(PDO $db): void
{
    $statements = [
        'CREATE TABLE IF NOT EXISTS pages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            html_content LONGTEXT NOT NULL,
            css_content LONGTEXT DEFAULT NULL,
            js_content LONGTEXT DEFAULT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            deleted_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS blog_authors (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL UNIQUE,
            bio TEXT DEFAULT NULL,
            avatar_url VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS blog_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            slug VARCHAR(170) NOT NULL UNIQUE,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS blog_posts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL UNIQUE,
            excerpt TEXT DEFAULT NULL,
            category VARCHAR(190) DEFAULT NULL,
            category_id INT UNSIGNED DEFAULT NULL,
            content LONGTEXT NOT NULL,
            reading_minutes INT UNSIGNED NOT NULL DEFAULT 1,
            published_at DATETIME NOT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            template_id INT UNSIGNED DEFAULT NULL,
            author_id INT UNSIGNED DEFAULT NULL,
            featured_image_url VARCHAR(255) DEFAULT NULL,
            video_url VARCHAR(500) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_blog_posts_published (is_published, published_at),
            KEY idx_blog_posts_deleted (deleted_at)
        )',
        'CREATE TABLE IF NOT EXISTS blog_post_categories (
            post_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (post_id, category_id),
            KEY idx_bpc_category (category_id)
        )',
        'CREATE TABLE IF NOT EXISTS product_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            slug VARCHAR(140) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS gallery_images (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            media_type VARCHAR(20) NOT NULL DEFAULT \'image\',
            image_url TEXT NOT NULL,
            folder_id INT UNSIGNED DEFAULT NULL,
            alt_text VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )',
    ];
    foreach ($statements as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable) {
            // Tabela există deja cu o definiție echivalentă.
        }
    }
    // Tabela products vine din database/schema.sql (rulează scripts/install.php întâi).
    $exists = false;
    try {
        $db->query('SELECT 1 FROM products LIMIT 1');
        $exists = true;
    } catch (Throwable) {
    }
    if (!$exists) {
        exit("Tabela `products` lipsește. Rulează întâi: php scripts/install.php\n");
    }
}

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

/**
 * @param int|null $lastStatus Codul HTTP al ultimei încercări (0 = eroare de rețea/timeout).
 */
function httpGet(string $url, int $timeout = 30, int $retries = 3, ?int &$lastStatus = null): ?string
{
    global $lastHttpError;

    $ua = 'Mozilla/5.0 (compatible; MigrareSite/1.0; +https://example.org)';
    $lastStatus = 0;
    $lastHttpError = '';

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            $lastStatus = $status;
            if ($curlError !== '') {
                $lastHttpError = $curlError;
            }
            if (is_string($body) && $status >= 200 && $status < 300) {
                return $body;
            }
            // 404/410 etc. = definitiv lipsă, nu reîncerca. 403/429 pot fi
            // blocaje temporare de firewall/rate-limit, deci merită reîncercate.
            if ($status >= 400 && $status < 500 && !in_array($status, [403, 408, 429], true)) {
                return null;
            }
        } else {
            $ctx = stream_context_create([
                'http' => ['timeout' => $timeout, 'user_agent' => $ua, 'follow_location' => 1],
                'ssl' => ['verify_peer' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if (is_string($body)) {
                return $body;
            }
        }
        if ($attempt < $retries) {
            sleep($attempt * 2);
        }
    }
    return null;
}

/** Notează în storage/logs/migrate-images-failed.log imaginile care nu au putut fi descărcate. */
function logImageFailure(string $url, int $status): void
{
    global $lastHttpError;

    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $reason = $status > 0
        ? 'HTTP ' . $status
        : 'retea/timeout: ' . (is_string($lastHttpError) && $lastHttpError !== '' ? $lastHttpError : 'necunoscut');

    @file_put_contents(
        $dir . '/migrate-images-failed.log',
        date('Y-m-d H:i:s') . " | {$reason} | {$url}\n",
        FILE_APPEND
    );
}

function httpGetJson(string $url): ?array
{
    $body = httpGet($url);
    if ($body === null) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/** Iterează un endpoint WP REST paginat și întoarce toate elementele. */
function fetchAllPaginated(string $baseUrl, int $limit): array
{
    $all = [];
    $perPage = 100;
    for ($page = 1; $page <= 200; $page++) {
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        $batch = httpGetJson("{$baseUrl}{$sep}per_page={$perPage}&page={$page}");
        if (!is_array($batch) || $batch === [] || isset($batch['code'])) {
            break;
        }
        // Unele API-uri întorc obiect asociativ la eroare; ne asigurăm că e listă.
        if (array_is_list($batch) === false) {
            break;
        }
        foreach ($batch as $item) {
            $all[] = $item;
            if ($limit > 0 && count($all) >= $limit) {
                return $all;
            }
        }
        if (count($batch) < $perPage) {
            break;
        }
    }
    return $all;
}

// ---------------------------------------------------------------------------
// Utilitare text/slug/imagini
// ---------------------------------------------------------------------------

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text));
    $map = ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't'];
    $text = strtr($text, $map);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted)) {
            $text = $converted;
        }
    }
    $text = (string) preg_replace('~[^a-z0-9]+~', '-', $text);
    return trim($text, '-') ?: 'element-' . substr(md5($text), 0, 8);
}

/** Normalizează segmentele `.` și `..` dintr-o cale de URL. */
function normalizeUrlPath(string $path): string
{
    $out = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        $out[] = $segment;
    }
    return '/' . implode('/', $out);
}

/**
 * Transformă orice formă de adresă (relativă, protocol-relativă, cu `../`)
 * într-un URL absolut valid pe site-ul sursă.
 */
function absolutizeUrl(string $src, string $host): string
{
    $src = trim(html_entity_decode($src, ENT_QUOTES));
    if ($src === '' || str_starts_with($src, 'data:') || str_starts_with($src, 'mailto:')) {
        return $src;
    }
    if (str_starts_with($src, '//')) {
        $src = 'https:' . $src;
    }

    $scheme = 'https';
    $targetHost = $host;
    $rest = $src;

    if (preg_match('~^(https?)://([^/?#]+)(.*)$~i', $src, $m)) {
        $scheme = strtolower($m[1]);
        $targetHost = $m[2];
        $rest = $m[3] !== '' ? $m[3] : '/';
    }

    $query = '';
    if (str_contains($rest, '?')) {
        [$rest, $queryPart] = explode('?', $rest, 2);
        $query = '?' . $queryPart;
    }
    if (str_contains($rest, '#')) {
        [$rest] = explode('#', $rest, 2);
    }

    $path = normalizeUrlPath($rest);

    // Pe WordPress `wp-content` stă întotdeauna în rădăcină; dacă a rămas
    // îngropat sub alt segment (ex. /cmo/wp-content/...), tăiem prefixul.
    $pos = strpos($path, '/wp-content/');
    if ($pos !== false && $pos > 0) {
        $path = substr($path, $pos);
    }

    return $scheme . '://' . $targetHost . $path . $query;
}

function cleanText(string $html): string
{
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function readingMinutes(string $html): int
{
    $words = str_word_count(cleanText($html));
    return max(1, (int) round($words / 200));
}

/**
 * Descarcă o imagine local în public/uploads/migrated/ și o înregistrează
 * în galerie. Întoarce calea locală (/uploads/migrated/...) sau URL-ul
 * original dacă descărcarea eșuează / este dezactivată.
 */
function localizeImage(PDO $db, string $url, array $options, array &$report, string $altText = ''): string
{
    global $sourceHost;
    static $cache = [];

    $url = html_entity_decode(trim($url), ENT_QUOTES);
    if ($url === '' || str_starts_with($url, 'data:')) {
        return $url;
    }
    $url = absolutizeUrl($url, (string) $sourceHost);
    if ($options['skip_images'] || $options['dry_run']) {
        return $url;
    }
    if (isset($cache[$url])) {
        return $cache[$url];
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    $basename = preg_replace('~[^A-Za-z0-9._-]~', '-', basename($path));
    if ($basename === '' || $basename === null) {
        $basename = 'img';
    }
    $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
        return $cache[$url] = $url; // nu pare imagine descărcabilă
    }

    $dir = __DIR__ . '/../public/uploads/migrated';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        $report['images']['errors']++;
        return $cache[$url] = $url;
    }

    $filename = substr(md5($url), 0, 10) . '-' . mb_substr($basename, -140);
    $target = $dir . '/' . $filename;
    $localUrl = '/uploads/migrated/' . $filename;

    if (!is_file($target)) {
        $status = 0;
        $data = httpGet($url, 60, 3, $status);
        if ($data === null || $data === '') {
            $report['images']['errors']++;
            logImageFailure($url, $status);
            return $cache[$url] = $url;
        }
        if (file_put_contents($target, $data) === false) {
            $report['images']['errors']++;
            logImageFailure($url, -1);
            return $cache[$url] = $url;
        }
        $report['images']['downloaded']++;
    } else {
        $report['images']['reused']++;
    }

    // Înregistrează în galerie (o singură dată per fișier).
    try {
        $check = $db->prepare('SELECT id FROM gallery_images WHERE image_url = :url LIMIT 1');
        $check->execute(['url' => $localUrl]);
        if (!$check->fetchColumn()) {
            $insert = $db->prepare(
                'INSERT INTO gallery_images (title, media_type, image_url, alt_text) VALUES (:title, :media, :url, :alt)'
            );
            $insert->execute([
                'title' => mb_substr($altText !== '' ? $altText : $basename, 0, 190),
                'media' => 'image',
                'url' => $localUrl,
                'alt' => mb_substr($altText, 0, 255) ?: null,
            ]);
        }
    } catch (Throwable) {
        // Galeria e opțională; nu blocăm migrarea.
    }

    return $cache[$url] = $localUrl;
}

/** Rescrie toate <img> din conținut către copii locale + curăță srcset. */
function localizeContentImages(PDO $db, string $html, string $sourceHost, array $options, array &$report): string
{
    if (trim($html) === '') {
        return $html;
    }
    $html = (string) preg_replace('~\s+(srcset|sizes)="[^"]*"~i', '', $html);
    return (string) preg_replace_callback(
        '~(<img[^>]+src=")([^"]+)(")~i',
        static function (array $m) use ($db, $sourceHost, $options, &$report): string {
            // Acceptă orice formă: absolută, `/root`, `relativa/`, `../parinte/`.
            $src = absolutizeUrl($m[2], $sourceHost);
            if ($src === '' || str_starts_with($src, 'data:')) {
                return $m[0];
            }
            $host = (string) parse_url($src, PHP_URL_HOST);
            if ($host !== '' && $host !== $sourceHost && !str_ends_with($host, '.' . $sourceHost)) {
                return $m[0]; // imagine externă (CDN terț etc.) - o lăsăm
            }
            return $m[1] . localizeImage($db, $src, $options, $report) . $m[3];
        },
        $html
    );
}

// ---------------------------------------------------------------------------
// Upsert helpers
// ---------------------------------------------------------------------------

function upsertPage(PDO $db, array $options, array &$report, string $title, string $slug, string $html): void
{
    if ($options['dry_run']) {
        echo "  [dry-run] pagină: {$slug} ({$title})\n";
        $report['pages']['skipped']++;
        return;
    }
    $stmt = $db->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $db->prepare('UPDATE pages SET title = :title, html_content = :html, deleted_at = NULL WHERE id = :id')
            ->execute(['title' => mb_substr($title, 0, 190), 'html' => $html, 'id' => $id]);
        $report['pages']['updated']++;
        echo "  [update] pagină: {$slug}\n";
    } else {
        $db->prepare('INSERT INTO pages (title, slug, html_content, is_published) VALUES (:title, :slug, :html, 1)')
            ->execute(['title' => mb_substr($title, 0, 190), 'slug' => $slug, 'html' => $html]);
        $report['pages']['created']++;
        echo "  [nou]    pagină: {$slug}\n";
    }
}

function upsertBlogCategory(PDO $db, string $name, string $slug): int
{
    $stmt = $db->prepare('SELECT id FROM blog_categories WHERE slug = :slug OR name = :name LIMIT 1');
    $stmt->execute(['slug' => $slug, 'name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO blog_categories (name, slug) VALUES (:name, :slug)')
        ->execute(['name' => mb_substr($name, 0, 150), 'slug' => mb_substr($slug, 0, 170)]);
    return (int) $db->lastInsertId();
}

function upsertBlogAuthor(PDO $db, string $name): int
{
    $slug = slugify($name);
    $stmt = $db->prepare('SELECT id FROM blog_authors WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO blog_authors (name, slug) VALUES (:name, :slug)')
        ->execute(['name' => mb_substr($name, 0, 190), 'slug' => $slug]);
    return (int) $db->lastInsertId();
}

function upsertBlogPost(PDO $db, array $options, array &$report, array $post): void
{
    if ($options['dry_run']) {
        echo "  [dry-run] articol: {$post['slug']} ({$post['title']})\n";
        $report['posts']['skipped']++;
        return;
    }
    $stmt = $db->prepare('SELECT id FROM blog_posts WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $post['slug']]);
    $id = $stmt->fetchColumn();

    $fields = [
        'title' => mb_substr($post['title'], 0, 255),
        'slug' => mb_substr($post['slug'], 0, 255),
        'excerpt' => $post['excerpt'] !== '' ? $post['excerpt'] : null,
        'category' => $post['category'] !== '' ? mb_substr($post['category'], 0, 190) : null,
        'category_id' => $post['category_id'] ?: null,
        'content' => $post['content'],
        'reading_minutes' => readingMinutes($post['content']),
        'published_at' => $post['published_at'],
        'author_id' => $post['author_id'] ?: null,
        'featured_image_url' => $post['featured_image_url'] !== '' ? mb_substr($post['featured_image_url'], 0, 255) : null,
    ];

    if ($id) {
        $fields['id'] = $id;
        $db->prepare(
            'UPDATE blog_posts SET title = :title, excerpt = :excerpt, category = :category,
                category_id = :category_id, content = :content, reading_minutes = :reading_minutes,
                published_at = :published_at, author_id = :author_id,
                featured_image_url = :featured_image_url, is_published = 1, deleted_at = NULL
             WHERE id = :id'
        )->execute(array_diff_key($fields, ['slug' => true]));
        $report['posts']['updated']++;
        echo "  [update] articol: {$post['slug']}\n";
    } else {
        $db->prepare(
            'INSERT INTO blog_posts
                (title, slug, excerpt, category, category_id, content, reading_minutes, published_at, author_id, featured_image_url, is_published)
             VALUES (:title, :slug, :excerpt, :category, :category_id, :content, :reading_minutes, :published_at, :author_id, :featured_image_url, 1)'
        )->execute($fields);
        $id = (int) $db->lastInsertId();
        $report['posts']['created']++;
        echo "  [nou]    articol: {$post['slug']}\n";
    }

    if (!empty($post['category_ids'])) {
        $link = $db->prepare('INSERT IGNORE INTO blog_post_categories (post_id, category_id) VALUES (:post_id, :category_id)');
        foreach ($post['category_ids'] as $catId) {
            $link->execute(['post_id' => $id, 'category_id' => $catId]);
        }
    }
}

function upsertProductCategory(PDO $db, string $name, string $slug): int
{
    $stmt = $db->prepare('SELECT id FROM product_categories WHERE slug = :slug OR name = :name LIMIT 1');
    $stmt->execute(['slug' => $slug, 'name' => $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO product_categories (name, slug) VALUES (:name, :slug)')
        ->execute(['name' => mb_substr($name, 0, 120), 'slug' => mb_substr($slug, 0, 140)]);
    return (int) $db->lastInsertId();
}

function upsertProduct(PDO $db, array $options, array &$report, array $p): void
{
    if ($options['dry_run']) {
        echo "  [dry-run] produs: {$p['slug']} ({$p['name']}) - {$p['price']} lei\n";
        $report['products']['skipped']++;
        return;
    }
    $stmt = $db->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $p['slug']]);
    $id = $stmt->fetchColumn();

    $fields = [
        'name' => mb_substr($p['name'], 0, 190),
        'sku' => $p['sku'] !== '' ? mb_substr($p['sku'], 0, 80) : null,
        'category' => $p['category'] !== '' ? mb_substr($p['category'], 0, 120) : null,
        'category_id' => $p['category_id'] ?: null,
        'slug' => mb_substr($p['slug'], 0, 190),
        'short_description' => $p['short_description'] !== '' ? $p['short_description'] : null,
        'description' => $p['description'] !== '' ? $p['description'] : null,
        'price' => $p['price'],
        'sale_price' => $p['sale_price'],
        'stock' => $p['stock'],
        'out_of_stock' => $p['out_of_stock'] ? 1 : 0,
        'image_url' => $p['image_url'] !== '' ? $p['image_url'] : null,
        'gallery_images_json' => $p['gallery'] !== []
            ? json_encode($p['gallery'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null,
    ];

    if ($id) {
        $fields['id'] = $id;
        $db->prepare(
            'UPDATE products SET name = :name, sku = :sku, category = :category, category_id = :category_id,
                short_description = :short_description, description = :description,
                price = :price, sale_price = :sale_price, stock = :stock, out_of_stock = :out_of_stock,
                image_url = :image_url, gallery_images_json = :gallery_images_json,
                is_active = 1, deleted_at = NULL
             WHERE id = :id'
        )->execute(array_diff_key($fields, ['slug' => true]));
        $report['products']['updated']++;
        echo "  [update] produs: {$p['slug']}\n";
    } else {
        $db->prepare(
            'INSERT INTO products
                (name, sku, category, category_id, slug, short_description, description,
                 price, sale_price, stock, out_of_stock, image_url, gallery_images_json, is_active)
             VALUES
                (:name, :sku, :category, :category_id, :slug, :short_description, :description,
                 :price, :sale_price, :stock, :out_of_stock, :image_url, :gallery_images_json, 1)'
        )->execute($fields);
        $report['products']['created']++;
        echo "  [nou]    produs: {$p['slug']}\n";
    }
}

// ---------------------------------------------------------------------------
// Migrare prin WP REST API
// ---------------------------------------------------------------------------

function wpRestAvailable(string $source): bool
{
    $data = httpGetJson($source . '/wp-json/wp/v2/types');
    return is_array($data) && $data !== [];
}

function migratePagesViaApi(PDO $db, string $source, array $options, array &$report): void
{
    global $sourceHost;
    echo "-- Migrare PAGINI (WP REST API) --\n";
    $pages = fetchAllPaginated($source . '/wp-json/wp/v2/pages?status=publish', $options['limit']);
    echo '   ' . count($pages) . " pagini găsite.\n";
    foreach ($pages as $page) {
        try {
            $title = cleanText((string) ($page['title']['rendered'] ?? ''));
            $slug = (string) ($page['slug'] ?? '');
            $content = (string) ($page['content']['rendered'] ?? '');
            if ($slug === '' || $title === '') {
                $report['pages']['skipped']++;
                continue;
            }
            $content = localizeContentImages($db, $content, $sourceHost, $options, $report);
            upsertPage($db, $options, $report, $title, $slug, $content);
        } catch (Throwable $e) {
            $report['pages']['errors']++;
            echo '  [EROARE] pagină: ' . ($page['slug'] ?? '?') . ' - ' . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

function migratePostsViaApi(PDO $db, string $source, array $options, array &$report): void
{
    global $sourceHost;
    echo "-- Migrare ARTICOLE BLOG (WP REST API) --\n";

    // Categoriile întâi (mapare id WP -> id local).
    $wpCatMap = [];
    foreach (fetchAllPaginated($source . '/wp-json/wp/v2/categories', 0) as $cat) {
        $name = cleanText((string) ($cat['name'] ?? ''));
        $slug = (string) ($cat['slug'] ?? '');
        if ($name === '' || $slug === '' || $slug === 'uncategorized') {
            continue;
        }
        if (!$options['dry_run']) {
            $wpCatMap[(int) ($cat['id'] ?? 0)] = ['id' => upsertBlogCategory($db, $name, $slug), 'name' => $name];
        }
    }
    echo '   ' . count($wpCatMap) . " categorii blog sincronizate.\n";

    $posts = fetchAllPaginated($source . '/wp-json/wp/v2/posts?status=publish&_embed=1', $options['limit']);
    echo '   ' . count($posts) . " articole găsite.\n";

    foreach ($posts as $post) {
        try {
            $slug = (string) ($post['slug'] ?? '');
            $title = cleanText((string) ($post['title']['rendered'] ?? ''));
            if ($slug === '' || $title === '') {
                $report['posts']['skipped']++;
                continue;
            }
            $content = localizeContentImages(
                $db,
                (string) ($post['content']['rendered'] ?? ''),
                $sourceHost,
                $options,
                $report
            );

            $authorId = 0;
            $embedded = is_array($post['_embedded'] ?? null) ? $post['_embedded'] : [];
            $authorName = cleanText((string) ($embedded['author'][0]['name'] ?? ''));
            if ($authorName !== '' && !$options['dry_run']) {
                $authorId = upsertBlogAuthor($db, $authorName);
            }

            $featured = '';
            $media = $embedded['wp:featured_media'][0] ?? null;
            if (is_array($media) && !empty($media['source_url'])) {
                $featured = localizeImage($db, (string) $media['source_url'], $options, $report, $title);
            }

            $categoryIds = [];
            $categoryNames = [];
            foreach ((array) ($post['categories'] ?? []) as $wpCatId) {
                if (isset($wpCatMap[(int) $wpCatId])) {
                    $categoryIds[] = $wpCatMap[(int) $wpCatId]['id'];
                    $categoryNames[] = $wpCatMap[(int) $wpCatId]['name'];
                }
            }

            $publishedAt = (string) ($post['date'] ?? '');
            $publishedAt = $publishedAt !== '' ? date('Y-m-d H:i:s', strtotime($publishedAt)) : date('Y-m-d H:i:s');

            upsertBlogPost($db, $options, $report, [
                'title' => $title,
                'slug' => $slug,
                'excerpt' => mb_substr(cleanText((string) ($post['excerpt']['rendered'] ?? '')), 0, 500),
                'category' => $categoryNames[0] ?? '',
                'category_id' => $categoryIds[0] ?? 0,
                'category_ids' => $categoryIds,
                'content' => $content,
                'published_at' => $publishedAt,
                'author_id' => $authorId,
                'featured_image_url' => $featured,
            ]);
        } catch (Throwable $e) {
            $report['posts']['errors']++;
            echo '  [EROARE] articol: ' . ($post['slug'] ?? '?') . ' - ' . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

/** Întoarce true dacă produsele au fost migrate printr-un API (nu mai e nevoie de crawl). */
function migrateProductsViaApi(PDO $db, string $source, array $options, array &$report): bool
{
    // 1) WooCommerce REST v3 cu chei (cele mai complete date: stoc exact, prețuri).
    if ($options['wc_key'] !== '' && $options['wc_secret'] !== '') {
        echo "-- Migrare PRODUSE (WooCommerce REST v3, autentificat) --\n";
        $auth = 'consumer_key=' . urlencode($options['wc_key']) . '&consumer_secret=' . urlencode($options['wc_secret']);
        $products = fetchAllPaginated($source . '/wp-json/wc/v3/products?status=publish&' . $auth, $options['limit']);
        if ($products !== []) {
            echo '   ' . count($products) . " produse găsite.\n";
            foreach ($products as $p) {
                importWcV3Product($db, $options, $report, $p);
            }
            echo "\n";
            return true;
        }
        echo "   [!] Nu am putut citi produse cu cheile date; încerc Store API public.\n";
    }

    // 2) WooCommerce Store API (public, fără chei).
    echo "-- Migrare PRODUSE (WooCommerce Store API, public) --\n";
    $products = fetchAllPaginated($source . '/wp-json/wc/store/v1/products', $options['limit']);
    if ($products === []) {
        $products = fetchAllPaginated($source . '/wp-json/wc/store/products', $options['limit']);
    }
    if ($products === []) {
        echo "   [!] Store API indisponibil. Se trece pe fallback crawl.\n\n";
        return false;
    }
    echo '   ' . count($products) . " produse găsite.\n";
    foreach ($products as $p) {
        importStoreApiProduct($db, $options, $report, $p);
    }
    echo "\n";
    return true;
}

function importWcV3Product(PDO $db, array $options, array &$report, array $p): void
{
    global $sourceHost;
    try {
        $slug = (string) ($p['slug'] ?? '');
        $name = cleanText((string) ($p['name'] ?? ''));
        if ($slug === '' || $name === '') {
            $report['products']['skipped']++;
            return;
        }

        $catId = 0;
        $catName = '';
        if (!$options['dry_run']) {
            foreach ((array) ($p['categories'] ?? []) as $cat) {
                $cn = cleanText((string) ($cat['name'] ?? ''));
                if ($cn === '') {
                    continue;
                }
                $cid = upsertProductCategory($db, $cn, (string) ($cat['slug'] ?? slugify($cn)));
                if ($catId === 0) {
                    $catId = $cid;
                    $catName = $cn;
                }
            }
        }

        $gallery = [];
        $mainImage = '';
        foreach ((array) ($p['images'] ?? []) as $img) {
            $src = (string) ($img['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $local = localizeImage($db, $src, $options, $report, cleanText((string) ($img['alt'] ?? $name)));
            if ($mainImage === '') {
                $mainImage = $local;
            } else {
                $gallery[] = $local;
            }
        }

        $regular = (float) ($p['regular_price'] ?: ($p['price'] ?: 0));
        $sale = $p['sale_price'] !== '' && $p['sale_price'] !== null ? (float) $p['sale_price'] : null;
        $stock = is_numeric($p['stock_quantity'] ?? null) ? (int) $p['stock_quantity'] : 0;
        $inStock = ($p['stock_status'] ?? 'instock') === 'instock';

        upsertProduct($db, $options, $report, [
            'name' => $name,
            'sku' => (string) ($p['sku'] ?? ''),
            'category' => $catName,
            'category_id' => $catId,
            'slug' => $slug,
            'short_description' => localizeContentImages($db, (string) ($p['short_description'] ?? ''), $sourceHost, $options, $report),
            'description' => localizeContentImages($db, (string) ($p['description'] ?? ''), $sourceHost, $options, $report),
            'price' => $regular,
            'sale_price' => $sale,
            'stock' => $stock,
            'out_of_stock' => !$inStock,
            'image_url' => $mainImage,
            'gallery' => $gallery,
        ]);
    } catch (Throwable $e) {
        $report['products']['errors']++;
        echo '  [EROARE] produs: ' . ($p['slug'] ?? '?') . ' - ' . $e->getMessage() . "\n";
    }
}

function importStoreApiProduct(PDO $db, array $options, array &$report, array $p): void
{
    global $sourceHost;
    try {
        $slug = (string) ($p['slug'] ?? '');
        $name = cleanText((string) ($p['name'] ?? ''));
        if ($slug === '' || $name === '') {
            $report['products']['skipped']++;
            return;
        }

        $catId = 0;
        $catName = '';
        if (!$options['dry_run']) {
            foreach ((array) ($p['categories'] ?? []) as $cat) {
                $cn = cleanText((string) ($cat['name'] ?? ''));
                if ($cn === '') {
                    continue;
                }
                $cid = upsertProductCategory($db, $cn, (string) ($cat['slug'] ?? slugify($cn)));
                if ($catId === 0) {
                    $catId = $cid;
                    $catName = $cn;
                }
            }
        }

        $gallery = [];
        $mainImage = '';
        foreach ((array) ($p['images'] ?? []) as $img) {
            $src = (string) ($img['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $local = localizeImage($db, $src, $options, $report, cleanText((string) ($img['alt'] ?? $name)));
            if ($mainImage === '') {
                $mainImage = $local;
            } else {
                $gallery[] = $local;
            }
        }

        // Store API: prețuri în unități minore (ex. bani), cu currency_minor_unit.
        $prices = (array) ($p['prices'] ?? []);
        $minorUnit = (int) ($prices['currency_minor_unit'] ?? 2);
        $div = 10 ** max(0, $minorUnit);
        $regular = isset($prices['regular_price']) ? ((float) $prices['regular_price']) / $div : 0.0;
        $current = isset($prices['price']) ? ((float) $prices['price']) / $div : $regular;
        $sale = null;
        if (isset($prices['sale_price']) && $prices['sale_price'] !== null && $current < $regular) {
            $sale = ((float) $prices['sale_price']) / $div;
        }

        $inStock = (bool) ($p['is_in_stock'] ?? true);

        upsertProduct($db, $options, $report, [
            'name' => $name,
            'sku' => (string) ($p['sku'] ?? ''),
            'category' => $catName,
            'category_id' => $catId,
            'slug' => $slug,
            'short_description' => localizeContentImages($db, (string) ($p['short_description'] ?? ''), $sourceHost, $options, $report),
            'description' => localizeContentImages($db, (string) ($p['description'] ?? ''), $sourceHost, $options, $report),
            'price' => $regular > 0 ? $regular : $current,
            'sale_price' => $sale,
            // Store API nu expune stocul numeric; punem 100 dacă e pe stoc (ajustezi din admin).
            'stock' => $inStock ? 100 : 0,
            'out_of_stock' => !$inStock,
            'image_url' => $mainImage,
            'gallery' => $gallery,
        ]);
    } catch (Throwable $e) {
        $report['products']['errors']++;
        echo '  [EROARE] produs: ' . ($p['slug'] ?? '?') . ' - ' . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// Fallback: sitemap + parsare HTML
// ---------------------------------------------------------------------------

function collectSitemapUrls(string $source): array
{
    $queue = [];
    foreach (['/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml'] as $candidate) {
        $xml = httpGet($source . $candidate);
        if ($xml !== null && str_contains($xml, '<')) {
            $queue[] = $xml;
            break;
        }
    }
    if ($queue === []) {
        return [];
    }

    $urls = [];
    $guard = 0;
    while ($queue !== [] && $guard < 50) {
        $guard++;
        $xml = array_shift($queue);
        if (preg_match_all('~<loc>\s*([^<]+?)\s*</loc>~i', $xml, $m)) {
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode(trim($loc), ENT_QUOTES);
                if (preg_match('~\.xml(\?.*)?$~i', $loc)) {
                    $sub = httpGet($loc);
                    if ($sub !== null) {
                        $queue[] = $sub;
                    }
                } else {
                    $urls[$loc] = true;
                }
            }
        }
    }
    return array_keys($urls);
}

function classifyUrl(string $url, string $html): string
{
    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    if (preg_match('~/(produs|product|magazin/produs|shop)/[^/]+/?$~', $path)) {
        return 'products';
    }
    if (str_contains($html, '"@type":"Product"') || str_contains($html, '"@type": "Product"')) {
        return 'products';
    }
    if (preg_match('~/(blog|articol|stiri|noutati)/[^/]+~', $path)
        || str_contains($html, '"@type":"BlogPosting"')
        || str_contains($html, '"@type": "BlogPosting"')
        || preg_match('~<meta[^>]+property="og:type"[^>]+content="article"~i', $html)) {
        return 'posts';
    }
    return 'pages';
}

function extractTitle(string $html): string
{
    if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~i', $html, $m)) {
        return cleanText($m[1]);
    }
    if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
        return cleanText($m[1]);
    }
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        // Scoate sufixul " - Nume Site" / " | Nume Site".
        return cleanText((string) preg_replace('~\s*[-|–]\s*[^-|–]+$~u', '', $m[1]));
    }
    return '';
}

function extractMainContent(string $html): string
{
    foreach ([
        '~<div[^>]+class="[^"]*\bentry-content\b[^"]*"[^>]*>(.*)</div>~isU',
        '~<article[^>]*>(.*)</article>~isU',
        '~<main[^>]*>(.*)</main>~isU',
        '~<div[^>]+id="content"[^>]*>(.*)</div>~isU',
    ] as $pattern) {
        if (preg_match($pattern, $html, $m) && mb_strlen(cleanText($m[1])) > 100) {
            $content = $m[1];
            // Curăță scripturi/stiluri/formulare rămase.
            $content = (string) preg_replace('~<(script|style|form|noscript)[^>]*>.*?</\1>~is', '', $content);
            return trim($content);
        }
    }
    return '';
}

/** Extrage primul obiect JSON-LD de tipul dat din pagină. */
function extractJsonLd(string $html, string $type): ?array
{
    if (!preg_match_all('~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $m)) {
        return null;
    }
    foreach ($m[1] as $block) {
        $decoded = json_decode(trim($block), true);
        if (!is_array($decoded)) {
            continue;
        }
        $candidates = [];
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $candidates = $decoded['@graph'];
        } elseif (array_is_list($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = [$decoded];
        }
        foreach ($candidates as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemType = $item['@type'] ?? '';
            $types = is_array($itemType) ? $itemType : [$itemType];
            if (in_array($type, $types, true)) {
                return $item;
            }
        }
    }
    return null;
}

function migrateViaCrawl(PDO $db, string $source, array $options, array &$report, string $wantedType): void
{
    global $sourceHost;
    static $classified = null;

    echo "-- Migrare {$wantedType} (fallback: sitemap + HTML) --\n";

    if ($classified === null) {
        $urls = collectSitemapUrls($source);
        if ($urls === []) {
            echo "   [!] Nu am găsit sitemap la {$source}. Nu pot continua fallback-ul.\n";
            echo "       Verifică manual /sitemap.xml sau furnizează chei WooCommerce.\n\n";
            $classified = ['pages' => [], 'posts' => [], 'products' => []];
            return;
        }
        echo '   ' . count($urls) . " URL-uri găsite în sitemap. Se clasifică (poate dura)...\n";
        $classified = ['pages' => [], 'posts' => [], 'products' => []];
        foreach ($urls as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host !== $sourceHost) {
                continue;
            }
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            if ($path === '' || preg_match('~/(tag|author|autor|page|wp-content|feed|atasament|attachment)(/|$)~', $path)) {
                continue;
            }
            $html = httpGet($url);
            if ($html === null) {
                continue;
            }
            $classified[classifyUrl($url, $html)][$url] = $html;
        }
        echo '   Clasificate: '
            . count($classified['pages']) . ' pagini, '
            . count($classified['posts']) . ' articole, '
            . count($classified['products']) . " produse.\n";
    }

    $items = $classified[$wantedType] ?? [];
    if ($options['limit'] > 0) {
        $items = array_slice($items, 0, $options['limit'], true);
    }

    foreach ($items as $url => $html) {
        try {
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            $slug = slugify((string) basename($path));
            $title = extractTitle($html);
            if ($title === '' || $slug === '') {
                $report[$wantedType]['skipped']++;
                continue;
            }

            if ($wantedType === 'products') {
                $ld = extractJsonLd($html, 'Product') ?? [];
                $offers = (array) ($ld['offers'] ?? []);
                if (isset($offers[0]) && is_array($offers[0])) {
                    $offers = $offers[0];
                }
                $price = (float) ($offers['price'] ?? ($offers['lowPrice'] ?? 0));
                $available = stripos((string) ($offers['availability'] ?? 'InStock'), 'InStock') !== false;
                $imageRaw = $ld['image'] ?? '';
                if (is_array($imageRaw)) {
                    $imageRaw = (string) (array_is_list($imageRaw) ? ($imageRaw[0] ?? '') : ($imageRaw['url'] ?? ''));
                }
                $image = $imageRaw !== '' ? localizeImage($db, (string) $imageRaw, $options, $report, $title) : '';
                $description = extractMainContent($html);
                $description = localizeContentImages($db, $description, $sourceHost, $options, $report);

                upsertProduct($db, $options, $report, [
                    'name' => cleanText((string) ($ld['name'] ?? $title)),
                    'sku' => (string) ($ld['sku'] ?? ''),
                    'category' => '',
                    'category_id' => 0,
                    'slug' => $slug,
                    'short_description' => mb_substr(cleanText((string) ($ld['description'] ?? '')), 0, 500),
                    'description' => $description,
                    'price' => $price,
                    'sale_price' => null,
                    'stock' => $available ? 100 : 0,
                    'out_of_stock' => !$available,
                    'image_url' => $image,
                    'gallery' => [],
                ]);
            } elseif ($wantedType === 'posts') {
                $ld = extractJsonLd($html, 'BlogPosting') ?? extractJsonLd($html, 'Article') ?? [];
                $content = localizeContentImages($db, extractMainContent($html), $sourceHost, $options, $report);
                if ($content === '') {
                    $report['posts']['skipped']++;
                    continue;
                }
                $published = (string) ($ld['datePublished'] ?? '');
                $authorRaw = $ld['author'] ?? [];
                if (isset($authorRaw[0]) && is_array($authorRaw[0])) {
                    $authorRaw = $authorRaw[0];
                }
                $authorName = cleanText((string) (is_array($authorRaw) ? ($authorRaw['name'] ?? '') : $authorRaw));
                $featuredRaw = $ld['image'] ?? '';
                if (is_array($featuredRaw)) {
                    $featuredRaw = (string) (array_is_list($featuredRaw) ? ($featuredRaw[0] ?? '') : ($featuredRaw['url'] ?? ''));
                }
                $featured = $featuredRaw !== '' ? localizeImage($db, (string) $featuredRaw, $options, $report, $title) : '';

                upsertBlogPost($db, $options, $report, [
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => mb_substr(cleanText((string) ($ld['description'] ?? '')), 0, 500),
                    'category' => '',
                    'category_id' => 0,
                    'category_ids' => [],
                    'content' => $content,
                    'published_at' => $published !== ''
                        ? date('Y-m-d H:i:s', strtotime($published))
                        : date('Y-m-d H:i:s'),
                    'author_id' => $authorName !== '' && !$options['dry_run'] ? upsertBlogAuthor($db, $authorName) : 0,
                    'featured_image_url' => $featured,
                ]);
            } else {
                $content = localizeContentImages($db, extractMainContent($html), $sourceHost, $options, $report);
                if ($content === '') {
                    $report['pages']['skipped']++;
                    continue;
                }
                upsertPage($db, $options, $report, $title, $slug, $content);
            }
        } catch (Throwable $e) {
            $report[$wantedType]['errors']++;
            echo "  [EROARE] {$url} - " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}
