---
"@whity/ui": patch
---

Mark 21 entry points that no app imports as internal and deprecated for outside use: the `editor/*` building blocks of `editor/doc-editor`, and `agent-run-status`, `beta-warning`, `collapsible`, `color-card`, `menu-bar`, `switcher`, `time-picker` and `top-nav`. They stay exported and unchanged until the next major, which removes them. The README lists them.
