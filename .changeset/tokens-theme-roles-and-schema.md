---
"@whity/tokens": minor
---

Themes (WHIT-1225, TH.1-TH.14):

- New colour roles in light and dark: `surface-1` to `surface-5`, `primary-container` and
  `on-primary-container`, `secondary-container` and `on-secondary-container`, and `tertiary`
  with `tertiary-foreground`, `tertiary-container` and `on-tertiary-container`.
- The radius utilities read `--wy-radius-*` first, so a workspace theme's shape preset
  can change corners; without it they keep decision 4.2's values.
- `@whity/tokens/theme.schema.json`: the theme file format (schema version 1), the
  same schema core validates against.
