# White-label and partners

A partner sells its own product; Whity stays underneath. The tenant's brand leads every screen, email and document its people and customers see, and Whity appears only where the partner chooses to say so, as a fact.

## What a tenant or partner changes

- **Name, logo, favicon and domain**, per tenant (`admin/settings/branding`, template `TplAdminBranding`). The logo slot (`TenantLogo`) takes an upload or falls back to initials on the main colour.
- **One main colour.** The rest is derived from its hue (decision 2.8): the colour on dark (L 0.70), the plane (L 0.30) and the tint (L 0.96). The label on it is white or near-black, whichever passes 4.5:1; if neither does, the colour is darkened and the admin sees what changed. Each derived value can be overridden.
- The tokens a tenant theme sets: `primary`, `brand`, `ring`, `chart-1`, `primary-tint`, `primary-tint-foreground`, `sidebar-primary`, `sidebar-primary-foreground`, the four plane shadows and `doc-accent` (the colour on printed titles, the same in light and dark), plus `primary-foreground` (white or near-black, whichever reaches 4.5:1). `Whity.tenantTheme(colour, 'light' | 'dark')` returns all of them from one colour; `.wy-theme-partner` in `bundle.css` is the same set written out for the demo tenant (burnt orange `#b74c05`).
- **Sign-in, emails and documents** carry the tenant's logo and name (`TplAuthSignIn`, the document templates).

## Themes (TH.1–TH.14, 19 Nov 2026)

- **A workspace can apply a theme** from Whity, a plugin or the store, free on every plan. A theme changes colour (one to three seed colours, with overrides), type (a Latin and Arabic pair from the allowed list, for headings and body), shape, density, elevation and motion. See `30-themes.md`.
- The main colour above is the theme's primary seed, so a workspace that sets only its colour keeps working as before.
- Making themes, in the live editor, is part of core since 21 Nov 2026 (BM.15, "All into core"); it was planned as paid Whity Studio. Section 30 has the details.

## What stays

- Status colours, layout, components and icons.
- The rules that protect people: contrast (4.5:1 for text, with automatic fallback), the minimum sizes (A.1, M.5), Arabic and RTL, and print under E.44.
- The plane rules, approvals, scopes and attribution for agents: a waiting proposal always looks different from approved work, whatever the theme. A partner can't remove them: they protect the partner's own customers.

## Built on Whity

- The `BuiltOnWhity` component or `built-on-whity-light.svg` / `-dark.svg`: the 16px mark and "Built on Whity", linking to whity.dev.
- Optional for partners. When used: in the footer, the About screen or the sidebar foot; never in a header, never larger than the partner's logo, never in the partner's colour.

## Co-branding (Whity beside a partner)

- The host leads in reading order. Separate the logos with a 1px `border` line as tall as the mark, with 3 m of space on each side.
- The partner's logo is no taller than the Whity mark and no wider than the Whity lockup.
- Never redraw or recolour a partner's logo, and never build a combined lockup.

## Trademark (from TRADEMARK.md)

| Allowed without asking | Needs written permission | Never |
| --- | --- | --- |
| "Built on Whity", "compatible with Whity", "Whity hosting" | Naming a product, company or domain "Whity" or something close | Registering "Whity" or a similar mark or domain |
| The Built on Whity badge | Using the Whity logo as your own branding | Suggesting Whity endorses you when it doesn't |
| Plugins named for their job: "Invoicing for Whity" | Shipping modified Whity Core still called "Whity" | Presenting a white-labelled product as "Whity" |
| Tutorials, reviews, talks, critical or not | "Official partner", "Whity certified", "Whity Enterprise" | Naming Whity as the source of your warranty or support |
