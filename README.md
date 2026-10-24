# Have I read that? 📚

Een klein, zelf te hosten alternatief voor Goodreads. Houd bij wat je leest, wilt
lezen en gelezen hebt, en laat de app automatisch een toot naar je Mastodon-account
sturen als je aan een boek begint of het uit hebt. Je StoryGraph-historie importeer
je in één keer.

## Functies

- Boeken met status *Wil ik lezen*, *Aan het lezen*, *Gelezen* en *Niet uitgelezen (DNF)*
- Beoordeling (0–5, in kwarten zoals StoryGraph), recensie, tags, formaat, pagina's
- Meerdere leesmomenten per boek (herlezen telt mee)
- Posten naar Mastodon bij beginnen/uitlezen, met aanpasbare tekst; per actie uit te zetten
- Eenmalige import van een StoryGraph-export (CSV), zonder te posten; dubbel importeren is veilig
- Boeken zoeken via Open Library om gegevens in te vullen, covers via Open Library
- Statistieken per jaar (aantal boeken, pagina's, gemiddelde beoordeling)
- Eén gebruiker, beveiligd met een wachtwoord; alles in één SQLite-bestand

## Installeren met Docker

```sh
git clone <deze repo> have-i-read-that && cd have-i-read-that
cp .env.example .env     # vul APP_PASSWORD en je Mastodon-gegevens in
docker compose up -d --build
```

De app draait op <http://localhost:8000>. De database staat in `./data/books.sqlite3`
— maak daar backups van. Zet de app achter een reverse proxy met HTTPS (Caddy,
Traefik, nginx) als je hem vanaf internet bereikbaar maakt, en zet dan
`SESSION_COOKIE_SECURE=true`.

## Mastodon koppelen

1. Op je Mastodon-server: *Voorkeuren → Ontwikkelaars → Nieuwe applicatie*.
2. Vink alleen `write:statuses` en `read:accounts` aan.
3. Zet het toegangstoken in `MASTODON_ACCESS_TOKEN` en je server in `MASTODON_INSTANCE`.
4. Herstart en controleer op de pagina *Mastodon* met “Test verbinding”.

| Variabele | Standaard | Uitleg |
|---|---|---|
| `APP_PASSWORD` | — | Wachtwoord om in te loggen (verplicht) |
| `MASTODON_INSTANCE` | — | Bijv. `mastodon.social` |
| `MASTODON_ACCESS_TOKEN` | — | Token van je Mastodon-applicatie |
| `MASTODON_VISIBILITY` | `public` | `public`, `unlisted`, `private` of `direct` |
| `MASTODON_POST_BY_DEFAULT` | `true` | Of het vinkje “Post naar Mastodon” standaard aan staat |
| `MASTODON_TEMPLATE_FINISHED` | zie `.env.example` | Tekst bij uitlezen |
| `MASTODON_TEMPLATE_STARTED` | zie `.env.example` | Tekst bij beginnen |
| `MASTODON_MAX_CHARS` | `500` | Lange recensies worden ingekort tot dit past |
| `SESSION_COOKIE_SECURE` | `false` | `true` achter HTTPS |
| `SECRET_KEY` | automatisch | Wordt anders in `data/secret_key` bewaard |
| `DATA_DIR` | `/data` (Docker) | Map voor database en sleutel |

In de templates kun je `{title}`, `{author}`, `{stars}`, `{rating}`, `{review}` en
`{isbn}` gebruiken, en `\n` voor een nieuwe regel.

## StoryGraph importeren

1. In StoryGraph: *Manage Account → Manage Your Data → Export StoryGraph Library*.
2. Download de CSV zodra je de mail/melding krijgt.
3. In de app: *Import* → kies het bestand → *Importeren*.

Leesstatus, beoordeling, recensie, tags, moods, formaat, “owned” en alle
leesdatums (ook herlezingen) worden overgenomen. Er wordt niets gepost. Boeken die
al bestaan (zelfde ISBN, of zelfde titel + auteur) worden overgeslagen.

## Ontwikkelen

```sh
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
pytest
DATA_DIR=./data APP_PASSWORD=test flask --app app run --debug
```
