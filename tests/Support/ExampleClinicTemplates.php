<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Clinic, Dental built on it, and an extension, for tests of templates that
 * build on each other (SDK 1.170, WHIT-1413). Dental is an SDK test example
 * only, not a store template (owner, 25 Nov 2026, design section 40 Q4).
 * Made-up people only.
 */
final class ExampleClinicTemplates
{
    /** @return array{en: string, ar: string} */
    private static function t(string $en, string $ar): array
    {
        return ['en' => $en, 'ar' => $ar];
    }

    /** @return array<string, mixed> */
    public static function clinic(int $version = 1, string $consultationPrice = '20.00'): array
    {
        return [
            'key' => 'clinic',
            'version' => $version,
            'name' => self::t('Clinic', 'عيادة'),
            'summary' => self::t('Appointments and a patient intake form.', 'مواعيد ونموذج استقبال المرضى.'),
            'setsUp' => [self::t('Visits you confirm', 'زيارات تؤكدها أنت')],
            'settings' => ['timezone' => 'Asia/Amman'],
            'calendar' => ['settings' => ['instant_booking' => false, 'min_notice_minutes' => 120]],
            'services' => [
                ['key' => 'consultation', 'name' => self::t('Consultation', 'استشارة'), 'durationMinutes' => 30, 'prices' => ['USD' => $consultationPrice, 'JOD' => '14.000']],
                ['key' => 'check-up', 'name' => self::t('Check-up', 'فحص دوري'), 'durationMinutes' => 45, 'prices' => ['USD' => '40.00', 'JOD' => '28.000']],
            ],
            'roles' => [
                ['key' => 'reception', 'name' => self::t('Reception', 'الاستقبال'), 'permissions' => ['calendar:read', 'calendar:write']],
            ],
            'forms' => [
                ['key' => 'patient-intake', 'name' => self::t('Patient intake', 'استقبال المريض'), 'publish' => true, 'fields' => [
                    ['key' => 'full_name', 'type' => 'text', 'label' => self::t('Full name', 'الاسم الكامل'), 'required' => true],
                ]],
            ],
            'examples' => [
                'contacts' => [['key' => 'ibrahim', 'name' => 'Ibrahim Mansour', 'email' => 'ibrahim@example.com']],
                'bookings' => [['key' => 'first', 'service' => 'consultation', 'contact' => 'ibrahim', 'inDays' => 2, 'time' => '10:00']],
            ],
            'setup' => ['title' => self::t('Set up your clinic', 'إعداد عيادتك'), 'steps' => [
                ['id' => 'hours', 'kind' => 'hours', 'title' => self::t('Opening hours', 'ساعات العمل')],
                ['id' => 'services', 'kind' => 'services', 'title' => self::t('Visits and fees', 'الزيارات والرسوم')],
            ]],
            'changes' => [['version' => $version, 'summary' => self::t('Visits and an intake form', 'زيارات ونموذج استقبال')]],
        ];
    }

    /** @return array<string, mixed> */
    public static function dental(): array
    {
        return [
            'key' => 'dental',
            'version' => 1,
            'extends' => 'clinic',
            'remove' => ['services' => ['check-up']],
            'name' => self::t('Dental clinic', 'عيادة أسنان'),
            'summary' => self::t('A clinic with cleanings and fillings.', 'عيادة مع تنظيف وحشوات.'),
            'setsUp' => [self::t('Cleanings and fillings', 'تنظيف وحشوات')],
            'services' => [
                ['key' => 'cleaning', 'name' => self::t('Cleaning', 'تنظيف'), 'durationMinutes' => 45, 'prices' => ['USD' => '60.00', 'JOD' => '40.000']],
            ],
            'examples' => [
                'bookings' => [['key' => 'cleaning', 'service' => 'cleaning', 'contact' => 'ibrahim', 'inDays' => 3, 'time' => '11:00']],
            ],
            'setup' => ['steps' => [
                ['id' => 'chairs', 'kind' => 'team', 'title' => self::t('Your dentists', 'أطباء الأسنان')],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function patientFile(int $version = 1): array
    {
        return [
            'key' => 'patient-file',
            'version' => $version,
            'target' => 'clinic',
            'setsUp' => [self::t('A file for each patient', 'ملف لكل مريض')],
            'forms' => [
                ['key' => 'visit-notes', 'name' => self::t('Visit notes', 'ملاحظات الزيارة'), 'fields' => [
                    ['key' => 'notes', 'type' => 'textarea', 'label' => self::t('Notes', 'ملاحظات')],
                ]],
            ],
        ];
    }
}
