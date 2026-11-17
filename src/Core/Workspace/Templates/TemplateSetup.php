<?php

declare(strict_types=1);

namespace Whity\Core\Workspace\Templates;

use Whity\Core\Money\Currency;
use Whity\Sdk\Setup\WorkspaceSetup;

/**
 * A template's guided setup (SDK 1.116, WHIT-1195), drawn by core from the
 * manifest's step kinds so a template needs no code.
 *
 * {@see self::onboarding()} builds the steps as block screens, the same shape
 * as a plugin's guided setup (SDK 1.109), so the setup stepper at
 * `/admin/setup/{plugin}` draws them unchanged. Each form posts to core's own
 * template routes, which set the workspace up through {@see WorkspaceSetup} as
 * the person. {@see self::status()} works out which steps are done from what
 * the workspace holds, so a step done elsewhere in the app counts too.
 *
 * When the template offers a paid plugin, a last step says what is free in
 * Whity and what the plugin adds, with a one-click trial.
 */
final class TemplateSetup
{
    /** The setup's name in the onboarding list: never a plugin name, which starts with a capital. */
    public const PREFIX = 'template-';

    public const SWATCHES = ['#0961bb', '#a67c00', '#222222', '#b74c05', '#00792f', '#c53829', '#643b9a', '#c2185b'];

    private const OFFER_STEP = 'offer';

    public function __construct(private readonly WorkspaceSetup $api)
    {
    }

    /**
     * The template's guided setup as a plugin onboarding entry, before the
     * text is resolved to the reader's language.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed>|null $offer the offer as listed (plugin installed and paid), or null
     * @return array<string, mixed>|null null when the template has no setup and no offer
     */
    public static function onboarding(array $template, ?array $offer, string $permission): ?array
    {
        $key = (string) $template['key'];
        $api = '/api/v1/workspace/templates/' . $key . '/setup';
        $steps = [];
        foreach (is_array($template['setup']['steps'] ?? null) ? $template['setup']['steps'] : [] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $steps[] = [
                'id' => $step['id'],
                'title' => $step['title'],
                'description' => $step['why'] ?? null,
                'blocks' => self::blocks((string) $step['kind'], $step, $api, $permission),
            ];
        }
        if ($offer !== null) {
            $steps[] = [
                'id' => self::OFFER_STEP,
                'title' => ['en' => 'What\'s free, and what more you can get', 'ar' => 'ما هو مجاني، وما يمكنك إضافته'],
                'description' => null,
                'blocks' => self::offerBlocks($offer, $api),
            ];
        }
        if ($steps === []) {
            return null;
        }

        return [
            'plugin' => self::PREFIX . $key,
            'title' => $template['setup']['title'] ?? $template['name'],
            'description' => $template['setup']['description'] ?? [
                'en' => 'A few short steps. Everything starts filled in from your template: change what is different for you. You can skip a step and come back to it.',
                'ar' => 'بضع خطوات قصيرة. كل شيء معبّأ من القالب: غيّر ما يختلف عندك. يمكنك تخطي خطوة والعودة إليها.',
            ],
            'permission' => $permission,
            'status' => $api . '/status',
            'steps' => $steps,
        ];
    }

    /**
     * Which steps are done, from what the workspace holds.
     *
     * @param array<string, mixed> $template
     * @param array{state: string}|null $offerState the offered plugin's state here, or null
     * @return array<string, bool>
     */
    public function status(array $template, int $profileId, ?array $offerState, bool $offerClosed): array
    {
        $settings = $this->data('GET', '/api/settings');
        $overridden = is_array($settings['overridden'] ?? null) ? $settings['overridden'] : [];
        $calendar = null;
        $out = [];
        foreach (is_array($template['setup']['steps'] ?? null) ? $template['setup']['steps'] : [] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $calendar ??= in_array($step['kind'], ['booking', 'link'], true) ? $this->data('GET', '/api/calendar/settings') : null;
            $out[(string) $step['id']] = match ($step['kind']) {
                'business' => in_array('company.name', $overridden, true) || in_array('site_name', $overridden, true),
                'hours' => $this->list("/api/calendar/staff/{$profileId}/hours") !== [],
                'services' => $this->list('/api/calendar/services') !== [],
                'team' => count($this->list('/api/users')) > 1 || $this->list('/api/invitations') !== [],
                'booking', 'link' => ($calendar['booking_page_enabled'] ?? false) === true,
                default => false,
            };
        }
        if ($offerState !== null) {
            $out[self::OFFER_STEP] = $offerClosed || $offerState['state'] !== 'off';
        }

        return $out;
    }

