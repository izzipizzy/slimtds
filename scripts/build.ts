import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, renameSync, rmSync, writeFileSync, readFileSync, readdirSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { spawnSync } from 'node:child_process'

const root = resolve(import.meta.dir, '..')
const outDir = join(root, 'public/assets')
const pixelOut = join(root, 'public/p.js')

if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true })

// Clean previous hashed outputs — keep directory, drop files matching our patterns
for (const f of readdirSync(outDir)) {
  if (/^app\..+\.(js|css)$/.test(f) || /^session-player\..+\.(js|css)$/.test(f) || f === 'manifest.json') {
    rmSync(join(outDir, f))
  }
}

function shortHash(buf: Uint8Array | string): string {
  return createHash('sha256').update(buf).digest('hex').slice(0, 8)
}

// --- Build app.js (Alpine bootstrap) ---
const jsResult = await Bun.build({
  entrypoints: [join(root, 'resources/js/app.js')],
  outdir: outDir,
  minify: true,
  naming: '[dir]/[name].[ext]',
  target: 'browser',
})
if (!jsResult.success) {
  console.error('JS build failed:', jsResult.logs)
  process.exit(1)
}
// Find the produced file and rename with content hash
let appJsName = ''
for (const artifact of jsResult.outputs) {
  const contents = readFileSync(artifact.path)
  const hash = shortHash(contents)
  const dst = join(outDir, `app.${hash}.js`)
  renameSync(artifact.path, dst)
  appJsName = `app.${hash}.js`
}

// --- Build pixel.js → public/p.js (no hash, stable URL) ---
const pxResult = await Bun.build({
  entrypoints: [join(root, 'resources/js/pixel.js')],
  minify: true,
  target: 'browser',
})
if (!pxResult.success) {
  console.error('Pixel build failed:', pxResult.logs)
  process.exit(1)
}
// Write output to public/p.js manually (bun returns blob)
const pxOutput = pxResult.outputs[0]
if (!pxOutput) {
  console.error('Pixel build produced no output')
  process.exit(1)
}
writeFileSync(pixelOut, await pxOutput.text())

// --- Build sessionPlayer.js → public/assets/session-player.[hash].js ---
// IIFE format isolates the bundle's top-level declarations in a function scope.
// It loads as a classic <script> alongside app.js (also a minified bundle) on the
// admin replay page; without IIFE their minified globals (e.g. `k0`) collide with
// "Identifier already declared", which silently aborts sessionPlayer and leaves
// window.slimSessionPlayer undefined so the player never mounts.
const spResult = await Bun.build({
  entrypoints: [join(root, 'resources/js/sessionPlayer.js')],
  outdir: outDir,
  minify: true,
  naming: '[dir]/[name].[ext]',
  target: 'browser',
  format: 'iife',
})
if (!spResult.success) {
  console.error('Session player build failed:', spResult.logs)
  process.exit(1)
}
let sessionPlayerJs = ''
for (const artifact of spResult.outputs) {
  if (!artifact.path.endsWith('.js')) continue
  const contents = readFileSync(artifact.path)
  const hash = shortHash(contents)
  const dst = join(outDir, `session-player.${hash}.js`)
  renameSync(artifact.path, dst)
  sessionPlayerJs = `session-player.${hash}.js`
}

// --- Copy rrweb Replayer CSS → public/assets/session-player.[hash].css ---
// sessionPlayer.js drives rrweb's Replayer directly, so it needs rrweb's own
// stylesheet (the .replayer-wrapper / iframe scaling), not rrweb-player's.
const playerCssSrc = join(root, 'node_modules/rrweb/dist/style.css')
const playerCssBuf = readFileSync(playerCssSrc)
const playerCssHash = shortHash(playerCssBuf)
const sessionPlayerCss = `session-player.${playerCssHash}.css`
writeFileSync(join(outDir, sessionPlayerCss), playerCssBuf)

// --- Build Tailwind CSS via tailwindcss CLI ---
const tmpCss = join(outDir, 'app.tmp.css')
const tailwindResult = spawnSync(
  'bunx',
  ['@tailwindcss/cli', '-i', 'resources/css/app.css', '-o', tmpCss, '--minify'],
  { cwd: root, stdio: 'inherit' },
)
if (tailwindResult.status !== 0) {
  console.error('Tailwind build failed')
  process.exit(1)
}
const cssContents = readFileSync(tmpCss)
const cssHash = shortHash(cssContents)
const appCssName = `app.${cssHash}.css`
renameSync(tmpCss, join(outDir, appCssName))

// --- Write manifest ---
const manifest = {
  'app.css': appCssName,
  'app.js':  appJsName,
  'session-player.js':  sessionPlayerJs,
  'session-player.css': sessionPlayerCss,
}
writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')

console.log('[build] done')
console.log(`  ${appJsName}`)
console.log(`  ${appCssName}`)
console.log(`  ${sessionPlayerJs}`)
console.log(`  ${sessionPlayerCss}`)
console.log(`  public/p.js`)
console.log(`  public/assets/manifest.json`)
