import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import { KATEGORIEN, alsIso, heimabendSamstage, zeitenAus, type Kategorie } from '../lib/termine';
import { ladeCloudTermine } from '../lib/nextcloud-termine';

export async function getStaticPaths() {
  return [
    { params: { feed: 'termine' } },
    ...Object.keys(KATEGORIEN).map((k) => ({ params: { feed: `termine-${k}` } })),
  ];
}

function escapeText(s: string): string {
  return s
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\r?\n/g, '\\n');
}

// RFC 5545: Zeilen über 75 Oktette werden mit CRLF + Leerzeichen umgebrochen
function fold(zeile: string): string {
  const teile: string[] = [];
  let rest = zeile;
  while (rest.length > 73) {
    teile.push(rest.slice(0, 73));
    rest = ' ' + rest.slice(73);
  }
  teile.push(rest);
  return teile.join('\r\n');
}

function kompakt(iso: string): string {
  return iso.replaceAll('-', '');
}

function tagDanach(d: Date): string {
  const t = new Date(d);
  t.setUTCDate(t.getUTCDate() + 1);
  return kompakt(alsIso(t));
}

const VTIMEZONE = [
  'BEGIN:VTIMEZONE',
  'TZID:Europe/Berlin',
  'BEGIN:DAYLIGHT',
  'TZOFFSETFROM:+0100',
  'TZOFFSETTO:+0200',
  'TZNAME:CEST',
  'DTSTART:19700329T020000',
  'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
  'END:DAYLIGHT',
  'BEGIN:STANDARD',
  'TZOFFSETFROM:+0200',
  'TZOFFSETTO:+0100',
  'TZNAME:CET',
  'DTSTART:19701025T030000',
  'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
  'END:STANDARD',
  'END:VTIMEZONE',
];

export const GET: APIRoute = async ({ params }) => {
  const kategorie = params.feed === 'termine'
    ? null
    : (params.feed!.replace('termine-', '') as Kategorie);

  const termine = (await getCollection('termine'))
    .filter((t) => !kategorie || t.data.kategorie === kategorie)
    .sort((a, b) => a.data.datum.valueOf() - b.data.datum.valueOf());

  const dtstamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+/, '');
  const name = kategorie
    ? `Stamm Hubertus Siegen – ${KATEGORIEN[kategorie].label}`
    : 'Stamm Hubertus Siegen';

  const zeilen: string[] = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Stamm Hubertus Siegen e.V.//Website//DE',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    `X-WR-CALNAME:${escapeText(name)}`,
    'X-WR-TIMEZONE:Europe/Berlin',
    ...VTIMEZONE,
  ];

  for (const t of termine) {
    const start = kompakt(alsIso(t.data.datum));
    let zeiten = zeitenAus(t.data.zeit);
    // Mehrtägig mit nur einer Uhrzeit: als ganztägiges Mehrtages-Event führen,
    // sonst würde im Abo nur der erste Tag ab Startzeit erscheinen
    if (t.data.endDatum && zeiten.length < 2) zeiten = [];

    zeilen.push('BEGIN:VEVENT');
    zeilen.push(`UID:${t.id}@stamm-hubertus-siegen.de`);
    zeilen.push(`DTSTAMP:${dtstamp}`);
    zeilen.push(`SUMMARY:${escapeText(t.data.titel)}`);

    if (zeiten.length > 0) {
      zeilen.push(`DTSTART;TZID=Europe/Berlin:${start}T${zeiten[0].replace(':', '')}00`);
      if (zeiten.length > 1) {
        const endTag = t.data.endDatum ? kompakt(alsIso(t.data.endDatum)) : start;
        zeilen.push(`DTEND;TZID=Europe/Berlin:${endTag}T${zeiten[1].replace(':', '')}00`);
      }
    } else {
      zeilen.push(`DTSTART;VALUE=DATE:${start}`);
      zeilen.push(`DTEND;VALUE=DATE:${tagDanach(t.data.endDatum ?? t.data.datum)}`);
    }

    if (t.data.ort) zeilen.push(`LOCATION:${escapeText(t.data.ort)}`);
    zeilen.push(`CATEGORIES:${escapeText(KATEGORIEN[t.data.kategorie].label)}`);
    if (t.body?.trim()) zeilen.push(`DESCRIPTION:${escapeText(t.body.trim())}`);
    zeilen.push('END:VEVENT');
  }

  // Termine aus dem Nextcloud-Kalender des Teams; die Landesverbands-Termine
  // stecken nur im eigenen Feed termine-lvnrw.ics, nicht im Gesamt-Abo
  const cloudTermine = (await ladeCloudTermine()).filter((t) =>
    kategorie ? t.kategorie === kategorie : t.kategorie !== 'lvnrw'
  );
  for (const t of cloudTermine) {
    const start = kompakt(alsIso(t.datum));
    let zeiten = zeitenAus(t.zeit);
    if (t.endDatum && zeiten.length < 2) zeiten = [];

    zeilen.push('BEGIN:VEVENT');
    zeilen.push(`UID:cloud-${t.id}@stamm-hubertus-siegen.de`);
    zeilen.push(`DTSTAMP:${dtstamp}`);
    zeilen.push(`SUMMARY:${escapeText(t.titel)}`);
    if (zeiten.length > 0) {
      zeilen.push(`DTSTART;TZID=Europe/Berlin:${start}T${zeiten[0].replace(':', '')}00`);
      if (zeiten.length > 1) {
        zeilen.push(`DTEND;TZID=Europe/Berlin:${start}T${zeiten[1].replace(':', '')}00`);
      }
    } else {
      zeilen.push(`DTSTART;VALUE=DATE:${start}`);
      zeilen.push(`DTEND;VALUE=DATE:${tagDanach(t.endDatum ?? t.datum)}`);
    }
    if (t.ort) zeilen.push(`LOCATION:${escapeText(t.ort)}`);
    zeilen.push(`CATEGORIES:${escapeText(KATEGORIEN[t.kategorie].label)}`);
    if (t.beschreibung) zeilen.push(`DESCRIPTION:${escapeText(t.beschreibung)}`);
    zeilen.push('END:VEVENT');
  }

  // Heimabend: jeden Samstag 15–17 Uhr außerhalb der NRW-Schulferien
  if (!kategorie || kategorie === 'stamm') {
    for (const samstag of heimabendSamstage(alsIso(new Date()))) {
      const tag = kompakt(samstag);
      zeilen.push('BEGIN:VEVENT');
      zeilen.push(`UID:heimabend-${tag}@stamm-hubertus-siegen.de`);
      zeilen.push(`DTSTAMP:${dtstamp}`);
      zeilen.push('SUMMARY:Heimabend');
      zeilen.push(`DTSTART;TZID=Europe/Berlin:${tag}T150000`);
      zeilen.push(`DTEND;TZID=Europe/Berlin:${tag}T170000`);
      zeilen.push(`LOCATION:${escapeText('Stammesheim, Seilereiweg 8')}`);
      zeilen.push(`CATEGORIES:${escapeText(KATEGORIEN.stamm.label)}`);
      zeilen.push(`DESCRIPTION:${escapeText('Gruppenstunde für alle Stufen – in den Schulferien fällt der Heimabend aus.')}`);
      zeilen.push('END:VEVENT');
    }
  }

  zeilen.push('END:VCALENDAR');

  return new Response(zeilen.map(fold).join('\r\n') + '\r\n', {
    headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
  });
};
