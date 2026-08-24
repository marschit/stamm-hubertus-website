import { alsIso, type Kategorie } from './termine';

/**
 * Liest Termine aus dem Nextcloud-Kalender „stammestermine“ (CalDAV-Export).
 * Zugangsdaten kommen beim Build aus NC_KALENDER_URL/USER/PASS – fehlen sie
 * oder ist die Cloud nicht erreichbar, baut die Seite ohne Cloud-Termine.
 */

export interface CloudTermin {
  id: string;
  titel: string;
  datum: Date; // UTC-Mitternacht des Starttags
  endDatum?: Date;
  zeit?: string; // z. B. "15:00 – 17:00"
  ort?: string;
  kategorie: Kategorie;
  beschreibung?: string;
}

function kategorieAusText(text: string | undefined): Kategorie {
  const t = (text ?? '').toLowerCase();
  if (t.includes('meute') || t.includes('wölfling') || t.includes('woelfling')) return 'meute';
  if (t.includes('sippe')) return 'sippen';
  if (t.includes('gilde') || t.includes('r/r') || t.includes('ranger')) return 'gilde';
  if (t.includes('förder') || t.includes('foerder')) return 'foerderverein';
  if (t.includes('vermiet') || t.includes('anhänger') || t.includes('anhaenger')) return 'vermietung';
  return 'stamm';
}

/** RFC-5545-Zeilenfaltung rückgängig machen */
function entfalte(ics: string): string[] {
  return ics
    .replace(/\r\n/g, '\n')
    .replace(/\n[ \t]/g, '')
    .split('\n');
}

function enteschape(s: string): string {
  return s.replace(/\\n/gi, '\n').replace(/\\([,;\\])/g, '$1');
}

const berlinDatum = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Berlin' });
const berlinUhr = new Intl.DateTimeFormat('de-DE', {
  timeZone: 'Europe/Berlin',
  hour: '2-digit',
  minute: '2-digit',
});

/** DTSTART/DTEND-Wert → { tagIso, uhr? } */
function parseZeitpunkt(params: string, wert: string): { tagIso: string; uhr?: string } | null {
  if (params.includes('VALUE=DATE') || /^\d{8}$/.test(wert)) {
    return { tagIso: `${wert.slice(0, 4)}-${wert.slice(4, 6)}-${wert.slice(6, 8)}` };
  }
  const m = wert.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})\d{2}(Z?)$/);
  if (!m) return null;
  if (m[6] === 'Z') {
    const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
    return { tagIso: berlinDatum.format(d), uhr: berlinUhr.format(d) };
  }
  // TZID (praktisch immer Europe/Berlin) oder floating: Wert wörtlich nehmen
  return { tagIso: `${m[1]}-${m[2]}-${m[3]}`, uhr: `${m[4]}:${m[5]}` };
}

function utcMitternacht(iso: string): Date {
  const [j, m, t] = iso.split('-').map(Number);
  return new Date(Date.UTC(j, m - 1, t));
}

