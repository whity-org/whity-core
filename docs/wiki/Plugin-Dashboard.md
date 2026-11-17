# Plugin Dashboard: setup points and widgets (SDK 1.115)

A plugin can put its own setup tasks and its own cards on the workspace dashboard (`/dashboard`), next to core's, instead of only on its own pages. Whity for Salons, for example, adds "Add your chairs" to **Finish setting up** and a "Today's bookings" widget under **From your apps**.

Implement `Whity\Sdk\PluginDashboardInterface`. It has one method, `getDashboard()`, returning two optional parts: `setup` and `widgets`.

```php
use Whity\Sdk\PluginDashboardInterface;

final class SalonsPlugin implements PluginInterface, PluginOnboardingInterface, PluginDashboardInterface
{
    public function getDashboard(): array
    {
        return [
            'setup' => [
                'permission' => 'salons:manage',
                'status' => '/api/salons/setup-points',
                'points' => [
                    [
                        'id' => 'chairs',
                        'label' => ['en' => 'Add your chairs', 'ar' => 'أضف كراسي الصالون'],
                        'why' => ['en' => 'Bookings need a chair', 'ar' => 'الحجوزات تحتاج إلى كرسي'],
                        'action' => ['en' => 'Add', 'ar' => 'إضافة'],
                        'step' => 'chairs',
                        'icon' => 'armchair',
                    ],
                ],
            ],
            'widgets' => [
                [
                    'id' => 'todays-bookings',
                    'kind' => 'list',
                    'title' => ['en' => "Today's bookings", 'ar' => 'حجوزات اليوم'],
                    'permission' => 'salons:read',
                    'source' => '/api/salons/dashboard/today',
                    'sizes' => ['medium', 'large'],
                    'blocks' => [[
                        'type' => 'dataTable',
                        'source' => '/api/salons/dashboard/today',
                        'columns' => [
                            ['key' => 'time', 'label' => ['en' => 'Time', 'ar' => 'الوقت']],
                            ['key' => 'customer', 'label' => ['en' => 'Customer', 'ar' => 'العميل']],
                            ['key' => 'service', 'label' => ['en' => 'Service', 'ar' => 'الخدمة']],
                        ],
                        'emptyText' => ['en' => 'No bookings today', 'ar' => 'لا حجوزات اليوم'],
                    ]],
                    'screen' => 'salons-bookings',
                    'icon' => 'calendar',
                ],
            ],
        ];
    }
}
```

Every visible string is `{en, ar}`, in the declaration and in the blocks. Core serves the reader's language.

## Setup points

Setup points join core's own steps on the **Finish setting up** card (logo and colour, team, an agent, the tax number, a plan). The card shows one progress bar over all of them and stays until every point the person can see is done.

| Key | Required | What it is |
| --- | --- | --- |
| `setup.permission` | yes | One of the plugin's own permissions. Only people holding it in the workspace see the points. |
| `setup.status` | yes | One of the plugin's own GET routes, gated on that permission. See below. |
| `setup.points` | yes | 1 to 6 points. |
| `id` | yes | Lowercase letters, digits and dashes, unique in the plugin. |
| `label` | yes | What to do, up to 80 characters: "Add your chairs". |
| `why` | no | Why it matters, up to 120 characters. Shown under the label until it is done. |
| `action` | no | The button's word, up to 24 characters. Core says "Set up" when it is missing. |
| `step` | one of | A step `id` of the plugin's guided setup ([`PluginOnboardingInterface`](Plugin-UI-Blocks.md), SDK 1.109). The button opens `/admin/setup/{plugin}?step={step}`. |
| `screen` | one of | One of the plugin's own screen ids. The button opens `/admin/x/{screen}`. |
| `icon` | no | A Tabler icon name. |

The status route answers which points are done:

```json
{ "data": { "points": { "chairs": true, "hours": false } } }
```

Work it out from what the workspace holds, as onboarding's status does, so a point done by hand somewhere else counts too. A point the answer leaves out counts as not done. When the route fails, the plugin's points are left off the card and the rest of the card still shows. The setup route and the onboarding status route can be the same handler answering both `steps` and `points`.

