<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Renders transactional email into Whity's email shell (EM.1, built from
 * `docs/design/handoff/emails/`): tables and inline styles, 600px wide, a white
 * card on zinc, the workspace's initial on its colour, 17px body text, one main
 * button 52px tall, dark mode through `prefers-color-scheme`, and Arabic laid out
 * right to left with Noto Sans Arabic. Plus the matching plain-text alternative.
 * Dependency-free (no template engine).
 *
 * Callers supply only the message {@see EmailContent} and the brand
 * {@see EmailBranding}; the layout owns all structure and HTML-escaping, so a
 * caller can never inject markup. Bold is weight 600 (the register rules out 700).
 */
final class EmailLayout
{
    private const TEXT = '#18181b';
    private const SOFT = '#52525b';
    private const FOOT = '#71717a';
    private const FAINT = '#a1a1aa';
    private const LINE = '#e4e4e7';
    private const PANEL = '#f4f4f5';
    private const NOTE = '#fef3c7';
    private const TICK = '#0a7e3a';
    private const DANGER = '#b42318';

    /** The layout's own words, in each language it ships. */
    private const WORDS = [
        'en' => ['sent_with' => 'Sent with Whity', 'help' => 'Need help? Contact'],
        'ar' => ['sent_with' => 'أُرسلت عبر Whity', 'help' => 'تحتاج مساعدة؟ راسل'],
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
        $ar = $c->isArabic();
        $words = self::WORDS[$ar ? 'ar' : 'en'];
        $dir = $ar ? 'rtl' : 'ltr';
        $lang = $ar ? 'ar' : 'en';
        $start = $ar ? 'right' : 'left';
        $end = $ar ? 'left' : 'right';
        $font = $ar
            ? "'Noto Sans Arabic', 'Segoe UI', Tahoma, Arial, sans-serif"
            : "'Noto Sans', 'Segoe UI', Arial, Helvetica, sans-serif";
        $brand = self::esc($b->brandColor);
        $site = self::esc($b->siteName);
        $initial = self::esc($b->initial());
        $heading = self::esc($c->heading);

        $body = '';
        $paragraphs = '';
        foreach ($c->paragraphs as $p) {
            $paragraphs .= self::para($p, self::TEXT);
        }
        if (!$c->rowsFirst) {
            $body .= $paragraphs;
        }

        if ($c->code !== null && $c->code !== '') {
            $body .= '<p style="margin:6px 0 22px;font-family:\'Geist Mono\',\'Noto Sans Mono\',Menlo,Consolas,monospace;'
                . 'font-size:34px;font-weight:600;letter-spacing:8px;color:' . self::TEXT . '" dir="ltr">'
                . self::esc($c->code) . '</p>';
        }

        if ($c->rows !== []) {
            $rows = '';
            foreach ($c->rows as $i => [$label, $value]) {
                $border = $i === 0 ? '0' : '1px solid ' . self::LINE;
                $rows .= '<tr><td style="padding:10px 16px;font-size:16px;color:' . self::SOFT . ';border-top:' . $border . '">'
                    . self::esc($label) . '</td>'
                    . '<td style="padding:10px 16px;font-size:16px;color:' . self::TEXT . ';text-align:' . $end
                    . ';border-top:' . $border . '"><span dir="auto">' . self::esc($value) . '</span></td></tr>';
            }
            $body .= '<table role="presentation" class="rows" width="100%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="margin:4px 0 22px;background:' . self::PANEL . ';border-radius:12px">' . $rows . '</table>';
        }
        if ($c->rowsFirst) {
            $body .= $paragraphs;
        }

        if ($c->checklist !== []) {
            $items = '';
            $tickPad = $ar ? '6px 0 6px 10px' : '6px 10px 6px 0';
            foreach ($c->checklist as $item) {
                $items .= '<tr><td valign="top" style="padding:' . $tickPad . ';font-size:17px;color:' . self::TICK . ';width:20px">&#10003;</td>'
                    . '<td style="padding:6px 0;font-size:17px;line-height:1.5;color:' . self::TEXT . '">' . self::esc($item) . '</td></tr>';
            }
            $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 22px">'
                . $items . '</table>';
        }

        if ($c->proposal !== null) {
            // Dashed: an agent's proposal that has not happened yet.
            $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="margin:4px 0 22px;border:2px dashed ' . $brand . ';border-radius:14px"><tr><td style="padding:16px 18px">'
                . '<p style="margin:0 0 6px;font-size:14px;color:' . self::SOFT . '">' . self::esc($c->proposal['meta']) . '</p>'
                . '<p style="margin:0 0 4px;font-size:19px;font-weight:600;color:' . self::TEXT . '">' . self::esc($c->proposal['title']) . '</p>'
                . '<p style="margin:0;font-size:16px;color:' . self::SOFT . '">' . self::esc($c->proposal['detail']) . '</p>'
                . '</td></tr></table>';
        }

        if ($c->callout !== null && $c->callout !== '') {
            $lead = $c->calloutLead !== null && $c->calloutLead !== ''
                ? '<b style="font-weight:600">' . self::esc($c->calloutLead) . '</b> '
                : '';
            $body .= '<table role="presentation" class="note" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px">'
                . '<tr><td style="padding:14px 16px;border-radius:12px;background:' . self::NOTE . ';font-size:16px;line-height:1.5;color:' . self::TEXT . '">'
                . $lead . self::esc($c->callout) . '</td></tr></table>';
        }

        $ctaUrl = $c->hasCta() ? self::safeUrl((string) $c->ctaUrl) : null;
        if ($ctaUrl !== null) {
            $fill = $c->ctaTone === 'danger' ? self::DANGER : $brand;
            $body .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 22px">'
                . '<tr><td style="border-radius:12px;background:' . $fill . '">'
                . '<a href="' . self::esc($ctaUrl) . '" style="display:inline-block;padding:15px 30px;font-size:17px;font-weight:600;'
                . 'line-height:22px;color:#ffffff;text-decoration:none;border-radius:12px">'
                . self::esc((string) $c->ctaLabel) . '</a></td></tr></table>';
        }

        if ($c->footnote !== null && $c->footnote !== '') {
            $body .= self::para($c->footnote, self::SOFT, 'soft');
        }

        $foot = '';
        if ($c->notice !== null && $c->notice !== '') {
            $foot .= self::footLine(self::esc($c->notice));
        }
        if ($b->footerText !== '') {
            $foot .= self::footLine(self::esc($b->footerText));
        }
        if ($b->supportEmail !== '') {
            $support = self::esc($b->supportEmail);
            $foot .= self::footLine($words['help'] . ' <a href="mailto:' . $support . '" style="color:' . self::FOOT . '">' . $support . '</a>');
        }
        if (!$b->whiteLabel) {
            $foot .= '<p class="wl" style="margin:12px 0 0;font-size:13px;color:' . self::FAINT . '">' . self::esc($words['sent_with']) . '</p>';
        }

        $preheader = '';
        if ($c->preheader !== null && $c->preheader !== '') {
            $preheader = '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . self::esc($c->preheader) . '</div>';
        }

        $text = self::TEXT;
        $panel = self::PANEL;

        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
<title>{$heading}</title>
<style>
  body { margin:0; padding:0; background:{$panel}; }
  a { color:{$brand}; }
  @media (max-width:620px) { .card { padding:24px 20px !important; border-radius:0 !important; } .outer { padding:0 !important; } }
  @media (prefers-color-scheme: dark) {
    body, .bg { background:#09090b !important; } .card { background:#18181b !important; }
    .card h1, .card p, .card td { color:#f4f4f5 !important; } .card .soft, .foot p { color:#a1a1aa !important; }
    .rows, .note td { background:#27272a !important; } .rows td { border-color:#3f3f46 !important; }
  }
</style></head>
<body style="margin:0;padding:0;background:{$panel}">
{$preheader}
<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$panel}"><tr><td class="outer" align="center" style="padding:32px 16px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;font-family:{$font};text-align:{$start}" dir="{$dir}">
<tr><td class="card" style="background:#ffffff;border-radius:18px;padding:36px 40px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px"><tr><td style="width:40px;height:40px;border-radius:11px;background:{$brand};color:#ffffff;font-size:20px;font-weight:600;text-align:center;line-height:40px">{$initial}</td><td style="width:12px"></td><td style="font-size:18px;font-weight:600;color:{$text}">{$site}</td></tr></table>
<h1 style="margin:0 0 14px;font-size:25px;line-height:1.3;font-weight:600;color:{$text}">{$heading}</h1>
{$body}
</td></tr>
<tr><td class="foot" style="padding:20px 8px 0;font-size:13px;line-height:1.6;color:#71717a">{$foot}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }

    private function renderText(EmailContent $c, EmailBranding $b): string
    {
        $words = self::WORDS[$c->isArabic() ? 'ar' : 'en'];
        $lines = [$b->siteName, '', $c->heading, ''];
        foreach ($c->paragraphs as $p) {
            $lines[] = $p;
            $lines[] = '';
        }
        if ($c->code !== null && $c->code !== '') {
            $lines[] = $c->code;
            $lines[] = '';
        }
        if ($c->rows !== []) {
            foreach ($c->rows as [$label, $value]) {
                $lines[] = $label . ': ' . $value;
            }
            $lines[] = '';
        }
        if ($c->checklist !== []) {
            foreach ($c->checklist as $item) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }
        if ($c->proposal !== null) {
            $lines[] = $c->proposal['meta'];
            $lines[] = $c->proposal['title'];
            $lines[] = $c->proposal['detail'];
            $lines[] = '';
        }
        if ($c->callout !== null && $c->callout !== '') {
            $lines[] = trim(($c->calloutLead ?? '') . ' ' . $c->callout);
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
        if ($c->notice !== null && $c->notice !== '') {
            $lines[] = $c->notice;
        }
        if ($b->footerText !== '') {
            $lines[] = $b->footerText;
        }
        if ($b->supportEmail !== '') {
            $lines[] = $words['help'] . ' ' . $b->supportEmail;
        }
        if (!$b->whiteLabel) {
            $lines[] = $words['sent_with'];
        }

        return implode("\n", $lines) . "\n";
    }

    private static function para(string $text, string $color, string $class = ''): string
    {
        $cls = $class !== '' ? ' class="' . $class . '"' : '';

        return '<p' . $cls . ' style="margin:0 0 16px;font-size:17px;line-height:1.6;color:' . $color . '">' . self::esc($text) . '</p>';
    }

    /** A footer line; $html is already escaped. */
    private static function footLine(string $html): string
    {
        return '<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:' . self::FOOT . '">' . $html . '</p>';
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

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
