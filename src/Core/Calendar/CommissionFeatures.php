<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Router;

/**
 * The Commission screen (WHIT-1334, design section 35 §4, register SL.12), a
 * server-driven block screen moved from the Salons plugin with its English and
 * Arabic, in general words (a member of staff, not a stylist). Only block
 * types that already exist.
 *
 * `requiredPermission` only decides whether the screen appears; the routes
 * behind it enforce `calendar:commission`.
 */
final class CommissionFeatures
{
    public const COMMISSION = 'calendar-commission';

    /** @return list<array<string, mixed>> */
    public static function all(Router $router): array
    {
        return [self::commission($router->versionedPath('/api/calendar/commission'))];
    }

    /** @return array<string, mixed> */
    public static function navigationItem(string $language): array
    {
        return [
            'id' => self::COMMISSION,
            'label' => LocalizedText::resolve(self::label(), $language),
            'href' => '/admin/x/' . self::COMMISSION,
            'icon' => 'percent',
            'group' => 'work',
            'order' => 43,
            'requiredPermission' => CorePermissions::CALENDAR_COMMISSION,
        ];
    }

    /** @return array{en: string, ar: string} */
    private static function label(): array
    {
        return ['en' => 'Commission', 'ar' => 'العمولات'];
    }

    /** @return array<string, mixed> */
    private static function commission(string $api): array
    {
        return [
            'id' => self::COMMISSION,
            'plugin' => 'core',
            'label' => self::label(),
            'screen' => 'blocks',
            'requiredPermission' => CorePermissions::CALENDAR_COMMISSION,
            'icon' => 'percent',
            'group' => 'work',
            'order' => 43,
            'blocks' => [
                ['type' => 'alert', 'variant' => 'info', 'body' => [
                    'en' => "Set what share of each booking's price a member of staff earns. A rate for one service replaces their rate for that service. The report counts bookings that are done: confirmed and already over, at the price on the booking.",
                    'ar' => 'حدّد نسبة الموظف من سعر كل حجز. النسبة الخاصة بخدمة تحلّ محل نسبته لتلك الخدمة. يحسب التقرير الحجوزات المنتهية: المؤكدة التي انقضى وقتها، بالسعر المسجل في الحجز.',
                ]],
                [
                    'type' => 'selector',
                    'name' => 'period',
                    'label' => ['en' => 'Period', 'ar' => 'الفترة'],
                    'source' => $api . '/periods',
                    'valueField' => 'id',
                    'labelField' => 'label',
                ],
                [
                    'type' => 'dataTable',
                    'source' => $api . '/report',
                    'params' => [['param' => 'period', 'from' => 'period']],
                    'emptyText' => ['en' => 'No done bookings in this period.', 'ar' => 'لا حجوزات منتهية في هذه الفترة.'],
                    'columns' => [
                        ['key' => 'person', 'label' => ['en' => 'Staff member', 'ar' => 'الموظف']],
                        ['key' => 'bookings', 'label' => ['en' => 'Bookings', 'ar' => 'الحجوزات']],
                        ['key' => 'sales', 'label' => ['en' => 'Sales', 'ar' => 'المبيعات']],
                        ['key' => 'commission', 'label' => ['en' => 'Commission', 'ar' => 'العمولة']],
                    ],
                ],
                [
                    'type' => 'modal',
                    'id' => 'set-rate',
                    'title' => ['en' => 'Set a commission rate', 'ar' => 'تحديد نسبة عمولة'],
                    'trigger' => ['en' => 'Set a rate', 'ar' => 'تحديد نسبة'],
                    'variant' => 'outline',
                    'children' => [[
                        'type' => 'form',
                        'requiredPermission' => CorePermissions::CALENDAR_COMMISSION,
                        'submit' => ['method' => 'POST', 'endpoint' => $api . '/rates'],
                        'children' => [
                            [
                                'type' => 'referenceSelect', 'name' => 'profile_id', 'required' => true,
                                'label' => ['en' => 'Staff member', 'ar' => 'الموظف'],
                                'source' => $api . '/staff', 'valueField' => 'profile_id', 'labelField' => 'name',
                            ],
                            [
                                'type' => 'referenceSelect', 'name' => 'service_id',
                                'label' => ['en' => 'Service (optional)', 'ar' => 'الخدمة (اختياري)'],
                                'placeholder' => ['en' => 'All services', 'ar' => 'كل الخدمات'],
                                'source' => $api . '/services', 'valueField' => 'id', 'labelField' => 'name',
                            ],
                            ['type' => 'textInput', 'name' => 'percent', 'required' => true, 'label' => ['en' => 'Percentage of the price', 'ar' => 'النسبة من السعر'], 'placeholder' => '30'],
                            ['type' => 'submitButton', 'label' => ['en' => 'Save rate', 'ar' => 'حفظ النسبة'], 'variant' => 'primary', 'requiredPermission' => CorePermissions::CALENDAR_COMMISSION],
                        ],
                    ]],
                ],
                [
                    'type' => 'dataTable',
                    'source' => $api . '/rates',
                    'emptyText' => ['en' => 'No rates yet. Someone without one earns no commission.', 'ar' => 'لا نسب بعد. من ليست له نسبة لا يحصل على عمولة.'],
                    'columns' => [
                        ['key' => 'person', 'label' => ['en' => 'Staff member', 'ar' => 'الموظف']],
                        ['key' => 'service', 'label' => ['en' => 'Service', 'ar' => 'الخدمة']],
                        ['key' => 'percent', 'label' => ['en' => 'Rate', 'ar' => 'النسبة']],
                    ],
                    'rowActions' => [[
                        'label' => ['en' => 'Remove', 'ar' => 'إزالة'], 'method' => 'DELETE', 'endpoint' => $api . '/rates/{id}',
                        'confirm' => ['en' => "Remove this rate? Reports will use the person's other rate, or none.", 'ar' => 'إزالة هذه النسبة؟ ستستخدم التقارير النسبة الأخرى للموظف، أو لا شيء.'],
                    ]],
                ],
                [
                    'type' => 'modal',
                    'id' => 'close-period',
                    'title' => ['en' => 'Close a period', 'ar' => 'إقفال فترة'],
                    'trigger' => ['en' => 'Close a period', 'ar' => 'إقفال فترة'],
                    'variant' => 'outline',
                    'children' => [[
                        'type' => 'form',
                        'requiredPermission' => CorePermissions::CALENDAR_COMMISSION,
                        'submit' => ['method' => 'POST', 'endpoint' => $api . '/closes'],
                        'children' => [
                            ['type' => 'alert', 'variant' => 'info', 'body' => [
                                'en' => 'Closing freezes what each person earned in a period that has ended, so later changes to prices or rates never move it, and sends it to the books when they take commission. A closed period cannot be closed again.',
                                'ar' => 'الإقفال يثبّت ما حصل عليه كل شخص في فترة انتهت، فلا تغيّره تعديلات الأسعار أو النسب لاحقاً، ويرسله إلى الدفاتر حين تستقبل العمولات. لا يمكن إقفال فترة مقفلة مرة أخرى.',
                            ]],
                            [
                                'type' => 'referenceSelect', 'name' => 'period', 'required' => true,
                                'label' => ['en' => 'Period', 'ar' => 'الفترة'],
                                'source' => $api . '/closes/periods', 'valueField' => 'id', 'labelField' => 'label',
                            ],
                            ['type' => 'submitButton', 'label' => ['en' => 'Close this period', 'ar' => 'إقفال هذه الفترة'], 'variant' => 'primary', 'requiredPermission' => CorePermissions::CALENDAR_COMMISSION],
                        ],
                    ]],
                ],
                [
                    'type' => 'dataTable',
                    'source' => $api . '/closes',
                    'emptyText' => ['en' => 'No closed periods yet.', 'ar' => 'لا فترات مقفلة بعد.'],
                    'columns' => [
                        ['key' => 'period', 'label' => ['en' => 'Closed period', 'ar' => 'الفترة المقفلة']],
                        ['key' => 'total', 'label' => ['en' => 'Commission', 'ar' => 'العمولة']],
                        ['key' => 'books', 'label' => ['en' => 'In the books', 'ar' => 'في الدفاتر']],
                    ],
                    'rowActions' => [[
                        'label' => ['en' => 'Send to the books', 'ar' => 'إرسال إلى الدفاتر'], 'method' => 'POST', 'endpoint' => $api . '/closes/{id}/book',
                    ]],
                ],
            ],
        ];
    }
}
