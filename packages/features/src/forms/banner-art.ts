/**
 * Banners drawn in code from the workspace's main colour (WHIT-944).
 *
 * A form stores only a style and a seed, and the picture is drawn here, so it
 * always suits the brand and follows it when the colour changes. Every colour in a
 * picture comes from one small palette built around the brand colour, and the
 * palette is darkened or lightened until the title's colour (white or ink) reaches
 * WCAG AA, 4.5:1, against every one of them. Nothing in the picture is see-through,
 * so that check covers every pixel the title can sit on.
 *
 * Pure functions: the same colour, style and seed always give the same picture.
 */

export const BANNER_STYLES = ["gradient", "waves", "dots", "stripes", "circles", "mesh"] as const
export type BannerStyle = (typeof BANNER_STYLES)[number]

/** Kept in step with `FormBanner::SEED_MAX` on the server. */
export const SEED_MAX = 2_147_483_647

export type Rgb = readonly [number, number, number]

/** The text colours a banner title can take. Ink is the light theme's foreground. */
export const WHITE: Rgb = [255, 255, 255]
export const INK: Rgb = [20, 22, 26]

/** AA for normal text, with a little room for gradients between two palette colours. */
const TARGET = 4.6

export interface BannerArt {
  /** The picture, as an SVG document. */
  svg: string
  /** The title's colour, as a hex string. */
  text: string
  /** The title against the palette colour it contrasts least with. */
  contrast: number
}

export function hexToRgb(hex: string): Rgb | null {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim())
  if (match === null) return null
  const n = Number.parseInt(match[1], 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

export function rgbToHex([r, g, b]: Rgb): string {
  return `#${[r, g, b].map((c) => Math.round(c).toString(16).padStart(2, "0")).join("")}`
}

/** `a` moved towards `b` by `t` (0 to 1), in sRGB. */
export function mix(a: Rgb, b: Rgb, t: number): Rgb {
  return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]
}

