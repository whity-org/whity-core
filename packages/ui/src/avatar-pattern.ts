/**
 * Seeded avatar patterns (M.34, docs/design/components/index.d.ts `avatarPattern`
 * and `brandHue`, bundle.js). People without a photo get a small Whity pattern
 * instead of bare initials: the same seed and hue always draw the same picture,
 * on every device and in the phone app.
 *
 * Pass a stable seed (the Whity ID, or the person's id) and the hue of the
 * installation colour where the account was created (`brandHue`).
 */

/** A small deterministic random number generator, seeded by a string. */
export function seededRandom(seed: string): () => number {
  let hash = 1779033703 ^ seed.length
  for (let i = 0; i < seed.length; i++) {
    hash = Math.imul(hash ^ seed.charCodeAt(i), 3432918353)
    hash = (hash << 13) | (hash >>> 19)
  }
  let state = hash >>> 0
  return () => {
    state = (state + 0x6d2b79f5) >>> 0
    let r = Math.imul(state ^ (state >>> 15), 1 | state)
    r = (r + Math.imul(r ^ (r >>> 7), 61 | r)) ^ r
    return ((r ^ (r >>> 14)) >>> 0) / 4294967296
  }
}

/** The OKLCH hue (0-360) of a brand colour, used to colour avatar patterns. */
export function brandHue(hex: string): number {
  let m = String(hex || "#0961bb").replace("#", "")
  if (m.length === 3) m = m.replace(/./g, "$&$&")
  const [r, g, b] = [0, 2, 4].map((i) => {
    const v = parseInt(m.substring(i, i + 2), 16) / 255
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
  })
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
  const mm = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)
  const a = 1.9779984951 * l - 2.428592205 * mm + 0.4505937099 * s
  const bb = 0.0259040371 * l + 0.7827717662 * mm - 0.808675766 * s
  const hue = (Math.atan2(bb, a) * 180) / Math.PI
  return Math.round(hue < 0 ? hue + 360 : hue)
}

function rect(x: number, y: number, w: number, h: number, rx: number, fill: string, extra = ""): string {
  return `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${rx}" fill="${fill}"${extra}/>`
}

/**
 * SVG markup for a seeded avatar pattern (M.34): the mark's sheet and planes in
 * one of four arrangements, tinted around `hue` (Whity Blue, 255, by default).
 * Built only from numbers, so it is safe to inline.
 */
export function avatarPattern(seed: string, hue = 255): string {
  const random = seededRandom(String(seed || "whity"))
  const pick = <T,>(values: T[]): T => values[Math.floor(random() * values.length)]
  const hb = hue + pick([-36, -18, 0, 18, 36])
  const ha = hue + pick([-70, 70, 160])
  const bg = `oklch(0.93 0.045 ${hb})`
  const ink = `oklch(0.52 0.16 ${hue})`
  const deep = `oklch(0.3 0.1 ${hue})`
  const soft = `oklch(0.76 0.12 ${ha})`
  const white = "#fff"
  const layout = Math.floor(random() * 4)
  const rot = pick([0, 0, -10, 10, -18, 18])
  const s = 15 + Math.floor(random() * 5)
  const x = 12 + Math.floor(random() * 5)
  const y = 9 + Math.floor(random() * 5)
  const parts: string[] = []
  if (layout === 0) {
    parts.push(rect(3, y + s - 1, 34, 8, 3, ink), rect(x - 3, y + 3, s, s, 3, deep), rect(x, y, s, s, 3, white))
  } else if (layout === 1) {
    parts.push(rect(x - 7, y + 5, s, s, 3, soft), rect(x - 1, y + 2, s, s, 3, deep), rect(x + 2, y - 1, s, s, 3, ink))
  } else if (layout === 2) {
    const cx = pick([12, 28])
    parts.push(
      rect(4, 29, 32, 6, 3, soft),
      `<circle cx="${cx}" cy="${y + 4}" r="6" fill="${ink}"/>`,
      rect(cx === 12 ? 17 : 8, y + 2, s, s, 3, "none", ` stroke="${deep}" stroke-width="2.6" stroke-dasharray="4.5 3"`)
    )
  } else {
    parts.push(
      rect(-12, 18, 64, 13, 0, ink, ` transform="rotate(${pick([-24, 24, -36, 36])} 20 24)"`),
      rect(x - 3, y + 1, s, s, 3, deep),
      rect(x, y - 2, s, s, 3, white)
    )
  }
  return `<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="40" height="40" fill="${bg}"/><g transform="rotate(${rot} 20 20)">${parts.join("")}</g></svg>`
}
