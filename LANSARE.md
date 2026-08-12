# Listă de verificare pentru mutarea domeniului

Pași de făcut la trecerea de la `vitalitatesiprotectie.ro` (site vechi) la site-ul nou,
**pe lângă** setarea nameserverelor și conectarea domeniului în Hostinger.

Ordinea contează: pașii de la „Înainte de comutare" trebuie făcuți cât timp
site-ul vechi este încă online.

---

## 1. Înainte de comutarea DNS

### Emailul — cel mai ușor de stricat

Schimbarea nameserverelor înlocuiește **toată** zona DNS, inclusiv
înregistrările de email. Dacă domeniul are căsuțe sau folosește un serviciu
extern (Google Workspace, Zoho, cPanel-ul vechi), emailul se oprește în
momentul comutării.

- [ ] Notează înregistrările actuale, la vechiul furnizor sau cu
      `dig MX vitalitatesiprotectie.ro` / `dig TXT vitalitatesiprotectie.ro`
- [ ] Recreează în Hostinger, **înainte** de comutare: `MX`, `SPF` (TXT),
      `DKIM`, `DMARC`
- [ ] Notează și subdomeniile existente: `www`, `mail`, `webmail`, `ftp`,
      orice altceva
- [ ] Notează înregistrările TXT de verificare (Google Search Console,
      Facebook Business etc.) — altfel pierzi verificarea

### Copie de siguranță a site-ului vechi

- [ ] Export complet al bazei de date WordPress
- [ ] Arhivă cu `wp-content/uploads`
- [ ] Păstrează-le cel puțin o lună după mutare

### Ultima sincronizare de conținut

Clientul poate publica ceva în ultimele zile. Rulează migrarea încă o dată,
cât site-ul vechi mai răspunde:

```bash
php scripts/migrate-from-wordpress.php --only=pages,posts
php scripts/scan-legacy-classes.php
cat storage/logs/migrate-images-failed.log 2>/dev/null | wc -l
```

- [ ] Migrare rulată în ultima zi înainte de comutare
- [ ] Zero clase nestilizate raportate de scaner
- [ ] Imaginile eșuate verificate (dacă sunt 404 reale, nu se pot recupera)

### Legături interne rămase către site-ul vechi

Articolele migrate conțin linkuri scrise în vechea structură de adrese.
După comutare vor ajunge pe site-ul nou, dar pe căi care nu există.

```bash
php -r '
require "bootstrap.php"; $c = require "config/app.php";
$db = App\Support\Database::connection($c["db"]);
$n = $db->query("SELECT COUNT(*) FROM blog_posts WHERE content LIKE \"%vitalitatesiprotectie.ro%\"")->fetchColumn();
$m = $db->query("SELECT COUNT(*) FROM pages WHERE html_content LIKE \"%vitalitatesiprotectie.ro%\"")->fetchColumn();
printf("articole cu linkuri vechi: %d | pagini: %d\n", $n, $m);'
```

- [ ] Numărul verificat
- [ ] Dacă sunt multe, planificat fie rescrierea lor, fie redirecționări

---

## 2. Pregătirea site-ului nou

### Fișiere de diagnostic (obligatoriu)

```bash
rm -f .user.ini php-errors.log public/_debug.php
```

- [ ] Șterse — `.user.ini` afișează erorile PHP vizitatorilor

### Configurare

- [ ] `.env`: `APP_DEBUG=false`
- [ ] `.env`: `APP_URL=https://vitalitatesiprotectie.ro`
- [ ] Parola contului de admin schimbată din cea de instalare
- [ ] Verificat că `.env` **nu** este în Git (`git status` curat)

### Conținut și aspect

- [ ] Pagina principală are slug-ul `acasa` (sau gol) și arată corect
- [ ] Antetul și footer-ul salvate în Design Site
- [ ] Adresele de Facebook / YouTube completate (nu `INLOCUIESTE`)
- [ ] Toate linkurile din meniu și bara laterală testate una câte una
- [ ] Pagina de contact funcționează: **trimite un mesaj de test**
- [ ] Expeditorul emailurilor configurat în Admin → Email-uri
- [ ] Favicon încărcat (Admin → Setări magazin → Favicon)
- [ ] Pagină 404 verificată (accesează o adresă inexistentă)
- [ ] Verificat pe telefon, nu doar pe desktop

---

## 3. La comutare

- [ ] Nameservere schimbate la registrar
- [ ] Domeniu conectat în Hostinger
- [ ] Așteptată propagarea (de la câteva minute la 24h): `dig vitalitatesiprotectie.ro`
- [ ] Certificat SSL emis automat de Hostinger — verifică lacătul în browser
- [ ] Redirecționare forțată către HTTPS activată din hPanel
- [ ] `https://www.vitalitatesiprotectie.ro` duce în același loc ca varianta fără `www`

---

## 4. După comutare

### Redirecționări pentru adresele vechi

**Se fac automat.** Când o adresă veche nu corespunde niciunei rute, aplicația
caută slug-ul între pagini și articole și trimite un redirect permanent (301)
către adresa nouă. Acoperă articolele mutate sub `/blog/`, prefixele vechi de
secțiune, arhivele de categorie și etichetă, arhivele de autor, precum și
variantele WordPress `/feed`, `/amp`, `/page/2`.

Verifică după comutare câteva adrese vechi reprezentative:

```bash
for u in "/nume-articol-vechi/" "/category/nutritie/" "/tag/cancer/"; do
  echo "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' "https://vitalitatesiprotectie.ro$u")  $u"
done
```

- [ ] Adrese vechi testate, răspund cu `301` și duc unde trebuie
- [ ] Export din Google Search Console cu adresele care aduc trafic, pentru
      a verifica dacă vreuna rămâne la 404

### Motoare de căutare

- [ ] Sitemap activat (Admin → Setări magazin → Sitemap XML)
- [ ] Sitemap trimis în Google Search Console
- [ ] `robots.txt` nu blochează indexarea
- [ ] Codul Google Analytics / Tag Manager adăugat, dacă exista pe site-ul vechi

Domeniul rămâne același, deci **nu** e nevoie de „Change of address" în
Search Console — doar de sitemap și redirecționări.

### Verificare finală

- [ ] Emailul funcționează: trimite și primește un mesaj de test
- [ ] Imaginile se încarcă (nu mai există adrese către site-ul vechi)
- [ ] Formularul de contact ajunge în căsuță
- [ ] Site-ul vechi **nu** este șters încă — păstrează-l măcar două
      săptămâni, ca sursă de recuperare

### Opțional, pentru viteză

Pe găzduire partajată, primul acces după inactivitate e mai lent. Un cron la
5 minute ține aplicația „caldă":

```
*/5 * * * * wget -q -O /dev/null https://vitalitatesiprotectie.ro/health
```
