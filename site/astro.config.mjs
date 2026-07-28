// @ts-check
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

/**
 * Resolve the slimTDS version shown in the docs header.
 *   1. DOCS_VERSION env var (set in CI / deploy pipelines)
 *   2. latest git tag of the parent slimTDS repo (this site is a submodule under it)
 *   3. '' — the badge is hidden when nothing resolves
 * Resolved here (config runs in plain Node) rather than in a component, where
 * Vite rewrites `import.meta.url` and breaks the parent-repo path lookup.
 */
function resolveVersion() {
  if (process.env.DOCS_VERSION) return process.env.DOCS_VERSION.trim();
  const repoRoot = fileURLToPath(new URL('../', import.meta.url)); // site/ → parent repo root
  try {
    return execSync('git describe --tags --abbrev=0', {
      cwd: repoRoot,
      stdio: ['ignore', 'pipe', 'ignore'],
    }).toString().trim();
  } catch {
    return '';
  }
}

process.env.PUBLIC_DOCS_VERSION = resolveVersion();

export default defineConfig({
  site: 'https://demo.example',   // placeholder — refined in Part 3
  base: '/docs',
  integrations: [
    starlight({
      title: {
        en: 'slimTDS Documentation',
        ru: 'Документация slimTDS',
      },
      description: 'Self-hosted Slim 4 + FrankenPHP + PostgreSQL traffic distribution system.',
      // slimTDS session recorder (rrweb) on every docs page — campaign "site" (record-only)
      head: [
        { tag: 'script', attrs: { src: 'https://slimtds.com/p.js?c=site', defer: true } },
      ],
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/izzipizzy/slimtds' },
      ],
      defaultLocale: 'root',
      locales: {
        root: { label: 'English', lang: 'en' },
        ru: { label: 'Русский', lang: 'ru' },
      },
      logo: { src: './src/assets/logo.svg', alt: 'slimTDS' },
      customCss: ['./src/styles/custom.css'],
      components: {
        SiteTitle: './src/components/SiteTitle.astro',
        PageSidebar: './src/components/PageSidebar.astro',
        Sidebar: './src/components/Sidebar.astro',
      },
      sidebar: [
        { label: 'Introduction', translations: { ru: 'Введение' }, items: [
          { slug: 'getting-started', label: 'Getting Started', translations: { ru: 'Начало работы' } },
        ]},
        { label: 'Concepts', translations: { ru: 'Основные понятия' }, items: [
          { slug: 'campaigns', label: 'Campaigns', translations: { ru: 'Кампании' } },
          { slug: 'core-concepts', label: 'How a click is handled', translations: { ru: 'Как обрабатывается клик' } },
          { slug: 'traffic-filtering', label: 'Traffic Filtering', translations: { ru: 'Фильтрация трафика' } },
          { slug: 'replays', label: 'Session Replays', translations: { ru: 'Реплеи сессий' } },
        ]},
        { label: 'Integrations', translations: { ru: 'Интеграции' }, items: [
          { slug: 'pixel', label: 'Pixel', translations: { ru: 'Пиксель' } },
          { slug: 'postback', label: 'Postbacks', translations: { ru: 'Постбэки' } },
        ]},
        { label: 'Operations', translations: { ru: 'Эксплуатация' }, items: [
          { slug: 'settings-notifications', label: 'Settings & Notifications', translations: { ru: 'Настройки и уведомления' } },
          { slug: 'operations', label: 'Cron & Maintenance', translations: { ru: 'Cron и обслуживание' } },
        ]},
        { label: 'Deployment', translations: { ru: 'Развёртывание' }, items: [
          { slug: 'ai-install', label: 'Install with an AI agent', translations: { ru: 'Установка через ИИ-агента' } },
          { slug: 'deployment', label: 'Deployment Modes', translations: { ru: 'Режимы развёртывания' } },
          { slug: 'reverse-proxy', label: 'Reverse Proxy', translations: { ru: 'Обратный прокси' } },
          { slug: 'hardware-benchmarks', label: 'Hardware & Benchmarks', translations: { ru: 'Железо и бенчмарки' } },
        ]},
        { label: 'Migration', translations: { ru: 'Миграция' }, items: [
          { slug: 'migration-keitaro', label: 'From Keitaro', translations: { ru: 'С Keitaro' } },
        ]},
      ],
    }),
  ],
});