## Widgets

A widget is a card in **From your apps**. A plugin may declare up to 8.

| Key | Required | What it is |
| --- | --- | --- |
| `id` | yes | Lowercase letters, digits and dashes, unique in the plugin. Its key on the dashboard is `{plugin}:{id}`. |
| `kind` | yes | `stat`, `list`, `chart` or `actions`. |
| `title` | yes | Up to 60 characters. |
| `description` | no | Up to 160 characters, under the title. |
| `permission` | yes | One of the plugin's own permissions. Only people holding it in the workspace see the widget. |
| `source` | yes, except `actions` | One of the plugin's own GET routes, gated on the widget's `permission`. Every data-bound block in the widget reads this route. |
| `sizes` | no | The widths it fits, from `small` (one column), `medium` (two) and `large` (the whole row), first the one it starts at. |
| `blocks` | yes | The widget's body, from the blocks its kind allows. |
| `screen` | no | One of the plugin's own screen ids. The title links there and the ⋯ menu offers Open. |
| `icon` | no | A Tabler icon name. |

### Kinds

| Kind | Built from | Needs | Default sizes | Its `source` answers |
| --- | --- | --- | --- | --- |
| `stat` | `dataStat`, `stat`, `row`, `grid`, `text`, `badge` | a `dataStat` | small, medium | `{data: {...}}`, one object each `dataStat` reads a field of |
| `list` | `dataList`, `dataTable`, `text`, `button` | a `dataList` or `dataTable` | medium, large | `{data: [...]}`, rows |
| `chart` | `chart`, `text` | a `chart` | medium, large | `{data: [...]}`, points |
| `actions` | `button`, `actionButton`, `row`, `text` | a `button` or `actionButton` | small, medium | no route needed |

The blocks follow [Plugin UI Blocks](Plugin-UI-Blocks.md) and are checked like a blocks screen's: every `source`, `href` and `endpoint` must be the plugin's own. An `actionButton` posts to one of the plugin's own routes, under that route's own permission.

Keep a widget short. A list shows a handful of rows and links to the screen for the rest; a stat shows the number that matters today.

### Scope

The widget's route answers in the person's scope, because core shows whatever it returns: a branch manager's "Today's bookings" lists their branch. Core never reads the data itself; the browser asks the plugin's route with the person's session, so the route's own permission check and scope apply.

## What core does

- **Checks the declaration** when the plugin loads. A bad point or widget is dropped alone, with a logged reason, and the plugin keeps loading. `Whity\Sdk\Manifest\DashboardValidator::errors($plugin->getDashboard())` runs the same shape checks in a plugin's own tests.
- **Scopes it** to the workspace and the person: points and widgets are listed only while the plugin is on in the workspace, and only to people holding their permission there (`GET /api/me/dashboard`).
- **Lets people arrange it.** Each person can hide and show widgets, move them earlier or later, and pick a size among those the widget fits. Their order covers the home cards too (SDK 1.62), so everything from their apps is one list. It is theirs alone, per workspace (`PUT /api/me/dashboard/layout`). A new widget appears at the end for everyone.
- **Fails calmly.** A widget whose route fails, answers nothing or throws while drawing shows one quiet line, "Nothing to show here right now", with Try again; it never stops the rest of the dashboard. A route answering 403 or 404 means the person may not see it now, and the widget is left out.

## Testing

```php
use Whity\Sdk\Manifest\DashboardValidator;

self::assertSame([], DashboardValidator::errors((new SalonsPlugin())->getDashboard()));
```

The validator checks the shape and the blocks. Core also checks what only it knows: that `status` and `source` are routes the plugin registered under the same permission, that `step` is one of its guided-setup steps and `screen` one of its screens.

## See also

- [Plugin UI Blocks](Plugin-UI-Blocks.md), the block vocabulary
- [Plugin SDK coverage](Plugin-SDK-Coverage.md), the Home page area
- `docs/design/20-home.md` and decisions H.1 to H.5 in `docs/design/00-decision-register.md`
