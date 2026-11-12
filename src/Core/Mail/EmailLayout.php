<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Renders transactional email into the branded Whity shell (WC-email, EM.1), the
 * shape of `docs/design/handoff/emails/`: a 600 px card on a light background,
 * tables and inline styles so Gmail, Outlook and Apple Mail agree, and the
 * matching plain-text alternative. Dependency-free (no template engine).
 *
 * What EM.1 asks of every email, and where it is held:
 *   - the sender's own brand in the header: logo, or its initial on a tile in
 *     its colour, then its name ({@see EmailBranding});
 *   - body text 17 px, line height 1.6, nothing under 13 px, no weight above 600;
 *   - ONE main button, 52 px tall, in the brand colour. {@see EmailContent} has
 *     room for one only, so a second cannot be added. An approval email's button
 *     only opens the workspace: the decision is made there;
 *   - English and Arabic: Arabic is `dir="rtl"` in Noto Sans Arabic, mirrored,
 *     while codes, amounts and row values stay left to right;
 *   - "Sent with Whity" in the footer unless the plan removes it (white-label).
 *
 * Colours are the hand-off's, resolved to hex (an email cannot read CSS
 * variables). Dark mode follows the hand-off's `prefers-color-scheme` block.
 *
 * Callers supply only the message {@see EmailContent}; the layout owns all
 * structure and HTML-escaping, so a caller can never inject markup.
 */
final class EmailLayout
{
    // The hand-off's colours (zinc scale), as hex.
    private const PAGE = '#f4f4f5';
    private const CARD = '#ffffff';
    private const INK = '#18181b';
    private const SOFT = '#52525b';
    private const FOOT = '#71717a';
    private const FAINT = '#a1a1aa';
    private const LINE = '#e4e4e7';

    private const FONT_EN = "'Noto Sans', 'Segoe UI', Arial, Helvetica, sans-serif";
    private const FONT_AR = "'Noto Sans Arabic', 'Segoe UI', Tahoma, Arial, sans-serif";
    private const FONT_MONO = "'Geist Mono', 'Noto Sans Mono', Menlo, Consolas, monospace";

    /** The layout's own words, in both languages. */
    private const WORDS = [
        'en' => [
            'help' => 'Need help? Contact %s.',
            'sent_with' => 'Sent with Whity',
        ],
        'ar' => [
            'help' => 'تحتاج مساعدة؟ راسل %s.',
            'sent_with' => 'أُرسلت عبر Whity',
        ],
    ];

    public function render(EmailContent $content, EmailBranding $branding): RenderedEmail
    {
        return new RenderedEmail(
            text: $this->renderText($content, $branding),
            html: $this->renderHtml($content, $branding),
        );
    }

