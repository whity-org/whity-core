<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\i18n\ServerLabels;

/**
 * The wording core's merge fields send to the editor, in the reader's language
 * (#1044: text the server declares is translated at serving time, and a miss
 * is the English, never a raw key).
 *
 * @i18n-keys documents
 *   fields.group.company = Company
 *   fields.group.today = Today
 *   fields.group.document = Document
 *   fields.company.name = Company name
 *   fields.company.address = Company address
 *   fields.company.taxNumber = Company tax number
 *   fields.today = Today
 *   fields.document.title = Document title
 *   fields.document.number = Document number
 *   fields.document.template = Template
 *   fields.document.issued = Issued on
 *   fields.group.issuedDocument = This document
 *   preflight.check.template = There is a template to issue from
 *   preflight.check.record = The record can be read
 *   preflight.check.values = The values given fit the template
 *   preflight.check.fields = Every required field has a value
 *   preflight.check.origin_key = The origin key can be used
 *   preflight.check.render = A PDF can be made here
 *   preflight.check.plugin = A plugin's own check
 *   preflight.why.invalid_request = The request has a mistake in it
 *   preflight.why.template_not_found = The template wasn't found, or you can't see it
 *   preflight.why.template_unpublished = This template hasn't been published yet
 *   preflight.why.slot_unavailable = The plugin this is for is off here
 *   preflight.why.slot_unbound = No template has been chosen for this yet
 *   preflight.why.slot_misfit = The template chosen for this no longer fits it
 *   preflight.why.background_not_allowed = This workspace hasn't allowed this plugin to issue documents on its own
 *   preflight.why.record_not_found = The record wasn't found, or you can't see it
 *   preflight.why.field_conflict = A value was given for something the record already says
 *   preflight.why.missing_fields = Some required fields have no value
 *   preflight.why.origin_key_conflict = This origin key already names another document
 *   preflight.why.render_unavailable = PDFs can't be made on this instance
 *   preflight.why.persist_unavailable = PDFs can't be stored on this instance
 *   fields.issuedDocument.number = This document's number
 *   fields.month.january = January
 *   fields.month.february = February
 *   fields.month.march = March
 *   fields.month.april = April
 *   fields.month.may = May
 *   fields.month.june = June
 *   fields.month.july = July
 *   fields.month.august = August
 *   fields.month.september = September
 *   fields.month.october = October
 *   fields.month.november = November
 *   fields.month.december = December
 *   fields.inWords.en = {field} in words (English)
 *   fields.inWords.ar = {field} in words (Arabic)
 *   fields.yes = Yes
 *   fields.no = No
 */
final class FieldLabels
{
    public const DOMAIN = 'documents';

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public function __construct(private readonly ?ServerLabels $labels = null)
    {
    }

    public function get(string $key, string $english): string
    {
        return $this->labels?->label(self::DOMAIN, $key, $english) ?? $english;
    }

    /**
     * "29 October 2026": the day and year stay Western digits (stage 5), the
     * month is in the reader's language.
     */
    public function date(\DateTimeInterface $date): string
    {
        $month = self::MONTHS[(int) $date->format('n') - 1];

        return $date->format('j') . ' ' . $this->get('fields.month.' . strtolower($month), $month) . ' ' . $date->format('Y');
    }
}
