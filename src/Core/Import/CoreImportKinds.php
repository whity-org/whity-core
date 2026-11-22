<?php

declare(strict_types=1);

namespace Whity\Core\Import;

use Whity\Core\Contacts\ContactRepository;
use Whity\Core\Inventory\LocationService;
use Whity\Core\RBAC\CorePermissions;

/**
 * Core's own import kinds (register IM.3): contacts, items, opening stock and
 * supplier prices, as the shared import screen shows them (WHIT-1326, design
 * section 34 §6.5). Each kind's engine is where it was (WHIT-953, WHIT-1322,
 * WHIT-1234); this only describes them.
 */
final class CoreImportKinds
{
    public static function register(ImportKinds $kinds, ?LocationService $locations, ?ContactRepository $contacts): void
    {
        $kinds->register(self::contacts());
        $kinds->register(self::items());
        $kinds->register(self::openingStock($locations));
        $kinds->register(self::supplierPrices($contacts));
    }

    public static function contacts(): ImportKind
    {
        return new ImportKind(
            key: 'contacts',
            label: ['en' => 'Contacts', 'ar' => 'جهات الاتصال'],
            description: ['en' => 'Customers and suppliers, matched by tax number, mobile or email.', 'ar' => 'العملاء والموردون، يُطابَقون بالرقم الضريبي أو الجوال أو البريد الإلكتروني.'],
            permissions: [CorePermissions::CONTACTS_IMPORT],
            path: '/api/v1/contacts/imports',
            area: 'contacts',
            back: ['href' => '/contacts', 'label' => ['en' => 'Go to contacts', 'ar' => 'الذهاب إلى جهات الاتصال']],
            targets: [
                'name' => ['en' => 'Name', 'ar' => 'الاسم'],
                'contact_person' => ['en' => 'Contact person', 'ar' => 'الشخص المسؤول'],
                'email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني'],
                'mobile' => ['en' => 'Mobile', 'ar' => 'الجوال'],
                'phone' => ['en' => 'Phone', 'ar' => 'الهاتف'],
                'tax_number' => ['en' => 'Tax number', 'ar' => 'الرقم الضريبي'],
                'payment_terms' => ['en' => 'How they pay', 'ar' => 'طريقة الدفع'],
                'address' => ['en' => 'Address', 'ar' => 'العنوان'],
                'notes' => ['en' => 'Notes', 'ar' => 'ملاحظات'],
                'tags' => ['en' => 'Tags', 'ar' => 'الوسوم'],
                'type' => ['en' => 'Customer or supplier', 'ar' => 'عميل أو مورد'],
            ],
            required: ['name'],
            options: [
                new ImportOption(
                    key: 'match_by',
                    type: 'select',
                    label: ['en' => 'Find existing contacts by', 'ar' => 'ابحث عن جهات الاتصال الموجودة حسب'],
                    choices: [
                        ['value' => null, 'label' => ['en' => 'Tax number, then mobile, then email', 'ar' => 'الرقم الضريبي، ثم الجوال، ثم البريد الإلكتروني']],
                        ['value' => 'tax_number', 'label' => ['en' => 'Tax number', 'ar' => 'الرقم الضريبي']],
                        ['value' => 'mobile', 'label' => ['en' => 'Mobile', 'ar' => 'الجوال']],
                        ['value' => 'email', 'label' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني']],
                        ['value' => 'name', 'label' => ['en' => 'Name', 'ar' => 'الاسم']],
                    ],
                ),
                new ImportOption(
                    key: 'country_code',
                    type: 'text',
                    label: ['en' => 'Country code for local numbers', 'ar' => 'رمز الدولة للأرقام المحلية'],
                    placeholder: '+962',
                ),
                new ImportOption(
                    key: 'default_kind',
                    type: 'select',
                    label: ['en' => 'Rows without a type are', 'ar' => 'الصفوف التي لا نوع لها هي'],
                    choices: [
                        ['value' => 'customer', 'label' => ['en' => 'Customers', 'ar' => 'العملاء']],
                        ['value' => 'supplier', 'label' => ['en' => 'Suppliers', 'ar' => 'الموردون']],
                        ['value' => 'both', 'label' => ['en' => 'Customer and supplier', 'ar' => 'عميل ومورد']],
                    ],
                    default: 'customer',
                ),
            ],
            words: [
                'title' => ['en' => 'Import contacts', 'ar' => 'استيراد جهات الاتصال'],
                'create' => ['en' => 'New contacts', 'ar' => 'جهات اتصال جديدة'],
                'update' => ['en' => 'Updated', 'ar' => 'محدّثة'],
                'run' => ['en' => 'Import {count} contacts', 'ar' => 'استيراد {count} جهة اتصال'],
            ],
        );
    }

