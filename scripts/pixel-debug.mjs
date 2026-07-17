import { chromium } from 'playwright'

const lander = process.argv[2] || 'a'
const baseUrl = `https://lander-${lander}.local`

const browser = await chromium.launch({ headless: true })
const ctx = await browser.newContext({ ignoreHTTPSErrors: true })
const page = await ctx.newPage()

let posted = 0
page.on('response', async r => {
  if (r.url().includes('/p/event')) {
    posted++
    console.log(`[event ${posted}] ${r.status()} ${r.url()}  ←  page=${page.url()}`)
  }
})
page.on('requestfailed', r => console.log('[failed]', r.url(), '—', r.failure()?.errorText))
page.on('pageerror', e => console.log('[js error]', e.message))

// 1) Home — direct visit, document.referrer = ''
await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' })
await page.waitForTimeout(2500)

// 2) Click button — fires custom event
await page.locator('button').first().click()
await page.waitForTimeout(1500)

// 3) Click About — internal navigation, document.referrer = baseUrl + '/'
await page.locator('a[href="/about"]').click()
await page.waitForTimeout(2500)

// 4) Click Pricing — internal navigation, document.referrer = baseUrl + '/about'
await page.locator('a[href="/pricing"]').click()
await page.waitForTimeout(2500)

console.log(`lander-${lander}: posted ${posted} events`)
await browser.close()
