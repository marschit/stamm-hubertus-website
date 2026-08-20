#!/usr/bin/env python3
"""Spiegelt das KleverKey-Schließsystem (Nutzer, Karten, Berechtigungen)
als Übersicht in die Nextcloud des Stammes.

Benötigte Umgebungsvariablen:
  KLEVERKEY_API_KEY  – API-Key aus dem KleverKey-Portal (Account → Api Key)
  NC_KALENDER_USER   – Nextcloud-Nutzer (admin)
  NC_KALENDER_PASS   – Nextcloud-App-Passwort
"""

import json
import os
import sys
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


def kk_get(pfad: str, accept: str = "application/json"):
    req = urllib.request.Request(
        API + pfad,
        headers={"Authorization": f"ApiKey {API_KEY}", "Accept": accept},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        daten = r.read()
    return json.loads(daten) if accept == "application/json" else daten.decode("utf-8")


def liste(antwort):
    """API liefert teils {items: [...]}, teils direkt eine Liste."""
    if isinstance(antwort, dict):
        for k in ("items", "data", "results", "users", "permissions", "devices", "locks"):
            if isinstance(antwort.get(k), list):
                return antwort[k]
        return []
    return antwort if isinstance(antwort, list) else []


def feld(obj, *namen, standard=""):
    for n in namen:
        if isinstance(obj, dict) and obj.get(n) not in (None, ""):
            return obj[n]
    return standard


def nc_put(name: str, inhalt: str):
    import base64

    auth = base64.b64encode(f"{NC_USER}:{NC_PASS}".encode()).decode()
    # Ordner sicherstellen (MKCOL, 405 = existiert schon)
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
            if e.code not in (405,):
                raise
    req = urllib.request.Request(
        f"{NC_BASIS}/{urllib.parse.quote(NC_ORDNER)}/{urllib.parse.quote(name)}",
        data=inhalt.encode("utf-8"),
        method="PUT",
        headers={"Authorization": f"Basic {auth}"},
    )
    urllib.request.urlopen(req, timeout=60)
    print(f"  hochgeladen: {name} ({len(inhalt)} Zeichen)")


import urllib.parse

orgs = liste(kk_get("/api/v1/organizations"))
if not orgs:
    sys.exit("Keine Organisation für diesen API-Key gefunden")

for org in orgs:
    org_id = feld(org, "id", "organizationId")
    org_name = feld(org, "name", standard=f"Organisation {org_id}")
    print(f"Organisation: {org_name} ({org_id})")
    basis = f"/api/v1/organizations/{org_id}"

    schloesser = liste(kk_get(f"{basis}/locks"))
    nutzer = liste(kk_get(f"{basis}/users"))
    karten = liste(kk_get(f"{basis}/devices/smart-cards"))
    rechte = liste(kk_get(f"{basis}/permissions"))
    try:
        gruppen = liste(kk_get(f"{basis}/access-groups"))
    except Exception:
        gruppen = []

    schloss_name = {feld(s, "id", "lockId"): feld(s, "name", standard="?") for s in schloesser}
    nutzer_info = {
        feld(u, "id", "userId"): {
            "name": (feld(u, "firstName") + " " + feld(u, "lastName")).strip()
            or feld(u, "name", "displayName", standard="?"),
            "email": feld(u, "email", "eMail"),
        }
        for u in nutzer
    }

    stand = datetime.now(timezone.utc).astimezone().strftime("%d.%m.%Y %H:%M Uhr")
    z = [
        f"# Schließsystem {org_name} – Übersicht",
        "",
        f"Automatisch gespiegelt aus KleverKey · Stand: {stand}",
        "",
        "> Änderungen an Rechten und Karten bitte im "
        "[KleverKey-Portal](https://portal.kleverkey.com) vornehmen – "
        "diese Datei wird täglich überschrieben.",
        "",
        f"## Schlösser ({len(schloesser)})",
        "",
        "| Schloss | ID |",
        "|---|---|",
    ]
    for s in schloesser:
        z.append(f"| {feld(s, 'name', standard='?')} | {feld(s, 'id', 'lockId')} |")

    z += ["", f"## Personen ({len(nutzer)})", "", "| Name | E-Mail |", "|---|---|"]
    for u in sorted(nutzer_info.values(), key=lambda x: x["name"].lower()):
        z.append(f"| {u['name']} | {u['email']} |")

    z += ["", f"## Smartcards / Chips ({len(karten)})", "", "| Karte | UID | Zugewiesen an |", "|---|---|---|"]
    for k in karten:
        inhaber_id = feld(k, "userId", "assignedUserId", "ownerId")
        inhaber = nutzer_info.get(inhaber_id, {}).get("name", "") or feld(
            k, "userName", "assignedTo", standard="– nicht zugewiesen –"
        )
        z.append(
            f"| {feld(k, 'name', 'displayName', standard='?')} "
            f"| {feld(k, 'uid', 'serialNumber', 'hexId')} | {inhaber} |"
        )

    z += ["", f"## Berechtigungen ({len(rechte)})", "", "| Person | Schloss | Details |", "|---|---|---|"]

    def sortschluessel(r):
        uid = feld(r, "userId")
        return (nutzer_info.get(uid, {}).get("name", "").lower(), feld(r, "lockId"))

    for r in sorted(rechte, key=sortschluessel):
        uid, lid = feld(r, "userId"), feld(r, "lockId")
        person = nutzer_info.get(uid, {}).get("name") or feld(r, "userName", standard=uid)
        schloss = schloss_name.get(lid) or feld(r, "lockName", standard=lid)
        details = []
        for k2, v in (r.items() if isinstance(r, dict) else []):
            if k2 in ("userId", "lockId", "userName", "lockName") or v in (None, "", False):
                continue
            details.append(f"{k2}: {v}")
        z.append(f"| {person} | {schloss} | {'; '.join(details[:4])} |")

    if gruppen:
        z += ["", f"## Zutrittsgruppen ({len(gruppen)})", ""]
        for g in gruppen:
            z.append(f"- **{feld(g, 'name', standard='?')}**")

    suffix = "" if len(orgs) == 1 else f"_{org_id}"
    nc_put(f"Schliesssystem_Uebersicht{suffix}.md", "\n".join(z) + "\n")
    nc_put(
        f"kleverkey_rohdaten{suffix}.json",
        json.dumps(
            {"stand": stand, "schloesser": schloesser, "nutzer": nutzer, "karten": karten, "rechte": rechte, "gruppen": gruppen},
            ensure_ascii=False,
            indent=1,
        ),
    )

print("Fertig.")