    private function renderHtml(EmailContent $c, EmailBranding $b): string
    {
        $rtl = $c->isRtl();
        $lang = $rtl ? 'ar' : 'en';
        $dir = $rtl ? 'rtl' : 'ltr';
        $start = $rtl ? 'right' : 'left';
        $end = $rtl ? 'left' : 'right';
        // Constants with single quotes only, so they sit safely in a double-quoted style attribute.
        $font = $rtl ? self::FONT_AR : self::FONT_EN;
        $mono = self::FONT_MONO;
        $words = self::WORDS[$lang];

        $brand = self::esc(self::color($b->brandColor));
        $site = self::esc($b->siteName);
        $heading = self::esc($c->heading);
        $ink = self::INK;
        $soft = self::SOFT;
        $foot = self::FOOT;
        $page = self::PAGE;
        $card = self::CARD;
        $line = self::LINE;
        $faint = self::FAINT;

        // Header: the logo, or the initial on a tile in the brand colour, then the name.
        $logo = $b->logoUrl !== null ? self::safeImageUrl($b->logoUrl) : null;
        $mark = $logo !== null
            ? '<td style="width:40px;height:40px"><img src="' . self::esc($logo) . '" width="40" height="40" alt="' . $site . '"'
                . ' style="display:block;width:40px;height:40px;border:0;border-radius:11px"></td>'
            : '<td style="width:40px;height:40px;border-radius:11px;background:' . $brand . ';color:#ffffff;font-size:20px;'
                . 'font-weight:600;text-align:center;line-height:40px">' . self::esc($b->initial()) . '</td>';
        $header = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px"><tr>'
            . $mark . '<td style="width:12px"></td>'
            . '<td style="font-size:18px;font-weight:600;color:' . $ink . '">' . $site . '</td></tr></table>';

        $body = '<h1 style="margin:0 0 14px;font-size:25px;line-height:1.3;font-weight:600;color:' . $ink . '">' . $heading . '</h1>';

        foreach ($c->paragraphs as $p) {
            $body .= '<p style="margin:0 0 16px;font-size:17px;line-height:1.6;color:' . $ink . '">' . self::esc($p) . '</p>';
        }

        // A short code shown large, always left to right.
        if ($c->code !== null && $c->code !== '') {
            $body .= '<p dir="ltr" style="margin:6px 0 22px;font-family:' . $mono . ';font-size:34px;font-weight:600;'
                . 'letter-spacing:8px;color:' . $ink . ';text-align:' . $start . '">' . self::esc($c->code) . '</p>';
        }

        // Label and value rows (an invoice's total); values stay left to right.
        if ($c->rows !== []) {
            $rows = '';
            $first = true;
            foreach ($c->rows as $row) {
                [$label, $value] = self::row($row);
                $border = $first ? 'border-top:0' : 'border-top:1px solid ' . $line;
                $weight = $first ? '600' : '400';
                $rows .= '<tr><td style="padding:10px 16px;font-size:16px;color:' . $soft . ';' . $border . '">' . self::esc($label) . '</td>'
                    . '<td style="padding:10px 16px;font-size:16px;font-weight:' . $weight . ';color:' . $ink . ';text-align:' . $end . ';'
                    . $border . ';white-space:nowrap"><span dir="ltr">' . self::esc($value) . '</span></td></tr>';
                $first = false;
            }
            $body .= '<table role="presentation" class="rows" width="100%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:4px 0 22px;background:' . $page . ';border-radius:12px">' . $rows . '</table>';
        }

        // The callout: a quiet box, or dashed in the brand colour when it has not happened yet.
        if ($c->callout !== null && $c->callout !== '') {
            $box = $c->calloutPending
                ? 'border:2px dashed ' . $brand . ';border-radius:14px'
                : 'background:' . $page . ';border-radius:12px';
            $body .= '<table role="presentation" class="note" width="100%" cellpadding="0" cellspacing="0" border="0"'
                . ' style="margin:4px 0 22px"><tr><td style="padding:14px 16px;' . $box . ';font-size:16px;line-height:1.6;color:' . $ink . '">'
                . self::esc($c->callout) . '</td></tr></table>';
        }

        // The one main button, 52 px tall (15 + 22 + 15), only for a safe link.
        $ctaUrl = $c->hasCta() ? self::safeUrl((string) $c->ctaUrl) : null;
        if ($ctaUrl !== null) {
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 22px"><tr>'
                . '<td style="border-radius:12px;background:' . $brand . '">'
                . '<a href="' . self::esc($ctaUrl) . '" style="display:inline-block;padding:15px 30px;font-size:17px;'
                . 'font-weight:600;line-height:22px;color:#ffffff;text-decoration:none;border-radius:12px">'
                . self::esc((string) $c->ctaLabel) . '</a></td></tr></table>';
        }

        if ($c->footnote !== null && $c->footnote !== '') {
            $body .= '<p class="soft" style="margin:0 0 16px;font-size:17px;line-height:1.6;color:' . $soft . '">'
                . self::esc($c->footnote) . '</p>';
        }

        // Footer: the sender's line, help, and "Sent with Whity" unless white-labelled.
        $footer = '';
        if ($b->footerText !== '') {
            $footer .= '<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:' . $foot . '">' . self::esc($b->footerText) . '</p>';
        }
        if ($b->supportEmail !== '') {
            $support = self::esc($b->supportEmail);
            $link = '<a href="mailto:' . $support . '" style="color:' . $foot . '"><span dir="ltr">' . $support . '</span></a>';
            $footer .= '<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:' . $foot . '">'
                . sprintf(self::esc($words['help']), $link) . '</p>';
        }
        if ($b->sentWithWhity) {
            $footer .= '<p class="wl" style="margin:12px 0 0;font-size:13px;color:' . $faint . '">' . self::esc($words['sent_with']) . '</p>';
        }

        $preheader = $c->preheader !== null && $c->preheader !== ''
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . self::esc($c->preheader) . '</div>' . "\n"
            : '';

        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}" xmlns="http://www.w3.org/1999/xhtml">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
<title>{$heading}</title>
<style>
  body { margin:0; padding:0; background:{$page}; }
  a { color:{$brand}; }
  @media (max-width:620px) { .card { padding:24px 20px !important; border-radius:0 !important; } .outer { padding:0 !important; } }
  @media (prefers-color-scheme: dark) {
    body, .bg { background:#09090b !important; } .card { background:#18181b !important; }
    .card h1, .card p, .card td { color:#f4f4f5 !important; } .card .soft, .foot p { color:#a1a1aa !important; }
    .rows, .note td { background:#27272a !important; } .rows td { border-color:#3f3f46 !important; }
  }
</style></head>
<body style="margin:0;padding:0;background:{$page}">
{$preheader}<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$page}"><tr><td class="outer" align="center" style="padding:32px 16px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;font-family:{$font};text-align:{$start}" dir="{$dir}">
<tr><td class="card" style="background:{$card};border-radius:18px;padding:36px 40px">{$header}{$body}</td></tr>
<tr><td class="foot" style="padding:20px 8px 0;font-size:13px;line-height:1.6;color:{$foot}">{$footer}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }

    private function renderText(EmailContent $c, EmailBranding $b): string
    {
        $words = self::WORDS[$c->isRtl() ? 'ar' : 'en'];
        $lines = [$c->heading, ''];
        foreach ($c->paragraphs as $p) {
            $lines[] = $p;
            $lines[] = '';
        }
        if ($c->code !== null && $c->code !== '') {
            $lines[] = $c->code;
            $lines[] = '';
        }
        if ($c->rows !== []) {
            foreach ($c->rows as $row) {
                [$label, $value] = self::row($row);
                $lines[] = $label . ': ' . $value;
            }
            $lines[] = '';
        }
        if ($c->callout !== null && $c->callout !== '') {
            $lines[] = $c->callout;
            $lines[] = '';
        }
        if ($c->hasCta() && self::safeUrl((string) $c->ctaUrl) !== null) {
            $lines[] = $c->ctaLabel . ': ' . $c->ctaUrl;
            $lines[] = '';
        }
        if ($c->footnote !== null && $c->footnote !== '') {
            $lines[] = $c->footnote;
            $lines[] = '';
        }
        $lines[] = '--';
        $lines[] = $b->siteName;
        if ($b->footerText !== '') {
            $lines[] = $b->footerText;
        }
        if ($b->supportEmail !== '') {
            $lines[] = sprintf($words['help'], $b->supportEmail);
        }
        if ($b->sentWithWhity) {
            $lines[] = $words['sent_with'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A row as label and value, whatever shape the caller passed.
     *
     * @return array{0: string, 1: string}
     */
    private static function row(mixed $row): array
    {
        if (!is_array($row)) {
            return ['', is_scalar($row) ? (string) $row : ''];
        }
        $values = array_values($row);
        $label = $values[0] ?? '';
        $value = $values[1] ?? '';

        return [is_scalar($label) ? (string) $label : '', is_scalar($value) ? (string) $value : ''];
    }

    /** A #RRGGBB colour, or Whity Blue: a colour goes into CSS, so nothing else may. */
    private static function color(string $value): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : EmailBranding::DEFAULT_COLOR;
    }

    /**
     * Allow only http(s)/mailto URLs into the HTML (blocks javascript:, data:,
     * etc.). Returns the URL when safe, else null (the button is omitted).
     */
    private static function safeUrl(string $url): ?string
    {
        return preg_match('#^(https?:)?//#i', $url) === 1
            || preg_match('#^https?:#i', $url) === 1
            || preg_match('#^mailto:#i', $url) === 1
            ? $url
            : null;
    }

    /** Only an absolute http(s) image: an email cannot load anything else. */
    private static function safeImageUrl(string $url): ?string
    {
        return preg_match('#^https?://\S+$#i', $url) === 1 ? $url : null;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
