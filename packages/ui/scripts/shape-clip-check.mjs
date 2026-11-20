#!/usr/bin/env node
// Shape clip check (WHIT-1225). Renders a set of tricky components from the
// built Storybook under every theme shape preset, in left-to-right light and
// right-to-left dark, at desktop and phone width, and fails when a rounded
// corner cuts text or a painted child pokes past a curve
// (scripts/clip-probe.js). The presets come from shape-presets.json, which
// a PHP test keeps equal to what ThemeCompiler emits.
//
//   npm run build-storybook -w @whity/ui
//   node packages/ui/scripts/shape-clip-check.mjs [--static packages/ui/storybook-static] [--out dir] [--shots] [--preset round]
//
// --url http://localhost:6006 checks a running dev Storybook instead.
// --shots saves a screenshot of every page, not only failing ones.
import { createServer } from "node:http"
import { readFileSync, existsSync, mkdirSync, writeFileSync, statSync } from "node:fs"
import { dirname, extname, join, resolve } from "node:path"
import { fileURLToPath } from "node:url"
import { createRequire } from "node:module"

const here = dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const { chromium } = require("playwright")

const args = process.argv.slice(2)
const opt = (name, fallback) => {
  const i = args.indexOf(`--${name}`)
  return i >= 0 ? args[i + 1] : fallback
}
const staticDir = resolve(opt("static", join(here, "..", "storybook-static")))
const outDir = resolve(opt("out", join(here, "..", "shape-clip-check")))
const onlyPreset = opt("preset", null)
const allShots = args.includes("--shots")

// The surfaces that clipped under the round preset, and their neighbours:
// fields that grow, chips that wrap, the editor and its comment rail,
// tables, tabs, segmented controls, menus, popovers, cards with media,
// dialogs, the sidebar and the phone bar.
const STORIES = [
  "primitives-textarea--states",
  "primitives-input--states",
  "primitives-input--compact-file-input-alternative",
  "primitives-select--default",
  "primitives-taginput--all-selected",
  "primitives-audiencepeoplepicker--with-selection",
  "primitives-audiencepeoplepicker--right-to-left",
  "primitives-bilingualinput--with-title-and-badges",
  "editor-doceditor--editing",
  "editor-comments-and-suggestions--rail-with-comments-and-suggestions",
  "editor-comments-and-suggestions--resolved-threads",
  "primitives-table--default",
  "primitives-datatable--with-row-actions",
  "primitives-datatable--with-filtering-and-search",
  "primitives-tabs--horizontal",
  "primitives-tabs--vertical",
  "layout-pageheader--with-embedded-tabs",
  "inputs-viewtoggle--three-options",
  "design-cards--toggle-group-card",
  "design-cards--menu-card",
  "overlays-dropdownmenu--default",
  "primitives-popover--default",
  "primitives-card--plugin-store-card",
  "primitives-card--feature-install-card",
  "primitives-plugincard--store-tiles",
  "design-cards--dialog-card",
  "primitives-badge--all-variants",
  "primitives-alert--with-action",
  "agents-collaboration--approvals-queue",
  "people-rolesummary--many-roles",
  "layout-appsidebar--full-featured-with-profile-icon",
  "layout-topnav--default",
]
const PHONE = [
  "layout-topnav--mobile-view",
  "primitives-textarea--states",
  "primitives-taginput--all-selected",
  "editor-comments-and-suggestions--narrow-drawer",
  "primitives-datatable--with-row-actions",
]
const MODES = [
  { name: "ltr-light", globals: "theme:light;direction:ltr" },
  { name: "rtl-dark", globals: "theme:dark;direction:rtl" },
]

const presets = JSON.parse(readFileSync(join(here, "shape-presets.json"), "utf8"))
const probe = readFileSync(join(here, "clip-probe.js"), "utf8")
const css = (vars) => `:root{${Object.entries(vars).map(([k, v]) => `--${k}:${v}`).join(";")}}`