export function parseKalender(ics: string): CloudTermin[] {
  const termine: CloudTermin[] = [];
  let felder: Record<string, { params: string; wert: string }> | null = null;

  for (const zeile of entfalte(ics)) {
    if (zeile === 'BEGIN:VEVENT') {
      felder = {};
      continue;
    }
    if (zeile === 'END:VEVENT' && felder) {
      const ereignis = felder;
      felder = null;
      if (ereignis['RRULE']) continue; // Serientermine kann die Website (noch) nicht
      const start = ereignis['DTSTART'] && parseZeitpunkt(ereignis['DTSTART'].params, ereignis['DTSTART'].wert);
      if (!start || !ereignis['SUMMARY']) continue;
      const ende = ereignis['DTEND'] && parseZeitpunkt(ereignis['DTEND'].params, ereignis['DTEND'].wert);

      let endDatum: Date | undefined;
      if (ende) {
        let endIso = ende.tagIso;
        if (!ende.uhr) {
          // Ganztägig: DTEND ist exklusiv
          const d = utcMitternacht(endIso);
          d.setUTCDate(d.getUTCDate() - 1);
          endIso = alsIso(d);
        }
        if (endIso > start.tagIso) endDatum = utcMitternacht(endIso);
      }

      let zeit: string | undefined;
      if (start.uhr) {
        zeit = ende?.uhr && ende.uhr !== start.uhr && !endDatum ? `${start.uhr} – ${ende.uhr}` : start.uhr;
      }

      termine.push({
        id: (ereignis['UID']?.wert ?? start.tagIso + ereignis['SUMMARY'].wert).replace(/[^a-zA-Z0-9]/g, '').slice(0, 40),
        titel: enteschape(ereignis['SUMMARY'].wert),
        datum: utcMitternacht(start.tagIso),
        endDatum,
        zeit,
        ort: ereignis['LOCATION'] ? enteschape(ereignis['LOCATION'].wert) : undefined,
        kategorie: kategorieAusText(ereignis['CATEGORIES']?.wert),
        beschreibung: ereignis['DESCRIPTION'] ? enteschape(ereignis['DESCRIPTION'].wert).trim() : undefined,
      });
      continue;
    }
    if (felder) {
      const m = zeile.match(/^([A-Z-]+)((?:;[^:]*)?):(.*)$/);
      if (m) felder[m[1]] = { params: m[2], wert: m[3] };
    }
  }
  return termine;
}

/** Ein Kalender je Website-Kategorie: termine-stamm, termine-meute, … */
const KALENDER: Kategorie[] = [
  'stamm',
  'meute',
  'sippen',
  'gilde', // heißt in der Anzeige „Kreis“
  'foerderverein',
  'vermietung',
];

/** Öffentlicher Termin-Kalender des BdP Landesverbands NRW (Kategorie lvnrw) */
const LVNRW_EXPORT =
  'https://cloud.bdpnrw.de/remote.php/dav/public-calendars/Y33JPQXCY5KiATCJ?export';

let zwischenspeicher: CloudTermin[] | null = null;

export async function ladeCloudTermine(): Promise<CloudTermin[]> {
  if (zwischenspeicher) return zwischenspeicher;
  const basis = import.meta.env.NC_KALENDER_URL ?? process.env.NC_KALENDER_URL;
  const user = import.meta.env.NC_KALENDER_USER ?? process.env.NC_KALENDER_USER;
  const pass = import.meta.env.NC_KALENDER_PASS ?? process.env.NC_KALENDER_PASS;
  if (!basis || !user || !pass) {
    console.warn('[termine] NC_KALENDER_* nicht gesetzt – baue ohne Cloud-Termine.');
    return [];
  }
  const auth = { Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') };
  const alle: CloudTermin[] = [];

  const ladeLvnrw = (async () => {
    try {
      const antwort = await fetch(LVNRW_EXPORT, { signal: AbortSignal.timeout(20000) });
      if (!antwort.ok) throw new Error(`HTTP ${antwort.status}`);
      for (const t of parseKalender(await antwort.text())) {
        // Ferien-/Feiertagsblöcke des LV ausblenden – Ferien zeigt die
        // Website bereits über die eigene Heimabend-Logik
        if (/ferien|feiertag/i.test(t.titel)) continue;
        alle.push({ ...t, kategorie: 'lvnrw' });
      }
    } catch (fehler) {
      console.warn('[termine] LV-NRW-Kalender nicht ladbar:', fehler);
    }
  })();

  await Promise.all([
    ladeLvnrw,
    ...KALENDER.map(async (kategorie) => {
      try {
        const antwort = await fetch(`${basis}termine-${kategorie}/?export`, {
          headers: auth,
          signal: AbortSignal.timeout(20000),
        });
        if (antwort.status === 404) return; // Kalender gelöscht? Still überspringen
        if (!antwort.ok) throw new Error(`HTTP ${antwort.status}`);
        for (const t of parseKalender(await antwort.text())) {
          alle.push({ ...t, kategorie });
        }
      } catch (fehler) {
        console.warn(`[termine] Kalender termine-${kategorie} nicht ladbar:`, fehler);
      }
    }),
  ]);
  console.log(`[termine] ${alle.length} Termine aus den Cloud-Kalendern geladen.`);
  zwischenspeicher = alle;
  return alle;
}
