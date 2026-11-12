<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

/**
 * One kind of email a plugin sends (WHIT-959), such as an invoice or a receipt.
 * Declare it through {@see PluginEmailTemplatesInterface} and send it with
 * {@see EmailSender}.
 *
 *     final class InvoiceEmail implements EmailTemplate
 *     {
 *         public function key(): string { return 'invoice'; }
 *
 *         public function build(array $data, string $locale): EmailMessage
 *         {
 *             $ar = $locale === 'ar';
 *             return new EmailMessage(
 *                 subject: $ar ? "فاتورة {$data['number']}" : "Invoice {$data['number']}",
 *                 heading: $ar ? 'فاتورة جديدة' : 'A new invoice',
 *                 buttonLabel: $ar ? 'اعرض الفاتورة' : 'View the invoice',
 *                 buttonUrl: $data['url'],
 *                 rows: [[$ar ? 'المجموع' : 'Total', $data['total']]],
 *             );
 *         }
 *     }
 */
interface EmailTemplate
{
    /**
     * A BARE key: lowercase, starting with a letter, then letters, digits,
     * underscores or dots. The host stores it as `<plugin>:<key>`.
     */
    public function key(): string;

    /**
     * Build the email from the data the plugin passed to
     * {@see EmailSender::send()}. `$locale` is `en` or `ar`; write the email in
     * that language. Throw {@see \InvalidArgumentException} for missing data.
     *
     * @param array<string, mixed> $data
     */
    public function build(array $data, string $locale): EmailMessage;
}