const devUrl = opt("url", null)
if (!devUrl && !existsSync(join(staticDir, "iframe.html"))) {
  console.error(`No built Storybook at ${staticDir}. Run: npm run build-storybook -w @whity/ui`)
  process.exit(2)
}
const types = { ".html": "text/html", ".js": "text/javascript", ".mjs": "text/javascript", ".css": "text/css", ".json": "application/json", ".svg": "image/svg+xml", ".png": "image/png", ".woff2": "font/woff2", ".woff": "font/woff", ".ttf": "font/ttf" }
const server = createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, "http://x").pathname)
  let file = join(staticDir, path)
  if (!file.startsWith(staticDir) || !existsSync(file) || statSync(file).isDirectory()) file = join(staticDir, "index.html")
  res.writeHead(200, { "content-type": types[extname(file)] ?? "application/octet-stream" })
  res.end(readFileSync(file))
})
if (!devUrl) await new Promise((r) => server.listen(0, "127.0.0.1", r))
const base = devUrl ?? `http://127.0.0.1:${server.address().port}`

mkdirSync(outDir, { recursive: true })
const jobs = []
for (const [preset, vars] of Object.entries(presets)) {
  if (onlyPreset && preset !== onlyPreset) continue
  for (const mode of MODES) {
    for (const id of STORIES) jobs.push({ preset, vars, mode, id, width: 1100, height: 800, size: "desktop" })
    for (const id of PHONE) jobs.push({ preset, vars, mode, id, width: 390, height: 844, size: "phone" })
  }
}

const browser = await chromium.launch()
const failures = []
const run = async () => {
  const page = await browser.newPage()
  for (let job = jobs.shift(); job; job = jobs.shift()) {
    const { preset, vars, mode, id, width, height, size } = job
    await page.setViewportSize({ width, height })
    const shot = join(outDir, `${preset}__${mode.name}__${size}__${id}.png`)
    try {
      await page.goto(`${base}/iframe.html?id=${id}&viewMode=story&globals=${mode.globals}`, { waitUntil: "load" })
      // Drawers and sheets render in a portal, outside the story root.
      await page.waitForSelector("#storybook-root > *, body > [role=dialog], [data-slot=sheet-content], [data-slot=drawer-content]", { state: "attached", timeout: 15000 })
      await page.waitForTimeout(300)
      await page.addStyleTag({ content: css(vars) + "*,*::before,*::after{transition:none!important;animation:none!important}" })
      await page.evaluate(() => document.fonts.ready)
      const found = await page.evaluate(`(${probe.slice(probe.indexOf("function clipProbe"))})()`)
      if (found.length) {
        failures.push({ preset, mode: mode.name, size, id, found })
        await page.screenshot({ path: shot, fullPage: true })
      } else if (allShots) {
        await page.screenshot({ path: shot, fullPage: true })
      }
    } catch (e) {
      failures.push({ preset, mode: mode.name, size, id, found: [{ error: String(e.message).split("\n")[0] }] })
    }
  }
  await page.close()
}
const total = jobs.length
await Promise.all(Array.from({ length: 4 }, run))
await browser.close()
if (!devUrl) server.close()

writeFileSync(join(outDir, "findings.json"), JSON.stringify(failures, null, 2))
if (failures.length) {
  console.error(`FAIL: ${failures.length} of ${total} renders have content cut by a rounded corner.`)
  for (const f of failures.slice(0, 40)) {
    for (const x of f.found.slice(0, 3)) {
      console.error(`  ${f.preset} ${f.mode} ${f.size} ${f.id}: ${x.error ?? `${x.kind} ${x.child} in ${x.owner} at ${x.corner}, ${x.depth}px`}`)
    }
  }
  console.error(`Screenshots and findings.json in ${outDir}`)
  process.exit(1)
}
console.log(`OK: ${total} renders across ${Object.keys(presets).length} shape presets, nothing cut by a rounded corner.`)
