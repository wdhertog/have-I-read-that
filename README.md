# Have I read that? 📚

Een klein, zelf te hosten alternatief voor Goodreads. Houd bij wat je leest, wilt
lezen en gelezen hebt; de site stuurt automatisch een toot naar je Mastodon-account
als je aan een boek begint of het uit hebt. Je StoryGraph-historie importeer je in
één keer, en met de Android-app scan je de barcode van een boek om het toe te voegen.

```
server/    PHP 8.1+ en MySQL/MariaDB: website, openbare pagina en JSON-API
android/   Kotlin/Jetpack Compose-app die met de API praat
```

## Functies

- Boeken met status *Wil ik lezen*, *Aan het lezen*, *Gelezen* en *Niet uitgelezen (DNF)*
- Beoordeling (0–5 in kwarten, zoals StoryGraph), recensie, tags, formaat, pagina's
- Meerdere leesmomenten per boek (herlezen telt mee)
- Posten naar Mastodon bij beginnen/uitlezen, met aanpasbare tekst; per actie uit te zetten
- Eenmalige import van een StoryGraph-export (CSV), zonder te posten; dubbel importeren is veilig
- Openbare alleen-lezen pagina op `/`: wat je nu leest en wat je per jaar gelezen hebt
  (je verlanglijst blijft privé)
- Leesdoel per jaar en leesvoortgang (pagina of percentage), eventueel met een toot
- Export naar CSV (StoryGraph-formaat, weer te importeren) en JSON
- Waarschuwing als je een boek toevoegt dat er al in staat
- Toots met de cover als afbeelding (met alt-tekst), en een jaaroverzicht-toot met een collage van covers
- Openbare boekpagina's met nette previews als je een link deelt, en een RSS-feed (`/feed.xml`)
- Statistieken per jaar, boeken zoeken via Open Library, covers via Open Library
- Eén gebruiker met een wachtwoord; de app logt in met een token dat je op de site aanmaakt

## Server installeren

### Met Docker (aanbevolen)

```sh
cd server
cp .env.example .env      # vul minstens APP_PASSWORD en DB_PASSWORD in
docker compose up -d --build
```

Dit start PHP/Apache en MariaDB. De database-tabellen worden bij het starten
automatisch aangemaakt of bijgewerkt. De site luistert op `127.0.0.1:8080`; zet er
een reverse proxy met HTTPS voor, bijvoorbeeld met Caddy:

```
boeken.example.nl {
    reverse_proxy 127.0.0.1:8080
}
```

Backups: `docker compose exec db mariadb-dump -u books -p books > backup.sql`.

Docker vertrouwt standaard `X-Forwarded-For` van het Docker-netwerk
(`TRUSTED_PROXIES=172.16.0.0/12`), zodat de inlogbeperking per echte bezoeker telt.
Je reverse proxy moet die header dus zetten (Caddy en de nginx-config hieronder doen dat).

### Op webhosting met `public_html` (DirectAdmin, cPanel, Plesk)

Composer en SSH zijn niet nodig.

1. Maak in het hostingpaneel een MySQL-database met een gebruiker aan.
2. Upload de **inhoud** van de map `server/` naar de `public_html` van je
   (sub)domein, dus `public_html/public`, `public_html/src`, `public_html/.htaccess`, enzovoort.
   Vergeet het verborgen bestand `.htaccess` niet.
3. Kopieer `.env.example` naar `.env` in `public_html` en vul het wachtwoord en
   de databasegegevens in (`DB_HOST` is meestal `localhost`).
4. Zet PHP in het paneel op 8.1 of nieuwer.
5. Open de site: de database-tabellen worden bij het eerste bezoek aangemaakt.

De `.htaccess` in de hoofdmap stuurt alles naar `public/`, zodat `.env` en de
code niet via de browser op te vragen zijn. Krijg je een foutmelding, zet dan
tijdelijk `APP_DEBUG=true` in `.env` om de oorzaak te zien.

### Zonder Docker (nginx/Apache + PHP-FPM + MySQL)

Vereist PHP 8.1+ met `pdo_mysql`, `curl` en `mbstring`, en Composer.

```sh
cd server
composer install --no-dev --optimize-autoloader
cp .env.example .env      # vul je databasegegevens in
mysql -e "CREATE DATABASE books CHARACTER SET utf8mb4; CREATE USER 'books'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON books.* TO 'books'@'localhost';"
php bin/migrate.php       # optioneel: gebeurt anders bij het eerste bezoek
```

Laat de webserver naar `server/public` wijzen en stuur alle verzoeken die geen
bestand zijn naar `index.php`. Voor Apache staat er al een `.htaccess`. Voor nginx:

