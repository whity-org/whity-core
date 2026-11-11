<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * What a channel can do, so the inbox and the send API adapt to it instead of
 * asking which channel it is (SDK 1.97).
 */
final class ChannelCapabilities
{
    /**
     * @param bool     $supportsSubject       Messages have a subject line (email).
     * @param bool     $supportsHtml          A message may carry an HTML body.
     * @param bool     $deliveryReceipts      The provider reports delivered.
     * @param bool     $readReceipts          The provider reports read.
     * @param int      $maxBodyLength         Longest body in characters; core refuses longer.
     * @param bool     $requiresTemplate      Outside the free-form window only a template may be sent.
     * @param int|null $freeformWindowSeconds How long after the customer's last message
     *                                        free text is allowed (WhatsApp: 86400). Null: always.
     */
    public function __construct(
        public readonly bool $supportsSubject = false,
        public readonly bool $supportsHtml = false,
        public readonly bool $deliveryReceipts = false,
        public readonly bool $readReceipts = false,
        public readonly int $maxBodyLength = 4000,
        public readonly bool $requiresTemplate = false,
        public readonly ?int $freeformWindowSeconds = null,
    ) {
        if ($maxBodyLength < 1) {
            throw new \InvalidArgumentException('maxBodyLength must be at least 1.');
        }
        if ($freeformWindowSeconds !== null && $freeformWindowSeconds < 0) {
            throw new \InvalidArgumentException('freeformWindowSeconds must not be negative.');
        }
    }

    /**
     * Whether free text may be sent now, given when the customer last wrote.
     *
     * @param int|null $secondsSinceLastInbound Null when the customer never wrote.
     */
    public function allowsFreeform(?int $secondsSinceLastInbound): bool
    {
        if (!$this->requiresTemplate || $this->freeformWindowSeconds === null) {
            return true;
        }

        return $secondsSinceLastInbound !== null && $secondsSinceLastInbound <= $this->freeformWindowSeconds;
    }

    /** @return array<string, bool|int|null> */
    public function toArray(): array
    {
        return [
            'supports_subject' => $this->supportsSubject,
            'supports_html' => $this->supportsHtml,
            'delivery_receipts' => $this->deliveryReceipts,
            'read_receipts' => $this->readReceipts,
            'max_body_length' => $this->maxBodyLength,
            'requires_template' => $this->requiresTemplate,
            'freeform_window_seconds' => $this->freeformWindowSeconds,
        ];
    }
}
