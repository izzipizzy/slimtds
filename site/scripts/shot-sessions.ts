// Captures the Sessions list + a replay into src/assets/screenshots/.
// Run: ADMIN_PASSWORD=... bun scripts/shot-sessions.ts
import { chromium } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const BASE = process.env.ADMIN_URL ?? 'https://slimtds.local';
const LOGIN = process.env.ADMIN_LOGIN ?? 'admin';
const PASS = process.env.ADMIN_PASSWORD ?? 'demo1234';
const OUT = 'src/assets/screenshots';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
const page = await ctx.newPage();
await mkdir(OUT, { recursive: true });

await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
await page.fill('input[name="login"]', LOGIN);
await page.fill('input[name="password"]', PASS);
await page.click('button[type="submit"]');
await page.waitForURL('**/admin**');
if (page.url().includes('/login')) throw new Error(`Login failed at ${page.url()}`);

// Switch UI to English (sets the ui_lang cookie) to match the English-primary docs.
await page.goto(`${BASE}/admin/lang/en`, { waitUntil: 'networkidle' });

// Sessions list
await page.goto(`${BASE}/admin/sessions`, { waitUntil: 'networkidle' });
await page.waitForTimeout(400);
await page.screenshot({ path: `${OUT}/sessions.png`, fullPage: true });
console.log('wrote sessions.png');

// One replay — open the first session's replay link and let the rrweb player render.
const replay = page.locator('table a[href*="/admin/sessions/"]').first();
if (await replay.count()) {
  await replay.click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2500); // let the rrweb-player bundle mount + paint the first frame
  await page.screenshot({ path: `${OUT}/session-replay.png` });
  console.log('wrote session-replay.png');
} else {
  console.warn('no replay link found — skipped session-replay.png');
}

await browser.close();
console.log('done');
