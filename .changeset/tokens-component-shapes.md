---
"@whity/tokens": minor
---

Per-component shape overrides (WHIT-1225, TH.21): every radius utility now reads a
`--shape-*` property set on `:root` from `--wy-radius-*`, and again on the
elements of seven component families (`data-slot`) from `--wy-<family>-radius-*`,
so a family can carry its own preset. `--corner-shape` follows the same way as
`--shape-corner`. The theme schema accepts `shape.components`.
