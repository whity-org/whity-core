# Form printouts: engineering handoff (3.4)

A form response prints as a record; with an approval route it is also the certificate. Decisions FP.1–FP.12 in `00-decision-register.md`. Cards: `DocFormPrintout` (the long case), `DocFormPrintoutMore` (Arabic, other clients), `DocFormLooks` (three customised looks), `DocFormBlocks` (every block with its spec), `TplFormPrintStyle` (the settings).

## How it is built

1. **Auto layout.** Whity Render lays out any form response from the form's sections and questions using the blocks below and the workspace theme's `form` options. No template needed.
2. **Customise in the editor.** Turns the auto layout into a template (document kind `template`, record type `formResponse:<formId>`) made of the same blocks, which people rearrange. Templates keep the theme they were published with (E.44).
3. **Plans.** `form` options other than the standard layout apply only on paid plans and trials. When a trial ends, new printouts use the standard layout; existing PDFs are frozen and never re-rendered.

## Theme options (`DocumentThemeTokens.form`, in `handoff/editor/whity-document.ts`)

| Key | Values | Standard |
| --- | --- | --- |
| `header` | `letterhead` · `centred` · `bilingual` | `letterhead` |
| `officeDetails` | `decisionLine` · `strip` | `decisionLine` |
| `decision` | `topAndEnd` · `top` · `end` | `topAndEnd` |
| `sectionStyle` | `number` (A) · `mark` (B) · `eyebrow` (C) · `rail` (D) · `tab` (E) · `plane` (F) | `mark`; free workspaces always `mark` |
| `questions` | `stacked` · `twoColumns` (a template may set it per section) | `stacked` |
| `longAnswers` | `countAndContinued` · `plain` | `countAndContinued` |
| `choices` | `allOptions` · `chosenOnly` (more than 8 options always chosen only) | `allOptions` |
| `yesNo` | `word` · `threeColumns` | `word` |
| `empty` | `collapseAndMark` · `hide` · `printAll` | `collapseAndMark` |
| `signatures` | `confirmedAndDrawn` · `confirmedOnly` · `withPaperLines` | `confirmedAndDrawn` |
| `approvals` | `list` · `cards` | `list` |
| `footer` | `{ reference: boolean; pageCount: true; marks: boolean; qrEveryPage: boolean }` | all on but `qrEveryPage` |
| `marks` | theme images (max 3, 8 mm tall) shown at the footer's end side | none |

## What the print engine needs (block sheet 8)

1. **Section heading styles A–F** from `form.sectionStyle`; B draws two rounded squares (6.8 mm, 1.9 mm radius, 0.7 mm outline) and the number as vector shapes; D a row of bars; E and F a 10% tint.
2. **Tints and boxes:** a 10% tint of the print colour; a bordered box with a radius (decision block, office strip, signature cards, record card).
3. **Choice with every option:** ☒ / ☐ per option, chosen only above 8.
4. **Collapsing and hiding:** a section all N/A prints as one line; unanswered questions print "Not answered"; theme modes hide or print everything.
5. **Running header and footer:** page 2 onward; footer reference, "Page x of y", theme images, optional QR on every page.
6. **"continued":** a question whose answer breaks across a page repeats its number and question at the top of the next page.
7. **Word count** under answers with a limit.
8. **Signature row:** name, role and "Confirmed in Whity" beside the drawn signature image (captured on a touch screen, stored as SVG), on a line; a dashed line when none was drawn.
9. **Decision, approvals and office blocks** bound to the form's approval route; approvers who supervise the project are excluded and listed as "Not counted".
10. **Letterhead layouts:** centred, and bilingual (English and Arabic either side of the logo).
11. **Two-column questions** per section, as a borderless table with hairlines.
12. **Plan gate** on the theme's Form printouts tab (PlanGate on free workspaces).

Everything else (headings, paragraphs, tables, facts grid, images, QR and barcodes, lists, callouts, dividers, page breaks) uses the blocks that exist.

## The AAU case

AAU's ethics application is this surface with: `header: letterhead`, the crest as the logo, ISO 9001:2015 and the quality assurance mark as `marks`, references `IRB-AAU {order}/{meeting}/{year}` (human) and `AUB-AAU …` (animal), nine sections, the committee approvals with supervisors excluded, and the faculty and dean recommendations as official-use questions. Its crest and marks live in AAU's workspace, not in the design system.
