<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The shape of one workspace template, as returned by
 * {@see \Whity\Sdk\PluginWorkspaceTemplatesInterface::getWorkspaceTemplates()}
 * (SDK 1.116, WHIT-1194).
 *
 * ```php
 * [
 *     'key' => 'bakery',
 *     'version' => 1,
 *     'name' => ['en' => 'Bakery or café', 'ar' => 'مخبز أو مقهى'],
 *     'summary' => ['en' => 'Orders, a menu and opening hours.', 'ar' => 'الطلبات وقائمة الأصناف وساعات العمل.'],
 *     'icon' => 'cake',
 *     'setsUp' => [['en' => 'Your menu with prices', 'ar' => 'قائمتك بالأسعار']],
 *     'settings' => ['billing.default_currency' => 'JOD', 'timezone' => 'Asia/Amman', 'locale' => 'ar'],
 *     'calendar' => ['settings' => ['min_notice_minutes' => 60], 'hours' => [['weekday' => 6, 'start_time' => '07:00', 'end_time' => '15:00']]],
 *     'services' => [['key' => 'cake-order', 'name' => ['en' => 'Cake order', 'ar' => 'طلب كيك'], 'durationMinutes' => 15, 'prices' => ['JOD' => '20.000', 'USD' => '28.00']]],
 *     'roles' => [['key' => 'front-desk', 'name' => ['en' => 'Front desk', 'ar' => 'الاستقبال'], 'permissions' => ['calendar:write', 'contacts:write']]],
 *     'forms' => [['key' => 'cake-order', 'name' => [...], 'fields' => [['key' => 'flavour', 'type' => 'select', 'label' => [...], 'options' => [...]]], 'publish' => true]],
 *     'documents' => [['key' => 'price-list', 'kind' => 'note', 'title' => [...], 'blocks' => ['en' => [...], 'ar' => [...]]]],
 *     'examples' => ['contacts' => [['key' => 'c1', 'name' => 'Example Bakery', 'email' => 'hello@example.com']], 'bookings' => [['key' => 'b1', 'service' => 'cake-order', 'contact' => 'c1', 'inDays' => 1, 'time' => '10:00']]],
 *     'setup' => ['steps' => [['id' => 'hours', 'kind' => 'hours', 'title' => [...]]]],
 *     'offer' => ['plugin' => 'FrontOffice', 'free' => [[...]], 'paid' => [[...]]],
 * ]
 * ```
 *
 * Everything but `key`, `version`, `name`, `summary` and `setsUp` is optional.
 * Every visible string is `{en, ar}`. This checks what the manifest says about
 * itself; the host also checks what only it knows (that the offered plugin is
 * installed and paid, that each permission exists) and applies only what the
 * person choosing it may do.
 */
final class WorkspaceTemplateValidator
{
    public const MAX_SETS_UP = 8;
    public const MAX_SERVICES = 30;
    public const MAX_ROLES = 6;
    public const MAX_FORMS = 5;
    public const MAX_FIELDS = 30;
    public const MAX_DOCUMENTS = 5;
    public const MAX_EXAMPLES = 10;
    public const MAX_STEPS = 8;
    public const MAX_OFFER_LINES = 6;

    /** Workspace settings a template may set, and only while the workspace keeps the default. */
    public const SETTINGS = ['billing.default_currency', 'timezone', 'locale', 'calendar.week_start', 'calendar.weekend_days'];

    /** Booking rules a template may set (PUT /api/calendar/settings). */
    public const CALENDAR_SETTINGS = ['booking_page_enabled', 'min_notice_minutes', 'max_advance_days', 'slot_step_minutes', 'reminder_hours_before', 'instant_booking', 'multi_service_booking', 'week_start', 'weekend_days'];

