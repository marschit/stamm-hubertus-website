#!/usr/bin/env python3
"""Spiegelt das KleverKey-Schließsystem (Nutzer, Karten, Berechtigungen)
als Übersicht in die Nextcloud des Stammes (Verein/Schließsystem).

Benötigte Umgebungsvariablen:
  KLEVERKEY_API_KEY  – API-Key aus dem KleverKey-Portal (Account → Api Key)
  NC_KALENDER_USER   – Nextcloud-Nutzer (admin)
  NC_KALENDER_PASS   – Nextcloud-App-Passwort
"""

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone

API = "https://api.kleverkey.com"
NC_BASIS = "https://cloud.stamm-hubertus-siegen.de/remote.php/dav/files/admin"
NC_ORDNER = "Verein/Schließsystem"

API_KEY = os.environ.get("KLEVERKEY_API_KEY", "")
NC_USER = os.environ.get("NC_KALENDER_USER", "")
NC_PASS = os.environ.get("NC_KALENDER_PASS", "")
if not (API_KEY and NC_USER and NC_PASS):
    sys.exit("KLEVERKEY_API_KEY / NC_KALENDER_USER / NC_KALENDER_PASS fehlen")

STATUS_TEXT = {"1": "eingeladen", "2": "aktiv", "3": "aktiv"}


