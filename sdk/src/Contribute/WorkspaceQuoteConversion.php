<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

use Whity\Sdk\Sales\ConversionCheck;
use Whity\Sdk\Sales\ConversionResult;
use Whity\Sdk\Sales\QuoteSnapshot;

/**
 * Turn an accepted quote into something else (SDK 1.132, WHIT-1321, design
 * section 34 §3.3, register QT.6).
 *
 * Quotes ship before the things they turn into, so core never calls a sales
 * order or an invoice directly: it asks each registered target. Core's
 * inventory registers `sales_order` and core's accounting registers `invoice`
 * when they ship.
 *
 * The quote page shows one "Turn into …" button per target whose
 * {@see canConvert()} answers yes. {@see convert()} runs inside the host's
 * transaction: make the target as a draft and answer its id and a link; throw
 * {@see \Whity\Sdk\Hooks\HookVetoException} with a plain reason to refuse,
 * and nothing is written. The host records the conversion once per target,
 * so a retried click never makes two.
 *
 * The workspace comes from the call (the quote's), never from an argument.
 *
 * The host keeps the registry of targets.
 * Plugins offering their own come later, with the per-workspace plugin switch
 * deciding where each one shows.
 */
interface WorkspaceQuoteConversion
{
    /** Stable key, lowercase: `sales_order`, `invoice`, or the plugin's own (`rentals.booking`). */
    public function key(): string;

    /**
     * The button's words, "Turn into …" left out: `['en' => 'Sales order', 'ar' => 'أمر بيع']`.
     *
     * @return array{en: string, ar: string}
     */
    public function label(): array;

    /** Whether this quote can become this target now, with a plain reason when it cannot. */
    public function canConvert(QuoteSnapshot $quote, int $actingProfileId): ConversionCheck;

    /** Make the target from the quote. Runs in the host's transaction. */
    public function convert(QuoteSnapshot $quote, int $actingProfileId): ConversionResult;
}
