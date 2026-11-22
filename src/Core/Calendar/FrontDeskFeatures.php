<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Router;

/**
 * The front desk's Walk-ins screen (WHIT-1334, design section 35 §3, register
 * SL.12), a server-driven block screen like Convening's
 * ({@see \Whity\Core\Convening\ConveningFeatures}), moved from the Salons
 * plugin with its English and Arabic. Only block types that already exist.
 *
 * The words are general (a member of staff, not a stylist), since any business
 * that takes bookings may use it.
 *
 * `requiredPermission` only decides whether the screen appears; the routes
 * behind it enforce `calendar:front_desk` and the workspace's switch.
 */
final class FrontDeskFeatures
{
    public const WALKINS = 'calendar-walkins';

    /** WHIT-1334 (SL.8): the price list and the booking QR poster, to print. */
    public const PRINTABLES = 'calendar-printables';

    /** @return list<array<string, mixed>> */
    public static function all(Router $router): array
    {
        return [
            self::walkins($router->versionedPath('/api/calendar/walkins')),
            self::printables($router->versionedPath('/api/calendar/price-list')),
        ];
    }

    /**
     * The printables' menu entry, shown beside Walk-ins (a front desk's
     * workspace).
     *
     * @return array<string, mixed>
     */
    public static function printablesNavigationItem(string $language): array
    {
        return [
            'id' => self::PRINTABLES,
            'label' => LocalizedText::resolve(self::printablesLabel(), $language),
            'href' => '/admin/x/' . self::PRINTABLES,
            'icon' => 'printer',
            'group' => 'work',
            'order' => 44,
            'requiredPermission' => CorePermissions::CALENDAR_READ,
        ];
    }

    /** @return array{en: string, ar: string} */
    private static function printablesLabel(): array
    {
        return ['en' => 'Print for the front desk', 'ar' => 'مطبوعات الاستقبال'];
    }

    /** @return array<string, mixed> */
    private static function printables(string $priceList): array
    {
        return [
            'id' => self::PRINTABLES,
            'plugin' => 'core',
            'label' => self::printablesLabel(),
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CALENDAR_READ,
            'icon' => 'printer',
            'group' => 'work',
            'order' => 44,
            'blocks' => [
                ['type' => 'alert', 'variant' => 'info', 'body' => [
                    'en' => 'Print your price list for the counter or the window, and a QR code customers scan to book. Both always show what is in Whity now: change a service or a price, then print again.',
                    'ar' => 'اطبع قائمة الأسعار للكاونتر أو الواجهة، ورمز QR يمسحه الزبائن للحجز. كلاهما يعرض ما في Whity الآن: غيّر خدمة أو سعراً ثم اطبع مجدداً.',
                ]],
                ['type' => 'button', 'label' => ['en' => 'Open the price list to print', 'ar' => 'فتح قائمة الأسعار للطباعة'], 'href' => $priceList, 'variant' => 'primary'],
                ['type' => 'button', 'label' => ['en' => 'Print the booking QR poster', 'ar' => 'طباعة ملصق QR للحجز'], 'href' => '/calendar/settings', 'variant' => 'outline'],
            ],
        ];
    }

    /**
     * The menu entry, shown only to a workspace with walk-ins on.
     *
     * @return array<string, mixed>
     */
    public static function navigationItem(string $language): array
    {
        $label = self::label();

        return [
            'id' => self::WALKINS,
            'label' => LocalizedText::resolve($label, $language),
            'href' => '/admin/x/' . self::WALKINS,
            'icon' => 'users',
            'group' => 'work',
            'order' => 40,
            'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK,
        ];
    }

    /** @return array{en: string, ar: string} */
    private static function label(): array
    {
        return ['en' => 'Walk-ins', 'ar' => 'الزبائن بدون حجز'];
    }

