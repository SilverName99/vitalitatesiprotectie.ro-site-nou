# Migrare conținut din vitalitatesiprotectie.ro (site-ul vechi)

Backend-ul din acest repo este o copie a platformei custom PHP (magazin + blog + admin)
din `Mutare-Site-Biovitality`, adaptată pentru **Vitalitate și Protecție**.

Scriptul `scripts/migrate-from-wordpress.php` preia **automat** conținutul din site-ul
vechi (WordPress/WooCommerce) și îl importă în baza de date a noului backend:

| Conținut vechi (WordPress)       | Destinație în noul backend                  |
|----------------------------------|---------------------------------------------|
| Pagini                           | `pages` (Admin → Pagini)                    |
| Articole blog                    | `blog_posts` (Admin → Blog)                 |
| Categorii articole               | `blog_categories` + legături many-to-many   |
| Autori articole                  | `blog_authors`                              |
| Produse WooCommerce              | `products` (Admin → Produse)                |
| Categorii produse                | `product_categories`                        |
| Imagini (featured + din conținut)| `public/uploads/migrated/` + `gallery_images` (Admin → Galerie) |

## Pași

### 1. Instalează backend-ul

```bash
cp .env.example .env       # completează datele DB
php scripts/install.php
php scripts/seed.php       # opțional, date demo
```

### 2. Rulează migrarea (test întâi)

```bash
# Test fără scriere în DB - vezi ce ar fi importat:
php scripts/migrate-from-wordpress.php --dry-run

# Migrare completă (pagini + articole + produse + imagini):
php scripts/migrate-from-wordpress.php
```

Sursa implicită este `https://vitalitatesiprotectie.ro`. O poți schimba cu `--source=`.

### 3. Opțiuni utile

```bash
# Doar produse:
php scripts/migrate-from-wordpress.php --only=products

# Doar pagini + articole:
php scripts/migrate-from-wordpress.php --only=pages,posts

# Primele 10 elemente per tip (test rapid):
php scripts/migrate-from-wordpress.php --limit=10

# Fără descărcarea imaginilor (păstrează URL-urile de pe site-ul vechi):
php scripts/migrate-from-wordpress.php --skip-images

# Cu chei WooCommerce (recomandat pentru stocuri exacte + prețuri complete):
php scripts/migrate-from-wordpress.php --wc-key=ck_xxx --wc-secret=cs_xxx
```

Cheile WooCommerce se generează din site-ul vechi:
**WP Admin → WooCommerce → Settings → Advanced → REST API → Add key** (permisiune Read).
Se pot da și prin variabile de mediu: `WC_CONSUMER_KEY`, `WC_CONSUMER_SECRET`.

## Cum funcționează (ordinea de detecție)

1. **WP REST API** (`/wp-json/wp/v2/...`) — pagini, articole, categorii, autori.
2. **Produse**: WooCommerce REST v3 (dacă ai dat chei) → altfel **Store API** publică
   (`/wp-json/wc/store/v1/products`, fără chei; stocul numeric nu e public, așa că
   produsele pe stoc primesc stoc 100 — ajustezi din admin sau rulezi cu chei).
3. **Fallback**: dacă API-urile sunt blocate (firewall, plugin de securitate), scriptul
   citește `sitemap.xml`, clasifică URL-urile (produs / articol / pagină) după structura
   URL și datele JSON-LD din pagini, și extrage conținutul din HTML.

## Bine de știut

- **Idempotent**: poți rula scriptul de câte ori vrei; actualizează după `slug`, nu duplică.
- **Imaginile** ajung în `public/uploads/migrated/` și sunt înregistrate automat în
  Galerie; URL-urile din conținut sunt rescrise către copiile locale. Imaginile de pe
  CDN-uri terțe rămân neatinse.
- **Rulează de pe un IP care are acces la site-ul vechi** (serverul tău sau local).
  Dacă site-ul vechi are Wordfence/Cloudflare agresiv, pune IP-ul pe whitelist.
- **Utilizatori/clienți**: se migrează separat cu `scripts/import-wordpress-users.php`
  (export CSV din WP) — vezi README.
- **Comenzile istorice** din WooCommerce nu se migrează (schema diferă prea mult);
  dacă ai nevoie de ele, păstrează un dump al DB-ului vechi.

## După migrare - verificări

1. Admin → Pagini / Blog / Produse — verifică conținutul importat.
2. Admin → Design Site — configurează header/footer/meniu (nu se pot migra automat,
   fiind structuri diferite de WordPress).
3. Admin → Setări — nume magazin, email-uri, transport, Stripe.
4. Setează pagina principală și meniul cu link-urile către paginile migrate.
