// Captures admin screenshots into src/assets/screenshots/.
// Prereqs: a seeded dev stack at https://slimtds.local, and `bunx playwright install chromium` (first run).
// Run: bun run screenshots   (ADMIN_PASSWORD=... to override)
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const BASE = process.env.ADMIN_URL ?? 'https://slimtds.local';
const LOGIN = process.env.ADMIN_LOGIN ?? 'admin';
const PASS = process.env.ADMIN_PASSWORD ?? 'demo1234';
const OUT = 'src/assets/screenshots';

const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  ignoreHTTPSErrors: true,
});
const page = await ctx.newPage();
await mkdir(OUT, { recursive: true });

// Login page
await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
await page.screenshot({ path: `${OUT}/login.png` });

// Log in
await page.fill('input[name="login"]', LOGIN);
await page.fill('input[name="password"]', PASS);
await page.click('button[type="submit"]');
await page.waitForURL('**/admin**');
if (page.url().includes('/login')) {
  throw new Error(`Login failed — still on ${page.url()}. Check ADMIN_PASSWORD.`);
}

// Main admin screens
for (const s of [
  { name: 'statistics', path: '/admin/statistics' },
  { name: 'campaigns', path: '/admin/campaigns' },
  { name: 'clicks', path: '/admin/clicks' },
  { name: 'conversions', path: '/admin/conversions' },
  { name: 'pixel', path: '/admin/pixel' },
]) {
  await page.goto(`${BASE}${s.path}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${OUT}/${s.name}.png`, fullPage: true });
}

// Campaign workspace — capture a campaign that HAS flows (so the diagram is meaningful).
// Prefer the "casino" row; fall back to the first campaign edit link.
await page.goto(`${BASE}/admin/campaigns`, { waitUntil: 'networkidle' });
const editSel = 'a[href*="/admin/campaigns/"][href*="/edit"]';
const casinoRow = page.locator('tr:has-text("casino")').first();
let href: string | null = null;
if (await casinoRow.count()) {
  href = await casinoRow.locator(editSel).first().getAttribute('href');
}
if (!href) href = await page.getAttribute(editSel, 'href');

if (href) {
  // Settings/overview tab
  await page.goto(`${BASE}${href}`, { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${OUT}/campaign-workspace.png`, fullPage: true });

  // Diagram tab — campaign → flows → offers/trash. Click the tab (a bare hash change
  // would not re-init Alpine); the click handler triggers renderCampaignDiagram().
  // Tab order: settings, flows, pixel, postback, diagram, danger → diagram = index 4 (locale-proof).
  await page.locator('button.tab').nth(4).click();
  await page.waitForTimeout(700); // let the diagram render
  await page.screenshot({ path: `${OUT}/campaign-diagram.png`, fullPage: true });
} else {
  console.warn('Could not find a campaign edit link — skipping campaign screenshots.');
}

await browser.close();
console.log('Done. Screenshots written to', OUT);
