# Workspace templates (SDK 1.116)

A workspace template is a ready start for one kind of business: a salon, a clinic, a bakery or café, an agency. Right after a workspace is created, Whity offers the templates on the installation as cards. Choosing one sets the workspace up in a minute, and its guided setup walks the owner through what is left.

Templates are **free**. A template may offer one paid plugin: its guided setup ends by saying plainly what the workspace can do free in Whity, what the plugin adds, and a button that starts the plugin's own trial (no card).

## Where templates come from

- Core ships one template, **Start blank**, which sets nothing up. Choosing it is how a workspace says "no template".
- Every other template comes from a plugin that implements `Whity\Sdk\PluginWorkspaceTemplatesInterface`. The official ones ship in the free `templates` package in whity-plugins.
- A template is data, not code. Improving one is a plugin release (store or disk), never a core release. Bump `version` whenever its manifest changes.
- A template is listed only where its plugin is on. Keys are unique; a second plugin offering a key already taken is ignored, and no plugin can replace `blank`.

```php
use Whity\Sdk\PluginWorkspaceTemplatesInterface;

final class TemplatesPlugin implements PluginInterface, PluginWorkspaceTemplatesInterface
{
    public function getWorkspaceTemplates(): array
    {
        return [json_decode((string) file_get_contents(__DIR__ . '/../templates/bakery.json'), true)];
    }
}
```

## The manifest

Checked by `Whity\Sdk\Manifest\WorkspaceTemplateValidator::errors()`. A template that fails is dropped with its reasons in the log; the plugin keeps loading. Every visible string is `{en, ar}`.