```nginx
root /var/www/have-i-read-that/server/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

Een submap (bijv. `example.nl/boeken/`) werkt ook.

### Wachtwoord

`APP_PASSWORD` mag platte tekst zijn. Liever een hash? Draai
`php bin/hash-password.php` en zet de uitkomst tussen enkele quotes:
`APP_PASSWORD='$2y$10$...'`.

## Beveiliging

- **Tweestapsverificatie:** zet onder *Instellingen* aan dat de site na je
  wachtwoord om een code uit je authenticator-app vraagt. Je krijgt 8 herstelcodes
  die elk één keer werken. Alles kwijt? `php bin/disable-2fa.php` op de server zet
  het uit.
- **Tokens met beperkte rechten:** een token met *Alleen lezen* kan niets
  wijzigen of posten (handig voor een widget of script).
- **Covers op je eigen server:** covers worden één keer bij Open Library opgehaald
  en in `server/var/covers` bewaard. Bezoekers van je pagina's maken dus geen
  contact meer met Open Library of archive.org. Bij *Bewerken* kun je een eigen
  omslag uploaden; die wordt opnieuw opgeslagen als schone JPEG, zonder
  metadata zoals GPS-gegevens.
- **Back-ups:** onder *Import/export* download je een volledige SQL-back-up, die je
  met phpMyAdmin terugzet. Automatisch elke nacht, met de nieuwste 14 bewaard:

  ```
  15 3 * * * php /pad/naar/bin/backup.php
  ```

  Ze komen in `server/var/backups` (of `BACKUP_DIR`). Kopieer ze af en toe naar een
  andere plek; een back-up op dezelfde server helpt niet als die server stuk gaat.

- Inloggen: na 10 mislukte pogingen vanaf hetzelfde adres (IPv6: dezelfde /64) is
  dat adres een kwartier geblokkeerd. Kies toch een sterk wachtwoord, liefst als hash.
- Een ander `APP_PASSWORD` instellen logt alle browsers uit.
- Een app-token geeft volledige toegang, inclusief posten naar Mastodon. Trek het in
  onder *Instellingen* als je telefoon kwijt is.
- Sessies, covers en back-ups staan in `server/var`; die map moet schrijfbaar zijn voor PHP.
- Controleer na het installeren dat `https://jouw-site/.env` een 403 of 404 geeft.

## Instellingen (`server/.env`)

| Variabele | Standaard | Uitleg |
|---|---|---|
| `APP_PASSWORD` | — | Wachtwoord om in te loggen (verplicht) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | `127.0.0.1`, `3306`, `books`, `books`, — | Database |
| `PUBLIC_PAGE` | `true` | Openbare pagina op `/`; bij `false` moet je overal inloggen |
| `PUBLIC_TITLE` | `Wat ik lees` | Titel van de openbare pagina |
| `PUBLIC_SHOW_REVIEWS` | `true` | Recensies tonen op de openbare pagina |
| `MASTODON_INSTANCE` | — | Bijv. `mastodon.social` |
| `MASTODON_ACCESS_TOKEN` | — | Token van je Mastodon-applicatie |
| `MASTODON_VISIBILITY` | `public` | `public`, `unlisted`, `private` of `direct` |
| `MASTODON_POST_BY_DEFAULT` | `true` | Of “Post naar Mastodon” standaard aangevinkt is (ook in de app) |
| `MASTODON_TEMPLATE_FINISHED` | zie `.env.example` | Tekst bij uitlezen |
| `MASTODON_TEMPLATE_STARTED` | zie `.env.example` | Tekst bij beginnen |
| `MASTODON_TEMPLATE_PROGRESS` | `📖 {percent}% gelezen van {title} – {author}` + hashtags | Tekst bij voortgang; ook `{page}` en `{pages}` |
| `MASTODON_ATTACH_COVER` | `true` | De cover als afbeelding bij de toot |
| `APP_URL` | — | Adres van je site, voor de RSS-feed, previews en `{url}` in toots |
| `MASTODON_MAX_CHARS` | `500` | Lange recensies worden ingekort tot de toot past |

In de templates kun je `{title}`, `{author}`, `{stars}`, `{rating}`, `{review}` en
`{isbn}` gebruiken, en `\n` voor een nieuwe regel.

## Mastodon koppelen

1. Op je Mastodon-server: *Voorkeuren → Ontwikkelaars → Nieuwe applicatie*.
2. Vink alleen `write:statuses` en `read:accounts` aan.
3. Zet het toegangstoken in `MASTODON_ACCESS_TOKEN` en je server in `MASTODON_INSTANCE`.
4. Controleer het op de site onder *Instellingen → Test verbinding*.

