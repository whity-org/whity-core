<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use Whity\Core\i18n\LocalizedText;
use Whity\Core\Inventory\InventoryNames;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Money\Currency;
use Whity\Core\Settings\SettingsService;

/**
 * The workspace's price list as a page to print (WHIT-1334, design section 35
 * §5.2, register SL.8), moved from the Salons plugin: every active service in
 * English and Arabic, how long it takes and its price, under the workspace's
 * name, phone and colour. The name and price come from each service's
 * inventory item, which is the price; how long comes from the calendar. Read
 * each time, nothing stored. A4, one column, the Print button hidden on paper.
 *
 * It becomes a document starter when the brand team's card arrives.
 */
final class PriceList
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ItemService $items,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return array{html: string, nonce: string} */
    public function page(int $tenantId, string $language = 'en'): array
    {
        $effective = $this->settings->effective($tenantId);
        $text = static fn (string $key): string => is_string($effective[$key] ?? null) ? trim($effective[$key]) : '';
        $nameEn = $text('company.name') !== '' ? $text('company.name') : $text('site_name');
        $nameAr = $text('company.name_ar');
        $phone = $text('company.phone');
        $address = $text('company.address');
        $colour = preg_match('/^#[0-9a-fA-F]{6}$/', $text('branding_main_color')) === 1 ? $text('branding_main_color') : '#1f2933';
        $ar = $language === 'ar';

        $rows = '';
        foreach ($this->lines($tenantId) as $line) {
            $rows .= '<tr><td class="en">' . self::e($line['en']) . '</td><td class="ar" lang="ar" dir="rtl">' . self::e($line['ar'])
                . '</td><td class="num">' . $line['minutes'] . ' min · ' . $line['minutes'] . ' د'
                . '</td><td class="num price">' . self::e($line['price']) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">' . ($ar ? 'لا خدمات بعد. أضفها من التقويم › الخدمات.' : 'No services yet. Add them in Calendar › Services.') . '</td></tr>';
        }

        $nonce = bin2hex(random_bytes(12));
        $title = $ar ? 'قائمة الأسعار' : 'Price list';
        $contact = implode(' · ', array_filter([$phone, $address], static fn (string $v): bool => $v !== ''));

        $html = '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title . ' · ' . $nameEn) . '</title>'
            . '<style>'
            . '@page{size:A4;margin:18mm}'
            . 'body{margin:0;padding:24px;font-family:"Noto Sans","Noto Sans Arabic",system-ui,sans-serif;font-size:16px;color:#1f2933;background:#fff}'
            . 'header{border-bottom:4px solid ' . $colour . ';padding-bottom:12px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}'
            . 'h1{margin:0;font-size:28px;font-weight:600;color:' . $colour . '}'
            . '.sub{margin:4px 0 0;font-size:15px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'td{padding:10px 8px;border-bottom:1px solid #d9dee3;vertical-align:baseline}'
            . '.ar{text-align:right}.num{white-space:nowrap;direction:ltr;text-align:end}.price{font-weight:600}'
            . '.empty{text-align:center;padding:32px}'
            . 'button{font:inherit;font-size:15px;min-height:40px;padding:0 16px;border-radius:8px;border:1px solid ' . $colour . ';background:#fff;color:' . $colour . ';cursor:pointer}'
            . '@media print{button{display:none}body{padding:0}}'
            . '</style></head><body>'
            . '<header><div><h1>' . self::e($nameEn) . ($nameAr !== '' ? ' · <span lang="ar" dir="rtl">' . self::e($nameAr) . '</span>' : '') . '</h1>'
            . '<p class="sub">' . self::e($title) . ($contact !== '' ? ' · ' . self::e($contact) : '') . '</p></div>'
            . '<button type="button" id="print">' . self::e($ar ? 'طباعة' : 'Print') . '</button></header>'
            . '<table><tbody>' . $rows . '</tbody></table>'
            . '<script nonce="' . $nonce . '">document.getElementById("print").addEventListener("click",function(){window.print()});</script>'
            . '</body></html>';

        return ['html' => $html, 'nonce' => $nonce];
    }

    /**
     * The active services, in the calendar's order, with the item's names and price.
     *
     * @return list<array{en: string, ar: string, minutes: int, price: string}>
     */
    public function lines(int $tenantId): array
    {
        $out = [];
        foreach ($this->services->list($tenantId, true) as $s) {
            $names = is_array($s['name']) ? $s['name'] : [];
            $price = is_int($s['price_minor']) ? $s['price_minor'] : null;
            $currency = is_string($s['currency']) ? $s['currency'] : '';
            $item = is_int($s['item_id'] ?? null) ? $this->items->row($tenantId, $s['item_id']) : null;
            if ($item !== null) {
                $names = InventoryNames::decode($item['names'] ?? '{}', (string) ($item['name'] ?? '')) + $names;
                $price = isset($item['sale_price_minor']) ? (int) $item['sale_price_minor'] : $price;
                $currency = is_string($item['currency'] ?? null) && trim($item['currency']) !== '' ? trim($item['currency']) : $currency;
            }
            $out[] = [
                'en' => LocalizedText::resolve(array_filter($names, 'is_string'), 'en'),
                'ar' => is_string($names['ar'] ?? null) ? $names['ar'] : '',
                'minutes' => (int) $s['duration_minutes'],
                'price' => $price !== null && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? Currency::display($price, $currency) : '',
            ];
        }

        return $out;
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