def kk_get(pfad: str):
    req = urllib.request.Request(
        API + pfad,
        headers={"Authorization": f"ApiKey {API_KEY}", "Accept": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)


def items(antwort):
    if isinstance(antwort, dict) and isinstance(antwort.get("items"), list):
        return antwort["items"]
    return antwort if isinstance(antwort, list) else []


def datum(iso: str) -> str:
    try:
        return datetime.fromisoformat(iso.replace("Z", "+00:00")).astimezone().strftime("%d.%m.%Y")
    except Exception:
        return ""


def nc_put(name: str, inhalt: str):
    auth = base64.b64encode(f"{NC_USER}:{NC_PASS}".encode()).decode()
    pfad = ""
    for teil in NC_ORDNER.split("/"):
        pfad += "/" + teil
        req = urllib.request.Request(
            NC_BASIS + urllib.parse.quote(pfad),
            method="MKCOL",
            headers={"Authorization": f"Basic {auth}"},
        )
        try:
            urllib.request.urlopen(req, timeout=30)
        except urllib.error.HTTPError as e:
            if e.code != 405:
                raise
    import time

    for versuch in range(4):
        req = urllib.request.Request(
            f"{NC_BASIS}/{urllib.parse.quote(NC_ORDNER)}/{urllib.parse.quote(name)}",
            data=inhalt.encode("utf-8"),
            method="PUT",
            headers={"Authorization": f"Basic {auth}"},
        )
        try:
            urllib.request.urlopen(req, timeout=60)
            break
        except urllib.error.HTTPError as e:
            # 423 = Datei gesperrt – z. B. weil jemand sie gerade im
            # Nextcloud-Editor offen hat. Kurz warten, sonst überspringen
            # (der nächste tägliche Lauf aktualisiert sie dann).
            if e.code == 423:
                if versuch < 3:
                    time.sleep(10)
                    continue
                print(f"  ÜBERSPRUNGEN (gesperrt, vermutlich im Editor geöffnet): {name}")
                return
            raise
    print(f"  hochgeladen: {NC_ORDNER}/{name} ({len(inhalt)} Zeichen)")


ich = kk_get("/api/v1/users/me")
org_ids = ich.get("organizationIds") or []
if not org_ids:
    sys.exit("Der API-Key gehört zu keiner Organisation")

for org_id in org_ids:
    basis = f"/api/v1/organizations/{org_id}"
    schloesser = items(kk_get(f"{basis}/locks"))
    nutzer = items(kk_get(f"{basis}/users"))
    karten = items(kk_get(f"{basis}/devices/smart-cards"))
    rechte = items(kk_get(f"{basis}/permissions"))
    gruppen = items(kk_get(f"{basis}/access-groups"))
    print(
        f"Organisation {org_id}: {len(schloesser)} Schlösser, {len(nutzer)} Personen, "
        f"{len(karten)} Karten, {len(rechte)} Berechtigungen"
    )

    def name_von(u) -> str:
        if not isinstance(u, dict):
            return "?"
        n = f"{u.get('firstName') or ''} {u.get('lastName') or ''}".strip()
        return n or (u.get("displayName") or "?").split("<")[0].strip()

    # Karten → Inhaber über die Geräteliste je Nutzer
    karten_inhaber: dict[str, str] = {}
    for u in nutzer:
        try:
            for geraet in items(kk_get(f"{basis}/users/{u['id']}/devices")):
                karten_inhaber[str(geraet.get("id"))] = name_von(u)
        except Exception as e:
            print(f"  Warnung: Geräte von {name_von(u)} nicht lesbar: {e}")

    stand = datetime.now(timezone.utc).astimezone().strftime("%d.%m.%Y %H:%M Uhr")
    z = [
        "# Schließsystem – Übersicht",
        "",
        f"Automatisch gespiegelt aus KleverKey · Stand: {stand}",
        "",
        "> Änderungen an Rechten und Karten bitte im "
        "[KleverKey-Portal](https://portal.kleverkey.com) vornehmen – "
        "diese Datei wird täglich um 5:37 Uhr überschrieben.",
        "",
        f"## Schlösser ({len(schloesser)})",
        "",
        "| Schloss | Batterie | Zuletzt aktiv |",
        "|---|---|---|",
    ]
    for s in schloesser:
        batterie = "⚠ Wechsel empfohlen" if s.get("batteryChangeRecommended") else "ok"
        z.append(f"| {s.get('name') or s.get('displayName')} | {batterie} | {datum(s.get('dateLastActivity') or '')} |")

    z += ["", f"## Personen ({len(nutzer)})", "", "| Name | E-Mail | Rolle |", "|---|---|---|"]
    for u in sorted(nutzer, key=lambda x: name_von(x).lower()):
        rollen = ", ".join(r.get("displayName") or r.get("name") or "" for r in (u.get("roles") or []))
        z.append(f"| {name_von(u)} | {u.get('email') or ''} | {rollen or 'Mitglied'} |")

    z += [
        "",
        f"## Smartcards / Chips ({len(karten)})",
        "",
        "| Karte | Chip-ID | Inhaber*in | Zuletzt benutzt |",
        "|---|---|---|---|",
    ]
    for k in sorted(karten, key=lambda x: (x.get("name") or "").lower()):
        inhaber = karten_inhaber.get(str(k.get("id")), "– nicht zugewiesen –")
        z.append(
            f"| {k.get('name') or k.get('displayName')} | {k.get('deviceHexId') or ''} "
            f"| {inhaber} | {datum(k.get('dateLastActivity') or '')} |"
        )

    z += [
        "",
        f"## Berechtigungen ({len(rechte)})",
        "",
        "| Person | Schloss | Status | Vergeben am |",
        "|---|---|---|---|",
    ]
    for r in sorted(rechte, key=lambda x: name_von(x.get("user")).lower()):
        status = STATUS_TEXT.get(str(r.get("permissionStatus")), f"Status {r.get('permissionStatus')}")
        schloss = (r.get("lock") or {}).get("name") or (r.get("lock") or {}).get("displayName") or "?"
        z.append(f"| {name_von(r.get('user'))} | {schloss} | {status} | {datum(r.get('dateGranted') or '')} |")

    if gruppen:
        z += ["", f"## Zutrittsgruppen ({len(gruppen)})", ""]
        for g in gruppen:
            z.append(f"- **{g.get('name') or g.get('displayName')}**")

    suffix = "" if len(org_ids) == 1 else f"_{org_id}"
    nc_put(f"Schliesssystem_Uebersicht{suffix}.md", "\n".join(z) + "\n")
    nc_put(
        f"kleverkey_rohdaten{suffix}.json",
        json.dumps(
            {
                "stand": stand,
                "schloesser": schloesser,
                "nutzer": nutzer,
                "karten": karten,
                "kartenInhaber": karten_inhaber,
                "rechte": rechte,
                "gruppen": gruppen,
            },
            ensure_ascii=False,
            indent=1,
        ),
    )

print("Fertig.")