## Jaaroverzicht

Onder *Statistieken* staat een kaart om je leesjaar te posten: aantal boeken,
pagina's, je favoriet en een collage van covers. Je kunt de tekst eerst aanpassen.
Automatisch posten op 31 december kan met een cronjob in je hostingpaneel:

```
0 20 31 12 * php /pad/naar/bin/year-review.php
```

Proberen zonder te posten: `php bin/year-review.php 2025 --dry-run`.

## StoryGraph importeren

1. In StoryGraph: *Manage Account → Manage Your Data → Export StoryGraph Library*.
2. Download de CSV zodra hij klaar is.
3. Op de site: *Import* → kies het bestand → *Importeren*.

Leesstatus, beoordeling, recensie, tags, moods, formaat, “owned” en alle
leesdatums (ook herlezingen) worden overgenomen. Er wordt niets gepost. Boeken die
al bestaan (zelfde ISBN, of zelfde titel + auteur) worden overgeslagen.

## Android-app

De app kan boeken toevoegen door de ISBN-barcode te scannen (via Google Play
services, dus zonder camera-toestemming), boeken starten en uitlezen met
beoordeling en recensie (en daarbij naar Mastodon posten), en je bibliotheek
doorbladeren en doorzoeken.

**APK krijgen:** elke wijziging aan de app op `main` levert een nieuwe versie op
onder *Releases* op GitHub. Installeer die over de vorige heen (sta “installeren
uit onbekende bron” toe). Zonder ondertekeningssleutel (zie hieronder) staat er
alleen een testversie onder *Actions → laatste run → Artifacts*, en die kan niet
over een eerdere versie heen.

**Eenmalig: een eigen ondertekeningssleutel.** Android staat een update alleen toe
als hij met dezelfde sleutel is ondertekend. Maak die sleutel zelf, op je eigen
computer (`keytool` zit bij Java en Android Studio):

```sh
keytool -genkeypair -v -keystore have-i-read-that.jks -alias haveireadthat \
        -keyalg RSA -keysize 4096 -validity 10000
```

Zet daarna onder *Settings → Secrets and variables → Actions → New repository
secret* deze vier geheimen:

| Naam | Waarde |
| --- | --- |
| `SIGNING_KEYSTORE_BASE64` | Het bestand als base64 (macOS: `base64 -i have-i-read-that.jks \| pbcopy`; Linux: `base64 -w0 have-i-read-that.jks`; Windows PowerShell: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("have-i-read-that.jks")) \| Set-Clipboard`) |
| `SIGNING_STORE_PASSWORD` | Het wachtwoord dat je bij `keytool` koos |
| `SIGNING_KEY_PASSWORD` | Hetzelfde wachtwoord |
| `SIGNING_KEY_ALIAS` | `haveireadthat` |

Bewaar `have-i-read-that.jks` en het wachtwoord goed (bijv. in je
wachtwoordmanager), maar zet ze nooit in de repository. Kwijt betekent: de app één
keer verwijderen en opnieuw installeren. De overstap van de testversie naar de
ondertekende versie vraagt ook één keer verwijderen.

Of open de map `android/` in Android Studio en kies *Run*.

**Koppelen:** maak op de site onder *Instellingen → Android-app* een token aan.
Vul in de app het serveradres (bijv. `https://boeken.example.nl`) en het token in.
Een token kun je daar ook weer intrekken. De app praat alleen via HTTPS met je server.

### API

Alle verzoeken met `Authorization: Bearer <token>`; bodies zijn JSON.

| Methode en pad | Wat |
|---|---|
| `GET /api/me` | Controleer het token; Mastodon-status |
| `GET /api/books?status=&q=` | Lijst van boeken |
| `GET /api/books/{id}` | Boek met leesmomenten, toots en voorbeeldtoots |
| `POST /api/books` | Boek toevoegen (`title`, `author`, `isbn`, `pages`, `status`, `rating`, `review`, `toot`) |
| `POST /api/books/{id}/start` | Begin met lezen (`date`, `toot`) |
| `POST /api/books/{id}/finish` | Uitgelezen (`date`, `rating`, `review`, `dnf`, `toot`) |
| `POST /api/books/{id}/toot` | Nu posten (`kind`: `started`/`finished`, optioneel `text`) |
| `GET /api/lookup?isbn=` | Staat dit ISBN al in je bibliotheek, en wat weet Open Library? |

## Ontwikkelen

```sh
cd server
composer install
cp .env.example .env
php bin/migrate.php
php -S 127.0.0.1:8080 -t public public/index.php
vendor/bin/phpunit        # integratietests gebruiken database books_test (zie phpunit.xml)
```
