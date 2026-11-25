# 39. Workspace templates SDK, and one plugin per template

Status: **proposal**, waiting on the owner's answers in §9. Nothing here is built yet.

Owner, 25 Nov 2026: "we need from you to also help us create a better SDK for the templates feature" and "remove all baked in templates to their plugins please". The SDK stays on 1.x and only adds (owner, 24 Nov).

## 1. What there is today (develop at SDK 1.168)

| Part | Where | What it does |
|---|---|---|
| `PluginWorkspaceTemplatesInterface::getWorkspaceTemplates()` (1.116; 2.0 name in `Contribute\`, 1.161) | sdk | A plugin returns manifests as arrays |
| `Manifest\WorkspaceTemplateValidator` | sdk | Checks one manifest's shape; a bad one is dropped with its reasons in the log |
| `PluginLoader::getWorkspaceTemplates()` | core | Templates of the plugins on in this workspace; the first plugin to offer a key keeps it |
| `TemplateCatalogue` | core | The plugins' templates, then **Start blank** (core's own, the only template baked into core) |
| `TemplateApplier` | core | Applies a manifest as the person, through `WorkspaceSetup` routes: adds what is missing, never overwrites, each part fails alone |
| `TemplateRuns` + migration 305 | core | `workspace_template_runs` (one per workspace and key: version, plugin) and `workspace_template_items` (what a run made, examples marked) |
| `TemplateSetup`, `WorkspaceTemplatesApiHandler` | core | Guided setup, offer, locked dashboard card, apply, remove examples |
| Picker, guided setup, examples banner | web | `/setup/template`, dashboard |
| The seven templates and seven themes | whity-plugins `workspace-templates` 0.3.0 (one free package) | Coffee house, Study house, Meeting rooms, Salon, Clinic, Bakery or café, Car dealership |

What the owner asked for and what is missing:

1. **Ship:** the manifest covers settings, booking rules, hours, services, roles, forms, documents, examples, guided setup, offer and theme. It has **no pipelines**, and a template plugin still needs PHP (`plugin.json` cannot declare templates).
2. **Compose and extend:** nothing. A template cannot build on another, and a plugin cannot add to someone else's template.
3. **Test and preview:** nothing. No dry run, no "what this adds" before applying, and plugin authors test only the shape (`WorkspaceTemplateValidator`), never what applying does.
4. **Adopt and upgrade:** applying again already adds only what is missing, but nobody is told a newer version exists, there is no way to see what an update adds, and a run records only one version.

## 2. The SDK, by capability

Every item below is additive: new optional interfaces, new optional manifest keys, new classes. Nothing that exists changes meaning.

### 2.1 Ship: the manifest and `plugin.json`

- **New manifest keys** (each optional, each checked by `WorkspaceTemplateValidator`):
  - `pipelines`: up to 5 review routes for the template's forms or documents, see §3.3.
  - `extends`: the key of the template this one builds on (§2.2).
  - `changes`: what each version added, `[{version, summary: {en, ar}}]`, newest first, at most 20. Shown when an update is offered (§2.4).
  - `requires`: `{sdk: "^1.17x"}` is already the plugin's; nothing new here. A template that needs a core feature says so with the plugin's SDK constraint, as today.
- **`plugin.json` declares templates** (D4, like `themes` in 1.163): `"workspaceTemplates": ["templates/clinic.json"]`. `Manifest\ManifestPlugin` answers `getWorkspaceTemplates()` from the files, so a template plugin is a folder of JSON and a `plugin.json`, with no PHP. `whity-cli plugin validate <dir>` checks the templates offline.
- **A typed builder** for authors who prefer PHP: `Templates\WorkspaceTemplate::make('clinic', 3)->name(...)->service(...)->toArray()`, which returns exactly the array the validator checks. Arrays stay the contract; the builder is sugar.
- **A JSON schema**, `resources/workspace-template.schema.json`, generated from the validator's constants, for editors and CI outside PHP.

### 2.2 Compose and extend

- **`extends`** (a template builds on another): `dental` says `"extends": "clinic"`. Core resolves it when the catalogue is read:
  - Lists (`services`, `roles`, `forms`, `documents`, `pipelines`, `examples.*`, `setup.steps`, `setsUp`) merge **by key**: the parent's first, then the child's. A child item with the same key replaces the parent's.
  - `remove: {services: ["check-up"], ...}` drops a parent item from the child.
  - Objects (`settings`, `calendar.settings`) merge key by key, the child winning. `calendar.hours`, `offer` and `theme` are the child's when it has them, else the parent's.
  - At most 3 levels; a loop or a missing parent drops the child with a logged reason. The parent must be on in the workspace, so the child's plugin lists the parent's in `getPluginDependencies()`.
  - The parent is not listed twice: a child marked `"hidesParent": true` hides the parent's card where the child is offered (for example a "Clinic" plugin replaced by a more specific one). Default: both show.
- **Extensions** (a plugin adds pieces to another plugin's template): new optional interface `Contribute\PluginWorkspaceTemplateExtensionsInterface::getWorkspaceTemplateExtensions()`, also declarable in `plugin.json`. An extension is `{key, version, target, setsUp, services, roles, forms, documents, pipelines, examples, setup.steps}`: **only additive parts**, no `settings`, `calendar`, `offer`, `theme` or `remove`. It applies with its target when its plugin is on in the workspace, and is recorded as its own part (§2.4), so it upgrades on its own. A future paid vertical plugin (for example Clinics) adds its own pieces to the Clinic template this way, instead of editing it.
- Keys an extension adds are namespaced by core as `{extension key}.{item key}`, so two extensions never collide with each other or with the template.

### 2.3 Test and preview

- **One engine for apply, dry run and tests.** The apply logic moves from core's `TemplateApplier` into the SDK as `Templates\TemplateEngine`, which works only through two interfaces: `Setup\WorkspaceSetup` (core's routes, as today) and a new `Templates\TemplateLedger` (what each run made). Core gives it the real routes and a database ledger; the dry run and the test helper give it their own. So the preview, the test and the real apply run the same code.
- **Dry run:** `Templates\DryRunWorkspaceSetup` wraps the real routes: reads go through, writes are recorded and answered with a made-up id, and nothing is saved. The ledger is in memory, seeded from the workspace's real runs.
- **Preview API:** `POST /api/workspace/templates/{key}/preview` with the same body as apply (`examples`, `theme`), under `settings:write`. It answers the plan: for each part, each item with `add`, `keep` (already there), or `skip` and why (for example "you do not hold `contacts:write`"). Nothing changes and nothing is audited.
- **Test helper for authors:** `Testing\WorkspaceTemplateTestCase` (PHPUnit), with an in-memory workspace (`Testing\FakeWorkspace`) that answers every `WorkspaceSetup` route the engine uses:
  - `assertTemplateValid($manifest)`: the validator, composition resolved, and the house rules (both languages, example names from WS.4, `@example.com`, prices as text in normal units).
  - `applyTemplate($manifest, examples: true)`: returns the plan and the fake workspace after it.
  - `assertIdempotent($manifest)`: a second apply makes nothing.
  - `assertUpgradeKeepsData($old, $new)`: applies the old version, edits and deletes some of what it made as a person would, applies the new version, and fails if anything was deleted, overwritten or brought back.
  - `assertExtends($child, $parent)` and `assertExtension($extension, $target)`.
  The official template plugins use it in their own CI. Core keeps a real-engine test that runs every official template against Postgres.

### 2.4 Adopt and upgrade

- **Parts and versions.** A run records each part it applied with its version: the template itself, each parent it extends, each extension. New table `workspace_template_parts` (run, part key, plugin, version, applied at). The run's `version` column stays and keeps meaning the template's own version.
- **An update is offered** when any part's current version is higher than the one recorded. `GET /api/workspace/templates` adds `update: {from, to, changes[]}` to a chosen template's card, and the dashboard shows one setup point, "Your {template} template has new pieces", to holders of `settings:write`. Never automatic.
- **Upgrading** is `POST /api/workspace/templates/{key}/apply` again (the preview first, §2.3). The rules that make it safe:
  1. **Never deletes.** An item dropped from a newer manifest stays in the workspace and in the ledger.
  2. **Never brings back.** An item the template made and the person later deleted or archived is not made again. (Today this already holds for services, roles, forms and documents through the ledger; examples follow the same rule.)
  3. **Never overwrites.** Settings only while still at the default; booking rules only the first time; an item already there by key or name is kept as the workspace has it. A changed item in a newer manifest (a new price, a new form field) is **not** pushed into the workspace's copy; the preview lists it as "changed in the template, yours kept".
  4. **Examples are not added by an upgrade** unless the person asks, and never after "Remove examples".
  5. **Idempotent.** Applying the same versions twice makes nothing the second time.
- **Adopt** (applying to a workspace that is already running) is the same apply, unchanged from today.

### 2.5 What each new SDK type is

| SDK type | Kind |
|---|---|
| `Contribute\PluginWorkspaceTemplateExtensionsInterface` | new optional interface |
| `Templates\TemplateEngine`, `Templates\TemplateLedger`, `Templates\MemoryLedger`, `Templates\DryRunWorkspaceSetup`, `Templates\TemplateMoney` (1.169, PR #2470); `Templates\TemplateComposer` (B) | new classes and interface; core implements the ledger in the database, `MemoryLedger` in memory |
| `Templates\WorkspaceTemplate` (builder) | new class |
| `Testing\WorkspaceTemplateTestCase`, `Testing\FakeWorkspace` | new test helpers |
| `Manifest\WorkspaceTemplateValidator` | new optional keys (`pipelines`, `extends`, `remove`, `hidesParent`, `changes`) and a new `extensionErrors()`; existing manifests stay valid. Older hosts refuse a manifest with a new key, so a plugin using one requires that SDK minor (as `theme` did in 1.131) |
| `plugin.json` `workspaceTemplates`, `workspaceTemplateExtensions` | new optional keys |
| `WorkspaceSetup::ROUTES` | grows by the routes pipelines need (§3.3) |

## 3. The manifest schema (v2, additive over v1)

The v1 keys stay exactly as `docs/wiki/Plugin-Workspace-Templates.md` lists them. Added:

```json
{
  "key": "dental",
  "version": 1,
  "extends": "clinic",
  "remove": {"services": ["check-up"]},
  "name": {"en": "Dental clinic", "ar": "عيادة أسنان"},
  "services": [{"key": "cleaning", "name": {"en": "Cleaning", "ar": "تنظيف"}, "durationMinutes": 45, "prices": {"USD": "60.00", "JOD": "40.000"}}],
  "pipelines": [{
    "key": "treatment-plan",
    "name": {"en": "Treatment plan approval", "ar": "اعتماد خطة العلاج"},
    "for": {"form": "patient-intake"},
    "stages": [
      {"key": "reception", "name": {"en": "Reception checks", "ar": "تدقيق الاستقبال"}, "role": "reception"},
      {"key": "doctor", "name": {"en": "Doctor approves", "ar": "اعتماد الطبيب"}, "permission": "forms:review"}
    ]
  }],
  "changes": [{"version": 1, "summary": {"en": "First version", "ar": "الإصدار الأول"}}]
}
```

### 3.1 Versioning rules

- `version` is a whole number from 1 and goes up by at least 1 on every change; a plugin release that lowers it is refused by the validator against the installed one (core logs it and keeps offering the higher version it has).
- Keys of items (`services[].key`, `forms[].key`, ...) are **forever**: renaming a key makes a new item and leaves the old one in the workspace. The test helper fails a new version that drops a key without listing it in `remove` (§2.2) or in `changes`.
- The template `key` is forever too. It is what ties a workspace to its template across plugin moves (§5).

### 3.2 Composition rules

As §2.2. The composed manifest is checked again after merging, so a child cannot make the result invalid.

### 3.3 Pipelines

A pipeline is a review route in Flows (document route templates, section 23) made for one of the template's forms or documents. Each stage names a role from the template (`role`) or a permission (`permission`); people are never named. Applying makes the route template through `POST /api/document-route-templates` and `PUT .../{id}/graph` as the person, and attaches it to the form. The person needs `route_templates:write`, or the pipeline is skipped with the reason. **Owner question 1 asks whether this is what "pipelines" means.**

## 4. Where templates live: one plugin per template

- **Start blank stays in core** and is the only template core ships.
- Each of the seven becomes its own free plugin in whity-plugins, data only (a `plugin.json` and its JSON), carrying **its template and its own theme**:

| Template key (unchanged) | Plugin (folder / name) | Theme it carries |
|---|---|---|
| `coffee-house` | `template-coffee-house` / `CoffeeHouseTemplate` | `templates.roastery` |
| `study-house` | `template-study-house` / `StudyHouseTemplate` | `templates.library` |
| `meeting-rooms` | `template-meeting-rooms` / `MeetingRoomsTemplate` | `templates.studio` |
| `salon` | `template-salon` / `SalonTemplate` | `templates.salon` |
| `clinic` | `template-clinic` / `ClinicTemplate` | `templates.clinic` |
| `bakery` | `template-bakery` / `BakeryTemplate` | `templates.market` |
| `car-dealership` | `template-car-dealership` / `CarDealershipTemplate` | `templates.showroom` |

  Display names: "Clinic template" / "قالب العيادة", and so on. Theme ids keep their `templates.` prefix so a workspace that chose one keeps it (§5).
- A future vertical plugin (Clinics, Bakeries...) **extends** its template with an extension (§2.2) rather than replacing it.
- `workspace-templates` is emptied one template at a time as each plugin ships (its version goes up and the template leaves it), then retired from the store catalogue with a README note, like Salons (35 §7).

## 5. Workspaces that already used a template

- **The key is the link.** `workspace_template_runs` keys runs by template key, and the catalogue finds a template by key whatever plugin offers it, so a run made from `workspace-templates` keeps its guided setup, offer, examples and update from `ClinicTemplate`. `plugin_name` on the run is updated the next time the run is read or applied.
- **No gap and no double.** For each template, one release does both: the new plugin is published with the key, and `workspace-templates` drops it in the same store release. If both are installed for a moment, the loader keeps the first to offer a key, and the two manifests are identical, so nothing differs.
- **On everywhere the package was.** Each new plugin is installed with "Every workspace" access, as the package is. For a disk install, the move PR adds a one-time step to core: when a plugin first offers a template key that a workspace has a run for, it is turned on in that workspace if `WorkspaceTemplates` was on there. (A migration cannot do it, because the plugin is not installed when migrations run.)
- **Themes:** the theme ids do not change, so `branding_theme` keeps pointing at the same theme, now shipped by the template's plugin. A workspace using a template theme keeps it even if the template plugin is later turned off there, as today's rule for plugin themes already says (TH.13).
- **Ledger and versions:** migration 358 gives every existing run its `template:{key}` part at the run's `version`, so the first update offer compares like with like.

## 6. What people see that changes (register rows, proposed)

| Id | Decision (proposed) |
|---|---|
| WT.1 | **See what a template adds before applying it.** Choosing a card opens "What this adds" from the dry run: each part with Add, Already there, or Can't add and why, in plain words. Apply is the one filled button. |
| WT.2 | **Template updates are offered, never automatic.** When a template a workspace uses gets new pieces, a setup point on the dashboard and a line on Settings › Workspace say so; "See what's new" opens the same preview, listing what will be added and what changed in the template but stays as you have it. |
| WT.3 | **An update never deletes, overwrites or brings back.** What was removed from the template stays in the workspace; what the person deleted is not made again; their edits are kept. |
| WT.4 | **One plugin per template.** Each template is its own free plugin carrying its theme; Start blank stays in core. Settings › Plugins lists them as "Clinic template" and so on. |

No new card: WT.1 and WT.2 reuse the picker and guided setup screens. A brand ask covers the preview panel and the update point.

## 7. Build order (each its own PR; numbers from the orchestrator)

1. **SDK: engine, ledger, dry run, test helper** (SDK 1.169, migration 358, WHIT-1409, PR #2470). Core's `TemplateApplier` becomes a thin wrapper over `TemplateEngine`; behaviour unchanged; preview API.
2. **SDK: manifest v2** (SDK minor B): `extends`, `remove`, `hidesParent`, `changes`, extensions interface, composer, `plugin.json` keys, schema, builder. Update offers on the list API and dashboard point.
3. **SDK: pipelines** (SDK minor C, after owner question 1): manifest key, `WorkspaceSetup::ROUTES` additions, engine part.
4. **Web:** preview panel in the picker, update point and "See what's new" (after the brand ask).
5. **Core: the move's one-time switch-on** (§5) and Settings › Plugins label.
6. **whity-plugins, one PR per batch:** Clinic and Salon first (the two with real users), then Coffee house, Study house, Meeting rooms, then Bakery and Car dealership. Each new plugin uses `WorkspaceTemplateTestCase`; each batch bumps `workspace-templates` to drop the moved keys. A `dental` example extending `clinic` ships in the SDK's test fixtures, not the store, unless the owner wants it (question 4).
7. **Check on a local stack:** an old workspace on `workspace-templates` 0.3.0 moves to the new plugins without losing its run, theme or examples; an update to a new version adds only what is new.

## 8. Risks

- A plugin author relying on `TemplateApplier` internals: none outside core (it is in `src/`).
- The engine moving into the SDK means its money parsing must not use core's `Currency`; the SDK gets a small `Templates\Money` reading the currency's decimals from the same table core uses (`CU.1`), checked by a test that both agree.
- Two plugins offering one key: unchanged rule (first wins), and the loader now logs it.

## 9. Questions for the owner (with recommendations)

1. **"Pipelines": which thing?** a) review routes in Flows for a template's forms and documents (recommended: it is core, free, and has a builder); b) Taskaat boards and task statuses (T.3 says Taskaat owns them, so a template would ask Taskaat to make a board); c) a sales pipeline of stages for leads (core has none today). Recommended **a**, with **b** later through Taskaat.
2. **Plugin names and look in Settings › Plugins:** "Clinic template" per plugin (recommended), or one "Templates" group listing them?
3. **Upgrades: changed items.** When a newer template changes something the workspace already has (a price, a form's fields), keep the workspace's copy and only say so in the preview (recommended), or offer "Use the template's version" per item?
4. **Compose: ship a real child template now?** A Dental template built on Clinic would prove compose in the store. Recommended: only as an SDK test fixture now; a store template when someone asks for it.
5. **Turning the new plugins on:** each new template plugin on in every workspace by default, like the package (recommended), or on only where the workspace used that template, with the others available to turn on?
6. **Old package:** retire `workspace-templates` after the last template moves (recommended), or keep it as an empty package one more release?
7. **Register rows WT.1-WT.4** in §6: accept as written?
