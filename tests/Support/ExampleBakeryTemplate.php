<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A full workspace template for tests (SDK 1.116, WHIT-1194): Example Bakery,
 * with made-up contacts only.
 */
final class ExampleBakeryTemplate
{
    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $t = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];

        return [
            'key' => 'bakery',
            'version' => 2,
            'name' => $t('Bakery or café', 'مخبز أو مقهى'),
            'summary' => $t('Orders, a menu with prices and opening hours.', 'الطلبات وقائمة بالأسعار وساعات العمل.'),
            'icon' => 'cake',
            'setsUp' => [$t('Your menu with prices', 'قائمتك بالأسعار'), $t('A cake order form', 'نموذج طلب كيك')],
            'settings' => ['billing.default_currency' => 'JOD', 'timezone' => 'Asia/Amman'],
            'calendar' => [
                'settings' => ['min_notice_minutes' => 60, 'booking_page_enabled' => true],
                'hours' => [['weekday' => 6, 'start_time' => '07:00', 'end_time' => '15:00']],
            ],
            'services' => [
                ['key' => 'cake-order', 'name' => $t('Cake order', 'طلب كيك'), 'durationMinutes' => 15, 'prices' => ['JOD' => '20.000', 'USD' => '28.00']],
                ['key' => 'tasting', 'name' => $t('Tasting', 'تذوق'), 'durationMinutes' => 30, 'ticked' => false],
            ],
            'roles' => [
                ['key' => 'counter', 'name' => $t('Counter', 'الكاونتر'), 'permissions' => ['calendar:write', 'contacts:write', 'roles:delete']],
            ],
            'forms' => [
                ['key' => 'cake-order', 'name' => $t('Cake order', 'طلب كيك'), 'publish' => true, 'fields' => [
                    ['key' => 'flavour', 'type' => 'select', 'label' => $t('Flavour', 'النكهة'), 'required' => true, 'options' => [
                        ['value' => 'chocolate', 'label' => $t('Chocolate', 'شوكولاتة')],
                    ]],
                ]],
            ],
            'documents' => [
                ['key' => 'price-list', 'kind' => 'note', 'title' => $t('Price list', 'قائمة الأسعار'), 'blocks' => [
                    'en' => [['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Our prices']]]],
                    'ar' => [['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'أسعارنا']]]],
                ]],
            ],
            'examples' => [
                'contacts' => [['key' => 'awss', 'name' => 'Awss Haddad', 'email' => 'awss@example.com']],
                'bookings' => [['key' => 'first', 'service' => 'cake-order', 'contact' => 'awss', 'inDays' => 2, 'time' => '10:30']],
            ],
            'setup' => ['steps' => [
                ['id' => 'hours', 'kind' => 'hours', 'title' => $t('Opening hours', 'ساعات العمل')],
                ['id' => 'menu', 'kind' => 'services', 'title' => $t('Your menu', 'قائمتك')],
                ['id' => 'logo', 'kind' => 'screen', 'title' => $t('Your logo', 'شعارك'), 'href' => '/admin/settings/branding', 'action' => $t('Open Branding', 'فتح الهوية')],
            ]],
            'offer' => [
                'plugin' => 'FrontOffice',
                'free' => [$t('Bookings and a booking page', 'الحجوزات وصفحة الحجز')],
                'paid' => [$t('Orders at the counter by QR', 'الطلبات عند الكاونتر برمز QR')],
            ],
        ];
    }
}