    public static function items(): ImportKind
    {
        return new ImportKind(
            key: 'inventory.items',
            label: ['en' => 'Items', 'ar' => 'الأصناف'],
            description: ['en' => 'Items and their variants, matched by SKU. A row per variant.', 'ar' => 'الأصناف وأنواعها، تُطابَق برمز الصنف. صف لكل نوع.'],
            permissions: [CorePermissions::INVENTORY_WRITE],
            path: '/api/v1/inventory/imports/items',
            area: 'inventory',
            back: ['href' => '/inventory', 'label' => ['en' => 'Go to stock', 'ar' => 'الذهاب إلى المخزون']],
            targets: [
                'sku' => ['en' => 'SKU', 'ar' => 'رمز الصنف'],
                'name' => ['en' => 'Name', 'ar' => 'الاسم'],
                'name_ar' => ['en' => 'Arabic name', 'ar' => 'الاسم بالعربية'],
                'unit' => ['en' => 'Unit', 'ar' => 'الوحدة'],
                'kind' => ['en' => 'Kind', 'ar' => 'النوع'],
                'sale_price' => ['en' => 'Sale price', 'ar' => 'سعر البيع'],
                'purchase_price' => ['en' => 'Purchase price', 'ar' => 'سعر الشراء'],
                'tax_category' => ['en' => 'Tax category', 'ar' => 'فئة الضريبة'],
                'barcode' => ['en' => 'Barcode', 'ar' => 'الباركود'],
                'description' => ['en' => 'Description', 'ar' => 'الوصف'],
                'group' => ['en' => 'Item group', 'ar' => 'مجموعة الصنف'],
                'option1' => ['en' => 'Option 1', 'ar' => 'الخيار 1'],
                'option2' => ['en' => 'Option 2', 'ar' => 'الخيار 2'],
                'option3' => ['en' => 'Option 3', 'ar' => 'الخيار 3'],
            ],
            required: ['sku'],
            options: [],
            words: [
                'title' => ['en' => 'Import items', 'ar' => 'استيراد الأصناف'],
                'create' => ['en' => 'New items', 'ar' => 'أصناف جديدة'],
                'update' => ['en' => 'Updated', 'ar' => 'محدّثة'],
                'run' => ['en' => 'Import {count} items', 'ar' => 'استيراد {count} صنف'],
            ],
            targetHints: [
                'group' => ['en' => 'Rows with the same group are variants of one item.', 'ar' => 'الصفوف ذات المجموعة نفسها أنواع لصنف واحد.'],
                'option1' => ['en' => 'The column’s heading names the option, such as Size.', 'ar' => 'عنوان العمود هو اسم الخيار، مثل المقاس.'],
                'option2' => ['en' => 'The column’s heading names the option, such as Colour.', 'ar' => 'عنوان العمود هو اسم الخيار، مثل اللون.'],
                'option3' => ['en' => 'The column’s heading names the option.', 'ar' => 'عنوان العمود هو اسم الخيار.'],
            ],
        );
    }

