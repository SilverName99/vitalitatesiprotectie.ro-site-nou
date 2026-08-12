<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/**
 * Redirecționează adresele vechi de WordPress către cele noi.
 *
 * Structura s-a schimbat la migrare: articolele stăteau la `/nume-articol/`
 * (uneori sub un prefix, ex. `/cmo/nume-articol/`) și sunt acum la
 * `/blog/nume-articol`, iar arhivele de categorie și etichetă au devenit
 * filtre pe listarea blogului.
 *
 * Rezolvarea este dinamică pentru că adresa veche nu spune dacă ținta era
 * pagină sau articol: căutăm slug-ul în ambele și trimitem unde există.
 * Se apelează doar când nicio rută nu s-a potrivit, deci nu poate devia
 * trafic care funcționează deja.
 */
final class LegacyRedirects
{
    /** Prefixe de secțiune din vechea structură, ignorate la potrivire. */
    private const IGNORED_PREFIXES = [
        'cmo',
        'blog',
        'articol',
        'articole',
        'stiri',
        'noutati',
        'recomanda-cmo',
        'cercetatori-cmo',
        'specialisti-recomanda-cmo',
        'utilizatori-recomanda-cmo',
    ];

    /** Segmente de la finalul adreselor WordPress, fără corespondent aici. */
    private const IGNORED_SUFFIXES = ['feed', 'amp', 'embed', 'trackback', 'print'];

    /**
     * @return string|null Adresa nouă, sau null dacă nu există echivalent.
     */
    public static function resolve(?PDO $db, string $requestPath): ?string
    {
        $path = (string) parse_url($requestPath, PHP_URL_PATH);
        $segments = self::segments($path);
        if ($segments === []) {
            return null;
        }

        // Arhive de autor: nu avem echivalent, trimitem la listarea completă.
        if ($segments[0] === 'author' || $segments[0] === 'autor') {
            return '/blog';
        }

        // Arhive de categorie și etichetă -> filtre pe listarea blogului.
        // Categoriile WordPress pot fi imbricate (`/category/parinte/copil/`),
        // deci contează ultimul segment.
        $taxonomy = match ($segments[0]) {
            'category', 'categorie' => 'categorie',
            'tag', 'eticheta', 'etichete' => 'eticheta',
            default => null,
        };
        if ($taxonomy !== null) {
            $slug = end($segments);
            return $slug !== false && $slug !== $segments[0]
                ? '/blog?' . $taxonomy . '=' . rawurlencode((string) $slug)
                : '/blog';
        }

        // `/blog/ceva` a ajuns aici înseamnă articol inexistent; dacă am
        // continua, l-am redirecta tot spre `/blog/ceva` și s-ar bucla.
        if ($segments[0] === 'blog' && count($segments) > 1) {
            return null;
        }

        $slug = self::candidateSlug($segments);
        if ($slug === '') {
            return null;
        }

        return self::targetForSlug($db, $slug);
    }

    /** @return array<int, string> */
    private static function segments(string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', strtolower(trim($path, '/'))),
            static fn (string $part): bool => $part !== ''
        ));

        // Elimină sufixele WordPress: /feed, /amp, /page/2 etc.
        while ($segments !== []) {
            $last = end($segments);
            $count = count($segments);
            if (in_array($last, self::IGNORED_SUFFIXES, true)) {
                array_pop($segments);
                continue;
            }
            // Paginare: `.../page/2`
            if ($count >= 2 && ctype_digit((string) $last) && $segments[$count - 2] === 'page') {
                array_pop($segments);
                array_pop($segments);
                continue;
            }
            break;
        }

        return $segments;
    }

    /** Ultimul segment semnificativ al adresei vechi. */
    private static function candidateSlug(array $segments): string
    {
        $last = (string) (end($segments) ?: '');
        if ($last === '' || in_array($last, self::IGNORED_PREFIXES, true)) {
            return '';
        }
        // Slug-urile WordPress sunt ASCII; orice altceva nu poate fi o țintă.
        return (string) preg_replace('~[^a-z0-9\-_]~', '', $last);
    }

    /** Caută slug-ul întâi între pagini, apoi între articole. */
    private static function targetForSlug(?PDO $db, string $slug): ?string
    {
        if (!$db instanceof PDO) {
            return null;
        }
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM pages
                 WHERE slug = :slug AND is_published = 1 AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['slug' => $slug]);
            if ($stmt->fetchColumn()) {
                return '/' . $slug;
            }

            $stmt = $db->prepare(
                'SELECT 1 FROM blog_posts
                 WHERE slug = :slug AND is_published = 1 AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['slug' => $slug]);
            if ($stmt->fetchColumn()) {
                return '/blog/' . $slug;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
