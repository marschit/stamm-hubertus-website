# Stamm Hubertus Siegen – Website

Statische Vereinswebsite für den [Stamm Hubertus Siegen e. V.](https://stamm-hubertus-siegen.de)
(BdP), gebaut mit [Astro](https://astro.build). Ersetzt die bisherige
WordPress-Seite.

## Inhalte pflegen (für die Redaktion)

Die Inhalte werden über **[Pages CMS](https://pagescms.org)** im Browser
bearbeitet – ohne technisches Wissen:

1. Auf <https://app.pagescms.org> mit dem GitHub-Account anmelden
2. Dieses Repository auswählen
3. Unter *Aktuelles*, *Gruppen*, *Seiten* oder *Einstellungen* Inhalte
   bearbeiten und speichern
4. Nach ca. 1–2 Minuten ist die Änderung live

Jede Änderung wird als Git-Commit gespeichert und ist jederzeit rückholbar.

## Struktur

| Pfad | Inhalt |
|---|---|
| `src/content/aktuelles/` | News-Beiträge (Markdown) |
| `src/content/termine/` | Termine mit Kategorie; daraus entstehen Terminseite + iCal-Feeds |
| `src/content/gruppen/` | Gruppen: Meute, Sippen, Gilde (Markdown) |
| `src/content/seiten/` | Feste Seiten: Über uns, Kontakt, Förderverein … |
| `src/data/einstellungen.json` | Adresse, Treffzeit, Kontakt, Kalender-Links |
| `public/bdp/` | BdP-CD-Assets: Logos (Klilie/WBM), Icons |
| `public/fonts/` | Hausschrift „einfachBdP“ (SIL Open Font License) |
| `public/bilder/` | Hochgeladene Bilder (Upload-Ziel von Pages CMS) |
| `.pages.yml` | Konfiguration der Pages-CMS-Redaktionsoberfläche |

## Entwicklung

```sh
npm install
npm run dev      # Entwicklungsserver auf http://localhost:4321
npm run build    # Statischer Build nach dist/
```

## Deployment

Bei jedem Push auf `main` baut GitHub Actions die Seite und veröffentlicht
sie auf GitHub Pages (`.github/workflows/deploy.yml`). Für die Domain
`stamm-hubertus-siegen.de` muss in den Repo-Einstellungen unter *Pages* die
Custom Domain eingetragen und der DNS-Eintrag der Domain umgestellt werden.

## Corporate Design

Farben, Schrift (einfachBdP), Logos und Icons folgen dem
[BdP Corporate Design](https://meinbdp.de/spaces/BUND/pages/432144490).

## Termine & Kalender-Abo

Termine werden wie alle Inhalte über Pages CMS gepflegt (Sammlung *Termine*)
und in Kategorien einsortiert (Ganzer Stamm, Meute, Sippen, Gilde,
Förderverein). Beim Build entstehen daraus:

- die Terminseite `/termine` mit Kategorienfilter
- der abonnierbare Kalender `/termine.ics` (alle Termine)
- je Kategorie ein eigener Feed, z. B. `/termine-meute.ics`

Ein wöchentlicher GitHub-Actions-Lauf (montags 4 Uhr) baut die Seite neu,
damit vergangene Termine auch ohne Inhaltsänderung verschwinden.
