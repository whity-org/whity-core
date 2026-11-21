// Runs in the page (Playwright `page.evaluate`). Finds content that a rounded
// corner cuts or that pokes past one: text, and painted children (a header
// band, a table head, an image) that are wider than the curve allows.
//
// Returns a list of { owner, child, corner, depth } where depth is how many
// pixels past the curve the worst point lies. A point inside the corner box
// whose distance from the corner's circle centre is more than the radius is
// outside the curve.
// eslint-disable-next-line no-unused-vars
function clipProbe(opts) {
  const tolerance = (opts && opts.tolerance) || 1
  const px = (v) => parseFloat(v) || 0
  const clearColour = (c) => !c || c === "transparent" || /rgba\(.*,\s*0\)$/.test(c) || / \/ 0\)$/.test(c)
  const painted = (cs) => {
    const hasBg = !clearColour(cs.backgroundColor)
    const hasBorder = ["Top", "Right", "Bottom", "Left"].some((s) => px(cs["border" + s + "Width"]) > 0 && cs["border" + s + "Style"] !== "none" && !clearColour(cs["border" + s + "Color"]))
    // A shadow or focus ring alone draws no edge that text can cross.
    return hasBg || hasBorder || cs.backgroundImage !== "none"
  }
  // Screen-reader-only and decorative overhangs (data-clip-ignore) are not
  // content a corner can cut.
  const visible = (el, cs) => {
    if (cs.visibility === "hidden" || cs.display === "none" || px(cs.opacity) === 0) return false
    if (el.closest("[data-clip-ignore]")) return false
    if (cs.clip && cs.clip !== "auto") return false
    if (cs.clipPath && cs.clipPath !== "none") return false
    const r = el.getBoundingClientRect()
    return r.width > 1 && r.height > 1
  }
  // Effective corner radii after the browser scales overlapping radii down.
  const radii = (el, cs) => {
    const r = el.getBoundingClientRect()
    const tl = px(cs.borderTopLeftRadius), tr = px(cs.borderTopRightRadius)
    const br = px(cs.borderBottomRightRadius), bl = px(cs.borderBottomLeftRadius)
    const f = Math.min(1, r.width / (tl + tr || 1), r.width / (bl + br || 1), r.height / (tl + bl || 1), r.height / (tr + br || 1))
    // The Cut preset bevels its corners (corner-shape: bevel): the edge is
    // the straight line between the two tangent points, inside the arc.
    const bevel = /bevel/.test(cs.getPropertyValue("corner-shape") || "")
    return { tl: tl * f, tr: tr * f, br: br * f, bl: bl * f, rect: r, bevel }
  }
  // How far point (x, y) lies outside the rounded box; 0 when inside.
  const outside = (R, x, y) => {
    const { rect } = R
    const corners = [
      ["tl", rect.left + R.tl, rect.top + R.tl, R.tl, x < rect.left + R.tl && y < rect.top + R.tl],
      ["tr", rect.right - R.tr, rect.top + R.tr, R.tr, x > rect.right - R.tr && y < rect.top + R.tr],
      ["br", rect.right - R.br, rect.bottom - R.br, R.br, x > rect.right - R.br && y > rect.bottom - R.br],
      ["bl", rect.left + R.bl, rect.bottom - R.bl, R.bl, x < rect.left + R.bl && y > rect.bottom - R.bl],
    ]
    for (const [name, cx, cy, rad, inBox] of corners) {
      if (rad > 0 && inBox) {
        // From the box's own corner: |x - edge| + |y - edge| < rad is past the bevel.
        const d = R.bevel
          ? (rad - (Math.abs(x - (cx < x ? cx + rad : cx - rad)) + Math.abs(y - (cy < y ? cy + rad : cy - rad)))) / Math.SQRT2
          : Math.hypot(x - cx, y - cy) - rad
        if (d > 0) return [name, d]
      }
    }
    return [null, 0]
  }
  const label = (el) => {
    const slot = el.getAttribute("data-slot")
    const id = el.id ? "#" + el.id : ""
    return el.tagName.toLowerCase() + id + (slot ? "[data-slot=" + slot + "]" : "")
  }
  // Glyph boxes: line boxes shrunk by the half-leading, so line-height alone
  // never counts as a clip.
  const textRects = (owner, clipOwner) => {
    const out = []
    const walker = document.createTreeWalker(owner, NodeFilter.SHOW_TEXT)
    let n
    while ((n = walker.nextNode())) {
      if (!n.textContent.trim()) continue
      const host = n.parentElement
      const cs = getComputedStyle(host)
      if (!visible(host, cs)) continue
      const range = document.createRange()
      range.selectNodeContents(n)
      const fs = px(cs.fontSize)
      // Truncated or scrolled text is only as wide as the box that clips it.
      let clipBox = null
      for (let a = host; a && a !== clipOwner; a = a.parentElement) {
        const acs = getComputedStyle(a)
        if (acs.overflowX !== "visible" || acs.overflowY !== "visible") {
          const ar = a.getBoundingClientRect()
          clipBox = clipBox
            ? { left: Math.max(clipBox.left, ar.left), right: Math.min(clipBox.right, ar.right), top: Math.max(clipBox.top, ar.top), bottom: Math.min(clipBox.bottom, ar.bottom) }
            : { left: ar.left, right: ar.right, top: ar.top, bottom: ar.bottom }
        }
      }
      for (const r of range.getClientRects()) {
        const inset = Math.max(0, (r.height - fs * 1.15) / 2)
        const t = { el: host, left: r.left, right: r.right, top: r.top + inset, bottom: r.bottom - inset }
        if (clipBox) {
          t.left = Math.max(t.left, clipBox.left); t.right = Math.min(t.right, clipBox.right)
          t.top = Math.max(t.top, clipBox.top); t.bottom = Math.min(t.bottom, clipBox.bottom)
        }
        if (t.right - t.left > 0.5 && t.bottom - t.top > 0.5) out.push(t)
      }
    }
    // Form fields paint their own text and placeholder.
    for (const f of owner.querySelectorAll("input, textarea")) {
      const cs = getComputedStyle(f)
      if (!visible(f, cs) || f.type === "checkbox" || f.type === "radio" || f.type === "range" || f.type === "color" || f.type === "file") continue
      const r = f.getBoundingClientRect()
      out.push({
        el: f,
        left: r.left + px(cs.borderLeftWidth) + px(cs.paddingLeft),
        right: r.right - px(cs.borderRightWidth) - px(cs.paddingRight),
        top: r.top + px(cs.borderTopWidth) + px(cs.paddingTop),
        bottom: f.tagName === "TEXTAREA" ? r.bottom - px(cs.borderBottomWidth) - px(cs.paddingBottom) : r.top + px(cs.borderTopWidth) + px(cs.paddingTop) + px(cs.lineHeight || cs.fontSize),
      })
    }
    return out
  }

  const findings = []
  const owners = Array.from(document.querySelectorAll("body *")).filter((el) => {
    const cs = getComputedStyle(el)
    if (!visible(el, cs) || !painted(cs)) return false
    const R = radii(el, cs)
    return R.tl + R.tr + R.br + R.bl > 2
  })
  for (const owner of owners) {
    const R = radii(owner, getComputedStyle(owner))
    const report = (child, corner, depth, kind) => {
      if (depth > tolerance) findings.push({ owner: label(owner), child: label(child), kind, corner, depth: Math.round(depth * 10) / 10 })
    }
    const own = owner.tagName === "TEXTAREA" || owner.tagName === "INPUT"
    for (const t of textRects(own ? owner.parentElement || owner : owner, owner)) {
      if (own && t.el !== owner) continue
      if (!own && !owner.contains(t.el)) continue
      let worst = [null, 0]
      const ocs = getComputedStyle(owner)
      if (!own && (ocs.overflowX !== "visible" || ocs.overflowY !== "visible")) {
        t.left = Math.max(t.left, R.rect.left); t.right = Math.min(t.right, R.rect.right)
        t.top = Math.max(t.top, R.rect.top); t.bottom = Math.min(t.bottom, R.rect.bottom)
        if (t.right - t.left <= 0.5 || t.bottom - t.top <= 0.5) continue
      }
      for (const [x, y] of [[t.left, t.top], [t.right, t.top], [t.right, t.bottom], [t.left, t.bottom]]) {
        const o = outside(R, x, y)
        if (o[1] > worst[1]) worst = o
      }
      if (worst[0]) report(t.el, worst[0], worst[1], "text")
    }
    // Painted children with square corners that reach into a curve: they
    // poke out (overflow visible) or lose their corner (overflow clip).
    for (const child of owner.querySelectorAll("*")) {
      const cs = getComputedStyle(child)
      if (!visible(child, cs) || !painted(cs) || cs.position === "fixed") continue
      const C = radii(child, cs)
      let worst = [null, 0]
      const pts = [
        ["tl", C.rect.left + C.tl * 0.29, C.rect.top + C.tl * 0.29],
        ["tr", C.rect.right - C.tr * 0.29, C.rect.top + C.tr * 0.29],
        ["br", C.rect.right - C.br * 0.29, C.rect.bottom - C.br * 0.29],
        ["bl", C.rect.left + C.bl * 0.29, C.rect.bottom - C.bl * 0.29],
      ]
      // Only corners the child paints: its background, or a border on one
      // of the corner's two sides (a row divider paints no bottom corner).
      const side = (n) => px(cs["border" + n + "Width"]) > 0 && cs["border" + n + "Style"] !== "none" && !clearColour(cs["border" + n + "Color"])
      const fill = !clearColour(cs.backgroundColor) || cs.backgroundImage !== "none"
      const paintsCorner = {
        tl: fill || side("Top") || side("Left"),
        tr: fill || side("Top") || side("Right"),
        br: fill || side("Bottom") || side("Right"),
        bl: fill || side("Bottom") || side("Left"),
      }
      for (const [name, x, y] of pts) {
        if (!paintsCorner[name]) continue
        const o = outside(R, x, y)
        if (o[1] > worst[1]) worst = o
      }
      // A clipping parent cuts the child's corner to its own curve, which is
      // the intended look; only a child painted past a curve that does not
      // clip is a fault.
      if (worst[0] && getComputedStyle(owner).overflow === "visible") report(child, worst[0], worst[1], "pokes-out")
    }
  }
  return findings
}
