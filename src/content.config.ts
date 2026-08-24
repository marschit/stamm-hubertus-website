import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const gruppen = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/gruppen' }),
  schema: z.object({
    name: z.string(),
    stufe: z.string(),
    alter: z.string(),
    farbe: z.enum(['gelb', 'blau', 'rot', 'gruen']).default('blau'),
    icon: z.string().default('/bdp/icon-kohte.png'),
    abzeichen: z.string().optional(),
    abzeichenText: z.string().optional(),
    reihenfolge: z.number().default(99),
    treffzeit: z.string().optional(),
  }),
});

const aktuelles = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/aktuelles' }),
  schema: z.object({
    titel: z.string(),
    datum: z.coerce.date(),
    bild: z.string().optional(),
  }),
});

const termine = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/termine' }),
  schema: z.object({
    titel: z.string(),
    datum: z.coerce.date(),
    endDatum: z.coerce.date().optional(),
    zeit: z.coerce.string().optional(),
    ort: z.string().optional(),
    kategorie: z
      .enum([
        'stamm',
        'meute',
        'sippen',
        'gilde',
        'foerderverein',
        'vermietung',
      ])
      .default('stamm'),
  }),
});

const galerie = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/galerie' }),
  schema: z.object({
    titel: z.string(),
    datum: z.coerce.date(),
    bilder: z
      .array(
        z.object({
          bild: z.string(),
          text: z.string().optional(),
        })
      )
      .default([]),
  }),
});

const seiten = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/seiten' }),
  schema: z.object({
    titel: z.string(),
    untertitel: z.string().optional(),
  }),
});

export const collections = { gruppen, aktuelles, termine, galerie, seiten };
