---
"@whity/tokens": patch
---

Adds `--spacing-inset-control` (padding that grows with the control radius, 30%
of it and never under 4px) and `--radius-control-inner` (the control radius less
that padding), so fields that hold chips keep them clear of a round corner
(WHIT-1225).
