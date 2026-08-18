import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://stamm-hubertus-siegen.de',
  trailingSlash: 'ignore',
  integrations: [sitemap()],
});
