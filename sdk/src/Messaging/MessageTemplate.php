<?php

declare(strict_types=1);

namespace Whity\Sdk\Messaging;

/**
 * A pre-approved message a channel may send outside its free-form window, such
 * as a WhatsApp template (SDK 1.97).
 */
final class MessageTemplate
{
    /**
     * @param string       $name      The provider's template name.
     * @param string       $language  `en` or `ar`.
     * @param string       $preview   The text with placeholders, shown to staff.
     * @param list<string> $variables Placeholder names, in order.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language,
        public readonly string $preview,
        public readonly array $variables = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A template needs a name.');
        }
    }

    /** @return array{name: string, language: string, preview: string, variables: list<string>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'preview' => $this->preview,
            'variables' => $this->variables,
        ];
    }
}