    /** @return array<string, mixed> */
    private static function walkins(string $api): array
    {
        return [
            'id' => self::WALKINS,
            'plugin' => 'core',
            'label' => self::label(),
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK,
            'icon' => 'users',
            'group' => 'work',
            'order' => 40,
            'blocks' => [
                ['type' => 'alert', 'variant' => 'info', 'body' => [
                    'en' => 'Add people who come in without a booking. The top of the list is next. Handing someone over puts the visit in the calendar, checked in, and the customer in Contacts. Each person counts once a month towards your customers a month; going over never turns anyone away.',
                    'ar' => 'أضف من يأتي بدون حجز. أول القائمة هو التالي. عند تسليم الزبون لأحد الفريق يُضاف الموعد إلى التقويم مسجّل الحضور، ويُضاف الزبون إلى جهات الاتصال. يُحسب كل شخص مرة واحدة في الشهر ضمن زبائن الشهر؛ تجاوز العدد لا يردّ أحداً.',
                ]],
                [
                    'type' => 'modal',
                    'id' => 'add-walkin',
                    'title' => ['en' => 'Add a walk-in', 'ar' => 'إضافة زبون بدون حجز'],
                    'trigger' => ['en' => 'Add a walk-in', 'ar' => 'إضافة زبون'],
                    'variant' => 'primary',
                    'children' => [[
                        'type' => 'form',
                        'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK,
                        'submit' => ['method' => 'POST', 'endpoint' => $api],
                        'children' => [
                            ['type' => 'textInput', 'name' => 'name', 'required' => true, 'label' => ['en' => 'Name', 'ar' => 'الاسم']],
                            ['type' => 'textInput', 'name' => 'phone', 'label' => ['en' => 'Mobile (optional)', 'ar' => 'الجوال (اختياري)'], 'placeholder' => '+962 7…'],
                            [
                                'type' => 'referenceSelect', 'name' => 'service_id', 'required' => true,
                                'label' => ['en' => 'Service', 'ar' => 'الخدمة'],
                                'source' => $api . '/services', 'valueField' => 'id', 'labelField' => 'name',
                            ],
                            [
                                'type' => 'referenceSelect', 'name' => 'profile_id',
                                'label' => ['en' => 'Asked for (optional)', 'ar' => 'طلب شخصاً معيّناً (اختياري)'],
                                'placeholder' => ['en' => 'Whoever is free', 'ar' => 'أي شخص متاح'],
                                'source' => $api . '/staff', 'valueField' => 'profile_id', 'labelField' => 'label',
                            ],
                            ['type' => 'textInput', 'name' => 'note', 'label' => ['en' => 'Note (optional)', 'ar' => 'ملاحظة (اختياري)']],
                            ['type' => 'submitButton', 'label' => ['en' => 'Add to the queue', 'ar' => 'إضافة إلى الدور'], 'variant' => 'primary', 'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK],
                        ],
                    ]],
                ],
                [
                    'type' => 'dataTable',
                    'source' => $api,
                    'emptyText' => ['en' => 'Nobody is waiting.', 'ar' => 'لا أحد ينتظر.'],
                    'columns' => [
                        ['key' => 'position', 'label' => ['en' => 'Turn', 'ar' => 'الدور']],
                        ['key' => 'name', 'label' => ['en' => 'Name', 'ar' => 'الاسم']],
                        ['key' => 'service', 'label' => ['en' => 'Service', 'ar' => 'الخدمة']],
                        ['key' => 'preferred', 'label' => ['en' => 'Asked for', 'ar' => 'طلب']],
                        ['key' => 'waited_minutes', 'label' => ['en' => 'Waiting (minutes)', 'ar' => 'الانتظار (دقائق)']],
                    ],
                    'rowActions' => [
                        ['label' => ['en' => 'Next free person', 'ar' => 'أول شخص متاح'], 'method' => 'POST', 'endpoint' => $api . '/{id}/assign'],
                        ['label' => ['en' => 'Choose who…', 'ar' => 'اختيار الشخص…'], 'open' => 'assign-walkin'],
                        [
                            'label' => ['en' => 'Left', 'ar' => 'غادر'], 'method' => 'POST', 'endpoint' => $api . '/{id}/left',
                            'confirm' => ['en' => 'Take them off the queue? They stay in the history.', 'ar' => 'إزالته من الدور؟ يبقى في السجل.'],
                        ],
                    ],
                ],
                [
                    'type' => 'modal',
                    'id' => 'assign-walkin',
                    'title' => ['en' => 'Hand to someone', 'ar' => 'تسليم لأحد الفريق'],
                    'children' => [[
                        'type' => 'form',
                        'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK,
                        'submit' => ['method' => 'POST', 'endpoint' => $api . '/assign'],
                        'children' => [
                            [
                                'type' => 'referenceSelect', 'name' => 'walkin_id', 'required' => true,
                                'label' => ['en' => 'Who', 'ar' => 'الزبون'],
                                'source' => $api, 'valueField' => 'id', 'labelField' => 'name',
                                'defaultFrom' => 'assign-walkin.id',
                            ],
                            [
                                'type' => 'referenceSelect', 'name' => 'profile_id', 'required' => true,
                                'label' => ['en' => 'Served by', 'ar' => 'يخدمه'],
                                'source' => $api . '/staff', 'valueField' => 'profile_id', 'labelField' => 'label',
                            ],
                            ['type' => 'submitButton', 'label' => ['en' => 'Start now', 'ar' => 'ابدأ الآن'], 'variant' => 'primary', 'requiredPermission' => CorePermissions::CALENDAR_FRONT_DESK],
                        ],
                    ]],
                ],
            ],
        ];
    }
}