/** WCAG relative luminance. */
export function luminance([r, g, b]: Rgb): number {
  const channel = (c: number) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

/** WCAG contrast ratio, 1 to 21. */
export function contrast(a: Rgb, b: Rgb): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** The five colours a picture is painted with, around a ground colour. */
function paletteOf(ground: Rgb): Rgb[] {
  return [mix(ground, WHITE, 0.16), mix(ground, WHITE, 0.08), ground, mix(ground, INK, 0.2), mix(ground, INK, 0.36)]
}

function worst(text: Rgb, palette: Rgb[]): number {
  return Math.min(...palette.map((c) => contrast(text, c)))
}

/**
 * The palette and title colour for a brand colour: white over a darkened palette or
 * ink over a lightened one, whichever moves the brand colour less.
 */
export function paletteFor(brand: Rgb): { palette: Rgb[]; text: Rgb; contrast: number } {
  const fit = (text: Rgb, towards: Rgb) => {
    for (let step = 0; step <= 20; step += 1) {
      const palette = paletteOf(mix(brand, towards, step * 0.05))
      const ratio = worst(text, palette)
      if (ratio >= TARGET) return { palette, text, contrast: ratio, step }
    }
    const palette = paletteOf(towards)
    return { palette, text, contrast: worst(text, palette), step: 21 }
  }
  const light = fit(WHITE, INK)
  const dark = fit(INK, WHITE)
  const chosen = dark.step < light.step ? dark : light
  return { palette: chosen.palette, text: chosen.text, contrast: chosen.contrast }
}

/** A small, fast, seeded generator (mulberry32), so a seed is a layout. */
function random(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const W = 1200
const H = 300

const round = (n: number) => Math.round(n * 10) / 10

function draw(style: BannerStyle, p: string[], next: () => number): string {
  const [lightest, light, ground, dark, darkest] = p
  const pick = () => p[Math.floor(next() * p.length)]

  switch (style) {
    case "gradient": {
      const angle = Math.floor(next() * 360)
      const cx = round(next() * 100)
      const cy = round(next() * 100)
      return (
        `<defs><linearGradient id="g" gradientTransform="rotate(${angle} .5 .5)">` +
        `<stop offset="0" stop-color="${darkest}"/><stop offset=".55" stop-color="${ground}"/><stop offset="1" stop-color="${lightest}"/>` +
        `</linearGradient><radialGradient id="r" cx="${cx}%" cy="${cy}%" r="60%">` +
        `<stop offset="0" stop-color="${light}"/><stop offset="1" stop-color="${light}" stop-opacity="0"/></radialGradient></defs>` +
        `<rect width="${W}" height="${H}" fill="url(#g)"/>` +
        // The highlight fades to see-through, but only over colours between `light`
        // and `darkest`, so what shows through stays inside the checked palette.
        `<rect width="${W}" height="${H}" fill="url(#r)"/>`
      )
    }
    case "waves": {
      const layers = 4
      let out = `<rect width="${W}" height="${H}" fill="${darkest}"/>`
      const colours = [dark, ground, light, lightest]
      for (let i = 0; i < layers; i += 1) {
        const base = 70 + i * 55
        const amp = 18 + next() * 34
        const freq = 1 + Math.floor(next() * 3)
        const phase = next() * Math.PI * 2
        let d = `M0 ${H} L0 ${round(base)}`
        for (let x = 0; x <= W; x += 40) {
          d += ` L${x} ${round(base + Math.sin((x / W) * Math.PI * 2 * freq + phase) * amp)}`
        }
        out += `<path d="${d} L${W} ${H} Z" fill="${colours[i]}"/>`
      }
      return out
    }
    case "dots": {
      const gap = 26 + Math.floor(next() * 18)
      const grow = next() > 0.5
      let out = `<rect width="${W}" height="${H}" fill="${ground}"/>`
      for (let y = gap / 2; y < H; y += gap) {
        for (let x = gap / 2; x < W; x += gap) {
          const along = grow ? x / W : 1 - x / W
          const r = round(1.5 + along * gap * 0.42)
          out += `<circle cx="${round(x)}" cy="${round(y)}" r="${r}" fill="${lightest}"/>`
        }
      }
      return out
    }
    case "stripes": {
      const width = 24 + Math.floor(next() * 40)
      const angle = 20 + Math.floor(next() * 50) * (next() > 0.5 ? 1 : -1)
      return (
        `<defs><pattern id="s" width="${width * 2}" height="${H * 4}" patternUnits="userSpaceOnUse" patternTransform="rotate(${angle})">` +
        `<rect width="${width}" height="${H * 4}" fill="${dark}"/></pattern></defs>` +
        `<rect width="${W}" height="${H}" fill="${ground}"/><rect width="${W}" height="${H}" fill="url(#s)"/>`
      )
    }
    case "circles": {
      let out = `<rect width="${W}" height="${H}" fill="${ground}"/>`
      const count = 7 + Math.floor(next() * 6)
      for (let i = 0; i < count; i += 1) {
        out += `<circle cx="${round(next() * W)}" cy="${round(next() * H)}" r="${round(40 + next() * 160)}" fill="${pick()}"/>`
      }
      return out
    }
    case "mesh": {
      const cols = 10
      const rows = 4
      const cw = W / cols
      const rh = H / rows
      const points: Array<Array<[number, number]>> = []
      for (let r = 0; r <= rows; r += 1) {
        points.push([])
        for (let c = 0; c <= cols; c += 1) {
          const edgeX = c === 0 || c === cols
          const edgeY = r === 0 || r === rows
          points[r].push([
            round(c * cw + (edgeX ? 0 : (next() - 0.5) * cw * 0.7)),
            round(r * rh + (edgeY ? 0 : (next() - 0.5) * rh * 0.7)),
          ])
        }
      }
      let out = ""
      for (let r = 0; r < rows; r += 1) {
        for (let c = 0; c < cols; c += 1) {
          const [a, b, d, e] = [points[r][c], points[r][c + 1], points[r + 1][c], points[r + 1][c + 1]]
          out += `<path d="M${a} L${b} L${e} Z" fill="${pick()}"/><path d="M${a} L${e} L${d} Z" fill="${pick()}"/>`
        }
      }
      return out
    }
  }
}

/** The picture for a brand colour, style and seed. */
export function bannerArt(brand: Rgb, style: BannerStyle, seed: number): BannerArt {
  const { palette, text, contrast: ratio } = paletteFor(brand)
  const body = draw(style, palette.map(rgbToHex), random(seed))
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid slice" ` +
    `shape-rendering="geometricPrecision">${body}</svg>`
  return { svg, text: rgbToHex(text), contrast: ratio }
}

/** A CSS `background-image` value for an SVG. */
export function svgUrl(svg: string): string {
  return `url("data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}")`
}

/** A fresh seed, for "Show new options". */
export function newSeed(): number {
  return Math.floor(Math.random() * SEED_MAX)
}

/** Six options, one per style, from a starting seed. */
export function bannerOptions(start: number): Array<{ style: BannerStyle; seed: number }> {
  const next = random(start)
  return BANNER_STYLES.map((style) => ({ style, seed: Math.floor(next() * SEED_MAX) }))
}
