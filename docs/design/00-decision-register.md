# Whity brand decision register

The brand is being rebuilt one stage at a time. A stage is locked before the next one starts. The decision board is the "Whity Brand Decisions" artifact. Production files were drawn from these numbers in stage 8, and the final guideline in stage 9. All nine stages are locked; a change now reopens the stage it belongs to and is recorded here first.

## Procedure
1. Logo: locked 28 Oct 2026
2. Colour: locked 28 Oct 2026
3. Type, brand and UI: locked 28 Oct 2026
4. Shape, space, depth: locked 28 Oct 2026
5. Icons, motion, voice: locked 28 Oct 2026
6. Document components: locked 28 Oct 2026
7. UI components: locked 28 Oct 2026
8. Files and templates: locked 28 Oct 2026
9. Final guideline: locked 28 Oct 2026

Later versions: 2.1 P2 components · 2.2 Document editor · 2.3 Plugin store (locked 31 Oct 2026; S.9–S.12 added 2 Nov) · 2.4 Home (locked 2 Nov 2026) · 2.5 Page patterns and People (locked 2 Nov 2026) · 2.6 Forms (locked 2 Nov 2026) · 2.7 Ease of use and Documents and routing (locked 2 Nov 2026) · 2.8 AI and agents (locked 2 Nov 2026) · 2.9 Workspace, billing and settings (locked 2 Nov 2026)

## Stage 1 · Logo (locked 28 Oct 2026)

### The mark
The mark is built from one module, m, which is the width of the blue edge.

| # | Decision | Locked value |
| --- | --- | --- |
| 1.1 | Proportion | Both planes are 8 m square. The sheet is offset 3 m up and to the right (offset ÷ side = 0.375). |
| 1.2 | Corner radius | Outer radius 2 m, inner (window) radius 1 m, concentric. The ring is exactly 1 m at every angle. |
| 1.3 | Edge (the module) | 1 m, which is 2px at 24px and matches the Tabler icon stroke. |
| 1.4 | Corner shape | Circular arcs, the same as CSS border-radius. |
| — | Canvas | 12 m square, 0.5 m padding, live area 11 m. |

Overall ratio: side 8, offset 3, radius 2, edge 1.

### The wordmark
| # | Decision | Locked value |
| --- | --- | --- |
| 1.5a | Weight | Readex Pro 600, outlined from the variable font. |
| 1.5b | Letter spacing | −1% (−10 units per letter). |
| 1.5c | Dot on the i | A rounded square. Its side equals the stem width and its corner radius is ¼ of the side. |
| 1.5d | Letter corners | Outside corners are rounded to ¼ of the stem (0.36 m at cap height 8 m). Inside corners stay sharp. |

### The lockup
| # | Decision | Locked value |
| --- | --- | --- |
| 1.6a | Word size | Cap height 8 m (equal to the plane side), x-height 6 m. The baseline is the platform's bottom edge and the cap line is its top. |
| 1.6b | Gap | 3 m from the sheet's right edge to the leftmost point of the W. |
| 1.6c | Stacked lockup | The mark is centred over the word, 3 m apart, at the same cap height. |

### Small sizes and clear space
| # | Decision | Locked value |
| --- | --- | --- |
| 1.7a | Small sizes | Pixel-fitted cuts below 24px. The 16px cut is side 11, offset 4, edge 2, radius 3 (in px). The 20px cut is side 14, offset 5, edge 2, radius 3.5. The master is used from 24px up, where m = 2px. |
| 1.7b | Clear space | 3 m around the live area. |
| 1.7c | Minimum size | The horizontal lockup is at least 20px tall on screen (word cap height 13.3px) and 6 mm in print. The mark alone can go down to 16px. |

### Colourways and app icon
| # | Decision | Locked value |
| --- | --- | --- |
| 1.8a | On dark | The platform and edge are #59a0f9, the window is white and the word is #f2f3f5. |
| 1.8b | App icon | A Whity Blue tile with the mark in white and the window knocked out. The same tile is used for the PWA icon, the social avatar and large favicons. |
| 1.8c | Mark in the tile | The tile is 18 m and the mark's live area is 61% of it (3.5 m margin). |
| 1.8 | Set | Exactly five colourways: colour, reversed, on brand, one colour ink, one colour white. The one-colour versions knock the window out. |

### Rules
- Every corner is ¼ of what it ends: the planes, the dot and the letter stems.
- Every gap is the step of 3 m: the offset, the lockup gap and the clear space.
- Every height in the lockup is a mark measure.

## Stage 2 · Colour (locked 28 Oct 2026)

### Brand and ground
| # | Decision | Locked value |
| --- | --- | --- |
| 2.1 | Whity Blue | #0961bb, oklch(0.5 0.16 255). White text on it is 6.1:1. |
| 2.2 | Blue on dark | #59a0f9, oklch(0.70 0.15 255). It is 7.4:1 on the dark page. Buttons on dark take a dark label. |
| 2.3 | Greys | Pure grey (chroma 0), so they stay neutral under any tenant colour. |
| 2.4 | Page ground | #eeeeee, oklch(0.95), under white cards. |

### Status colours
| # | Decision | Locked value |
| --- | --- | --- |
| 2.5a | Structure | Each status has three tokens: a fill (with its own label colour), a text colour and a tint. Error and destructive are one token. |
| — | Success | Fill #0a7e3a with a white label. Text #087736. Tint #e0fae4. On dark: #5ac576, tint #14361d. |
| 2.5c | Warning | Fill #f2b036 with a dark label (#1a1206, 9.7:1). Text #9a5505. Tint #fef4df. On dark: #f7b83d, tint #432f07. |
| — | Error | Fill #cc2827 with a white label. Text #c2181d. Tint #fceeec. On dark: #f66d67, tint #4f1a18. |
| 2.5b | Info | Neutral: fill and text #555555, tint #f0f0f0. On dark: #aeaeae, tint #262626. Blue is kept for action and the brand. |
| — | Text contrast | Every status text colour passes 4.5:1 on both white cards and the #eeeeee page. |

### Contrast fixes
| # | Decision | Locked value |
| --- | --- | --- |
| 2.6 | Secondary text | #696969, oklch(0.52). It is 4.7:1 on the page and 5.5:1 on cards. |
| 2.6 | Input border | #929292, oklch(0.66). It is 3.1:1 on cards. Card borders and dividers stay light (#e5e5e5) because they are decorative. |

### Charts
| # | Decision | Locked value |
| --- | --- | --- |
| 2.7 | Categorical series, light | Main colour #0961bb, amber #f7a224, sky #91dbf2, plum #9d2398, green #35aa76, graphite #3a3a3a. |
| 2.7 | Categorical series, dark | #59a0f9, #f7a224, #91dbf2, #d25bcb, #53c48e, #858585. Graphite changed from #b7b7b7 on 28 Oct 2026 (version 2.1): beside green it was ΔE 5.3 under deuteranopia, below the 8 floor; #858585 gives 12.9 and stays above 3:1 on the dark card. |
| 2.7 | Contrast between series | The smallest OKLab difference is 0.08 under deuteranopia and protanopia. |
| 2.7 | Rules | Series 1 is always the tenant's main colour. Ordered (sequential) data uses lightness steps of the main colour. |

### Tenant colour rules
| # | Decision | Locked value |
| --- | --- | --- |
| 2.8a | Contrast | Pick a white or near-black label, whichever passes 4.5:1. Lower the lightness only if neither passes. The admin sees what changed. |
| 2.8b | Derivation | One colour goes in. The rest is derived from its hue: on dark at L 0.70 (chroma up to 0.15), the plane at L 0.30 (chroma up to 0.10), the tint at L 0.96 (chroma up to 0.03). Each can be overridden. |
| 2.8c | Demo tenant | Burnt orange #b74c05, oklch(0.55 0.154 45), replaces teal in all partner and demo templates. Its derived set is #e97c47 on dark, #4f1c00 plane and #feeee7 tint. The user disliked the teal. |

## Stage 3 · Type, brand and UI (locked 28 Oct 2026)

### Faces
| # | Decision | Locked value |
| --- | --- | --- |
| 3.1 | Brand headlines | Readex Pro 600 for the site, slides, document titles and social posts. Body text stays in Noto Sans. The product's own screens never use Readex Pro. Arabic document titles use its Arabic design (E.35). |
| 3.2 | Product, English | Noto Sans. |
| 3.3 | Product, Arabic | Noto Sans Arabic. |
| 3.4 | Codes, IDs, keys and code | Geist Mono. |

### Sizes
| # | Decision | Locked value |
| --- | --- | --- |
| 3.5a | Product scale | Caption 12, text 14 (default, = shadcn text-sm), large 16, card title 18, section 20, page title 24 px. 11px is the absolute floor and is used only for counters and badges. |
| 3.5b | Brand headline scale | Steps of 1.25 from a 16px body: 16 · 20 · 25 · 31 · 39 · 49 px. |

### Weights and line heights
| # | Decision | Locked value |
| --- | --- | --- |
| 3.6 | Body and component text | 400, line height 1.5 (Arabic 1.7). |
| 3.6 | Labels, buttons, tabs, table headers | 500, line height 1.25 (Arabic 1.5). |
| 3.6 | Product titles | 600, line height 1.25 (Arabic 1.5). |
| 3.6 | Key numbers | 600, tabular figures, line height 1.2. |
| 3.6 | Brand headlines | Readex Pro 600, line height 1.1 for large and 1.25 for small. |
| 3.6 | Mono | Geist Mono 400 or 500, line height 1.5. |
| 3.6 | Other rules | No 700 in the product. Letter spacing is 0 except brand headlines (−1% to −2%) and uppercase labels (+6%). |

### Arabic rules
| # | Decision | Locked value |
| --- | --- | --- |
| 3.7a | Size | Arabic is set at the same size as Latin (100%). The user chose this over the recommended 110%. |
| 3.7b | Digits | Western digits 0–9 in every language. |

## Stage 4 · Shape, space, depth (locked 28 Oct 2026)

### Round 1 (locked 28 Oct 2026)
| # | Decision | Locked value |
| --- | --- | --- |
| 4.1 | Density | Buttons and inputs are 36px tall (small buttons 28px for toolbars and tables). Table rows are 44px. Gaps between groups are 16px. Spacing stays on a 4px grid. |
| 4.2 | Corners | The ¼ rule: a control's radius is ¼ of its height (9px at 36, 7px at 28). Cards are 12px and dialogs 16px. |
| 4.3 | The plane | Used in three places: the main button, approved agent work and a hovered clickable card. It is a hard 3px offset down-left that lifts to 5px on hover and never mirrors in RTL. Waiting proposals stay dashed and flat. |
| 4.4 | Floating layers | Menus, popovers, toasts and dialogs use a soft two-layer shadow. The plane is never used for floating layers. |