    /**
     * What a step opens with: the business details or the person's week.
     *
     * @return array<string, mixed>
     */
    public function current(string $kind, int $profileId): array
    {
        if ($kind === 'hours') {
            $hours = [];
            foreach ($this->list("/api/calendar/staff/{$profileId}/hours") as $row) {
                if (is_int($row['weekday'] ?? null) && is_string($row['start_time'] ?? null) && is_string($row['end_time'] ?? null)) {
                    $hours[] = ['weekday' => $row['weekday'], 'start_time' => substr($row['start_time'], 0, 5), 'end_time' => substr($row['end_time'], 0, 5)];
                }
            }

            return $hours === [] ? [] : ['hours' => $hours];
        }
        if ($kind === 'booking') {
            $rules = $this->data('GET', '/api/calendar/settings');
            $out = [];
            foreach (['min_notice_minutes', 'max_advance_days', 'reminder_hours_before'] as $key) {
                if (is_int($rules[$key] ?? null)) {
                    $out[$key] = (string) $rules[$key];
                }
            }
            if (is_bool($rules['instant_booking'] ?? null)) {
                $out['instant_booking'] = $rules['instant_booking'];
            }

            return $out;
        }
        $settings = $this->data('GET', '/api/settings');
        $effective = is_array($settings['effective'] ?? null) ? $settings['effective'] : [];
        $overridden = is_array($settings['overridden'] ?? null) ? $settings['overridden'] : [];
        $out = [];
        foreach (['name_en' => 'company.name', 'name_ar' => 'company.name_ar', 'phone' => 'company.phone', 'address' => 'company.address'] as $field => $key) {
            if (in_array($key, $overridden, true) && is_string($effective[$key] ?? null) && $effective[$key] !== '') {
                $out[$field] = $effective[$key];
            }
        }
        $colour = $effective['branding_main_color'] ?? null;
        if (in_array('branding_main_color', $overridden, true) && is_string($colour)) {
            $out['colour'] = strtolower($colour);
        }

        return $out;
    }

    /**
     * The template's services as the list to tick, priced in the workspace's
     * currency, without the ones the workspace already has.
     *
     * @param array<string, mixed> $template
     * @return list<array{include: bool, name_en: string, name_ar: string, minutes: string, price: string}>
     */
    public function serviceRows(array $template): array
    {
        $currency = $this->currency();
        $existing = $this->existingServiceNames();
        $rows = [];
        foreach (is_array($template['services'] ?? null) ? $template['services'] : [] as $service) {
            if (!is_array($service) || isset($existing[mb_strtolower((string) $service['name']['en'])])) {
                continue;
            }
            $price = is_array($service['prices'] ?? null) ? ($service['prices'][$currency] ?? '') : '';
            $rows[] = [
                'include' => ($service['ticked'] ?? true) !== false,
                'name_en' => (string) $service['name']['en'],
                'name_ar' => (string) $service['name']['ar'],
                'minutes' => (string) $service['durationMinutes'],
                'price' => is_string($price) ? $price : '',
            ];
        }

        return $rows;
    }