    /**
     * The kinds of guided-setup step core draws for a template. Each has its
     * own form and its own "done" check, worked out from the workspace:
     * business (name, contacts, colour), hours (a week), services (the
     * template's list to tick and price), team (invite by email), booking
     * (booking rules and the page), link (the booking link to share) and
     * screen (a short explanation and a button to a screen of the app).
     */
    public const STEP_KINDS = ['business', 'hours', 'services', 'team', 'booking', 'link', 'screen'];

    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'checkbox', 'signature'];

    public const DOCUMENT_KINDS = ['note', 'letter', 'template', 'label'];

    private const TOP_KEYS = ['key', 'version', 'name', 'summary', 'icon', 'setsUp', 'settings', 'calendar', 'services', 'roles', 'forms', 'documents', 'examples', 'setup', 'offer'];
    private const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)+$/';
    private const ICON_PATTERN = '/^[a-z0-9-]{1,64}$/';
    private const TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    private const PRICE_PATTERN = '/^[0-9]{1,9}(\.[0-9]{1,3})?$/';

    private function __construct()
    {
    }

    /**
     * Everything wrong with one template.
     *
     * @return list<string> each naming its path; empty when valid
     */
    public static function errors(mixed $template): array
    {
        if (!is_array($template) || array_is_list($template)) {
            return ['template: must be an object'];
        }
        $errors = self::unknownKeys($template, self::TOP_KEYS, 'template');

        if (!is_string($template['key'] ?? null) || preg_match(self::KEY_PATTERN, $template['key']) !== 1) {
            $errors[] = 'template.key: required, lowercase letters, digits and hyphens';
        }
        if (!is_int($template['version'] ?? null) || $template['version'] < 1) {
            $errors[] = 'template.version: required, a whole number from 1';
        }
        array_push($errors, ...self::textErrors($template['name'] ?? null, 'template.name', 40));
        array_push($errors, ...self::textErrors($template['summary'] ?? null, 'template.summary', 160));
        if (isset($template['icon']) && (!is_string($template['icon']) || preg_match(self::ICON_PATTERN, $template['icon']) !== 1)) {
            $errors[] = 'template.icon: an icon name';
        }
        array_push($errors, ...self::listOf($template['setsUp'] ?? null, 'template.setsUp', 1, self::MAX_SETS_UP, static fn (mixed $line, string $path): array => self::textErrors($line, $path, 80)));

        if (array_key_exists('settings', $template)) {
            array_push($errors, ...self::settingsErrors($template['settings']));
        }
        if (array_key_exists('calendar', $template)) {
            array_push($errors, ...self::calendarErrors($template['calendar']));
        }
        $services = [];
        if (array_key_exists('services', $template)) {
            array_push($errors, ...self::listOf($template['services'], 'template.services', 0, self::MAX_SERVICES, [self::class, 'serviceErrors']));
            $services = self::keysOf($template['services']);
            array_push($errors, ...self::duplicateKeys($template['services'], 'template.services'));
        }
        if (array_key_exists('roles', $template)) {
            array_push($errors, ...self::listOf($template['roles'], 'template.roles', 0, self::MAX_ROLES, [self::class, 'roleErrors']));
            array_push($errors, ...self::duplicateKeys($template['roles'], 'template.roles'));
        }
        if (array_key_exists('forms', $template)) {
            array_push($errors, ...self::listOf($template['forms'], 'template.forms', 0, self::MAX_FORMS, [self::class, 'formErrors']));
            array_push($errors, ...self::duplicateKeys($template['forms'], 'template.forms'));
        }
        if (array_key_exists('documents', $template)) {
            array_push($errors, ...self::listOf($template['documents'], 'template.documents', 0, self::MAX_DOCUMENTS, [self::class, 'documentErrors']));
            array_push($errors, ...self::duplicateKeys($template['documents'], 'template.documents'));
        }
        if (array_key_exists('examples', $template)) {
            array_push($errors, ...self::exampleErrors($template['examples'], $services));
        }
        $kinds = [];
        if (array_key_exists('setup', $template)) {
            array_push($errors, ...self::setupErrors($template['setup']));
            $steps = is_array($template['setup']) ? ($template['setup']['steps'] ?? []) : [];
            foreach (is_array($steps) ? $steps : [] as $step) {
                if (is_array($step) && is_string($step['kind'] ?? null)) {
                    $kinds[] = $step['kind'];
                }
            }
        }
        if (in_array('services', $kinds, true) && $services === []) {
            $errors[] = 'template.setup: a services step needs the template\'s own services';
        }
        if (array_key_exists('offer', $template)) {
            array_push($errors, ...self::offerErrors($template['offer']));
        }

        return $errors;
    }

    /** @return list<string> */
    public static function settingsErrors(mixed $settings): array
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return ['template.settings: must be an object'];
        }
        $errors = self::unknownKeys($settings, self::SETTINGS, 'template.settings');
        foreach ($settings as $key => $value) {
            if (!is_string($value) && !(is_array($value) && array_is_list($value))) {
                $errors[] = "template.settings.{$key}: a text value";
            }
        }
        $currency = $settings['billing.default_currency'] ?? null;
        if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
            $errors[] = 'template.settings.billing.default_currency: three capital letters';
        }
        $locale = $settings['locale'] ?? null;
        if ($locale !== null && !in_array($locale, ['en', 'ar'], true)) {
            $errors[] = 'template.settings.locale: en or ar';
        }

        return $errors;
    }

    /** @return list<string> */
    public static function calendarErrors(mixed $calendar): array
    {
        if (!is_array($calendar) || ($calendar !== [] && array_is_list($calendar))) {
            return ['template.calendar: must be an object'];
        }
        $errors = self::unknownKeys($calendar, ['settings', 'hours'], 'template.calendar');
        $settings = $calendar['settings'] ?? [];
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            $errors[] = 'template.calendar.settings: must be an object';
        } else {
            array_push($errors, ...self::unknownKeys($settings, self::CALENDAR_SETTINGS, 'template.calendar.settings'));
            foreach ($settings as $key => $value) {
                if (!is_scalar($value) && !(is_array($value) && array_is_list($value))) {
                    $errors[] = "template.calendar.settings.{$key}: a plain value";
                }
            }
        }
        if (array_key_exists('hours', $calendar)) {
            array_push($errors, ...self::hoursErrors($calendar['hours'], 'template.calendar.hours'));
        }

        return $errors;
    }

    /** @return list<string> */
    public static function hoursErrors(mixed $hours, string $path): array
    {
        if (!is_array($hours) || !array_is_list($hours) || $hours === [] || count($hours) > 21) {
            return ["{$path}: a list of 1 to 21 rows"];
        }
        $errors = [];
        foreach ($hours as $i => $row) {
            if (!is_array($row) || !is_int($row['weekday'] ?? null) || $row['weekday'] < 1 || $row['weekday'] > 7
                || !is_string($row['start_time'] ?? null) || preg_match(self::TIME_PATTERN, $row['start_time']) !== 1
                || !is_string($row['end_time'] ?? null) || preg_match(self::TIME_PATTERN, $row['end_time']) !== 1
                || $row['start_time'] >= $row['end_time']) {
                $errors[] = "{$path}[{$i}]: {weekday 1-7, start_time HH:MM, end_time HH:MM after it}";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public static function serviceErrors(mixed $service, string $path): array
    {
        if (!is_array($service) || array_is_list($service)) {
            return ["{$path}: must be an object"];
        }
        $errors = self::unknownKeys($service, ['key', 'name', 'description', 'durationMinutes', 'bufferAfterMinutes', 'prices', 'ticked'], $path);
        array_push($errors, ...self::keyErrors($service['key'] ?? null, "{$path}.key"));
        array_push($errors, ...self::textErrors($service['name'] ?? null, "{$path}.name", 80));
        if (isset($service['description'])) {
            array_push($errors, ...self::textErrors($service['description'], "{$path}.description", 200));
        }
        $duration = $service['durationMinutes'] ?? null;
        if (!is_int($duration) || $duration < 5 || $duration > 1440) {
            $errors[] = "{$path}.durationMinutes: required, 5 to 1440";
        }
        $buffer = $service['bufferAfterMinutes'] ?? 0;
        if (!is_int($buffer) || $buffer < 0 || $buffer > 240) {
            $errors[] = "{$path}.bufferAfterMinutes: 0 to 240";
        }
        if (isset($service['prices'])) {
            $prices = $service['prices'];
            if (!is_array($prices) || array_is_list($prices) || count($prices) > 10) {
                $errors[] = "{$path}.prices: by currency, e.g. {\"JOD\": \"10.000\", \"USD\": \"14.00\"}";
            } else {
                foreach ($prices as $currency => $amount) {
                    if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || !is_string($amount) || preg_match(self::PRICE_PATTERN, $amount) !== 1) {
                        $errors[] = "{$path}.prices.{$currency}: an amount in normal units as text, e.g. \"12.500\"";
                    }
                }
            }
        }
        if (isset($service['ticked']) && !is_bool($service['ticked'])) {
            $errors[] = "{$path}.ticked: true or false";
        }

        return $errors;
    }

    /** @return list<string> */
    public static function roleErrors(mixed $role, string $path): array
    {
        if (!is_array($role) || array_is_list($role)) {
            return ["{$path}: must be an object"];
        }
        $errors = self::unknownKeys($role, ['key', 'name', 'description', 'permissions'], $path);
        array_push($errors, ...self::keyErrors($role['key'] ?? null, "{$path}.key"));
        array_push($errors, ...self::textErrors($role['name'] ?? null, "{$path}.name", 60));
        if (isset($role['description'])) {
            array_push($errors, ...self::textErrors($role['description'], "{$path}.description", 200));
        }
        $permissions = $role['permissions'] ?? null;
        if (!is_array($permissions) || !array_is_list($permissions) || $permissions === [] || count($permissions) > 60) {
            $errors[] = "{$path}.permissions: required, 1 to 60 permission slugs";
        } else {
            foreach ($permissions as $j => $slug) {
                if (!is_string($slug) || preg_match(self::PERMISSION_PATTERN, $slug) !== 1) {
                    $errors[] = "{$path}.permissions[{$j}]: a permission slug";
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public static function formErrors(mixed $form, string $path): array
    {
        if (!is_array($form) || array_is_list($form)) {
            return ["{$path}: must be an object"];
        }
        $errors = self::unknownKeys($form, ['key', 'name', 'description', 'fields', 'publish'], $path);
        if (!is_string($form['key'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $form['key']) !== 1) {
            $errors[] = "{$path}.key: required, lowercase letters, digits, - and _";
        }
        array_push($errors, ...self::textErrors($form['name'] ?? null, "{$path}.name", 80));
        if (isset($form['description'])) {
            array_push($errors, ...self::textErrors($form['description'], "{$path}.description", 200));
        }
        if (isset($form['publish']) && !is_bool($form['publish'])) {
            $errors[] = "{$path}.publish: true or false";
        }
        array_push($errors, ...self::listOf($form['fields'] ?? null, "{$path}.fields", 1, self::MAX_FIELDS, [self::class, 'fieldErrors']));
        $seen = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $i => $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            if (is_string($key)) {
                if (isset($seen[$key])) {
                    $errors[] = "{$path}.fields[{$i}].key: {$key} is used twice";
                }
                $seen[$key] = true;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public static function fieldErrors(mixed $field, string $path): array
    {
        if (!is_array($field) || array_is_list($field)) {
            return ["{$path}: must be an object"];
        }
        $errors = self::unknownKeys($field, ['key', 'type', 'label', 'help', 'required', 'options'], $path);
        if (!is_string($field['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field['key']) !== 1) {
            $errors[] = "{$path}.key: required, lowercase letters, digits and _";
        }
        $type = $field['type'] ?? null;
        if (!in_array($type, self::FIELD_TYPES, true)) {
            $errors[] = "{$path}.type: one of " . implode(', ', self::FIELD_TYPES);
        }
        array_push($errors, ...self::textErrors($field['label'] ?? null, "{$path}.label", 120));
        if (isset($field['help'])) {
            array_push($errors, ...self::textErrors($field['help'], "{$path}.help", 200));
        }
        if (isset($field['required']) && !is_bool($field['required'])) {
            $errors[] = "{$path}.required: true or false";
        }
        if (in_array($type, ['select', 'multiselect'], true)) {
            array_push($errors, ...self::listOf($field['options'] ?? null, "{$path}.options", 1, 30, static function (mixed $option, string $p): array {
                if (!is_array($option) || !is_string($option['value'] ?? null) || $option['value'] === '' || strlen($option['value']) > 64) {
                    return ["{$p}.value: required text"];
                }

                return self::textErrors($option['label'] ?? null, "{$p}.label", 80);
            }));
        } elseif (isset($field['options'])) {
            $errors[] = "{$path}.options: only for select and multiselect";
        }

        return $errors;
    }

    /** @return list<string> */
    public static function documentErrors(mixed $document, string $path): array
    {
        if (!is_array($document) || array_is_list($document)) {
            return ["{$path}: must be an object"];
        }
        $errors = self::unknownKeys($document, ['key', 'kind', 'title', 'blocks'], $path);
        array_push($errors, ...self::keyErrors($document['key'] ?? null, "{$path}.key"));
        if (!in_array($document['kind'] ?? null, self::DOCUMENT_KINDS, true)) {
            $errors[] = "{$path}.kind: one of " . implode(', ', self::DOCUMENT_KINDS);
        }
        array_push($errors, ...self::textErrors($document['title'] ?? null, "{$path}.title", 120));
        $blocks = $document['blocks'] ?? null;
        if (!is_array($blocks) || array_is_list($blocks)) {
            $errors[] = "{$path}.blocks: required, {en: [...], ar: [...]}";
        } else {
            foreach (['en', 'ar'] as $language) {
                $list = $blocks[$language] ?? null;
                if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > 200) {
                    $errors[] = "{$path}.blocks.{$language}: required, 1 to 200 editor blocks";
                    continue;
                }
                foreach ($list as $i => $block) {
                    if (!is_array($block) || !is_string($block['type'] ?? null) || !is_string($block['id'] ?? null)) {
                        $errors[] = "{$path}.blocks.{$language}[{$i}]: an editor block with an id and a type";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Examples: made-up contacts and bookings, marked and removable in one click.
     *
     * @param list<string> $services the template's own service keys
     * @return list<string>
     */
    public static function exampleErrors(mixed $examples, array $services): array
    {
        if (!is_array($examples) || ($examples !== [] && array_is_list($examples))) {
            return ['template.examples: must be an object'];
        }
        $errors = self::unknownKeys($examples, ['contacts', 'bookings'], 'template.examples');
        $contacts = [];
        if (array_key_exists('contacts', $examples)) {
            array_push($errors, ...self::listOf($examples['contacts'], 'template.examples.contacts', 0, self::MAX_EXAMPLES, static function (mixed $contact, string $path): array {
                if (!is_array($contact) || array_is_list($contact)) {
                    return ["{$path}: must be an object"];
                }
                $errors = self::unknownKeys($contact, ['key', 'name', 'email', 'phone', 'notes'], $path);
                array_push($errors, ...self::keyErrors($contact['key'] ?? null, "{$path}.key"));
                if (!is_string($contact['name'] ?? null) || $contact['name'] === '' || mb_strlen($contact['name']) > 200) {
                    $errors[] = "{$path}.name: required, up to 200 characters";
                }
                $email = $contact['email'] ?? null;
                if ($email !== null && (!is_string($email) || !str_ends_with($email, '@example.com'))) {
                    $errors[] = "{$path}.email: an @example.com address, never a real one";
                }
                foreach (['phone' => 40, 'notes' => 400] as $key => $max) {
                    if (isset($contact[$key]) && (!is_string($contact[$key]) || mb_strlen($contact[$key]) > $max)) {
                        $errors[] = "{$path}.{$key}: up to {$max} characters";
                    }
                }

                return $errors;
            }));
            $contacts = self::keysOf($examples['contacts']);
            array_push($errors, ...self::duplicateKeys($examples['contacts'], 'template.examples.contacts'));
        }
        if (array_key_exists('bookings', $examples)) {
            array_push($errors, ...self::listOf($examples['bookings'], 'template.examples.bookings', 0, self::MAX_EXAMPLES, static function (mixed $booking, string $path) use ($services, $contacts): array {
                if (!is_array($booking) || array_is_list($booking)) {
                    return ["{$path}: must be an object"];
                }
                $errors = self::unknownKeys($booking, ['key', 'service', 'contact', 'inDays', 'time'], $path);
                array_push($errors, ...self::keyErrors($booking['key'] ?? null, "{$path}.key"));
                if (!in_array($booking['service'] ?? null, $services, true)) {
                    $errors[] = "{$path}.service: one of the template's services";
                }
                if (!in_array($booking['contact'] ?? null, $contacts, true)) {
                    $errors[] = "{$path}.contact: one of the example contacts";
                }
                $days = $booking['inDays'] ?? null;
                if (!is_int($days) || $days < 1 || $days > 14) {
                    $errors[] = "{$path}.inDays: 1 to 14 days from when the template is applied";
                }
                if (!is_string($booking['time'] ?? null) || preg_match(self::TIME_PATTERN, $booking['time']) !== 1) {
                    $errors[] = "{$path}.time: HH:MM";
                }

                return $errors;
            }));
            array_push($errors, ...self::duplicateKeys($examples['bookings'], 'template.examples.bookings'));
        }

        return $errors;
    }

    /** @return list<string> */
    public static function setupErrors(mixed $setup): array
    {
        if (!is_array($setup) || array_is_list($setup)) {
            return ['template.setup: must be an object'];
        }
        $errors = self::unknownKeys($setup, ['title', 'description', 'steps'], 'template.setup');
        if (isset($setup['title'])) {
            array_push($errors, ...self::textErrors($setup['title'], 'template.setup.title', 60));
        }
        if (isset($setup['description'])) {
            array_push($errors, ...self::textErrors($setup['description'], 'template.setup.description', 240));
        }
        array_push($errors, ...self::listOf($setup['steps'] ?? null, 'template.setup.steps', 1, self::MAX_STEPS, static function (mixed $step, string $path): array {
            if (!is_array($step) || array_is_list($step)) {
                return ["{$path}: must be an object"];
            }
            $errors = self::unknownKeys($step, ['id', 'kind', 'title', 'why', 'href', 'action'], $path);
            array_push($errors, ...self::keyErrors($step['id'] ?? null, "{$path}.id"));
            $kind = $step['kind'] ?? null;
            if (!in_array($kind, self::STEP_KINDS, true)) {
                $errors[] = "{$path}.kind: one of " . implode(', ', self::STEP_KINDS);
            }
            array_push($errors, ...self::textErrors($step['title'] ?? null, "{$path}.title", 60));
            if (isset($step['why'])) {
                array_push($errors, ...self::textErrors($step['why'], "{$path}.why", 240));
            }
            if ($kind === 'screen') {
                $href = $step['href'] ?? null;
                if (!is_string($href) || preg_match('#^/[A-Za-z0-9_\-/]{1,120}$#', $href) !== 1) {
                    $errors[] = "{$path}.href: required for a screen step, a path in the app such as /admin/settings/branding";
                }
                array_push($errors, ...self::textErrors($step['action'] ?? null, "{$path}.action", 24));
            } elseif (isset($step['href']) || isset($step['action'])) {
                $errors[] = "{$path}: href and action are only for a screen step";
            }

            return $errors;
        }));
        array_push($errors, ...self::duplicateKeys($setup['steps'] ?? null, 'template.setup.steps', 'id'));

        return $errors;
    }

    /** @return list<string> */
    public static function offerErrors(mixed $offer): array
    {
        if (!is_array($offer) || array_is_list($offer)) {
            return ['template.offer: must be an object'];
        }
        $errors = self::unknownKeys($offer, ['plugin', 'free', 'paid'], 'template.offer');
        if (!is_string($offer['plugin'] ?? null) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $offer['plugin']) !== 1) {
            $errors[] = 'template.offer.plugin: required, the name of the plugin offered';
        }
        $line = static fn (mixed $text, string $path): array => self::textErrors($text, $path, 120);
        array_push($errors, ...self::listOf($offer['free'] ?? null, 'template.offer.free', 1, self::MAX_OFFER_LINES, $line));
        array_push($errors, ...self::listOf($offer['paid'] ?? null, 'template.offer.paid', 1, self::MAX_OFFER_LINES, $line));

        return $errors;
    }

    /** @return list<string> */
    private static function textErrors(mixed $text, string $path, int $max): array
    {
        if (!is_array($text)) {
            return ["{$path}: required, {en, ar}"];
        }
        $errors = [];
        foreach (['en', 'ar'] as $language) {
            $value = $text[$language] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
                $errors[] = "{$path}.{$language}: required, up to {$max} characters";
            }
        }
        foreach (array_keys($text) as $key) {
            if (!in_array($key, ['en', 'ar'], true)) {
                $errors[] = "{$path}.{$key}: only en and ar";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function keyErrors(mixed $key, string $path): array
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1 ? [] : ["{$path}: required, lowercase letters, digits and hyphens"];
    }

    /**
     * @param callable(mixed, string): list<string> $each
     * @return list<string>
     */
    private static function listOf(mixed $list, string $path, int $min, int $max, callable $each): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) < $min || count($list) > $max) {
            return ["{$path}: a list of {$min} to {$max}"];
        }
        $errors = [];
        foreach ($list as $i => $item) {
            array_push($errors, ...$each($item, "{$path}[{$i}]"));
        }

        return $errors;
    }

    /** @return list<string> */
    private static function keysOf(mixed $list): array
    {
        $keys = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item) && is_string($item['key'] ?? null)) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private static function duplicateKeys(mixed $list, string $path, string $field = 'key'): array
    {
        $seen = [];
        $errors = [];
        foreach (is_array($list) ? $list : [] as $i => $item) {
            $key = is_array($item) ? ($item[$field] ?? null) : null;
            if (!is_string($key)) {
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = "{$path}[{$i}].{$field}: {$key} is used twice";
            }
            $seen[$key] = true;
        }

        return $errors;
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<string> $known
     * @return list<string>
     */
    private static function unknownKeys(array $object, array $known, string $path): array
    {
        $errors = [];
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = "{$path}.{$key}: unknown key";
            }
        }

        return $errors;
    }
}
