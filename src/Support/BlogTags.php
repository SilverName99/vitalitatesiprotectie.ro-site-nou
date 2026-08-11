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

    /** Categoria cerută în URL (`?categorie=slug`), normalizată. */
    public static function requestedCategorySlug(): string
    {
        $raw = strtolower(trim((string) ($_GET['categorie'] ?? '')));
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

    /**
     * Grilă de carduri cu cele mai recente articole: categorie, titlu, rezumat
     * și link. Folosită prin tokenul {{posts_grid}} sau {{posts_grid:8}}.
     */
    public static function renderPostsGrid(?PDO $db, int $limit = 8): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        $limit = max(1, min(24, $limit));
        try {
            $stmt = $db->prepare(
                'SELECT id, title, slug, excerpt, content, category
                 FROM blog_posts
                 WHERE deleted_at IS NULL AND is_published = 1 AND published_at <= NOW()
                 ORDER BY published_at DESC, id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll();
        } catch (Throwable) {
            return '';
        }
        if (!is_array($posts) || $posts === []) {
            return '';
        }

        // Categoriile se aduc separat, ca să nu depindem de GROUP BY într-un
        // SELECT cu coloane neagregate (respinse sub ONLY_FULL_GROUP_BY).
        $categories = self::categoriesForPosts($db, array_map(
            static fn (array $p): int => (int) ($p['id'] ?? 0),
            $posts
        ));

        $html = self::listStyles() . '<div class="posts-grid">';
        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');
            if ($slug === '' || $title === '') {
                continue;
            }
            $id = (int) ($post['id'] ?? 0);
            $category = $categories[$id] ?? null;
            $categoryName = $category['name'] ?? trim((string) ($post['category'] ?? ''));

            $excerpt = trim((string) ($post['excerpt'] ?? ''));
            if ($excerpt === '') {
                $excerpt = trim(html_entity_decode(strip_tags((string) ($post['content'] ?? '')), ENT_QUOTES, 'UTF-8'));
            }
            if (mb_strlen($excerpt) > 230) {
                $excerpt = mb_substr($excerpt, 0, 230) . '…';
            }

            $html .= '<article class="posts-grid__card">';
            if ($categoryName !== '') {
                $categoryUrl = isset($category['slug']) && $category['slug'] !== ''
                    ? '/blog?categorie=' . rawurlencode((string) $category['slug'])
                    : '';
                $html .= '<p class="posts-grid__cat">în '
                    . ($categoryUrl !== ''
                        ? '<a href="' . $categoryUrl . '">' . htmlspecialchars($categoryName, ENT_QUOTES) . '</a>'
                        : htmlspecialchars($categoryName, ENT_QUOTES))
                    . '</p>';
            }
            $html .= '<h3 class="posts-grid__title"><a href="/blog/' . rawurlencode($slug) . '">'
                . htmlspecialchars($title, ENT_QUOTES) . '</a></h3>';
            if ($excerpt !== '') {
                $html .= '<p class="posts-grid__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>';
            }
            $html .= '<a class="posts-grid__more" href="/blog/' . rawurlencode($slug) . '">Citește… <span aria-hidden="true">→</span></a>';
            $html .= '</article>';
        }
        return $html . '</div>';
    }

    /**
     * Stilurile componentelor de listare, emise o singură dată pe pagină.
     *
     * Le livrăm împreună cu marcajul, ca tokenul să arate corect fără niciun
     * pas manual de configurare a CSS-ului.
     */
    private static function listStyles(): string
    {
        static $emitted = false;
        if ($emitted) {
            return '';
        }
        $emitted = true;

        return '<style>'
            . '.posts-list{display:flex;flex-direction:column;gap:34px;margin:0 0 30px;}'
            . '.posts-list__item{display:grid;grid-template-columns:300px minmax(0,1fr);'
            . 'gap:28px;align-items:start;}'
            . '.posts-list__media{display:block;overflow:hidden;border-radius:4px;}'
            . '.posts-list__media img{width:100%;height:auto;display:block;aspect-ratio:4/3;'
            . 'object-fit:cover;transition:transform .35s ease;}'
            . '.posts-list__media:hover img{transform:scale(1.04);}'
            . '.posts-list__cat{margin:0 0 8px;font-size:.9rem;font-weight:600;}'
            . '.posts-list__cat a{color:#2f6fb0;text-decoration:none;}'
            . '.posts-list__cat a:hover{text-decoration:underline;}'
            . '.posts-list__title{margin:0 0 12px;font-size:1.5rem;font-weight:700;line-height:1.3;}'
            . '.posts-list__title a{color:#1f2a33;text-decoration:none;}'
            . '.posts-list__title a:hover{color:#2f6fb0;}'
            . '.posts-list__excerpt{margin:0;color:#5b6a76;font-size:1rem;line-height:1.7;}'
            . '.posts-list__empty{color:#7b8794;font-style:italic;}'
            . '.posts-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:34px 30px;}'
            . '.posts-grid__card{display:flex;flex-direction:column;}'
            . '.posts-grid__cat{margin:0 0 6px;color:#7b8794;font-size:.9rem;}'
            . '.posts-grid__cat a{color:#7b8794;text-decoration:none;}'
            . '.posts-grid__cat a:hover{color:#2f6fb0;}'
            . '.posts-grid__title{margin:0 0 10px;font-size:1.3rem;font-weight:700;line-height:1.3;}'
            . '.posts-grid__title a{color:#1f2a33;text-decoration:none;}'
            . '.posts-grid__title a:hover{color:#2f6fb0;}'
            . '.posts-grid__excerpt{margin:0 0 12px;color:#5b6a76;font-size:.96rem;line-height:1.65;flex:1;}'
            . '.posts-grid__more{color:#2f6fb0;font-size:.95rem;text-decoration:none;}'
            . '.posts-grid__more:hover{text-decoration:underline;}'
            . '@media (max-width:820px){'
            . '.posts-list__item{grid-template-columns:1fr;gap:14px;}'
            . '.posts-list__title{font-size:1.25rem;}'
            . '.posts-grid{grid-template-columns:1fr;gap:28px;}}'
            . '</style>';
    }

    /**
     * Listă de articole dintr-o categorie: imagine în stânga, text în dreapta.
     * Folosită prin tokenul {{category_list:nutritie}} sau
     * {{category_list:nutritie:20}} pe paginile de secțiune.
     */
    public static function renderCategoryList(?PDO $db, string $categorySlug, int $limit = 20): string
    {
        if (!$db instanceof PDO) {
            return '';
        }
        // Slug malformat: refuzat, nu „curățat” tăcut într-altul valid.
        $raw = strtolower(trim($categorySlug));
        $categorySlug = (string) preg_replace('~[^a-z0-9\-]~', '', $raw);
        if ($categorySlug === '' || $categorySlug !== $raw) {
            return '';
        }
        $limit = max(1, min(60, $limit));

        try {
            $stmt = $db->prepare(
                'SELECT p.title, p.slug, p.excerpt, p.content, p.featured_image_url, c.name AS category_name
                 FROM blog_posts p
                 INNER JOIN blog_post_categories pc ON pc.post_id = p.id
                 INNER JOIN blog_categories c ON c.id = pc.category_id
                 WHERE c.slug = :slug
                   AND p.deleted_at IS NULL AND p.is_published = 1 AND p.published_at <= NOW()
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':slug', $categorySlug);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll();
        } catch (Throwable) {
            return '';
        }
        if (!is_array($posts) || $posts === []) {
            return '<p class="posts-list__empty">Momentan nu sunt articole în această secțiune.</p>';
        }

        $html = self::listStyles() . '<div class="posts-list">';
        foreach ($posts as $post) {
            $slug = (string) ($post['slug'] ?? '');
            $title = (string) ($post['title'] ?? '');
            if ($slug === '' || $title === '') {
                continue;
            }
            $url = '/blog/' . rawurlencode($slug);
            $image = trim((string) ($post['featured_image_url'] ?? ''));
            $categoryName = trim((string) ($post['category_name'] ?? ''));

            $excerpt = trim((string) ($post['excerpt'] ?? ''));
            if ($excerpt === '') {
                $excerpt = trim(html_entity_decode(strip_tags((string) ($post['content'] ?? '')), ENT_QUOTES, 'UTF-8'));
            }
            if (mb_strlen($excerpt) > 260) {
                $excerpt = mb_substr($excerpt, 0, 260) . '…';
            }

            $html .= '<article class="posts-list__item">';
            if ($image !== '') {
                $html .= '<a class="posts-list__media" href="' . $url . '">'
                    . '<img src="' . htmlspecialchars($image, ENT_QUOTES) . '" alt="'
                    . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy"></a>';
            }
            $html .= '<div class="posts-list__body">';
            if ($categoryName !== '') {
                $html .= '<p class="posts-list__cat"><a href="/blog?categorie=' . rawurlencode($categorySlug) . '">'
                    . htmlspecialchars($categoryName, ENT_QUOTES) . '</a></p>';
            }
            $html .= '<h2 class="posts-list__title"><a href="' . $url . '">'
                . htmlspecialchars($title, ENT_QUOTES) . '</a></h2>';
            if ($excerpt !== '') {
                $html .= '<p class="posts-list__excerpt">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>';
            }
            $html .= '</div></article>';
        }
        return $html . '</div>';
    }

    /**
     * Prima categorie a fiecărui articol.
     *
     * @param array<int, int> $postIds
     * @return array<int, array{name:string,slug:string}>
     */
    private static function categoriesForPosts(PDO $db, array $postIds): array
    {
        $postIds = array_values(array_filter($postIds));
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        try {
            $stmt = $db->prepare(
                'SELECT pc.post_id, c.name, c.slug
                 FROM blog_post_categories pc
                 INNER JOIN blog_categories c ON c.id = pc.category_id
                 WHERE pc.post_id IN (' . $placeholders . ')
                 ORDER BY pc.post_id, c.name'
            );
            $stmt->execute($postIds);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            if ($postId > 0 && !isset($map[$postId])) {
                $map[$postId] = ['name' => (string) ($row['name'] ?? ''), 'slug' => (string) ($row['slug'] ?? '')];
            }
        }
        return $map;
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
