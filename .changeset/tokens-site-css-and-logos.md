---
"@whity/tokens": minor
---

The websites' token CSS and the brand logos now ship in the package (WHIT-1055),
so other repos install them instead of keeping copies.

- `@whity/tokens/site.css`: the brand handoff's plain-CSS tokens (both themes,
  `@font-face`, type classes), with fonts read from the package's own `fonts/`.
- `@whity/tokens/logos/*`: the logo set (lockups, marks, wordmarks, app icons,
  favicons, "Built on Whity" badges) as SVG, PNG and ICO.
- `npx whity-tokens copy <folder> [--no-fonts] [--no-logos]` writes
  `tokens.css`, `fonts/` and `logos/` into a folder a static site serves.