    public static function openingStock(?LocationService $locations): ImportKind
    {
        return new ImportKind(
            key: 'inventory.opening_stock',
            label: ['en' => 'Opening stock', 'ar' => 'الرصيد الافتتاحي'],
            description: ['en' => 'What each place holds when you start, at its unit cost.', 'ar' => 'ما يحويه كل مكان عند البدء، بكلفة الوحدة.'],
            permissions: [CorePermissions::INVENTORY_WRITE, CorePermissions::STOCK_ADJUST],
            path: '/api/v1/inventory/imports/opening_stock',
            area: 'inventory',
            back: ['href' => '/inventory', 'label' => ['en' => 'Go to stock', 'ar' => 'الذهاب إلى المخزون']],
            targets: [
                'sku' => ['en' => 'SKU', 'ar' => 'رمز الصنف'],
                'quantity' => ['en' => 'Quantity', 'ar' => 'الكمية'],
                'unit' => ['en' => 'Unit', 'ar' => 'الوحدة'],
                'place' => ['en' => 'Place', 'ar' => 'المكان'],
                'unit_cost' => ['en' => 'Unit cost', 'ar' => 'كلفة الوحدة'],
            ],
            required: ['sku', 'quantity'],
            options: [
                new ImportOption(
                    key: 'location_id',
                    type: 'select',
                    label: ['en' => 'Place for rows that name none', 'ar' => 'المكان للصفوف التي لا تذكر مكانًا'],
                    source: $locations === null ? null : static function (int $tenantId) use ($locations): array {
                        $out = [];
                        foreach ($locations->list($tenantId) as $place) {
                            $names = is_array($place['names']) ? $place['names'] : [];
                            $en = (string) ($names['en'] ?? $place['name']);
                            $out[] = ['value' => (int) $place['id'], 'label' => ['en' => $en, 'ar' => (string) ($names['ar'] ?? $en)]];
                        }

                        return $out;
                    },
                    requiredUnless: 'place',
                    valueType: 'int',
                ),
            ],
            words: [
                'title' => ['en' => 'Import opening stock', 'ar' => 'استيراد الرصيد الافتتاحي'],
                'create' => ['en' => 'Stock to add', 'ar' => 'رصيد سيُضاف'],
                'update' => ['en' => 'Updated', 'ar' => 'محدّثة'],
                'run' => ['en' => 'Import {count} rows of stock', 'ar' => 'استيراد {count} صف من الرصيد'],
            ],
        );
    }

    public static function supplierPrices(?ContactRepository $contacts): ImportKind
    {
        return new ImportKind(
            key: 'inventory.supplier_prices',
            label: ['en' => 'Supplier prices', 'ar' => 'أسعار الموردين'],
            description: ['en' => 'What each supplier sells you and for how much, matched by supplier and SKU.', 'ar' => 'ما يبيعك إياه كل مورد وبكم، يُطابَق بالمورد ورمز الصنف.'],
            permissions: [CorePermissions::INVENTORY_WRITE],
            path: '/api/v1/inventory/imports/supplier_prices',
            area: 'inventory',
            back: ['href' => '/inventory', 'label' => ['en' => 'Go to stock', 'ar' => 'الذهاب إلى المخزون']],
            targets: [
                'supplier' => ['en' => 'Supplier', 'ar' => 'المورد'],
                'sku' => ['en' => 'SKU', 'ar' => 'رمز الصنف'],
                'price' => ['en' => 'Price', 'ar' => 'السعر'],
                'currency' => ['en' => 'Currency', 'ar' => 'العملة'],
                'unit' => ['en' => 'Unit', 'ar' => 'الوحدة'],
                'supplier_sku' => ['en' => 'Supplier’s code', 'ar' => 'رمز المورد'],
                'min_quantity' => ['en' => 'Least they take', 'ar' => 'أقل كمية'],
                'lead_days' => ['en' => 'Lead days', 'ar' => 'أيام التوريد'],
                'preferred' => ['en' => 'Preferred', 'ar' => 'مفضل'],
            ],
            required: ['sku', 'price'],
            options: [
                new ImportOption(
                    key: 'contact_id',
                    type: 'select',
                    label: ['en' => 'Supplier for rows that name none', 'ar' => 'المورد للصفوف التي لا تذكر موردًا'],
                    source: $contacts === null ? null : static function (int $tenantId) use ($contacts): array {
                        $out = [];
                        foreach ($contacts->list($tenantId, ['kind' => 'supplier'], 500, 0) as $contact) {
                            $name = (string) $contact['name'];
                            $out[] = ['value' => (int) $contact['id'], 'label' => ['en' => $name, 'ar' => $name]];
                        }

                        return $out;
                    },
                    requiredUnless: 'supplier',
                    valueType: 'int',
                ),
            ],
            words: [
                'title' => ['en' => 'Import supplier prices', 'ar' => 'استيراد أسعار الموردين'],
                'create' => ['en' => 'New prices', 'ar' => 'أسعار جديدة'],
                'update' => ['en' => 'Updated', 'ar' => 'محدّثة'],
                'run' => ['en' => 'Import {count} prices', 'ar' => 'استيراد {count} سعر'],
            ],
        );
    }
}