| Key | Required | What it is |
|---|---|---|
| `key` | yes | Lowercase letters, digits and hyphens (`bakery`) |
| `version` | yes | A whole number from 1. Bump it on every change |
| `name`, `summary` | yes | The card's title (40) and one line (160) |
| `icon` | no | One of Whity's icon names (`appointment`, `recipe`, `kanban`, `key`...); another shows the template icon |
| `setsUp` | yes | 1 to 8 lines the card lists: what choosing it sets up |
| `settings` | no | Workspace defaults: `billing.default_currency`, `timezone`, `locale` (`en` or `ar`), `calendar.week_start`, `calendar.weekend_days` |
| `calendar` | no | `settings` (booking rules, as `PUT /api/calendar/settings` takes them) and `hours` (a week of `{weekday 1-7, start_time, end_time}`) |
| `services` | no | Up to 30: `key`, `name`, `description`, `durationMinutes`, `bufferAfterMinutes`, `prices` by currency in normal units as text (`{"JOD": "10.000", "USD": "14.00"}`), and `ticked: false` for one the guided setup offers but applying does not make |
| `roles` | no | Up to 6: `key`, `name`, `description`, `permissions` (slugs) |
| `forms` | no | Up to 5: `key` (the form key), `name`, `description`, `fields` (`key`, `type`, `label`, `help`, `required`, `options` for select and multiselect), `publish` |
| `documents` | no | Up to 5: `key`, `kind` (`note`, `letter`, `template`, `label`), `title`, `blocks` as `{en: [...], ar: [...]}` editor blocks |
| `pipelines` | no | SDK 1.172: up to 5 review routes in Flows for the template's forms, see below |
| `examples` | no | Up to 10 `contacts` (`key`, `name`, an `@example.com` `email`, `phone`, `notes`) and 10 `bookings` (`key`, `service`, `contact`, `inDays` 1-14, `time` HH:MM) |
| `setup` | no | The guided setup: `title`, `description`, and 1 to 8 `steps`, each an `id`, a `kind`, a `title` and a `why` |
| `theme` | no | The theme that suits this business, by id (`templates.market`), usually one your plugin ships with `PluginThemesInterface` (SDK 1.125). SDK 1.131: older hosts refuse a manifest with it, so require `^1.131`. See below |
| `extends`, `remove`, `hidesParent` | no | SDK 1.170: build on another template, see below |
| `changes` | no | SDK 1.170: up to 20 `{version, summary}`, newest first, what each version added |
| `order` | no | SDK 1.176: a whole number from 1 to 9999, where the card goes among the others, lowest first. Templates without one come after, by English name; Start blank is always last. Whity's own use 10 to 70 |
| `offer` | no | `plugin` (the name of the plugin made for this business), `trialDays` (1 to 90, the trial started from the template's setup), and `free` and `paid`: 1 to 6 lines each. Hidden until that plugin is installed and paid, so a template can carry its offer before the plugin exists |

### Guided setup step kinds

Core draws each step and works out whether it is done from what the workspace holds, so a template needs no code and a step done by hand elsewhere counts too.

- `business`: name in both languages, phone, address, colour.
- `hours`: the week, on the weekly-hours picker.
- `services`: the template's services as a list to tick and price.
- `team`: invite people by email.
- `booking`: booking rules and the booking page.
- `link`: the booking link to share and print.
- `screen`: a short explanation and a button to a screen of the app (`href`, `action`).

## Applying

`POST /api/workspace/templates/{key}/apply` with `{"examples": true|false}`, under `settings:write`. Core applies the manifest **as the caller**, through its own routes and that person's permissions, the same gates as `Whity\Sdk\Setup\WorkspaceSetup`, plus three routes only core's applier may call: making roles, making documents and removing examples.

- **Adds, never overwrites.** A setting is set only while the workspace keeps the default. Booking rules are set the first time only. Hours go to the person applying only when they have none. A service with the same name (either language, any case) is kept and not made again. A role or form that already exists is kept.
- **Applying again is safe.** What a run made is recorded (migration 305), so a second run, or a newer `version`, adds only what is missing.
- **No more than the person has.** A role gets only the permissions the person applying holds themselves; the rest are listed as skipped.
- **Each part fails alone.** The answer lists what was made and, for each part not done, why.
- Examples are recorded as examples. Example bookings email nobody.

`DELETE /api/workspace/templates/examples` cancels the example bookings and archives the example contacts still there, as the caller. Nothing the workspace made itself is touched.

## Preview: what applying would do (SDK 1.169)

`POST /api/workspace/templates/{key}/preview` takes the same body as apply and runs the same engine as the caller, but changes nothing and audits nothing: reads go to the workspace, writes are only listed, and a write the caller may not make is answered as the real route would. It answers `version` (on offer), `appliedVersion` (this workspace's last, or null), and `items`: each part's items with what would happen:

| `action` | Means |
|---|---|
| `add` | Would be made |
| `keep` | Already in the workspace (same name or key); left as it is |
| `done` | An earlier run made it; not made again, even if the person has since deleted it |
| `left` | A service with `ticked: false`, left for the guided setup |
| `skip` | Cannot be done; `reason` says why |

Apply answers the same `items` for what it did. Each run records the version of every part it applied (`workspace_template_parts`, migration 358).

## Testing a template (SDK 1.169)

The engine core runs is in the SDK (`Whity\Sdk\Templates\TemplateEngine`), so a template's tests run the real thing without core or a database. Extend `Whity\Sdk\Testing\WorkspaceTemplateTestCase` (needs `phpunit/phpunit` in `require-dev`):

```php
use Whity\Sdk\Testing\WorkspaceTemplateTestCase;

final class ClinicTemplateTest extends WorkspaceTemplateTestCase
{
    public function testTheTemplate(): void
    {
        $clinic = json_decode((string) file_get_contents(__DIR__ . '/../templates/clinic.json'), true);

        $this->assertTemplateValid($clinic);   // core's check
        $this->assertIdempotent($clinic);      // a second apply makes nothing
        $this->assertUpgradeKeepsData($previousVersion, $clinic); // nothing deleted, overwritten or brought back
    }
}
```

`applyTemplate($manifest, $workspace, $ledger, examples: true)` applies it to a `Testing\FakeWorkspace`, a workspace in memory that answers the routes a template uses as core does (same-name services are one, role names and form keys are unique, settings remember which were chosen). Give it `permissions: [...]` to apply as someone who holds less, or `refuse('POST /api/roles')` to refuse a route. Its rows are public arrays (`services`, `roles`, `forms`, `documents`, `contacts`, `bookings`), so a test can act as the person between two applies.

## Templates from plugin.json (SDK 1.170)

A template plugin needs no PHP. List the files in `plugin.json` and extend `Whity\Sdk\Manifest\ManifestPlugin`:

```json
{
  "manifest": "whity.plugin/1",
  "plugin": {"name": "ClinicTemplate", "version": "1.0.0"},
  "requires": {"sdk": "^1.170"},
  "workspaceTemplates": ["templates/clinic.json"],
  "themes": ["themes/clinic.json"]
}
```

`whity-cli plugin validate <dir>` checks each file offline.

## Which workspaces a template plugin is on in

A template plugin is installed for the platform and on only in the workspaces that use it (owner, 26 Nov 2026; design section 40 §5, WHIT-1433). Install it with "Each workspace chooses" access.

- The picker lists the templates of template plugins that are off in the workspace too (`pluginOn: false` on the card), unless the operator keeps the plugin to themselves, it was withdrawn, or it is paid and the workspace may not turn it on.
- Choosing such a template turns its plugin on in the workspace first, as the person (`plugins:enable`), then applies it.
- A workspace that already applied one of a plugin's templates gets the plugin turned on the first time it is asked about there, unless the workspace switched it either way before. Every other workspace leaves it off.
- Turning a template plugin off never deletes what its template made.

## Building on a template (SDK 1.170)

A template can build on another with `extends`. For example, a Dental template on Clinic:

```json
{"key": "dental", "version": 1, "extends": "clinic", "remove": {"services": ["check-up"]}, "name": {...}, "summary": {...}, "setsUp": [...],
 "services": [{"key": "cleaning", ...}]}
```

- **Lists merge by key.** `services`, `roles`, `forms`, `documents`, the example `contacts` and `bookings`, and `setup.steps` (by `id`) all merge by key. The parent's items come first. A child item with the same key takes the parent item's place, and the child's new items come after. `remove` drops parent items by key: `services`, `roles`, `forms`, `documents` or `steps`.
- **Settings merge key by key.** For `settings` and `calendar.settings`, the child's value wins.
- **What stays the child's own.** `name`, `summary`, `icon`, `setsUp`, `version` and `changes` are always the child's. `calendar.hours`, `offer`, `theme` and the setup's title are the child's when it has them, otherwise the parent's.
- **Hiding the parent.** `"hidesParent": true` hides the parent's card wherever the child is offered.
- **References.** The child may point at the parent's services and example contacts. They are checked on the composed template.
- **Limits.** At most three levels. A child whose parent isn't offered in the workspace, or that builds a loop, is left out with the reason. The child's plugin should list the parent's plugin in `getPluginDependencies()`.

The Dental example lives in core's tests (`tests/Support/ExampleClinicTemplates.php`), not in the store.

## Adding to another plugin's template (SDK 1.170)

A plugin adds pieces to another plugin's template with `Contribute\PluginWorkspaceTemplateExtensionsInterface`, or with `"workspaceTemplateExtensions": ["templates/clinic-patient-file.json"]` in `plugin.json`:

```json
{"key": "patient-file", "version": 1, "target": "clinic",
 "setsUp": [{"en": "A file for each patient", "ar": "ملف لكل مريض"}],
 "forms": [{"key": "visit-notes", ...}]}
```

An extension only adds: `setsUp` lines, `services`, `roles`, `forms`, `documents`, `examples` and `setup.steps`.

- It applies to its target, and to every template built on the target, wherever its plugin is on.
- If the template already has an item with the same key, that item from the extension is left out.
- If the extension would make the template invalid, the whole extension is left out and the template is offered without it.
- Each extension is its own part, with its own `version`.

## Versions and updates (SDK 1.170; decisions WT.2, WT.3)

Bump `version` on every change, and say what changed in `changes`, newest first: `[{"version": 2, "summary": {"en": "...", "ar": "..."}}]`. Keys are forever: renaming a key makes a new item.

- **Recorded on every run.** Each run records the version of every part (`template:clinic`, `extends:clinic`, `extension:Clinics.patient-file`). Each item the run makes also records a fingerprint of the manifest entry it came from.
- **The update offer.** `GET /api/workspace/templates` shows `update` on a chosen card when any part is newer, or when a part is new (such as a new extension). It lists the parts with `from` and `to`, and the `changes` lines.
- **Never automatic.** Applying again adds only the new pieces. An update adds no examples unless asked, and none after the person removed them.
- **Changed items.** An item the template has changed since it was made is reported `changed` in the preview and the apply answer, and left exactly as the workspace has it.
- **Use the template's version.** `POST /api/workspace/templates/{key}/items/{part}/{item}/adopt` (`settings:write`) applies the template's version to that one item, as the person. It changes only what the template sets, and deletes nothing:
  - a service gets its names, duration and price in the workspace's currency;
  - a form gets the template's labels and help, plus the questions it is missing (the person's own questions stay);
  - a role gets the template's permissions it is missing, only those the person holds;
  - a document gets a new copy beside the workspace's;
  - a pipeline gets a new review route named with the template's version ("Intake review, version 2"). Its form moves to the new route only while it still uses the route the template made; the old route stays in Flows.
- **Nothing comes back.** An item the person deleted is never made again and has nothing to adopt.

## Pipelines: review routes in Flows (SDK 1.172)

A pipeline is a review route in Flows for one of the template's forms: each answer to the form goes through its stages in order. Each stage is a decision by the people holding one of the template's roles; an approval moves it on to the next stage.

```json
"pipelines": [{
  "key": "intake-review",
  "name": {"en": "Intake review", "ar": "مراجعة الاستقبال"},
  "description": {"en": "Reception checks each intake, then a doctor approves it.", "ar": "يدقق الاستقبال كل نموذج ثم يعتمده الطبيب."},
  "for": {"form": "patient-intake"},
  "stages": [
    {"key": "reception", "name": {"en": "Reception checks", "ar": "تدقيق الاستقبال"}, "role": "reception"},
    {"key": "doctor", "name": {"en": "Doctor approves", "ar": "اعتماد الطبيب"}, "role": "doctor", "sendBack": false}
  ]
}]
```

- `for.form` is the key of one of the template's forms, and each stage's `role` the key of one of its roles. People are never named. A stage cannot name a permission, as Flows routes by role.
- Up to 5 pipelines, each with 1 to 8 stages. `sendBack: false` stops that stage sending an answer back to the person who sent it.
- **Applying** makes the route template as the person, through core's own route-template API, and sends the form's answers through it when the form has no route yet. A route the person already chose for the form stays, and the preview says so. The person needs `route_templates:write`; without it, the pipeline is skipped with the reason and everything else is still made.
- Pipelines compose by key like the other lists: a template built on another can drop one with `remove.pipelines`, and an extension can add one for its target's forms and roles.
- Older hosts refuse a manifest with `pipelines`, so require `^1.172`.

## The theme

A template that names a `theme` shows "Use its look: <theme name>" when it is chosen, on by default. Core sets it only while the workspace still has the installation's theme, so a theme someone already chose stays, and the person can change it any time in Branding, for free. The card's `theme` is null when no theme by that id is installed in the workspace, and then nothing is offered (30-themes §14.6, TH.23).

## The offer

The offer names the plugin by its display name (SDK 1.117, `PluginDisplayNameInterface`), such as "Whity for Salons", never its id; a plugin that declares none shows its id.

`GET /api/workspace/templates` shows a template's `offer` only when the plugin it names is installed and paid (`PluginAccessInterface`). It carries the plugin's `trialDays`, whether this workspace can still start a trial, and the plugin's state here. The trial itself starts through `POST /api/workspace/code-plugins/{plugin}/trial` (`plugins:enable`), the same one-click, no-card trial as Settings › Plugins.

### Locked cards on the dashboard (WHIT-1202)

The offer also waits on the dashboard. `GET /api/me/dashboard` lists `offers`: one locked card per paid plugin the workspace's templates offer, while

- the plugin is installed, paid and offered to workspaces (not operator-only);
- the workspace has no access to it (no plan, no cash payment, no running trial) and has never had its trial;
- the workspace is not the system workspace, and the reader holds `plugins:enable`.

Each card carries its layout key `offer:{plugin}`, the template that offers it, the plugin's display name, at most three lines of the offer's `paid` text and the template's trial length. The web draws it dashed and locked, first under From your apps, with Start your {n}-day free trial (`POST /api/workspace/templates/{key}/setup/offer/trial`) and See what's included (`/admin/plugins#plugin-{name}`). It hides, shows and moves through `PUT /api/me/dashboard/layout` like any card; hiding it never withdraws the offer elsewhere. Once the trial starts the card is gone for good.

## Example data

Example rows use made-up names only: Example Bakery and friends for companies, the Whity team for people (Amro Saleh, Awss Haddad, Ahmad Khalil, Ibrahim Mansour, Emad Nasser), and `@example.com` addresses. Never real customers.
