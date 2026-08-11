<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Etichete pentru articolele de blog (echivalentul tag-urilor din WordPress).
 *
 * Sunt separate de categorii: un articol poate avea multe etichete, iar norul
 * de etichete din bara laterală filtrează listarea blogului prin `?eticheta=`.
 */
final class BlogTags
{
    public static function ensureSchema(PDO $db): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS blog_tags (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(170) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS blog_post_tags (
                post_id INT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (post_id, tag_id),
                KEY idx_bpt_tag (tag_id)
            )',
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable) {
                // Tabela există deja.
            }
        }
    }

    /** Eticheta cerută în URL (`?eticheta=slug`), normalizată. */
    public static function requestedSlug(): string
    {
        $raw = (string) ($_GET['eticheta'] ?? $_GET['tag'] ?? '');
        $raw = strtolower(trim($raw));
        return (string) preg_replace('~[^a-z0-9\-]~', '', $raw);
    }

    /** @return array<int, array{id:int,name:string,slug:string,posts:int}> */
    public static function all(?PDO $db, int $limit = 60): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            self::ensureSchema($db);
            $stmt = $db->prepare(
                'SELECT t.id, t.name, t.slug, COUNT(pt.post_id) AS posts
                 FROM blog_tags t
                 INNER JOIN blog_post_tags pt ON pt.tag_id = t.id
                 INNER JOIN blog_posts p ON p.id = pt.post_id
                    AND p.deleted_at IS NULL AND p.is_published = 1 AND p.published_at <= NOW()
                 GROUP BY t.id, t.name, t.slug
                 HAVING posts > 0
                 ORDER BY t.name ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', max(1, min(300, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Norul de etichete, gata de inserat în pagină sau în bara laterală. */
    public static function renderCloud(?PDO $db, int $limit = 60): string
    {
        $tags = self::all($db, $limit);
        if ($tags === []) {
            return '';
        }
        $active = self::requestedSlug();

        $html = '<div class="blog-tags">';
        foreach ($tags as $tag) {
            $slug = (string) ($tag['slug'] ?? '');
            $name = (string) ($tag['name'] ?? '');
            if ($slug === '' || $name === '') {
                continue;
            }
            $isActive = $slug === $active;
            $html .= '<a class="blog-tags__item' . ($isActive ? ' is-active' : '') . '"'
                . ' href="/blog?eticheta=' . rawurlencode($slug) . '"'
                . ' title="' . htmlspecialchars($name . ' (' . (int) ($tag['posts'] ?? 0) . ' articole)', ENT_QUOTES) . '">'
                . htmlspecialchars($name, ENT_QUOTES)
                . '</a>';
        }
        return $html . '</div>';
    }

    /** Lista compactă de articole recente, pentru bara laterală. */
    public static function renderRecentPosts(?PDO $db, int $limit = 5): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        try {
            $stmt = $db->prepare(
                'SELECT title, slug FROM blog_posts
                 WHERE deleted_at IS NULL AND is_published = 1 AND published_at <= NOW()
                 ORDER BY published_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return '';
        }
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $html = '<ul class="blog-recent">';
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $title = (string) ($row['title'] ?? '');
            if ($slug === '' || $title === '') {
                continue;
            }
            $html .= '<li><a href="/blog/' . rawurlencode($slug) . '">'
                . htmlspecialchars($title, ENT_QUOTES) . '</a></li>';
        }
        return $html . '</ul>';
    }

    /** Numele etichetei active, pentru titluri de tipul „Articole etichetate X”. */
    public static function nameForSlug(?PDO $db, string $slug): string
    {
        if (!$db instanceof PDO || $slug === '') {
            return '';
        }
        try {
            $stmt = $db->prepare('SELECT name FROM blog_tags WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
    }
}