### Round 2 (locked 28 Oct 2026)
| # | Decision | Locked value |
| --- | --- | --- |
| 4.5 | App shell | Sidebar 240px, collapsing to 56px. Reading pages (forms, settings) are capped at 1280px and centred. Tables and boards may use the full width. |
| 4.5 | Gutters and grid | Page gutters are 24px on desktop and 16px on phones. Cards have 16–20px of inside padding. 12 columns with 24px gaps. |
| 4.5 | Breakpoints | Tailwind's: 640, 768, 1024, 1280, 1536. The sidebar collapses below 1024 and becomes a drawer below 768. |
| 4.5 | Reading width | Forms at most 720px; text at most 68 characters per line. |
| 4.6a | Cards | 1px #e5e5e5 border, no shadow. |
| 4.6b | Focus | A 2px Whity Blue ring with a 2px offset. |
| 4.6 | Tables | Horizontal lines only (#ececec). No zebra stripes and no vertical lines. Numbers are right-aligned in tabular figures. The header row is #f7f7f7. |
| 4.6 | Dividers | 1px #ececec inside cards; 1px #e5e5e5 between page regions. |

## Stage 5 · Icons, motion, voice (locked 28 Oct 2026)

### Icons and motion
| # | Decision | Locked value |
| --- | --- | --- |
| 5.1 | Icon set | Tabler outline. The agent icon becomes Tabler's robot face; today it is a plug. |
| 5.2 | Icon weight | Rendered stroke of 1.5px at 16px (stroke-width 2.25 on 16px icons) and 2 at 20 and 24px. 16px is the default beside 14px text; 20px in touch navigation; 24px in headers and empty states. |
| 5.3 | Motion timing | Fast 120 ms (hover, press), base 180 ms (state changes), slow 250 ms (things entering), on one ease-out curve, cubic-bezier(0.2, 0, 0, 1). Reduced motion shows the end state only. |
| 5.4 | Approve animation | Set down: the dashed edge turns solid blue and the plane slides in underneath, 200 ms. Undo plays it backwards. |

### Voice
| # | Decision | Locked value |
| --- | --- | --- |
| 5.5a | English | Plain and direct. Address the reader as "you", use active verbs, and make buttons say what happens. No exclamation marks. "We" only on the website and in docs. |
| 5.5b | Arabic interface | Simple Modern Standard Arabic in short sentences, reviewed by a native speaker. |
| 5.6a | Agent messages | Describe the action: agent named by role, then who it acts for, then what it did or proposes. No "I", no feelings. |
| 5.6b | Agent names | By role (Billing assistant, HR assistant). No human first names and no "Whity AI". |
| 5.7 | Errors | Say what happened and what to do. No apology, no blame, one next step. Technical detail and a copyable reference go behind "Details". |

### Words
| Use | Avoid |
| --- | --- |
| agent, assistant (named by role) | AI employee, copilot, bot, magic |
| approve, ask first, allowed, scope | autonomous, hands-free, set and forget |
| platform, workspace, app | suite, solution, ecosystem, synergy |
| Buttons that name the action, such as "Issue invoice" | Submit, OK, Confirm, Proceed |
| Built on Whity | Powered by AI, AI-first |

Other rules:
- Sentence case everywhere.
- Western digits, JOD with 3 decimals, dates as 27 Oct 2026 (27/10/2026 where space is short), 24-hour times.

## Stage 6 · Document components (locked 28 Oct 2026)
These are the building blocks for everything Whity Render prints. They were shown on an A4 tax invoice for the demo tenant, Example Bakery (burnt orange).

### The page
| # | Decision | Locked value |
| --- | --- | --- |
| 6.1 | Margins | 16 mm on all sides of an A4 page, leaving 178 mm of content width. US Letter and A5 follow the same rules. |
| 6.2 | Letterhead | Tenant logo, address and tax number at the start side; title, number and date at the end side. |
| 6.3 | Tenant colour | Used on the document title only. Everything else is ink, so documents print and fax cleanly. A theme may extend it to headings (E.45, 31 Oct 2026). |
| 6.4 | Languages | Either language can lead, chosen per customer: Arabic with English labels (layout mirrors) or English with Arabic labels. Latin values inside Arabic are isolated with bdi. |

### Inside the document
| # | Decision | Locked value |
| --- | --- | --- |
| 6.5 | Type | Body Noto Sans 9.5 pt, labels 8.5 pt, title Readex Pro 600 at 22 pt, codes in Geist Mono. |
| 6.6 | Line-item table | A strong rule under the header and hairlines between rows. No zebra stripes, no grid. |
| 6.6 | Totals | End-aligned block 46% wide. Only the grand total is bold, with a 1.5 pt rule above. The amount is written in words under it in both languages (dinars and fils). |
| 6.6 | Numbers | Tabular figures, end-aligned, JOD to 3 decimals. |
| 6.7 | Footer | The JoFotara stamp, reference and "Page x of y" at the start side; the QR code ("Scan to verify") at the end side. |
| 6.7 | Continuation pages | A small running header (logo, number, "continued"), the table header repeated, "Carried forward" at the foot, and totals on the last page only. |
| — | Legal fields | Every legal field comes from tenant settings. Confirm the required invoice fields with an accountant and the JoFotara specification before shipping. |
| 6.8 | Document set | All four groups are in scope for stage 8. Sales: quote, invoice, receipt, credit note (the Tax invoice and the fiscal credit note, through JoFotara, are what Front Office adds; owner, 2 Nov 2026). Accounts: statement, payment reminder, delivery note. People: payslip, employment letter, leave approval. General: report, letter, purchase order. 80 mm thermal receipts come later. |

## Stage 7 · UI components (locked 28 Oct 2026)

### Inventory
- 56 components in 8 groups: Foundations, Actions, Inputs, Navigation, Data display, Feedback and layers, Agents, Platform.
- 41 are P1: what the Front Office demo, approvals and account flows need.
- Each component is marked as already in the design system, already in packages/ui (restyle), or new.
- The full list is on round 1 of stage 7 on the decision board. It is the stage 8 build order.

### Shared looks
| # | Decision | Locked value |
| --- | --- | --- |
| 7.1 | Secondary buttons | Outline: white with the 3:1 #929292 border. The main button is the only filled one and the only one with the plane. Ghost and destructive buttons are also available. |
| 7.2 | Inputs | Outlined, 1px #929292 on white, 36px tall, corner radius 9px. |
| 7.3 | Selected state | Tint: the derived tint (L 0.96) as the fill, with text in the main colour. Tabs are underlined in the main colour. No solid fill for the selected state. |
| 7.4 | Status badges | Tinted pills: the status tint as the background, with the status text colour. |

### Agent components and the data table
| # | Decision | Locked value |
| --- | --- | --- |
| 7.5a | Proposal | Summary first: one line saying what will happen and how much, two examples, then "Show all" for the details. It always shows the permissions used and the undo window. Approve, Edit and Decline. |
| 7.5b | Approvals inbox | One at a time: a queue of proposal cards ("1 of 4 waiting"). The user chose this over the recommended list with bulk approve. |
| 7.5c | Undo | A 10-second toast with Undo, then Undo stays on the activity item for the tool's undo window (for example 30 minutes for email). |
| 7.6 | Table selection | A checkbox column. When rows are selected, a bar rises at the foot showing the count, the money total and the actions. |
| — | Actor chip | Shown on everything an agent did or proposes: the role name, the robot face and the person it acts "for". People get initials; the system gets the mark. |
| — | Proposal states | Waiting: dashed and flat. Approved: solid, on the plane. Declined: grey with the title struck through. Expired: grey with "ask again". Undone: dashed again with "undone by …". |
| — | Scope grant | Off, Ask first or Allowed for each permission. An agent never exceeds the person who granted it, and changes are logged. |
| — | Agent run status | Running ("Reading 48 invoices"), Waiting for approval, Done, Failed (with what to do next). |
| — | Data table | 44px rows, sticky header, sort on click. A row opens a side drawer; the row menu sits at the end. Empty, loading (skeleton rows) and error states. A totals row for money columns. |

## Stage 8 · Files and templates (locked 28 Oct 2026)

### Built 28 Oct 2026
- Design system v2.0 draft published: logo files, tokens and components rebuilt from stages 1–7.
- `Doc` component (Whity Render): A4, A5 and US Letter pages to the stage 6 rules; either language leads; continuation pages (14 rows on page 1, 20 after, totals on the last page only, "Brought forward" and "Carried forward"); amount in words for JOD in English and Arabic; `--doc-accent`, the tenant's print colour, the same in light and dark mode.
- Sales documents: quote, tax invoice (English leads, Arabic leads, and a two-page version), credit note and A5 receipt. They tell one story: QUO-0318 → INV-1042 (JOD 301.600) → CN-0012 (JOD 98.600) → RCT-0207 (JOD 203.000).
- Accounts documents: statement of account (running balance, ageing), payment reminder (short letter, overdue invoices only, sent by the Billing assistant with approval) and delivery note (no prices, signed on receipt).
- People documents: payslip (earnings and deductions in separate columns, net pay in words, leave balance beside the totals), employment letter (Arabic leads, salary in figures and words, QR to verify) and leave approval (balance before and after, approval trail from the audit log).
- General documents: a two-page sales report (summary, key numbers, chart, tables, notes), a business letter and a purchase order.
- New parts: `DocPages` (letters and reports), `DocTable`, `DocKpis`, `DocBars`, and the `aside` slot beside the totals.
- The P1 components from the stage 7 inventory, built or upgraded: tenant logo slot, tooltip (on every icon button), text fields with counter and read-only, combobox, radio group and mixed checkbox, date and date range, money input, one-time code, search field, tenant switcher, language and theme switch, data table (selection, bulk bar with money total, sort, row menu, totals row, loading, empty and error), avatar, description list, timeline, toast with Undo, confirm with typed name, drawer, menu and popover, approvals queue, undo bar and document preview. The action proposal now shows two changes then "Show all", and the undo window.
- 10 icons added (87): sort arrows, chevron up, minus, zoom in and out, light, dark and system. A menu icon followed in stage 9 (88).

### Choices made while building (follow the locked rules; not separately decided)
- The JoFotara stamp is in ink, because 6.3 keeps the tenant colour on the title only.
- The stamp and QR appear on page 1 only; continuation pages carry the reference and page number.
- The quote has no stamp (not a tax document); its QR opens the quote online to accept it. The receipt has no stamp; its QR checks the receipt.
- Continuation pages open with a "Brought forward" row.
- Space between blocks is 5 mm on A4 and 3.5 mm on A5.
- Charts on paper are grey with the one bar that matters in ink, values on the bars and no legend, because 6.3 keeps the tenant colour on the title only. On screen, charts use the chart palette.
- Documents an agent made or sent say so in the footer, with the person it acted for (payment reminder, report, leave approval).
- HR documents (payslip, employment letter, leave approval) carry a QR to verify instead of a wet signature where the approval happened in Whity. The employment letter keeps a signature and stamp row because institutions expect one.
- Working days in examples run Sunday to Thursday.
- Calendars start on Saturday and grey Friday and Saturday as the weekend (the Jordan convention); both can be changed per tenant.
- Inputs accept Arabic-Indic digits and the Arabic decimal mark and store Western digits (3.7b).
- Tooltips appear after 500 ms on hover and at once on keyboard focus; they name things and never hold information needed to finish a task.
- Toasts sit at the bottom end corner. Errors and toasts that need action stay until dismissed.
- Agents never get a human face or photo: their avatar is the robot face on the tint. The system's avatar is the mark.
- Typing the name to confirm is kept for the biggest destructive actions (deleting a workspace, revoking every agent); anything that can be undone uses Undo instead of a confirm.
- The drawer is 480 px wide and covers the screen under 768 px.
- 700 weight removed from the tenant logo styles (stage 3: no 700 in the product).

### Still open in the inventory
- None for P1: the agent activity filter and the collapsed and mobile app shell were built in stage 9. P2 and P3 items stay planned (see section 17).

### To check before shipping
- The Arabic amount in words, with an Arabic-speaking accountant.
- The required invoice and credit note fields, against the JoFotara specification.

## Stage 9 · Final guideline (locked 28 Oct 2026)

### Published
- Design system v2.0 final: this register, the README and sections 01–17 (16 Documents and 17 Components new), every section rewritten from this register; 78 component, screen and document cards plus the cover; tokens, handoff files, logo files, social images and the motion clip.
- Brand guidelines PDF 2.0: 65 landscape A4 pages, built from the same sections, with the logo, colour, type, shape and document figures and real renders of the screens and documents.
- The cover: the mark's two planes at cover scale on the 24 grid (platform with the blueprint plus-grid, the sheet blank), a plane-deep block and an ink slab; the name in Readex Pro 600; "A blank sheet, on the platform beneath it."
- Social images redrawn with the v2 mark: the link preview (a sheet on the grey ground, Readex Pro headline, lockup bottom-start) and the avatar (the app icon tile, mark inside the inner 61%).
- The set-down motion clip re-recorded from the v2 components (proposal with its undo window).

### Fixes found while checking v1 content against the register
- Dark-mode control border (`input`) raised to oklch(0.52 0 0), 3.3:1 on the card, so every control border passes 3:1.
- Logo size tokens follow stage 1: horizontal lockup at least 20 px tall, the mark switches cut at 24 px, clear space 25% of the mark height (Flutter constants too).
- The Tailwind theme (`handoff/web/theme.css`) carries the stage 2–4 tokens: tint, status text and tint colours, divider, table header, chart 6, plane shadows, `shadow-float`, control radii, the display face, the plane lift and the blueprint grid.
- No 700 weight left in the component styles.
- Action proposal: the expired state (grey, "Ask again") and the undone state (dashed again, "Undone by …") added beside waiting, approved and declined.
- App shell: the 56 px rail with tooltips from 768 to 1023 px, and a drawer with a menu button under 768 px.
- Agent activity: filter by agent.
- Plan names and prices in cards replaced with placeholders, as stage 5 words require.
- The agent icon is the robot face everywhere (old wording removed from the Icon card and README).
- From the independent check against this register: the label on the main colour is pure white (#ffffff, 6.1:1, decision 2.1), not #fafafa; selected text on the dark tint is the main colour on dark (#59a0f9, 5.1:1; the partner's #e97c47, 4.9:1), decision 7.3; the 20px brand headline tracks −1%; the 700 font files and Geist Mono 600 are no longer declared; empty-state icons are 24px (5.2); menu items have 8px corners (¼ of 32); `Money` shows JOD with 3 decimals; the branding screen derives the whole tenant set from one colour (`Whity.tenantTheme`, decision 2.8); the stale v1 wording in the Button, Mark, Lockup, Icon, Actor chip, Badge, Tabs, Menu, Branding, Money and Page header READMEs was corrected.

### Values set while building (follow the locked rules)
- Large controls are 40px, for a single call to action; corners 10px (¼ rule).
- The stacked lockup's minimum height is 48px (the horizontal stays at 20px, 1.7c).
- Menu and popover items are 32px tall with 8px corners.
- "Bold" in documents (the grand total, 6.6) means weight 600, since 3.6 allows no 700.

### Rule for later changes
- Change this register first, then the files, then republish the guideline and bump its version (2.1, 2.2 …).

## Version 2.1 · P2 components (28 Oct 2026)
Stage 7's P2 items, built to the locked stage 7 rules. Asked and decided on 28 Oct 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| 2.1a | Dark chart graphite | #858585 (see 2.7). |
| 2.1b | Charts in Arabic | Time and values run left to right in both languages, like the numbers on them. Titles, legends and the table view follow the page direction. |
| 2.1c | Command palette | ⌘K (Ctrl K) searches records, pages and actions; the last row always asks an agent, dashed like every agent sheet, and the agent comes back with a proposal to approve. |
| 2.1d | Plan gate | A feature outside the plan shows greyed behind a card naming the plan. Owners get Upgrade and Compare plans; everyone else gets "Ask an owner". Never hidden. Plan names and prices stay placeholders. |

### Built
- Actions: button group, split button (one plane under the main pair), toggle group (pressed = the tint, 7.3).
- Inputs: phone input (+962 default, regional list, grouped as read, landlines 8 digits and mobiles 9 in Jordan, stored as E.164), file upload (dashed drop area, progress, done, error with Try again).
- Navigation: command palette, stepper (3–6 steps; "Step n of total" under 600px).
- Agents: agent run status (running with progress and Stop, waiting dashed with Review, done, failed with the next step and Try again; a compact pill).
- Platform: plan gate.
- Data: bar, line and donut charts on the 2.7 palette, series 1 the tenant's main colour; bars ≤24px with a 4px rounded end, 2px lines, 8px end dots with a 2px ring, one value labelled, a tooltip on every mark, "Show as table" on every chart, one axis only, a donut folds past six slices into "Other".
- Still planned: tag input and signature (P3).

## Document editor (version 2.2, locked 29 Oct 2026)
A Notion-, Gutenberg- and Google Docs–inspired editor for whity-core's Document designer (A33, `/admin/documents`). The design system designs it and defines its components (cards in every state, specs, the TypeScript contract for the document model and props, a TipTap/ProseMirror handoff); the Whity team builds the working editor. Decided one part at a time, starting 29 Oct 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| E.1 | Deliverable | Design-system definition: components, states, rules and contract. Implementation by the Whity team. |
| E.2 | Surface | Blocks (Notion/Gutenberg) on a continuous sheet, with an A4 page view (Google Docs) for what Whity Render prints. |
| E.3 | First version | Merge fields, comments and suggestions, rich blocks. Live collaboration (cursors, presence, version history) comes later. |
| E.4 | Agent edits | Agents write as suggestions: dashed, attributed ("Billing assistant for Amro"), nothing lands until a person accepts. |
| E.5 | Formatting tools | A fixed Google Docs–style toolbar, plus the selection bubble and the / menu. |
| E.6 | Comments and suggestions | Cards in a margin rail beside the text they belong to; a drawer list under 1024px. |
| E.7 | Default view | By document type: templates and anything Whity Render prints open in the page view; notes and wiki pages open continuous. Either can switch. |
| E.8 | Block chrome | Hovering any block shows + and a drag handle in the margin; selecting a table, image, callout or embed shows its own toolbar above it. |
| E.9 | Publishing | Templates have a draft and a published version: edits stay a draft until Publish (the one main action), and Whity Render and agents use the published version. Notes and wiki pages save live, with no Publish. |
| E.10 | Mode without edit permission | Suggesting: they propose changes and comment, and someone who can edit accepts, the same as agents. |
| E.11 | Dark mode | Papers (the A4 page view) stay white, like what prints; notes and wiki (the continuous sheet) follow the theme. |
| E.12 | Preview with a record | Fields show the record's values; comment highlights and suggestions are hidden so the pages look as they will print; the rail says so; read-only until Show fields. |
| E.13 | Block catalogue (v1) | Text, Heading 1–3, Quote, Code · Bulleted, Numbered, Checklist (3 levels) · Callout · Toggle, Columns (2), Divider, Page break · Table, Image (half, wide, full; caption), File · Record and Chart (live), Signature and stamp. |
| E.14 | Callouts | Two kinds: Note (grey) and Important (warning tint), so the success and error colours keep meaning state. |
| E.15 | Tables | Document tables: rows, columns, a header row, end-aligned numbers. Data stays in records and comes in as a Record or Chart block; no databases inside documents. |
| E.16 | Live values | Record and Chart blocks are live in the editor; each PDF freezes the values at the moment Whity Render made it. |
| E.17 | Plugin blocks: rendering | Plugins can add their own blocks. A Verified plugin is a signed declaration, drawn with the Whity SDK components by Whity's block renderer; publishers' own JavaScript never runs in the app. Other screens run in a sandboxed frame, sized by the editor. Security: `VERIFIED-PLUGINS-SECURITY.md`. |
| E.18 | Plugin blocks: printing | A plugin block needs a print view for Whity Render to be used in templates or anything that prints; without one it's available in notes and wiki pages only. |
| E.19 | Plugin blocks: plugin off | The block shows a placeholder ("Needs the Delivery Tracker plugin", with Enable for admins), its data stays in the document, and a template that contains it can't be published. |
| E.20 | Plugin blocks: inserting | A group per plugin in the / menu and + inserter, with its name and logo, after Whity's own blocks; searchable like the rest. |
| E.21 | Saved blocks: sync | People can select several blocks and save them as one block. When saving they choose Synced (editing it once updates every document that uses it) or Copy (inserts a copy people change freely). A synced block can be detached into a copy. |
| E.22 | Saved blocks: sharing | The creator picks Only me or Everyone in the workspace; owners and admins manage the workspace library. |
| E.23 | Saved blocks: per-document parts | In a synced block, the creator can mark text or fields as filled per document (a signer's name, a date); the rest stays synced. Merge fields work inside. |
| E.24 | Saved blocks: packaging | Workspace-only in v1; exporting a saved block as a plugin block comes with the plugin SDK work. |
| E.25 | Highlight | One highlight colour (the sky series at 40%), so colour never carries meaning in a document that may print in black and white. |
| E.26 | Merge field chips | The field's plain name on the tint ("Customer name"); the path (`customer.name`) on hover; one character for the caret. |
| E.27 | Missing values | Preview flags a missing value on the error tint. Whity Render leaves an optional field blank and won't issue a document with a required field missing; fiscal fields are required. |
| E.28 | Markdown shortcuts | On for everyone; ⌘Z straight after turns the shortcut back into what was typed. |
| E.29 | Accepting suggestions | Anyone who can edit the document accepts or rejects suggestions, including an agent's; each decision is logged with their name. |
| E.30 | @mentioning an agent | The agent replies in the thread; anything it changes in the document arrives as a suggestion. |
| E.31 | Agent context | When asked inside a document, an agent reads the document and the records its fields and blocks point to, within its own and the person's permissions. |
| E.32 | Unprompted agents | Agents suggest changes only when asked, or by a routine an owner sets up (for example, checking templates against new tax rules each month); always as suggestions. |
| E.33 | Body size on screen | 16px in the continuous sheet (a step up from the app's 14px); the page view uses stage 6's 9.5pt. Headings Noto Sans 600 at 24, 20 and 18px on screen and 14, 12 and 10.5pt on paper; the title alone is Readex Pro. |
| E.34 | Full width | A per-document Full width switch for wiki pages with wide tables; the 760px sheet (616px of text) is the default. |
| E.35 | Arabic document titles | Readex Pro 600 in its Arabic design, so 3.1 (document titles in Readex Pro) holds in both languages; the brand ships the Arabic subset (`readex-pro-arabic-600.woff2`). The Arabic invoice and every Arabic title follow. Product screens still never use Readex Pro. Asked and decided 29 Oct 2026. |
| E.36 | ⌘K in the editor | With text selected, ⌘K adds a link; with no selection it opens the command palette, as everywhere else (2.1c). Asked and decided 29 Oct 2026. |
| E.37 | Publish checks | Publishing a template is blocked by: open suggestions, a block whose plugin is off (E.19), a block without a print view (E.18), or a field the record type doesn't have. The check lists each with a way to fix it. |
| E.38 | Synced blocks in templates | Editing a synced saved block updates drafts, notes and wiki pages at once; a published template picks up the change at its next publish, so what prints never changes without a person publishing it. |
| E.39 | Comment highlight | Comments mark their text with the warning tint and a warning underline, on screen only; it never prints and isn't the highlight colour (E.25). |
| E.40 | Barcode block | A Whity block: Code 128, EAN-13 or Code 39, with the value typed or a merge field and the text under it optional. Ink only, sized in mm in the page view. Group "Whity" in the / menu. Added when the editor replaced the Document designer's canvas mode. Asked and decided 29 Oct 2026. |
| E.41 | QR code block | A Whity block: a value or a merge field as a QR, with an optional caption. Distinct from the document's verification QR in the footer (6.7), which stays automatic. Asked and decided 29 Oct 2026. |
| E.42 | Maths block | A block (and an inline `math` node) for formulas in TeX, rendered with KaTeX on screen and in print. Group "Basic". Asked and decided 29 Oct 2026. |
| E.43 | Label sheets | A document kind `label`: a sheet (A4, Letter) divided into a fixed grid of labels, each one the same blocks with fields; Print fills one label per record or per row of an uploaded CSV. It opens in the page view and never in the continuous view. Replaces the canvas mode's labels and certificates; a certificate is a one-label sheet. Asked and decided 29 Oct 2026. |
| E.44 | Document themes | A document's look comes from a theme: Whity's standard theme, a plugin's, or the workspace's own, which a document can override for the print colour and the letterhead only. No personal themes. A theme is a closed set of values (print colour, letterhead logo and lines, and the options in E.45-E.47), never CSS; everything else follows stage 6 and E.33. Inheritance: Whity standard, then a plugin theme, then the workspace theme, then the document. The print colour defaults to the workspace main colour (2.8). A published template keeps the theme it was published with until it's published again (as E.38). See `DOCUMENT-THEMES.md`. Asked and decided 31 Oct 2026. |
| E.45 | Accent on headings | A theme option, off by default: Heading 1-3 in the print colour as well as the title. This relaxes 6.3 ("title only") for themes that choose it; the owner's decision, against the recommendation to keep 6.3. Asked and decided 31 Oct 2026. |
| E.46 | Title face | A theme option: the document title in Readex Pro 600 (default, 3.1 and E.35) or in Noto Sans 600 (Noto Sans Arabic 600 in Arabic). Product screens still never use Readex Pro. Asked and decided 31 Oct 2026. |
| E.47 | Large print | A theme option: an 11pt body in print with headings scaled by the same steps as 6.5 and E.33; Arabic stays at 100% of the Latin size (3.7a). Asked and decided 31 Oct 2026. |

Parts: 1 shell (locked 29 Oct, cards EditorShell) · 2 canvas (locked 29 Oct, cards EditorShell, EditorPageView) · 3 blocks (locked 29 Oct, cards EditorBlocks, EditorBlockChrome) · 3b plugin and saved blocks (locked 29 Oct, card EditorCustomBlocks) · 4 inline (locked 29 Oct, card EditorInline) · 5 inserting (locked 29 Oct, card EditorInsert) · 6 collaboration (locked 29 Oct, card EditorCollab) · 7 agents (locked 29 Oct, card EditorAgents) · 8 typography (locked 29 Oct, card EditorType). All parts locked 29 Oct 2026; compiled into version 2.2. E.40–E.43 added on 29 Oct 2026 with the engineering survey (M0), when the editor was chosen to replace both modes of the existing Document designer; they have no cards yet.

## Plugin store (version 2.3, locked 31 Oct 2026)
The pages where a workspace finds, installs and manages plugins (A41). Guideline section 19; cards in "Templates · Plugin store".

| # | Decision | Locked value |
| --- | --- | --- |
| S.1 | Where | In the app first (A41, `/admin/plugins/store`). A workspace installs declarative plugins into that workspace. A code plugin is install-wide, for the whole deployment, and only an operator installs it. A public marketplace site reuses the same pages later. |
| S.2 | Billing | Paid plugins are billed through Whity: one invoice from Whity, which takes the marketplace cut and pays publishers. Prices show as `[price]` until decided. |
| S.3 | Who installs | Declarative plugins: anyone in the workspace whose role has `plugins:install` (S.9). Code plugins: operators only, from the system tenant. Agents can't install directly; with "Ask first" it becomes a proposal like any other action (7.5). |
| S.4 | Trust | Verified and By Whity badges, the permissions a plugin asks for, "used by N workspaces" and its last update. Everything else is labelled Not verified, with a plain warning that its code runs on the server once it's on. A code plugin whose exact version the Whity store reviewed is labelled Verified too, with "Reviewed on app.whity.dev on [date]; its code runs on your server" (8 Nov 2026, VERIFIED-PLUGINS-SECURITY.md 4.1). No ratings or reviews until there are enough installs for them to mean something. |
| S.5 | Agent tools on install | Every tool a plugin adds starts on Ask first; read-only tools may start Allowed. An admin changes modes afterwards in MCP tools, like any other tool. |
| S.6 | Updates | A Verified declarative plugin's update installs automatically; one that asks for new permissions or new outside domains waits for an admin's approval, and the old version keeps running meanwhile. A code plugin only ever updates by hand. Every update is in the audit log. |
| S.7 | Uninstall | The plugin's data is kept 30 days; reinstalling in that time restores everything, then it is deleted. Its blocks show the plugin-off placeholder (E.20) meanwhile. |
| S.8 | Trials | A paid plugin may offer a free trial; the publisher sets the length. Billing starts when it ends, with a reminder before it does. |
| S.9 | Install and upload | Two permissions: `plugins:install` installs from the store (declarative plugins in a workspace; code plugins only in the system tenant, S.3); `plugins:upload` (operators) uploads a .zip of unreviewed code. `plugins:read` sees the pages. Decided 2 Nov 2026 against the built app; narrowed 3 Nov. |
| S.10 | Sidebar | One sidebar item, Plugins. Its page has two tabs, Store and Installed, which keep their own routes (`/admin/plugins/store`, `/admin/plugins`). |
| S.11 | Operator tools | Upload plugin and Reload plugins sit in a ⋯ menu on the Installed tab, for `plugins:upload`. Store sources and their access tokens move to Settings › Advanced. A failed plugin's error and stack trace are in its details drawer, collapsed. |
| S.12 | State after install | Verified declarative and By Whity plugins are active once installed (the Access step is the consent). Anything Not verified installs turned off, with Turn on on the Done step. |

Values set while drawing, open to change:
- Third-party plugins are named for their job, "[Job] for Whity" or a plain name. Names starting with "Whity" are kept for first-party products.
- Trust marks: By Whity in the primary colour, Verified in the success colour, Sandboxed in muted grey with a lock. "Adds" chips on every tile: Screens, Document blocks, Agent tools (primary tint), Fields, Connects out.
- Install is one dialog with a Stepper: Access, Plan (paid only), Set up (only if the plugin declares settings), Done. Outside domains always show in a warning row.
- Installed states: Active, Trial, Update waiting, Failed, Turned off; uninstalled plugins sit under "Recently removed" for the 30 days.

## Home (version 2.4, locked 2 Nov 2026)
The page people land on after signing in, replacing the `/dashboard` placeholder and the Dashboard link to server statistics. Decided 2 Nov 2026, from the Whity team's inventory of the built app:

| # | Decision | Locked value |
| --- | --- | --- |
| H.1 | One home | Everyone lands on `/dashboard`, the sidebar's Dashboard. It is built per person: it shows only the cards for what that person can see and do. System-tenant operators also get the platform cards of the admin dashboard. |
| H.2 | What leads | **Waiting for you**: one list merging Inbox documents and agent Approvals, oldest first, with the decision buttons inline. |
| H.3 | Server statistics | `/admin/stats` becomes **System**, under Platform, for system-tenant operators only. Its numbers also feed the operators' cards on the home. |
| H.4 | Cards | Installed apps can each add one card to the home. People can hide cards but not rearrange them (v1). |

Values set while drawing, open to change:
- The title greets the person by first name with the time of day; the description gives the date, the workspace and their branch.
- Waiting for you shows at most six rows, then "and N more". Decisions are inline outline buttons (Approve beside a ghost Decline or Reject; Open when the item needs reading), so the home has no filled main button. When nothing waits, it collapses to one line.
- Owners and admins see "Finish setting up" until it's done: logo and colour, team, an agent, the tax number (invoices need it) and a plan.
- App cards: the app's logo and name, one number, a few lines, at most one chart; ⋯ has Hide from home and Open; hidden cards are listed at the foot with Show. A card shows only the person's scope.

## Finishing the app (versions 2.5–2.9, planned 2 Nov 2026)
Every screen in the Whity team's web app inventory (develop at `01db4bcd`, 1 Nov 2026) gets a design by 2.9.

| # | Decision | Locked value |
| --- | --- | --- |
| V.1 | Order | 2.5 page patterns and People · 2.6 Forms · 2.7 Documents and routing · 2.8 AI and agents · 2.9 Workspace, billing and settings. The inventory's problems (raw ids, missing confirmations, untranslated strings, kit violations) are fixed in the version that draws each screen. |
| V.2 | Depth | Each screen is drawn in its main state. Loading, empty, error, read-only and access denied are drawn once as patterns (2.5), and each screen's README names its own versions of them. |
| V.3 | Arabic | One Arabic card per version, the busiest screen mirrored; every README lists the Arabic labels. |
| V.4 | Delivery | Register, cards and sections are published every version; the guidelines PDF and the handoff zip are rebuilt once, at 2.9. |

## 2.5 Page patterns and People (locked 2 Nov 2026)
Patterns every later version reuses, then the People screens. Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| P.1 | Row click | Things with history and settings (people, roles, workspaces) open a full record page with its own address. Small things (a group, a delegation, a tag, a unit) open a side drawer. |
| P.2 | Record page | A header with the name, status and key facts, then tabs: Overview, Access, History. |
| P.3 | Editing | Fields are edited where they are. A sticky bar with Discard and Save changes appears as soon as something changes, and leaving asks first. |
| P.4 | Confirming | By how undoable it is. Undoable: it happens at once with Undo. Hard to undo: a dialog that names the consequence. Security-sensitive or irreversible: the dialog plus typing the name. |

Values set while drawing, open to change:
- The sidebar follows the built app's groups in sentence case; only the current group is open, the others fold to their label (`AppShell` nav groups take `collapsed`).
- People are always names with avatars, never ids; units, roles and dates are words. History is written in sentences, never event codes.
- A changed field gets the primary ring and a "Was …" note; the save bar names the changes.
- Filter buttons tint and name their value when set. Status lists use a segmented switch.
- Inbox states are words the app composes and translates: Your decision, To review, For your information, You approved, You rejected, Returned to you.
- A role's Access tab is a grid of areas by See, Create, Change, Delete, Approve; built-in roles are read-only with Duplicate to change.

## 2.6 Forms (locked 2 Nov 2026)
Form Builder, the forms catalogue, filling in, the public form and My submissions (A34). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| F.1 | Builder | The form as it will look, with questions edited in place on a canvas; the selected question's settings (type, required, help, logic, who sees answers) in a side panel. |
| F.2 | Filling | Short forms on one page. A form with sections shows them as steps with a Stepper, saving a draft as people go. |
| F.3 | Public form | The workspace's brand leads (logo and colour); a small "Built on Whity" in the footer. |
| F.4 | Responses | Each form has Questions, Responses and Settings tabs. Responses shows a summary per question, then a table; a response opens as a record with its routing. |

Values set while drawing, open to change:
- Sections carry a bar in the main colour; a selected question gets the primary ring and a small toolbar (move, duplicate, delete); conditional questions show an "Only if …" pill.
- The settings panel includes "Save the answer to", mapping an answer to a field on a record.
- Prefilled answers show as a summary from the person's profile, not as fields to retype. Drafts save on the server.
- The Forms page shows cards, drafts first under "Continue where you left off". My submissions shows where each submission is.
- Public form states: sent (with a reference), closed (with a way to reach the workspace), not available. Buttons name the action ("Send application").

## Ease of use (version 2.7, locked 2 Nov 2026)
Company infrastructure and management screens are used mostly by people over 50. These override earlier values where they differ.

| # | Decision | Locked value |
| --- | --- | --- |
| A.1 | Size | Product text 15px (was 14px, 3.5a); nothing below 13px (was 11px); controls 40px (small 32, large 44; was 36, 28, 40); table rows 48px (was 44); control corners stay ¼ of height (10px). Printed documents and the editor sheet are unchanged. |
| A.2 | Actions | Common actions are visible, labelled buttons. Icon-only buttons always have a text label on hover and focus. ⋯ holds only rare or destructive actions. |
| A.3 | Plain words | Management screens speak in plain words. Permission codes, ids and stack traces appear only when the person turns on **Show technical details** (a personal setting, also on the page where codes matter). |
| A.4 | Applies to | Everything designed so far is updated (home, store, 2.5, 2.6, components) and every screen from 2.7 on follows it. |

## 2.7 Documents and routing (locked 2 Nov 2026)
The library and document record, templates, notes and wiki, route templates and the flow editor, and circulation (A30–A32). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| D.1 | Areas | Library, Templates, Route templates and Notes and wiki stay separate sidebar items, each its own page. |
| D.2 | Flow editor | A top-down flow: stages stacked with arrows; branches (approved, rejected) side by side under their stage; the selected stage's settings in an inspector beside it. |
| D.3 | Circulation | A tab on the document record. Record tabs: Document, Routing, History, Verification. `/admin/document-routing/[id]` redirects to the Routing tab. |
| D.4 | Library | A rail of saved-search folders (Created by me, Awaiting me, Starred, collections and the rest) beside a list or grid, as built. |

Values set while drawing, open to change:
- Route stages: done green with a check, current in the main colour with a ring, next grey; each shows the people reached, the rule and the time.
- Your decision: an optional note seen by everyone on the route, then Approve (main), Reject, Return to the sender, Forward to someone else, and one sentence on what happens next.
- Routes are described in one sentence in the route list. Flow editor branches are labelled on their arrows; the inspector shows how many people a stage reaches today.
- A document's SHA-256 checksum appears only with Show technical details (A.3).

## 2.8 AI and agents (locked 2 Nov 2026)
Whity's own agents, outside AI apps connected to Whity, and what AI can do (A20–A23). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| AI.1 | Naming | **Agents** are Whity's own agents. **Connected AI apps** (was AI principals) are outside apps (Claude, Cursor, ChatGPT and others) signed in to Whity. The sidebar's AI group: Agents, Connected AI apps, What AI can do. |
| AI.2 | New agent | Start from a preset: a gallery of ready agents (Billing assistant, Stock assistant and others); pick one, adjust it, done. Blank is the last card. |
| AI.3 | Agent page | A record page (P.2) with tabs: Overview (what it does and for whom), Instructions, What it may do (Off, Ask first, Allowed), Activity. |
| AI.4 | What AI can do | The MCP tools page becomes **What AI can do**: actions in plain words, grouped by area, with how agents may use each. Tool names, toolsets and token counts appear only with Show technical details (A.3). |

Values set while drawing, open to change:
- The agents list shows "On its own" as counts of Allowed and Ask first actions. Tabs: My agents, Everyone's agents (admins).
- Presets show what each is for, three things it does, and what it needs (an app, or "Works with Whity alone").
- Instructions are a plain text box written as to a new colleague; "What it may read" lists pages and templates; tone and language are choices.
- Connect an AI app: three steps (app, what it may do, set up) with the app's own steps, Copy address, and a dashed waiting line; no filled button on the last step.
- What AI can do columns: Action, Area, Agents (can do it, proposes, suggests only, never), If it goes wrong.

## 2.9 Workspace, billing and settings (locked 2 Nov 2026)
The Settings area, a person's account, billing, the audit log, and the operators' Platform pages (A40–A53, U04). Decided 2 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| W.1 | Settings | One Settings entry in the sidebar; each settings page has a grouped sub-nav beside it (Workspace, Sign-in and security, Email, Data, Advanced) and keeps its own address, as built. |
| W.2 | Operators | Install-wide pages (Tenants, Plans and pricing, Promotions, System, Errors, Notification delivery, and the install's email, storage, feature flags and languages) live in the sidebar's **Platform** group, shown only to the system tenant. Workspace admins never see them. |
| W.3 | Account | A person's own settings in tabs: Profile, Sign-in and security, Sessions and devices, Preferences (language, theme, **text size** Standard or Larger, Show technical details). |
| W.4 | Audit log | Sentences grouped by day ("Amro Saleh changed Ahmad Khalil's unit to Abdoun"), with filters for person, agent, area and date; raw details behind Show technical details. |

Values set while drawing, open to change:
- The settings sub-nav adds a Devices group (Licensed devices), which had no link in the built app.
- Tags and tag groups share one page. Translations shows coverage tiles and "Only missing Arabic" by default.
- Text size Larger is 17px, every size one step up.
- Prices and discounts are typed with MoneyInput in normal units with the currency's decimals; Promotions default to JOD.
- System refreshes every minute and says so. Errors lead with plain words; technical details on request.
- Platform settings: install-wide switches as sentences; Email, File storage, Languages and Error tracking as tabs.

## Whity mobile (version 3.0, coverage complete 8 November 2026)
The phone app, designed mobile first and for one-handed use. Decided one part at a time from 8 Nov 2026:

| # | Decision | Locked value |
| --- | --- | --- |
| M.1 | Scope | A do-on-the-go companion: everything people do away from a desk (approvals, inbox, agents, forms, documents to read and sign, stock counts, notifications). Setup, builders and admin stay on the web. |
| M.2 | Navigation | A bottom tab bar in the thumb zone: Home, Inbox, a centre **Create** button, Agents, More. Apps and settings live in More. |
| M.3 | Platform | One Whity look on iPhone and Android (Flutter), with native behaviour: system back gesture, share sheet, haptics, date pickers and keyboard. |
| M.4 | Main action | A sticky bar at the bottom, above the tab bar, holds the screen's one main button, full width; secondary actions sit beside it or in a sheet. The top of the screen is for reading. |
| M.5 | Size | Body text 17px, nothing below 14px; every tappable target at least 48 × 48. The app follows the phone's own text-size setting. |
| M.6 | Gestures | Customisable per person. By default, swipes only do safe things (mark read, archive, snooze), always with Undo, and approve and decline are buttons. Power users can opt in to swipe to approve and decline in their preferences. |
| M.7 | Search | On list screens the search bar sits at the bottom, above the tab bar; filters open as a bottom sheet. The title stays at the top. |
| M.8 | Security | Face or fingerprint to open the app after a while and to confirm tier-3 actions (instead of typing a name); a 6-digit app PIN as the fallback. |
| M.9 | Swipe limits | With swipe to approve turned on, a swipe approves only tier-1 and tier-2 items, with Undo. Security-sensitive or irreversible items (tier 3) always open and ask for Face or fingerprint. |
| M.10 | Home | Waiting for you shows one decision card at a time by default (next card after each decision); a list is a personal option. Tapping opens the full decision with Approve in the bottom bar. |
| M.11 | Create | The centre button opens a sheet: Ask an agent on top, then six large tiles chosen by role (Fill a form, Scan a receipt, Stock count, New note and others), which each person can change. |
| M.12 | Agents tab | Chat plus activity: talk to your agents (type, or hold to speak) and see what they did and proposed today in one feed. |
| M.13 | Themed by the installation | The app connects to a Whity installation, pulls its design tokens on connection, and styles itself accordingly, so custom installations get their own mobile app for free. |
| M.14 | Connecting | Email first: the person types their work email and the app finds their installation and workspace. Or they scan a QR code from the web app, or type the installation's address. |
| M.15 | Theme scope | The installation's full published tokens (colours, type, corners, logo, name, light and dark), with Whity defaults filling any gap. Guard rails: the 17px and 48px minimums (M.5) always hold, and a colour pair that fails contrast falls back to its Whity default. |
| M.16 | App identity | Free: the Whity app, fully branded inside once connected; its store icon and name stay Whity. Paid add-on: an installation's own app in the stores, with its icon and name, built from the same code. |
| M.17 | Switching | A person can add several installations and workspaces and switch between them; switching re-themes the whole app, and notifications say which workspace they come from. |
| M.18 | Onboarding | First-time mobile screens set up the person's preferences and show them around the app. |
| M.19 | Motion with meaning | Lottie animations enhance onboarding and key moments, and every animation follows resistance and animation principles so it tells the app's story and explains the interaction, never motion just to look good. |
| M.20 | Destructive swipes | Delete and other destructive swipes feel hard and resistant. |
| M.21 | Tier 3 swipes | Swiping a tier-3 card makes it feel stuck: it barely moves, then a popup says tier 3 needs verification and asks for it (Face or fingerprint, M.8). |
| M.22 | Onboarding contents | People may be new to Whity entirely, so onboarding also handles creating their Whity ID (and what comes with it), as well as the story, preferences and the guided tour. |
| M.23 | Lottie, two kinds | Brand animations make the Whity brand make sense (the story). Real UI in motion explains what each preference will mean for the person when they choose it. |
| M.24 | Destructive swipe feel | Heavy, with a point of no return: the row follows the thumb at about a third of its speed, the red grows, a firm haptic tick at 70%; let go before it and it springs back. Undo after. |
| M.25 | Reduce motion | When the phone asks for less motion: Lottie stops on its last frame, movement becomes fades, resistance stays as haptics and colour, and the tier-3 "stuck" becomes a short buzz and the popup. |
| M.26 | Whity ID | One account for every installation: sign in once with Whity ID and reach all your workspaces on any installation. Installations may also keep their own sign-in (Google, Microsoft, their SSO). |
| M.27 | Creating a Whity ID | A combination: email and password as the basic way; signing up with Google or Apple also creates a Whity ID; the phone's passkey is linked to the Whity ID as a trusted device, and from then on the person approves their sign-ins elsewhere from their phone (like Google's prompts). Authenticator apps can be set up too. |
| M.28 | Onboarding order | Story → Whity ID → workspace → preferences → guided tour. |
| M.29 | No workspace yet | Two cases. A **tenant creator** creates a tenant (workspace) on an installation: app.whity.dev or a custom deployment built with Whity. A **customer of a tenant** needs the least resistance possible to sign up and use that tenant's product, on Whity or on a custom installation. |
| M.30 | Tenant creator | Creates the essentials on the phone, then goes: choose the installation (app.whity.dev or a custom address), name, logo from a photo, main colour with a live preview, invite people, and plan and trial if the installation sells plans. The rest happens on the web, with a link emailed. |
| M.31 | Tenant's customer | Arrives by the tenant's link, website or a QR (on a receipt, say); the app opens already in the tenant's brand, and Continue with Apple or Google creates their Whity ID and joins them in one step. No story or tour unless they ask. |
| M.32 | Approving sign-ins | The phone asks "Is this you signing in?" with the place and device; the person taps the number shown on the other screen, then Face or fingerprint. |
| M.33 | Protecting the account | A "Protect your account" step in onboarding: save this phone as a passkey (the default), or set up an authenticator app; required only when the workspace demands two-step sign-in. |
| M.34 | Your picture | Onboarding helps people make their picture: a pattern generated from a random seed, or an uploaded profile photo. |
| M.35 | The patterns | Built from Whity's own shapes (the sheet with its offset, the dashed sheet, the platform), arranged by a seed from the person's Whity ID, so the same person always looks the same. |
| M.36 | Picking | The pattern made from their name, a Shuffle button, and a row of six alternatives to tap. |
| M.37 | Pattern colours | From the palette of the installation where the Whity ID was made, with contrast guard rails; the picture keeps those colours in every installation. |
| M.38 | Photo | Take a photo or choose one, with a round crop; the step comes right after confirming the email; the Google picture is offered first when they signed up with Google; skippable, keeping the pattern. |
| M.39 | Full app pages | Whity mobile gets its full set of pages: Inbox, More, preferences, sign-in and security, and management pages, with the states between them. |
| M.40 | Management on the phone | On-the-go essentials: approve, let people in, invite, pause or remove people, pause agents, see activity and billing, sign documents, fill forms, count stock. Deep setup (roles, route templates, form builder, branding, plugins) opens on the web, with a one-time link emailed and approved on the phone. |
| M.41 | More tab | The person's card on top (picture, name, role, workspace), the workspace switcher, then grouped 56px rows: My work, Manage (admins only), You (preferences, sign-in and security), Help and about. |
| M.42 | Sign-in and security | A status checklist on top ("Well protected, 3 of 4": strong password, this phone as a passkey, recovery codes, authenticator app) with the missing step as a button; then ways to sign in, devices, recent sign-ins, app lock, recovery. |
| M.43 | Inbox tab | Three tabs at the top (Waiting for me, Mentions, Updates); search and filters at the bottom (M.7); swipes as the person set them (M.6). |
| M.44 | Push notifications | Notifications only open the app: no Approve or Decline on a notification. Opening asks for Face ID and lands on that decision. |
| M.45 | Arabic | Fully mirrored: tab order, back arrows, lists, sheets and swipe directions. Numbers, amounts and codes stay Western digits, left to right. |
| M.46 | Which hand | A preference, right hand by default, that moves the main button in two-button bars and steppers to the thumb's side and flips the swipe-to-approve direction. |
| M.47 | Tablets | Same app; from 744 points wide, lists and their detail show side by side and a side rail replaces the tab bar. |

Values set while drawing the picture step (open to change):
- The step is 3 of 5 in creating a Whity ID, before Protect your account; changed later from More › Profile › Picture with the same screens.
- Four pattern layouts (sheet on the platform, stacked sheets, dashed sheet with a dot, sheet on a diagonal band), a slight rotation, at most three shapes so each reads at 24px. Fixed lightness: pale background near the brand hue, the brand colour, its dark (the mark's offset colour), one accent hue.
- The preview is 168px; the six alternatives are 48px with 52px targets; Shuffle and Use a photo sit side by side in the lower half.
- The crop is a 300px circle on a dark screen; a zoom slider and a Turn button make it work with one thumb (pinch still works); "How it'll look" shows 48, 32 and 24px.
- Apple shares no picture, so Apple and email sign-ups start from their pattern. Customers who join a tenant with Google (M.31) get their Google picture without this step.
- Patterns are for people only: agents keep the robot, the system keeps the mark. Removing a photo goes back to the pattern.
- Components: `Avatar pattern=… hue=…`, `W.avatarPattern(seed, hue)` and `W.brandHue(hex)`; two icons added (shuffle, rotate), 123 in all.

Values set while drawing the full app pages (open to change):
- Inbox: Waiting for me is oldest first; Updates group by day and show Undo with the time left; filters are what, from, how long waiting, and order.
- More shows the Manage group only to people with admin rights. Help and about includes a help assistant (an agent), replay the tour, the story, reporting a problem with a screenshot and log.
- Preferences: Display, Using Whity, Feel (haptics, sounds, reduce motion), Data (Wi-Fi only downloads). Swipe left and right are each chosen from a list; swipe right to approve is off by default. Sign-in approvals can't be turned off; quiet hours default 22:00 to 07:00; the lock screen hides amounts by default.
- Security: app lock asks again after 5 minutes by default; three failed Face IDs fall back to the 6-digit PIN; tier-3 decisions always ask for Face ID. Authenticator setup on the same phone uses a direct link or a copyable key instead of a QR. Showing recovery codes asks for Face ID; ten codes.
- People: tabs Everyone, Want to join, Invited; search at the bottom with a + beside it to invite; pausing, resetting two-step and removing a person are tier 3 (Face ID). A request to join gets the Member role unless changed.
- Agents (managing): Pause all agents is the emergency stop; pausing one agent needs no Face ID, since it's safe and reversible; permissions change on the web.
- Documents: To sign first; signing is always Face ID; Return sends it back with a comment.
- Forms: one step per screen, saved as you go; stock count with a 72px stepper, + on the thumb side.
- Offline: tier-1 and tier-2 decisions queue and send later; signing and tier 3 wait for a connection.
- Links to the web work once, for 15 minutes, and the sign-in is approved on the phone.
- Fourteen icons added for these pages (key, archive, barcode, signature, at, fingerprint, shield, text-size, gesture, card, pause, play, attach, ban): 137 in all.

Values set while completing mobile coverage (open to change):
- In Arabic the main button follows the hand setting, not the reading direction: with the right hand (default) Approve stays on the right.
- Notifications name their workspace, hide amounts on the lock screen by default, stack per agent, and open on a press-and-hold with Open in Whity; in-app banners last 4 seconds and never cover the bottom bars.
- Unlocking: Face ID straight away, app PIN fallback, five wrong PINs sign the phone out. A new phone signs in by number matching on the old one or with a passkey; a lost phone uses a recovery code or the admin resets two-step.
- Tier-3 verification lasts 2 minutes. Decline asks for an optional reason and, by default, tells the agent. Editing an agent's draft shows how much changed.
- Receipt scanning marks each read field Sure or Check. Voice: hold to speak, let go to send, slide left to cancel. Unclear requests make the agent ask one question with tappable answers first.
- Search everything is reached by pulling down on Home or setting a list's scope to Everything; results group by kind; nothing found offers an agent.
- The customer app uses the tenant plugin's tabs; payments offer CliQ (default), card and cash on delivery.
- Largest text sizes stack rows and show the tab bar as icons, with labels on press and hold. Screen readers read a decision card as one sentence.
- Tablets: list pane 400 points; sheets become centred dialogs or popovers; actions sit at the bottom right of the detail.
- Sessions end after 30 days by default; an update can be required by the installation; no-access pages offer "Ask for access"; errors show a reference code.
- Section 26 lists the mobile screens as MB01–MB62.

## Work and operations (version 3.1, coverage complete 8 November 2026)

Decided from 8 November 2026. Sixteen areas: documents, flows, agents, agent chats, meetings, inventory, tasks, boards, organisational units, roles and delegations, approvals, approval gating, forms, applications, money and expenses, subscriptions and tiers, appointments.

| # | Decision | Choice |
| --- | --- | --- |
| X.1 | Surfaces | These sixteen areas are designed for web and mobile in full, including setup and builders on the phone. For them, this replaces M.40's "deep setup on the web". |
| X.2 | Boards | Kanban boards for work. |
| X.3 | Meetings | Both: everyday meetings, and formal meeting bodies (committees with members, terms, quorum, minutes and resolutions). |
| X.4 | Applications | Requests people submit (a permit, membership, a job, a grant), with review stages and a decision. |
| T.1 | Agents and tasks | Agents complete their tasks on their own when their scope allows; "Ask first" actions still become proposals. |
| T.2 | Boards and tasks | A board is a view of tasks: each board is a project, columns are task statuses, and the same task shows in lists, calendars and boards. |
| T.3 | Taskaat | Tasks integrate with Taskaat (taskaat.dev), which is the engine: tasks, boards, checks, evidence and decisions live in Taskaat; Whity's Tasks and Boards pages read and write them with Whity's people, permissions, approvals and look. Whity works with Taskaat on the integration. |
| T.4 | Done when | Agents' tasks must have a "done when" check written before work starts, and submit evidence for a person to accept; people may add a check when it helps. A task closed without passing evidence stays "Done, unverified". |
| MT.1 | Minutes | An agent drafts minutes, resolutions and action items from typed notes and an optional recording (everyone told); the secretary approves, the chair signs. |
| AP.1 | Appointments | Customers and staff both book: customers on the tenant's booking page or in the customer app; staff manage calendars of people and rooms, services and availability. |
| IN.1 | Inventory | Stock in every place (counts, transfers, reorder levels, the Stock assistant proposing reorders), plus recipes (what goes into what you make, and its cost) and batches with expiry dates. |
| MO.1 | Money and expenses | Light accounting: expense claims, company cards and petty cash, invoices and payments, budgets, plus a chart of accounts, journal entries and the sales tax return. Companies with an accountant's system export to it. |
| SU.1 | Subscriptions and tiers | Both: the workspace's own Whity plan (Billing), and plans a tenant sells its customers, in tiers, with subscribers, renewals and failed payments. |
| AA.1 | Applications review | Each application type has a form, stages and scoring criteria; reviewers score against the criteria; an agent checks documents first and asks for what's missing. |
| CH.1 | Chat | Agents' chats and people's chats share one chat: agents are part of the team, join direct chats and groups, and are clearly marked (robot face, an Agent tag, who they work for, what they may do in that chat). |
| CH.2 | Chat members | One-to-one by default; any chat can add people and several agents, who hand work to each other in the open. Adding an agent says what it can read (from now on by default), who it works for there, and what it may do. |
| AQ.1 | Approvals in bulk | Reviewing one at a time stays the default; a list view lets ordinary tier-1 items be approved together; tier 2 and 3 always open one by one. |
| AG.1 | Approval gating | Every gating approval asks for Face ID or a passkey; clearing someone's two-step also needs a second admin, who can't be the requester. |
| FL.1 | Flows | Workflows across everything: a trigger, steps where people decide and agents act, conditions and waits. A document's route is one kind of flow. |
| FL.2 | Building on the phone | Flows and forms are built on the phone as a list of steps in sentences, no canvas; a step can be added between any two. |
| OR.1 | Organisation on the phone | Browse units level by level, change someone's role, and hand your decisions to someone while you're away ("I'm away"), with dates. |
| DC.1 | Documents on the phone | Read and sign, comment and suggest, edit text and checklists; tables, fields and templates show and take comments, with editing on the web; an agent helps with text. |

Values set while drawing round 1 (open to change):
- Sidebar: a **Work** group (Tasks, Boards, Meetings, Appointments, Applications) after the top items, and **Operations** (Inventory, Money and expenses); Agent chats joins AI.
- Tasks: tabs My tasks, Assigned by me, Agents' tasks, All; groups Overdue, Today, This week, Later, Done today; List, Board or Calendar. The task panel: Done when (blue box), evidence item by item, the gate (Send back, Accept as done), decisions, and the Taskaat task and sync time. "Open in Taskaat" opens the same task.
- Boards: columns To do, Doing, Awaiting review, Done by default; **Awaiting review is the gate** and can't be skipped by dragging (skipping marks the task unverified). Dragged cards lift onto the plane, tilted 2°.
- Meetings: formal meetings have a blue edge; bodies list chair, secretary, members, schedule and quorum. Resolutions are numbered per meeting (14/1) with votes. Action items become Taskaat tasks when the minutes are approved; agents' items get a drafted done-when check. Recordings are kept 30 days by default.
- Appointments: day and week views with people and rooms as columns; booked blue, checked in green, requests dashed amber; reminders by WhatsApp 24 hours and 2 hours before; cancel or move up to 24 hours before by default.
- Nine icons added (kanban, meeting, appointment, flag, video, record, gavel, evidence, check-in): 146 in all.

Values set while drawing round 2 (open to change):
- Sidebar: Operations holds Inventory, Money and expenses, and Subscriptions.
- Inventory: tabs Stock, Batches, Recipes, Counts, Transfers; a low figure is red; expiring within 7 days is flagged; first expiring, first out. Making a batch takes the ingredients out, adds the product with a batch number and expiry (made date + shelf life), and prints labels; a shortage says what to do.
- Money: tabs Overview, Expenses, Invoices, Payments, Budgets, Accounts, Sales tax. Account names are plain words with the codes beside them. The Bookkeeping assistant sorts bank lines into accounts; matched lines can be posted together, guesses need a choice. The sales tax return is for two-month periods with JoFotara reporting counts; Whity prepares it, the company files it. Over budget changes who approves (here, the owner).
- Subscriptions: retries 3 times over 7 days, a WhatsApp each time with a link to pay, then pause (not cancel); CliQ and card. "Chosen most" comes from real numbers. Cancelling asks once whether to pause instead.
- Applications: others' scores stay hidden until a reviewer saves their own; the average shows once two have scored. Stages in a pipeline with counts; bulk moves with a message template per stage.
- Six icons added (package, transfer, recipe, batch, bank, wallet): 152 in all.

Values set while drawing round 3 (open to change):
- **CH.3, the phone's fourth tab becomes Chat** (it was Agents, M.2 and M.12): people and agents together, with Agents today as its second view. Arabic: المحادثات. Reverse this if the Agents tab should stay.
- The sidebar gets **Chat** among the top items (with an unread count); the AI group keeps Agents, Connected AI apps and What AI can do.
- In chat, agents' messages have a blue edge (web) or a blue-tinted bubble (phone); proposals are dashed cards showing who must decide, and only that person sees Approve; hand-offs and "is drafting" are system lines. Agents read a chat only from when they joined (unless set otherwise, which everyone sees) and never other chats.
- Approvals list: kinds are named Ordinary (tier 1), Check first (tier 2) and Needs verifying (tier 3, with a lock); bulk approval keeps a separate Undo for each item.
- Gating shows request dates and the identity checks done (who called whom, when).
- Agents can be created on the phone in four steps: preset, instructions, what it may do, where it works.

Values set while drawing round 4 (open to change):
- Sidebar: **Flows** joins the Work group; Route templates move into Flows (a document still shows its route on its Routing tab).
- Each flow reads as a sentence with its changeable words highlighted. Kinds of step: trigger (blue), condition (amber, Yes and No side by side), a person decides (a person, a role, a unit's manager), an agent acts (dashed), wait, done. "Describe a flow" lets an agent draft one. Publishing a new version applies to new runs; runs in progress finish on their version. If no one answers: remind after a day, then pass it on after two (per step).
- I'm away: dates, a delegate, what goes to them (approvals in flows, agents' proposals, documents to sign); anything above what the delegate may approve waits; a message for people who write; coming back early ends it.
- Role changes on the phone describe each role in plain words; more powerful roles ask for Face ID; the permissions grid stays on the web.
- Suggesting on the phone shows a green "Suggesting" chip; deletions red and struck, additions green and underlined; the document's owner accepts or rejects; an agent's text help arrives as suggestions.
- Four icons added (trigger, branch, away, org): 156 in all.
- All sixteen areas now have web and phone designs (coverage table in section 27).

## Roadmap (8 November 2026)

| # | Decision | Choice |
| --- | --- | --- |
| R.1 | Near term | The low-hanging fruit joins the plan. Design next: contacts, custom fields on existing records, spreadsheet import, receiving goods and supplier bills, waste and stock adjustments, bank reconciliation, basic reports, the 80 mm receipt, agent notifications by email and WhatsApp. Build after A6: A7 quick wins on built pages, A8 contacts and import, A9 custom fields, A10 tasks and boards on Taskaat. |
| R.2 | Long term | Everything else goes to the long-term plan, in order: Studio (custom records, views, formulas, page builder), the flows engine with code steps, sales and point of sale, full inventory and production, full accounting, HR and payroll, projects with time tracking, building the 3.1 modules, Whity mobile, reporting, integrations, platform (several companies and currencies, test and live, packaging customizations), live collaboration. Section 28. |

## Near-term pages (version 3.2, 8 November 2026)

The roadmap's near-term design items N1–N9 (R.1) are drawn. Values set while drawing (open to change):
- Sidebar: Operations holds Contacts, Inventory, Money and expenses, Subscriptions and Reports.
- Contacts: one record for customers and suppliers (a contact can be both); balances in mono, late red; a contact's page shows the workspace's own fields.
- Custom fields: twelve kinds (short and long text, choose one or several, amount, date, time range, a person, a contact, yes or no, file, formula); usable at once in lists, filters, document merge fields and flow conditions.
- Import: Excel or CSV; Whity suggests matches and fixes what it can (adds +962), says what will be created, updated and skipped before anything changes, and the whole import can be undone for 24 hours.
- Receiving and bills: three-way match (ordered, received, billed) line by line; the Bookkeeping assistant reads the bill and proposes the fix for a mismatch.
- Waste: reasons from a short list; approval above JOD 25 a line by the branch manager; end-of-day reminder at closing time.
- Bank reconciliation: statements uploaded or forwarded; exact matches confirmed together; the assistant's likely matches dashed; the month closes at a zero difference.
- Reports: profit and loss against the previous period, who owes us and whom we owe by 0, 1–30, 31–60 and 60+ days, balance sheet, stock value, sales tax; PDF and a monthly email.
- The 80 mm receipt: 72 mm printable, Geist Mono, sales tax 16%, JOD to 3 decimals, the JoFotara QR, Arabic mirrored with Western digits.
- Agent notifications by email and WhatsApp only open Whity, like push (M.44); at most one per agent per hour except sign-in approvals.

## Websites (8 November 2026)

| # | Decision | Choice |
| --- | --- | --- |
| WS.1 | whity.dev | Two doors, companies first: the page speaks to small companies (what Whity does, agents, made for here, your brand, plans in JOD), with a second door to builders (open source, SDK, MCP) |
| WS.2 | Deliverable | Each site as design-system cards plus build-ready static pages: whity.dev, brand.whity.dev, health.whity.dev, id.whity.dev (sign in, approve on the phone, account), docs.whity.dev (home and an article). Section 29 |
| WS.3 | Languages | English and Arabic, fully mirrored under `/ar/`; the name stays "Whity" in Latin letters |
| WS.4 | People in the designs | The Whity team stands in for every person in cards, pages and docs: Amro Saleh, Awss Haddad, Ahmad Khalil, Ibrahim Mansour, Emad Nasser (Arabic: عمرو صالح، أوس حداد، أحمد خليل، إبراهيم منصور، عماد ناصر). Where a screen needs more than five people, a team first name takes another common surname. Companies stay fictional (Example Bakery and friends) |
| WS.5 | Plans on whity.dev | Prices, limits and features are read from Whity's backend (`GET /api/public/plans`, built from the entitlement bundles of ADR 0010), with fallback to the copy last saved in the visitor's browser, then to the plans built into the page. Contract in section 29 |
| WS.6 | whity.dev hero | Headline kept ("Your company's work, in one place. Agents prepare it. You approve it."); the picture is the web Home with the phone in front, both showing what's waiting for approval; Book a demo opens Whity's own booking page |
| WS.7 | What it does, and module pages | The home section leads with four large cards (invoices and money, stock and recipes, approvals, documents and signing), each with a small screen and a link to its page; the other four sit in a compact row. Each of the four gets its own page from one template (hero with a screen, what you can do, agents, works with, questions, final call), in English and Arabic |
| WS.8 | whity.dev Agents | One proposal visitors try themselves: Approve sets it down on the plane, Undo plays it back, and the three steps light up as it goes; then an "Agents never" list (do more than you allow, have more rights than their person, hide what they did, pass for a person). "Your name on it" lets visitors try a colour on the brand animation; each module page has its own animation |
| WS.9 | whity.dev Made for here | Five points (Arabic and English, JoFotara, CliQ, WhatsApp, dinars to three decimals), each with a small animation, beside a receipt visitors switch between English and Arabic: the layout mirrors, the numbers don't change |
| WS.10 | Small details on the sites | The hero sets down once on load (the web Home lands on its plane, then the phone slides in); approving on the hero's phone updates the web Home behind it, with Undo; sections ease in once (10 px, 400 ms); the main button lifts and its plane grows on hover; clickable cards lift onto the plane; the nav gets a line once the page scrolls. None of it runs when less motion is asked for, and every page works without it |
| WS.11 | whity.dev For builders | A terminal types the real install commands from whity.dev/install once when seen, with Copy and Play again; three cards (open source, build apps, agents can run it) with small animations; one line for agencies ("sell Whity under your own brand") linking to a partners page to design later. The terminal stays left to right in Arabic |
| WS.12 | whity.dev Questions, final call, footer | Eight straight answers, always open (free, growing past 5, agents, where data is kept, leaving, Arabic, developers, who approves), then "Book 30 minutes with the team"; the final call sits on its own sheet on the plane with four ticks (free for 5, no card, Arabic and English, leave with your data); the footer shows live status from health.whity.dev (a plain link if it doesn't answer), the four promises and Back to top |
| WS.13 | whity.dev Security page | One page at `whity.dev/security` (Arabic under `/ar/`): a plain promise and a lock that sets down on view; six areas done properly (signing in, who can see what, agents, risky steps need two people, your data, open to inspection), three ticks each; "What we don't have yet" says plainly there are no certifications or outside audits so far; reporting is by email to security@whity.dev and `/.well-known/security.txt`, four steps, and credit by name (no money for now) in a "Thank you" list that starts empty; hosting is not named yet |
| WS.14 | Mega footer on every whity.dev page | A deep-blue blueprint band: the logo, one line on what Whity is, live status, language and a colour switch (same as my device, light, dark; remembered on that browser); "Talk to the team" with Amman's time and Book a call; all eight things Whity does as white tiles that set down one by one onto a long plane when they come into view; five link columns (Product, Company, Builders, Trust, Your account); a giant faint "Whity" that rises letter by letter onto a dark plane; the legal line, the four promises and Back to top. A soft light follows the mouse. Nothing moves when less motion is asked for, and it all works without scripts |
| WS.15 | whity.dev Partners page | One page at `whity.dev/partners` for four kinds of partner: agencies selling Whity as their own, companies setting it up for clients, plugin developers, and hosts running it for regulated clients. Visitors type their agency's name and pick a colour to see the sign-in page their clients would get (`id.youragency.jo`, no Whity name anywhere). Terms are contact-based for now: no prices, discounts or shares on the page; a call (`app.whity.dev/book/whity/partners`) agrees them partner by partner. "None yet. We're looking for the first." with empty slots. Four straight answers and a final Book a call. This supersedes showing the BM.10 partner price and revenue share publicly until they're set |
| WS.16 | whity.dev About page | One page at `whity.dev/about`: the story is repeated client work ("We kept building the same system. So we built it once, and opened it."), shown as three client copies of the same system that slide together into one base on the plane when the page opens. Facts: started 2026, made in Amman, Jordan (no street address), open source, Arabic and English. The four promises. A short note from the founder, signed Amro K. Saleh, Founder (Whity has one founder; initials, no photo). No registered company name yet, so none is shown. The site keeps saying "the team" |
| WS.17 | Global, not Jordan-only | Whity is a global tool. Marketing never says it is built for companies in Jordan or the region. Hero eyebrow "For small and growing companies"; "Made for here" becomes "Works locally: Local where it matters" (your tax system's e-invoicing with Jordan's JoFotara first, local payments like CliQ in Jordan, your currency to the right decimals); prices are "published, monthly" with no currency named in copy (the plans feed sets the currency); data is kept "in a region your country's data protection law accepts, with an in-country option"; footer: "Open source, for companies anywhere". Jordan stays only as the origin (built in Amman, Amman time for calls) and as examples. Updates the wording of WS.1, WS.9, WS.13, WS.15, WS.16 and how BM.9 is described publicly; BM.9 itself (which regions are offered) is to be re-checked for a global offer |
| WS.18 | Hosted in the EU | Whity's own hosting is in the EU: servers and database on EU servers (the provider is not named), files on Cloudflare R2 (EU), backups on Cloudflare (EU); domains and connections go through Cloudflare's worldwide network, which stores nothing of the customer's. The sites say "Hosted in the EU" (a footer badge linking to the Security page) and "stored in the EU"; no GDPR claim until a lawyer checks it. The Security page lists the providers (what, who, where), as is standard. Data inside one country: we host a dedicated installation there for a fee, or the company runs Whity on its own servers and pays for the Whity services it uses. Replaces the Jordan-region wording of BM.9 and lifts WS.13's "hosting is not named yet" |
| WS.19 | whity.dev Contact page | One page at `whity.dev/contact`. "Talk to us." with Amman's time, and a "Who are you?" card that sends people the right way: new to Whity (book a call, write a message, hello@), my company uses Whity (Help inside Whity, the guides, support@), I run Whity Core myself (GitHub issues, docs, install), agency or host (partners call, Partners page, partners@). A message form (topic chips, name, optional company, email or phone, message) posts to `app.whity.dev/api/public/contact`; if sending fails the message stays and hello@ is offered. Four addresses with copy buttons: hello@, support@, partners@, security@. No promise on reply time ("We read everything") |
| LT.4 | Lottie in the app | The web app and the phone use Lottie at five kinds of moment: empty states, done moments, the first run, waiting, and errors (14 new files, 31 in all). Approving **one** item plays `approve-set-down` (1.2 s) and Undo plays it back; **bulk** approval stays the 200 ms set-down in code (5.4 now covers bulk only). Lively but short: everyday moments 1–1.5 s, first-time and rare ones up to 3 s; empty states once a session per list, first-run scenes once ever; loops only while really waiting. One scene at most on a screen, never blocking, and the words always say what happened. New **Animations** setting (Full, Calm, Off; reduce motion means Off). Cards `TplMoments`, `TmMoments` |
| WS.20 | Install in one line | `curl -fsSL https://whity.dev/install.sh \| sh` (the team writes the script, spec in section 29): checks Docker and Node.js and stops with plain words if one is missing, downloads Whity Core into `./whity-core`, starts PostgreSQL 15 and the app in Docker, sets up the database with sample data (`--empty` for none), prints an admin sign-in with a one-time password, opens localhost:3000; options `--dir`, `--empty`, `--version`. `whity.dev/install` shows the line, the five steps, the manual README steps (no local PHP: `make setup`; the old `composer install` step was wrong) and where next. The home page's builders terminal now shows the one line |
| WS.21 | Privacy page | `whity.dev/privacy`: privacy in plain words (what we keep, why, where: the EU, who can see it, how long, what you can do), the other questions (your customers and staff, cookies: only sign-in and language, agents, changes), and a marked place for the full policy once a lawyer has checked it. Retention numbers in brackets until decided |
| WS.22 | Licence page | `whity.dev/license`: AGPL-3.0 with the Whity Plugin Exception in plain words (you can, you must, the plugin exception), a "Can I…?" table of common cases, the name ("The code is free; the name is not"), and links to LICENSE on GitHub and gnu.org rather than a copy. Marked as a guide, not legal advice |
| WS.23 | whity.dev 404 | The not-found animation, "This page isn't here.", and five ways on: Home, What it does, Plans, Docs, Contact |
| EM.1 | Emails | Fifteen emails in English and Arabic, build-ready in `handoff/emails/` with placeholders: account (invitation, verify email, reset password, new sign-in, two-step turned off), money to a workspace's customers (invoice, quote, payment reminder, receipt), agents (needs your approval, which only opens the app; daily summary at 18:00), workspace and billing (workspace ready; from Whity itself: plan limit, payment failed, Whity receipt). White-label: the workspace's logo, name and colour, "Sent with Whity" removed with white-label. Body 17 px, one main button, dark mode supported. Security emails can't be turned off |

The build plan gains A11 (receiving, waste and bank), A12 (reports and agent notifications) and W1–W5 (the websites, in the order whity.dev, health, id, brand, docs).

## Business model and positioning (8 November 2026)

Approved by the owner on 9 November 2026, for now; trusted reviewers have the one-page summary (English and Arabic) and their feedback may change the numbers.

| # | Decision | Choice |
| --- | --- | --- |
| BM.1 | Positioning | Company-first: "Your company's work in one place. Agents prepare it, you approve it." Builders and agencies come second: "Run it, or sell it as your own." Matches WS.1 |
| BM.2 | Three ways to run Whity | **Whity Cloud** (app.whity.dev, shared): the free way in, with Whity's own apps only, no third-party plugins or custom code. **Dedicated hosting**: we host a company's own installation and the company manages its Whity; needed for plugins and custom code, because plugins need a dedicated installation. **Own server**: Whity Core, free and open source |
| BM.3 | Free limits | Whity's paid services (Whity ID, Whity Render, the mobile app, agents) are free up to **5 staff accounts** and **500 customers a month**. Agents and customers don't count as staff. A customer is someone outside the company who signs in with Whity ID, books, uses the customer app or portal, or pays online; contacts stored in records are always unlimited. The same limits apply on every installation, ours or theirs |
| BM.4 | Paid steps | Plans with clear limits: Free (5 people, 500 customers), Team (25 people, 5,000 customers), Business (100 people, 25,000 customers), then a quote. Extra people or customers come in packs. These numbers are the starting point to test with first customers; plan names and prices stay `[plan name]` and `[price]`. Agent work stays base plus usage |
| BM.5 | White-label, what's paid | A workspace's logo and colour are free on every plan. Paid: its own web address, sign-in page and email sender, removing "Built on Whity", and its own app in the stores (M.16) |
| BM.6 | White-label, how far | Every Whity service is white-labelled on every level, including domain masking: sign-in (id.examplebakery.jo), documents and their QR check links, emails, notifications and the customer app run under the company's own domains, and Whity's name never shows to its customers |
| BM.7 | Hosting fee | A dedicated installation is a flat monthly fee by size (small, medium, large: `[price]`), plus Whity services above the free limits |
| BM.8 | Who hosts the services | Whity is the only host of Whity ID and Whity Render by default, for every installation, under the same limits. The exception is a paid on-premises licence (minimum contract, usage reported back) for regulated companies (banks, clinics, government) and large partners. Self-hosted installations keep working for a while if Whity ID can't be reached (open sessions continue; a local owner sign-in for emergencies). Core keeps supporting standard sign-in (OIDC), so Whity ID wins on convenience, not lock-in |
| BM.9 | Data region | Hosted services run in a region Jordan's data protection law (No. 24 of 2023) accepts for transfers, to be confirmed by a Jordanian lawyer; a Jordan-only setup is offered at a higher price for regulated companies |
| BM.10 | Partners | Agencies resell Whity to their clients under their own brand and choose how: a **partner price** (`[n]`% off; they invoice their clients; full white-label including domains) or a **revenue share** (clients pay Whity; the agency gets `[n]`% every month) |
| BM.11 | Promises | Published on whity.dev and in the docs: **Open stays open** (Core stays open source for good; nothing free moves behind a paywall); **Your data is yours** (export everything any time in open formats; leaving costs nothing; Core works with any standard sign-in provider); **Free means free** (the full product, not a trial, no card; passing a limit warns first and never locks out or deletes); **Plain pricing** (published prices in JOD, monthly for small companies, no hidden fees; charges for hosting, services, white-label and support) |

Open: prices, the partner discount and share, pack sizes, the agent work included in each plan, the hosting sizes. Core is AGPL, so others may host Core commercially; the closed services, the partner programme and the trademark (section 11) are what only Whity sells.

## Lottie (9 November 2026)

| # | Decision | Choice |
| --- | --- | --- |
| LT.1 | Where | The website and the app together: whity.dev (Agents, Your name on it, the module pages), the phone app's onboarding story, and empty and loading states. Inside the product, approving stays the 200 ms set-down in code (5.4) |
| LT.2 | How people interact | Try it yourself: people press Approve and Undo and watch it happen; other animations play once when seen and replay on a click or tap; nothing loops forever except real loading; less motion shows the last frame |
| LT.3 | How they're made | Real Lottie files written in code from the brand's shapes, with no text inside and every colour named after a token, so players recolour them for dark mode and each workspace's colour; editable in LottieFiles or After Effects, and a motion designer can polish them later |

## The web app on a phone (version 3.4, 13 November 2026)

Answers WHIT-1006. Applies to the web app under 640px wide, in the browser and installed (PWA). It reuses the phone app's parts (section 26) so the two look the same. Cards: `PwNav`, `PwLists`, `PwLibrary`, `PwEditor`, `PwCalendar`, `PwSettings`, `PwBuilders`, `PwShortSidebar`; each in English and Arabic, light and dark.

| # | Decision | Choice |
| --- | --- | --- |
| PW.1 | Navigation | **Owner decision:** the bottom tab bar on phones, in the browser as well as installed: Home, Inbox, the centre Create button, Chat, More (M.2, CH.3). Counts on Inbox and Chat. 640–767px keeps the top bar and drawer (`AppShellMobile`); 768–1023px the rail |
| PW.2 | More and Create | More lists everything the sidebar has, in the same groups and order, the account first, Settings and Help last. Create opens the phone app's sheet (M.11) |
| PW.3 | Keyboard | While the keyboard is open the tab bar hides and the screen's main button rides on the keyboard. In the browser our bar sits above the browser's own bar, padded for the safe area |
| PW.4 | Lists | Every data table becomes stacked rows: the title with status and a row menu, then up to three label and value lines. Search with Filter and the main button sit at the bottom (M.4, M.7); Sort is a button by the title with the current order written under it; sort and filters open as sheets. **Select** shows boxes; the bulk bar takes the tab bar's place (main action, Export, More); Clear all / Choose all in the header, and a line offers every match when the screen is all chosen. Active filters are chips with ✕; nothing found offers Clear filters |
| PW.5 | Document library | A Folder button above the list says where you are and opens the folders as a sheet (unit filter, then the tree). Collections are chips under it, Starred first. List or grid by the title; sort beside the folder button; search at the bottom, results split into "In the title" and "In the text" |
| PW.6 | Editor | A full-screen task with no tab bar. Two short lines on top: Back, the title and Publish; then the mode button and the save state. The mode opens a sheet (Editing, Suggesting, Viewing); Suggesting tints amber. The toolbar docks at the bottom and rides on the keyboard: text style, bold, italic, list, insert, comment, More, hide keyboard. More tools is a sheet (Preview, Page view, PDF, Find, Version history, then insert tiles). Publish opens the check as a sheet and waits for required items. Viewing shows Comment, PDF and Share at the bottom |
| PW.7 | Calendar | Day is the phone agenda; Week is a list of day cards; Month is a grid of counts with the chosen day's bookings under it. The range on its own line between ‹ and ›, Today by the title, who and where as chips, three bookings then "n more" when busy, the weekend shaded. Services, Rooms and branches, Hours and time off and Settings switch from the title, as a sheet |
| PW.8 | Settings | The settings menu is its own page: the five groups as titled lists. Coming back marks the page you came from **Last opened**. Pages have a back link, the path in small text, then the title. Groups a role can't open are hidden, with a line saying so. The save bar docks above the tab bar with Discard; search settings at the bottom |
| PW.9 | Builders | **Read-only on a phone** for the route template editor and the role permissions grid: routes as numbered steps in sentences, permissions as one list per area with Allowed / Not allowed in words. A note says why; **Open on a computer** emails or copies the link. Flows stay editable as sentences (`TmFlowsMobile`) |
| PW.10 | Sidebar on short screens | The workspace switcher at the top, and Settings and the account row at the bottom, stay put; only the links between them scroll, with a fade at either end and a line under the workspace once scrolled. The current page is scrolled into view. The account row opens its menu upward (WHIT-827): Account settings, Language, Light or Dark, Help, Sign out. Below 480px tall, groups fold to the current one (2.5) |
| PW.11 | Installing it, and notifications | More offers “Install … on this phone” once, in the browser only (Android: the browser’s prompt; iPhone: three steps). Icon, name (12 characters) and splash come from the workspace’s branding; Whity’s console uses the Whity mark; “Built on Whity” on the splash only where the plan shows it (section 11). Notifications are asked for once from a card that says what they’re for and that there’s never marketing; the system prompt follows the tap, never on page load. They come in the workspace’s name and open the item. Card `PwInstall` |

New icons for these: `folder`, `folder-open`, `grid`, `star`, `keyboard`, `checkbox`, `square` (Tabler, in `handoff/icons/`).

## Screens with no card (version 3.4, 13 November 2026)

Answers engineering's list of 13 November ("Screens with no card"). Every card has English and Arabic, light and dark, people-over-50 sizing and one filled main button. Built on the Settings sub-nav of D1 everywhere it appears.

| # | Screen | Choice | Card |
| --- | --- | --- | --- |
| SC.1 | Messages inbox (WHIT-939) | Status tabs (Open, Waiting for customer, Closed) above a list and the conversation side by side from 768px; All, Mine, Unassigned and search over the list. Internal notes full width on amber with a lock and "customers never see notes". An agent's reply waits dashed with "not sent yet", the time it goes, Cancel and Edit. The composer switches Reply / Internal note and turns amber for a note. Assign and Close in the conversation header. Failed sends say why in red with Try again; no reply permission makes the composer a note | `TplMessagesInbox`, `PwMessages` |
| SC.2 | Customer chat box | A plain card on the workspace's page: logo, name, when it replies. Name and email optional on the first message, the bot check in words above Send. Off, ended, too many messages and a failed bot check each say what to do next | `TplChatBox` |
| SC.3 | Settings › Messages | Three cards: Web chat (on, greeting, link), Email (reply address), Incoming email (address and secret). The secret shows once under "Copy it now"; a new secret asks first and says the old one stops at once | `TplSettingsMessages` |
| SC.4 | Approvals › Decided (WHIT-828) | Grouped by day: outcome as icon and word (approved, approved with changes, declined, undone, expired dashed), what the agent asked and any change or reason, who decided and when. Filters for outcome, agent, who decided and when; Export | `TplApprovalsDecided` |
| SC.5 | Edit before approving | One field per argument with the agent's values; invalid input says what to do and "Approve with changes" waits until valid. Irreversible proposals say "Cannot be undone" and never pre-select Approve | `TplApprovalsDecided` |
| SC.6 | Impact plan (WHIT-829) | Each change is a row with its kind in words: Safe, Changes data (with the number of records), Deletes data (solid red). English and Arabic text where people will see words. Tests under the changes; failures turn Approve off and offer "Ask Whity Builder to fix it". Destructive plans need a tick naming what is lost; "Try it on a copy" opens the test copy | `TplImpactPlan` |
| SC.7 | Issuing an agent credential (WHIT-830) | Four steps: name it by its job, who it acts for (always the issuer), the starting grant (held reads Allowed, the rest Ask first, no-undo never Allowed; permissions the issuer lacks can't be given), the key shown once. Done waits for "I've put the key somewhere safe" | `TplAgentCredential` |
| SC.8 | whity-agent desktop app (WHIT-821) | Tray menu; first run (sign in, tools found, choose and agree); tool rows say "as you" or "as an agent" with Disconnect and Put the file back for 7 days; review shows the command or the file before and after, Allow once never default for commands; quick ask and chat; settings; updates ask; problems say what to do | `TplWhityAgent` |
| SC.9 | Saved blocks library (E.21–E.24) | Documents › Saved blocks: name, Synced or Copy, who can use it, where it's used, when it changed. Where it's used is a side panel (published templates "change at next publish", issued documents never). Delete keeps copies in every use and says so | `TplSavedBlocks` |
| SC.10 | Welcome and Create your workspace (M.29) | Three answers on one signed-out card: Create (name, main language, main colour), Waiting (names the workspace, offers no second one), You need an invitation (the exact address, with Copy) | `TplWelcomeWorkspace` |
| SC.11 | Settings › Sign-up and team invites (WHIT-915) | Off, Approval required, Open with one line each; customer-safe roles only, with sign-ups paused when the saved role gains admin permissions; domains as chips; the link. New: Team invites lets a customer company invite its own people up to a number, starting as "Customer team member". The waiting queue on the same page with Approve, Reject and Approve all | `TplSettingsSignup` |
| SC.12 | Join a workspace (WHIT-915) | In the workspace's own brand: name, email, bot check, Join; the confirm page chooses a password or joins with Whity ID. Eight outcome cards with one icon and one next step each. Unknown workspaces look like closed ones | `TplJoinWorkspace` |
| SC.13 | Settings › Public forms (WHIT-906) | Keys list (name and form, short id, allowed sites, last used, status); issue with the allowed sites as chips; the key shown once; revoke asks and names the form that stops. Operators also see the bot check with a write-only secret and the "no bot check" switch off by default | `TplSettingsPublicForms` |
| SC.14 | Passing a plan limit (BM.11) | A warning, never a block. An amber banner across the app for people who can change the plan; Billing shows a meter per limit ("7 of 5", when it starts again), over in amber with stripes and a word. Near (80%) is a quiet note on Billing only. Members see nothing | `TplPlanLimit` |
| SC.15 | Document themes and the picker (WHIT-785, E.44–E.47) | Documents › Document themes: Whity standard, plugin themes and the workspace's own, with a short form (print colour with the contrast check in words, letterhead logo and lines, headings in colour, title face, large print), a live page preview and "Used by n published templates". In a document, the picker lists themes and then "Only for this document" (print colour, letterhead) | `TplDocThemes` |
| SC.16 | Issuing a document from a template (M4c) | From the record: only templates for that kind, then the published template with the record's values in blue and missing fields dashed red; the bar holds the template's own fields and Issue, which explains the number. Edit before issuing makes a letter people can change | `TplIssueDocument` |
| SC.17 | Two-column sign-in (#1734) | From 1024px: brand side at the start, form at the end. Whity's console uses sheets on paper with the plane under the sheet that holds Verify a document. A white-labelled workspace uses its colour, logo and one sentence, no Whity pattern. Under 1024px only the form, with Verify under it | `TplSignInTwoColumn` |
| SC.18 | Whity ID, more screens | Create your Whity ID, the 6-digit email code, offer a passkey once, reset password, choose a workspace (only with more than one), devices, delete with a warning about workspaces you own | `TplWhityIdMore` |

**The earlier cards engineering asked about**

- `TplAgents`, `TplAgentRecord`, `TplAgentPresets`, `TplAgentInstructions` cover the agents admin page (My agents and Everyone's agents), the agent's page and the preset gallery. Still to build to the old ask (WHIT-821): the persona editor's English and Arabic side by side with tone and toolsets, the "a write can't start Allowed" line, "preset updated: review changes", archive and restore, and stopping use revoking credentials. Use `TplAgentInstructions` and `ScopeGrant` for them.
- `TplAppointments` covers the staff day view by person and room, with requests and services. Week, month and the sub-pages follow PW.7 at every width; the booking sheet (Move, Cancel naming the consequence), a time just taken (409), hours and time off still follow the old ask (WHIT-946, brand-ask-calendar).
- `Preferences` and `TplAccountPrefs` are the account page, not the user menu. The user menu is now drawn: `PwShortSidebar` (PW.10).
- `TplPlatformSettings` is the operator's install-wide switches, not the Settings sub-nav. The sub-nav is the left column of every `TplSettings*` card, now with all of D1's groups and pages (`TplSettingsMessages`, `TplSettingsSignup`, `TplSettingsPublicForms`).

## Engineering's questions (version 3.4, 14 November 2026)

| # | Question | Answer |
| --- | --- | --- |
| Q34.1 | "Hosted in the EU" while app.whity.dev isn't hosted in the EU | **Owner: it is hosted in the EU.** WS.18 stands as written: EU servers and database (provider not named), files on Cloudflare R2 (EU), backups on Cloudflare (EU). Put the claims back on the sites: the footer badge linking to Security, "stored in the EU" where it was. No GDPR claim. If the hosting ever changes, the claims come off the same day |
| E.23a | Stable keys for a saved block's per-document parts | Every per-document part gets a key minted once (`pd_` + nanoid) when the creator marks it, and keeps it through edits, moves, reordering, version bumps and translation. Text carries it on the `perDocument` mark; a merge field in `FieldRef.perDocument`; the block lists its parts in `perDocumentParts` with a label in English and Arabic, `required` and a placeholder. Documents store values in `overrides[key]`, never by position. Copying a part inside the block mints a new key; pasting elsewhere drops the mark. Values for parts a newer version removed stay stored and unprinted (`orphanedParts`), so going back restores them. Old rows migrate once: position `i` → `perDocumentParts[i].key`. Contract: `handoff/editor/whity-document.ts` |
| Q34.2 | Privacy page | Still waiting for the lawyer's text and the retention periods. Keep `SitePrivacy`'s design; don't publish the page with `[date]`, `[30]`, `[35]` showing. Until then the footer links to the current page |
| Q34.3 | The store revamp isn't in the repository | **Owner: section 19 is the store.** `19-plugin-store.md` with `TplPluginStore`, `TplPluginDetail`, `TplPluginInstall`, `TplPluginsInstalled` (and the store module moving to Whity ID) is everything; nothing else is coming. Align the store pages to it and close WHIT-831's wait |

## Form printouts (version 3.4, 14 November 2026)

A form's answers printed as a record, which doubles as the approval certificate when the form has an approval route. Asked for AAU's ethics committee; designed as a generic surface every Whity client builds on. Cards: `DocFormPrintout`, `DocFormPrintoutMore`, `DocFormLooks`, `DocFormBlocks`, `TplFormPrintStyle`. Engine work: `handoff/print/FORM-PRINTOUTS.md`.

| # | Decision | Choice |
| --- | --- | --- |
| FP.1 | What it is | **Owner:** generic and customisable for every client, not an AAU one-off. Any form prints a clean record automatically from its sections and questions; "Customise in the editor" turns it into a template with the new blocks to rearrange |
| FP.2 | Customisation and plans | **Owner:** paid plans and free trials customise every part (header, office details, decision placement, section headings A–F, question layout, long answers, choices, Yes / No / N/A, empty and N/A, signatures, approvals, footer) on a "Form printouts" tab of the workspace's document theme, with a live preview; a template can change a section. Free workspaces print the standard layout below with their logo and colour. **When a trial ends, new printouts go back to the standard layout; printouts already made never change** |
| FP.3 | Standard layout | Letterhead and form title; the decision line on page 1 carrying the office details; questions stacked; choices with every option (☒ / ☐, chosen only above 8); Yes / No / N/A as a word with a mark; N/A sections collapse to one line and unanswered questions print "Not answered"; signatures "Confirmed in Whity" with the drawn signature; approvals as a list; footer with the reference, "Page x of y" and the client's marks |
| FP.4 | Section headings | Six styles: A number and rule, B the Whity mark, C mono eyebrow, D progress rail, E sheet tab, F sheet on its plane. **Owner:** free workspaces use B (the mark's two offset squares carrying the number, in the client's colour); paid plans and trials choose. Like E.45, this lets the print colour reach section headings |
| FP.5 | The decision | **Owner:** both — a short line on top of page 1 and the full block at the end: the decision in words with its mark (Approved, Conditionally approved, Revisions required, Not approved), reference, title, applicant, dates, validity, conditions (always printed, even "None") and the verification QR |
| FP.6 | Approvals | **Owner:** a list with decisions: one row per approver with decision, name, role, date and time. Members who supervise the project are left out automatically, and a "Not counted" line names them and why. Signature cards are the alternative style |
| FP.7 | Signatures | **Owner:** "Confirmed in Whity" with date and time, plus the signature drawn on a touch screen (phone, tablet, laptop touch screen). None drawn: a dashed line and "No drawn signature". Paper lines are an optional third style |
| FP.8 | Questions | **Owner:** stacked and two columns are both options. Number and question 9 pt 600, guidance 8 pt grey, answer 9.5 pt 9 mm in; long answers flow across pages with "continued" and a word count ("142 of 300 words") |
| FP.9 | Empty and N/A | **Owner:** collapse a section answered N/A throughout to one line, and print "Not answered" for an unanswered optional question; themes may hide them or print everything |
| FP.10 | Footer and header | **Owner:** footer with the reference, "Page x of y" and the client's marks (theme images, such as ISO or accreditation); the QR stays in the decision block, with an optional small QR on every page. From page 2 a running header replaces the letterhead |
| FP.11 | Document blocks | Every print block specified at print size, with today's blocks, changed ones and the new ones, in English and Arabic (`DocFormBlocks`) |
| FP.12 | Example organisations | Design System cards use fictional clients (Example University, Example Bakery, Example Clinic). A real client's version, such as AAU's with its crest and marks, is configured in its workspace and kept outside the shared system |

## Every surface (version 3.4, 14 November 2026)

A sweep of every route and everything Whity sends or shows outside the app (117 routes against the app map). These were the surfaces with no design; each now has a card, and every row of the app map now names one.

| # | Surface | Choice | Card |
| --- | --- | --- | --- |
| SU.1 | Verifying a document (`verify/[token]`) | The page a printed QR opens: no sign-in, in the issuer’s brand, English or Arabic from the browser. One answer per card: genuine (green seal), a newer version exists (amber), withdrawn or not found (grey, never red). Genuine lists what can be compared with the paper (document, issuer, date, reference, revision, pages) and never the content. Withdrawn is told only to people who can open the record | `TplVerifyDocument` |
| SU.2 | Payment return (`billing/return`) | One centred card with one answer: active (plan, price, renewal, how it was paid, Go to billing), checking (dashed, people can leave), not confirmed yet, failed. Says plainly the plan didn’t change; never “error” | `TplPaymentReturn` |
| SU.3 | Calendar on a computer (WHIT-946) | Day, Week, Month; who and where as filters; Services, Rooms and branches, Hours and time off, Settings as tabs. Week starts on the workspace’s first day; a red line for now; bookings in the primary tint, events grey, waiting for payment dashed. A booking opens a side sheet (Move, Message, Cancel, and what cancelling does to the deposit). A time just taken (409) says so and saves nothing. Several hour ranges a day | `TplCalendarStaff` |
| SU.4 | Agent persona editor (WHIT-821) | Instructions in English and Arabic side by side, 4,000 characters each; tone, language, tool groups, where a person’s grant starts (never Allowed for writes). Preset changes offer “Review the changes” with insertions and deletions. Archive keeps history; Stop using names what stops | `TplAgentPersona` |
| SU.5 | WhatsApp and SMS to customers | Fixed templates with placeholders, English and Arabic, matching the email of the same moment: invoice, reminder, receipt, booking confirmed and reminder, order ready, sign-in code. Buttons open the customer’s page on the workspace’s domain | `MsgCustomerMessages` |
| SU.6 | Link previews (Open Graph) | Whity’s pages: sheets on paper, the lockup, one Readex Pro sentence, the address in mono. A workspace’s public pages: its colour and logo, never Whity’s pattern. Shared documents never preview content: issuer, title or reference, “private link”. Text inside 1080 × 560; generated by Whity Render | `SiteShareCards` |
| SU.7 | The installer in a terminal (WS.20) | Checks, two questions, four steps, the address to open. ✓ green, ✗ red, ! amber, each with one line on what to do and “Nothing was changed” when it stops. No stack traces (`--verbose` for detail); colour only when supported; Arabic when the system is | `SiteInstallerTerminal` |
| SU.8 | Emails, the rest | Bookings (confirmed and moved carry an `.ics` with one UID for the booking’s life; cancelled sends METHOD:CANCEL), form received, form decision with the printout PDF, sign request, join approved and declined, message reply (threads back into Messages). Each in English and Arabic; 25 emails in all | `EmailBookings`, `EmailForms`, `EmailJoining` |
| SU.9 | Cards in dark mode | Every card follows the Design System’s theme, Arabic cards included (they set `dir` and `lang` after load so the theme can apply). Email cards show the emails’ own dark-mode styles; printed pages stay white on a dark ground | all |
| SU.10 | A customer’s own booking (`/booking/[token]`, WHIT-945) | The page every booking email and message links to: no sign-in, in the workspace’s brand, English or Arabic. The booking, then Add to my calendar (main), Move it, Cancel it, and the online deadline in a sentence. Moving: the next days and free times as 44px buttons, taken times crossed out, the button names the new time. Cancelling names what happens to the deposit first; the reason is optional and goes to Messages. Inside the cut-off (24 hours by default) the actions become Message and Call; a time just taken says nothing changed; an old link points to the latest email | `TplBookingManage` |

## Carried forward, to be re-confirmed in their own stage
- The name is "Whity", with a capital W, in Latin letters only. There is no Arabic name, logo or brand copy.