    /**
     * Save a step's form. Writes only what differs, adds and never removes.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function save(string $kind, int $profileId, array $body): array
    {
        return match ($kind) {
            'business' => ['saved' => $this->business($body)],
            'hours' => ['saved' => $this->hours($profileId, $body['hours'] ?? null)],
            'services' => ['added' => $this->services($profileId, is_array($body['services'] ?? null) ? $body['services'] : [])],
            'team' => ['invited' => $this->team(is_array($body['people'] ?? null) ? $body['people'] : [])],
            'booking' => ['saved' => $this->booking($body)],
            default => throw new \InvalidArgumentException('That step saves nothing.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function business(array $body): bool
    {
        $str = static fn (string $key): ?string => is_string($body[$key] ?? null) && trim($body[$key]) !== '' ? trim($body[$key]) : null;
        $colour = $str('colour');
        $values = array_filter([
            'site_name' => $str('name_en'),
            'company.name' => $str('name_en'),
            'company.name_ar' => $str('name_ar'),
            'company.phone' => $str('phone'),
            'company.address' => $str('address'),
            'branding_main_color' => $colour !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $colour) === 1 ? strtolower($colour) : null,
        ], static fn (?string $v): bool => $v !== null);
        if ($values === []) {
            throw new \InvalidArgumentException('Write at least the name of your business.');
        }
        $effective = $this->data('GET', '/api/settings')['effective'] ?? [];
        $effective = is_array($effective) ? $effective : [];
        $values = array_filter($values, static fn (string $v, string $key): bool => mb_strtolower((string) ($effective[$key] ?? '')) !== mb_strtolower($v), ARRAY_FILTER_USE_BOTH);

        return $values === [] || $this->ok($this->api->call('PATCH', '/api/settings', ['settings' => $values]));
    }

    private function hours(int $profileId, mixed $hours): bool
    {
        $rows = [];
        foreach (is_array($hours) ? $hours : [] as $row) {
            if (is_array($row) && is_numeric($row['weekday'] ?? null) && is_string($row['start_time'] ?? null) && is_string($row['end_time'] ?? null)) {
                $rows[] = ['weekday' => (int) $row['weekday'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']];
            }
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('Open at least one day.');
        }
        $this->api->call('PUT', "/api/calendar/staff/{$profileId}", ['bookable' => true]);
        $result = $this->api->call('PUT', "/api/calendar/staff/{$profileId}/hours", ['hours' => $rows]);
        if (!$this->ok($result)) {
            throw new \InvalidArgumentException(self::reason($result));
        }

        return true;
    }

    /**
     * @param array<mixed> $rows
     * @return list<string>
     */
    private function services(int $profileId, array $rows): array
    {
        $currency = $this->currency();
        $existing = $this->existingServiceNames();
        $added = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['include'] ?? true, [true, 'true', '1', 1, 'on'], true)) {
                continue;
            }
            $en = is_string($row['name_en'] ?? null) ? trim($row['name_en']) : '';
            if ($en === '' || isset($existing[mb_strtolower($en)])) {
                continue;
            }
            $minutes = is_numeric($row['minutes'] ?? null) ? (int) $row['minutes'] : 0;
            if ($minutes < 5 || $minutes > 1440) {
                throw new \InvalidArgumentException("{$en}: a service takes between 5 and 1440 minutes.");
            }
            $ar = is_string($row['name_ar'] ?? null) && trim($row['name_ar']) !== '' ? trim($row['name_ar']) : $en;
            $body = ['name' => ['en' => $en, 'ar' => $ar], 'duration_minutes' => $minutes, 'staff_profile_ids' => [$profileId]];
            $price = is_string($row['price'] ?? null) ? trim($row['price']) : '';
            if ($price !== '') {
                try {
                    $body['price_minor'] = Currency::parse(str_contains($price, '.') ? rtrim(rtrim($price, '0'), '.') : $price, $currency);
                    $body['currency'] = $currency;
                } catch (\Throwable) {
                    throw new \InvalidArgumentException("{$en}: \"{$price}\" is not a price.");
                }
            }
            if ($this->ok($this->api->call('POST', '/api/calendar/services', $body))) {
                $added[] = $en;
                $existing[mb_strtolower($en)] = true;
            }
        }

        return $added;
    }

    /**
     * @param array<mixed> $people rows of `{email}`
     * @return list<string>
     */
    private function team(array $people): array
    {
        $already = [];
        foreach ($this->list('/api/invitations') as $invitation) {
            if (is_string($invitation['email'] ?? null)) {
                $already[mb_strtolower($invitation['email'])] = true;
            }
        }
        $invited = [];
        foreach ($people as $row) {
            $email = is_array($row) && is_string($row['email'] ?? null) ? trim($row['email']) : '';
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException("\"{$email}\" is not an email address.");
            }
            if (isset($already[mb_strtolower($email)])) {
                continue;
            }
            $result = $this->api->call('POST', '/api/invitations', ['email' => $email]);
            if (!$this->ok($result)) {
                throw new \InvalidArgumentException(self::reason($result));
            }
            $invited[] = $email;
        }

        return $invited;
    }

    /** @param array<string, mixed> $body */
    private function booking(array $body): bool
    {
        $rules = ['booking_page_enabled' => true];
        foreach (['min_notice_minutes', 'max_advance_days', 'reminder_hours_before'] as $key) {
            if (is_numeric($body[$key] ?? null)) {
                $rules[$key] = (int) $body[$key];
            }
        }
        if (array_key_exists('instant_booking', $body)) {
            $rules['instant_booking'] = in_array($body['instant_booking'], [true, 'true', '1', 1, 'on'], true);
        }
        $result = $this->api->call('PUT', '/api/calendar/settings', $rules);
        if (!$this->ok($result)) {
            throw new \InvalidArgumentException(self::reason($result));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    private static function blocks(string $kind, array $step, string $api, string $permission): array
    {
        $t = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];
        $text = static fn (string $name, array $label, ?string $placeholder = null): array => array_filter(
            ['type' => 'textInput', 'name' => $name, 'label' => $label, 'placeholder' => $placeholder],
            static fn (mixed $v): bool => $v !== null,
        );
        $option = static fn (string $value, string $en, string $ar): array => ['value' => $value, 'label' => $t($en, $ar)];
        $muted = static fn (string $en, string $ar): array => ['type' => 'text', 'tone' => 'muted', 'value' => $t($en, $ar)];
        $form = static fn (string $kind, array $fields, array $save, bool $current = true): array => array_filter([
            'type' => 'form',
            'requiredPermission' => $permission,
            'submit' => ['method' => 'POST', 'endpoint' => $api . '/' . $kind],
            'dataSource' => $current ? ['method' => 'GET', 'path' => $api . '/current/' . $kind] : null,
            'children' => [...$fields, ['type' => 'submitButton', 'label' => $save, 'variant' => 'primary', 'requiredPermission' => $permission]],
        ], static fn (mixed $v): bool => $v !== null);
        $save = $t('Save', 'حفظ');

        return match ($kind) {
            'business' => [
                $form('business', [
                    $text('name_en', $t('Name in English', 'الاسم بالإنجليزية')),
                    $text('name_ar', $t('Name in Arabic', 'الاسم بالعربية')),
                    $text('phone', $t('Phone', 'رقم الهاتف')),
                    $text('address', $t('Address', 'العنوان')),
                    ['type' => 'colorInput', 'name' => 'colour', 'label' => $t('Your colour', 'لونك'), 'swatches' => self::SWATCHES],
                ], $save),
                ['type' => 'button', 'label' => $t('Add your logo', 'أضف شعارك'), 'href' => '/admin/settings/branding', 'variant' => 'outline'],
            ],
            'hours' => [
                $form('hours', [[
                    'type' => 'weeklyHours',
                    'name' => 'hours',
                    'label' => $t('When you are open', 'متى تفتح'),
                    'firstDay' => 6,
                    'required' => true,
                ]], $save),
                $muted('Your own hours, and the hours people can book you. Change anyone\'s in Calendar › Hours and time off.', 'ساعاتك أنت، والساعات التي يمكن الحجز فيها عندك. غيّر ساعات أي شخص من التقويم › الساعات والإجازات.'),
            ],
            'services' => [
                $muted('A ready list from your template, priced in your currency. Untick what you don\'t offer, change any time or price, and add your own.', 'قائمة جاهزة من القالب بأسعار بعملتك. ألغِ اختيار ما لا تقدمه، وغيّر أي وقت أو سعر، وأضف ما لديك.'),
                $form('services', [[
                    'type' => 'fieldArray',
                    'name' => 'services',
                    'label' => $t('Your services', 'خدماتك'),
                    'itemLabel' => $t('Service', 'خدمة'),
                    'addLabel' => $t('Add your own', 'إضافة خدمة خاصة بك'),
                    'source' => $api . '/services',
                    'children' => [
                        ['type' => 'checkbox', 'name' => 'include', 'label' => $t('We offer this', 'نقدّم هذه'), 'default' => true],
                        $text('name_en', $t('Name in English', 'الاسم بالإنجليزية')),
                        $text('name_ar', $t('Name in Arabic', 'الاسم بالعربية')),
                        ['type' => 'numberInput', 'name' => 'minutes', 'label' => $t('Minutes', 'الدقائق'), 'min' => 5, 'max' => 1440, 'step' => 5, 'default' => '30'],
                        $text('price', $t('Price', 'السعر')),
                    ],
                ]], $t('Save the services', 'حفظ الخدمات'), false),
            ],
            'team' => [
                $form('team', [[
                    'type' => 'fieldArray',
                    'name' => 'people',
                    'label' => $t('Who works with you', 'من يعمل معك'),
                    'itemLabel' => $t('Team member', 'عضو الفريق'),
                    'addLabel' => $t('Add a team member', 'إضافة عضو للفريق'),
                    'default' => [['email' => '']],
                    'children' => [$text('email', $t('Their email', 'بريده الإلكتروني'), 'name@example.com')],
                ]], $t('Invite', 'دعوة'), false),
                $muted('Each one gets an email to join. Skip this if you work alone.', 'يصل كل واحد بريد للانضمام. تخطَّ هذه الخطوة إذا كنت تعمل وحدك.'),
            ],
            'booking' => [
                $form('booking', [
                    ['type' => 'select', 'name' => 'min_notice_minutes', 'label' => $t('How soon before a visit can people book', 'أقرب وقت للحجز قبل الموعد'), 'default' => '60', 'options' => [
                        $option('0', 'Any time, even right away', 'في أي وقت، حتى فوراً'),
                        $option('30', '30 minutes before', 'قبل 30 دقيقة'),
                        $option('60', '1 hour before', 'قبل ساعة'),
                        $option('120', '2 hours before', 'قبل ساعتين'),
                        $option('1440', 'The day before', 'قبل يوم'),
                    ]],
                    ['type' => 'select', 'name' => 'max_advance_days', 'label' => $t('How far ahead', 'إلى أي مدى مسبقاً'), 'default' => '30', 'options' => [
                        $option('7', '1 week', 'أسبوع'),
                        $option('14', '2 weeks', 'أسبوعان'),
                        $option('30', '1 month', 'شهر'),
                        $option('90', '3 months', '3 أشهر'),
                    ]],
                    ['type' => 'select', 'name' => 'reminder_hours_before', 'label' => $t('Reminder', 'التذكير'), 'default' => '24', 'options' => [
                        $option('2', '2 hours before', 'قبل ساعتين'),
                        $option('24', 'The day before', 'قبل يوم'),
                        $option('48', 'Two days before', 'قبل يومين'),
                    ]],
                    ['type' => 'checkbox', 'name' => 'instant_booking', 'label' => $t('Bookings are confirmed at once', 'تُؤكَّد الحجوزات فوراً'), 'default' => true],
                ], $t('Save and open the booking page', 'حفظ وفتح صفحة الحجز')),
            ],
            'link' => [
                ['type' => 'text', 'value' => $t(
                    'You are ready. Share your booking link on Instagram, WhatsApp and Google Maps, and print it as a QR code for your counter.',
                    'أنت جاهز. شارك رابط الحجز على إنستغرام وواتساب وخرائط جوجل، واطبعه رمز QR على الكاونتر.',
                )],
                ['type' => 'button', 'label' => $t('Your booking link', 'رابط الحجز'), 'href' => '/calendar/settings', 'variant' => 'primary'],
            ],
            default => array_values(array_filter([
                is_array($step['why'] ?? null) ? ['type' => 'text', 'value' => $step['why']] : null,
                ['type' => 'button', 'label' => $step['action'] ?? $t('Open', 'فتح'), 'href' => (string) ($step['href'] ?? '/'), 'variant' => 'primary'],
            ])),
        };
    }

    /**
     * The last step: free in Whity, what the plugin adds, and its trial.
     *
     * @param array<string, mixed> $offer
     * @return list<array<string, mixed>>
     */
    private static function offerBlocks(array $offer, string $api): array
    {
        $plugin = (string) $offer['plugin'];
        $days = (int) ($offer['trialDays'] ?? 0);
        $ticks = static fn (array $lines): array => array_map(static fn (array $line): array => ['type' => 'text', 'value' => ['en' => '✓ ' . $line['en'], 'ar' => '✓ ' . $line['ar']]], $lines);
        $blocks = [
            ['type' => 'text', 'tone' => 'muted', 'value' => [
                'en' => 'Your template is free, and so is everything below in Whity. ' . $plugin . ' is a paid app that does more for your kind of business.',
                'ar' => 'القالب مجاني، وكذلك كل ما يلي في Whity. ' . $plugin . ' تطبيق مدفوع يقدّم المزيد لنوع عملك.',
            ]],
            ['type' => 'text', 'value' => ['en' => 'Free in Whity', 'ar' => 'مجاناً في Whity']],
            ...$ticks((array) $offer['free']),
            ['type' => 'text', 'value' => ['en' => 'With ' . $plugin, 'ar' => 'مع ' . $plugin]],
            ...$ticks((array) $offer['paid']),
        ];
        if ($days > 0) {
            $blocks[] = [
                'type' => 'actionButton',
                'label' => ['en' => "Start your {$days}-day free trial", 'ar' => "ابدأ تجربة مجانية لمدة {$days} يوماً"],
                'variant' => 'primary',
                'requiredPermission' => 'plugins:enable',
                'action' => ['method' => 'POST', 'endpoint' => $api . '/offer/trial'],
            ];
            $blocks[] = ['type' => 'text', 'tone' => 'muted', 'value' => [
                'en' => 'No card needed. When the trial ends, you choose whether to keep it.',
                'ar' => 'لا حاجة لبطاقة. عند انتهاء التجربة تختار إن كنت تريد الاستمرار.',
            ]];
        }
        $blocks[] = [
            'type' => 'actionButton',
            'label' => ['en' => 'Not now', 'ar' => 'ليس الآن'],
            'variant' => 'outline',
            'action' => ['method' => 'POST', 'endpoint' => $api . '/offer/close'],
        ];

        return array_values($blocks);
    }

    private function currency(): string
    {
        $effective = $this->data('GET', '/api/settings')['effective'] ?? [];
        $currency = is_array($effective) ? ($effective['billing.default_currency'] ?? null) : null;

        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    /** @return array<string, true> lower-cased English and Arabic names of the services there */
    private function existingServiceNames(): array
    {
        $names = [];
        foreach ($this->list('/api/calendar/services') as $service) {
            foreach (is_array($service['name'] ?? null) ? $service['name'] : [$service['name'] ?? null] as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[mb_strtolower(trim($name))] = true;
                }
            }
        }

        return $names;
    }

    /** @return array<mixed> */
    private function data(string $method, string $path): array
    {
        $result = $this->api->call($method, $path);
        $data = is_array($result['body']) ? ($result['body']['data'] ?? null) : null;

        return $this->ok($result) && is_array($data) ? $data : [];
    }

    /** @return list<array<mixed>> */
    private function list(string $path): array
    {
        $data = $this->data('GET', $path);

        return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /** @param array{status: int, body: mixed} $result */
    private function ok(array $result): bool
    {
        return $result['status'] >= 200 && $result['status'] < 300;
    }

    /** @param array{status: int, body: mixed} $result */
    private static function reason(array $result): string
    {
        $body = $result['body'];
        $message = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : 'That could not be saved.';
    }
}
