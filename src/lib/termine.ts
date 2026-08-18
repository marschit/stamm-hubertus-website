export const KATEGORIEN = {
  stamm: { label: 'Ganzer Stamm', farbe: '#0e4278', hell: false },
  meute: { label: 'Meute', farbe: '#ffd208', hell: true },
  sippen: { label: 'Sippen', farbe: '#2f6b3c', hell: false },
  gilde: { label: 'Gilde', farbe: '#971b2f', hell: false },
  foerderverein: { label: 'Förderverein', farbe: '#5b6b7a', hell: false },
} as const;

export type Kategorie = keyof typeof KATEGORIEN;

/** Date (UTC-Mitternacht aus dem Frontmatter) → "2026-09-05" */
export function alsIso(d: Date): string {
  return d.toISOString().slice(0, 10);
}

const datumFormat = new Intl.DateTimeFormat('de-DE', {
  weekday: 'short',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC',
});
const monatFormat = new Intl.DateTimeFormat('de-DE', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
});

export function formatDatum(d: Date): string {
  return datumFormat.format(d);
}

export function formatMonat(d: Date): string {
  return monatFormat.format(d);
}

export function monatsSchluessel(d: Date): string {
  return alsIso(d).slice(0, 7);
}

import ferienDaten from '../data/ferien.json';

/**
 * Alle Heimabend-Samstage ab `abIso` (einschließlich), außerhalb der
 * NRW-Schulferien aus src/data/ferien.json. Horizont: Ende der gepflegten
 * Ferien, mindestens aber 6 Monate.
 */
export function heimabendSamstage(abIso: string): string[] {
  const ferien = ferienDaten.ferien;
  const [j, m, t] = abIso.split('-').map(Number);
  const start = new Date(Date.UTC(j, m - 1, t));

  const minEnde = new Date(start);
  minEnde.setUTCMonth(minEnde.getUTCMonth() + 6);
  const endeIso = ferien.reduce(
    (max, f) => (f.bis > max ? f.bis : max),
    alsIso(minEnde)
  );

  const tage: string[] = [];
  const tag = new Date(start);
  tag.setUTCDate(tag.getUTCDate() + ((6 - tag.getUTCDay() + 7) % 7));
  while (alsIso(tag) <= endeIso) {
    const iso = alsIso(tag);
    if (!ferien.some((f) => f.von <= iso && iso <= f.bis)) tage.push(iso);
    tag.setUTCDate(tag.getUTCDate() + 7);
  }
  return tage;
}

/** "15:00 – 17:00" → ["15:00", "17:00"]; unlesbares → [] */
export function zeitenAus(zeit: string | undefined): string[] {
  if (!zeit) return [];
  return [...zeit.matchAll(/(\d{1,2}):(\d{2})/g)].map(
    (m) => `${m[1].padStart(2, '0')}:${m[2]}`
  );
}
