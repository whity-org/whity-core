<?php

declare(strict_types=1);

namespace Whity\Core\Import;

/**
 * One choice an import asks besides the column matching (design section 34
 * §6.5): the place for opening stock, the supplier for supplier prices, how
 * contacts are found. The shared import screen draws it from this
 * description alone, so a new kind needs no screen of its own.
 *
 * `select` offers {@see $choices} (static) or the workspace's own records
 * ({@see $source}, worked out per workspace); `text` is a short line.
 * {@see $requiredUnless} names the target that makes the option optional:
 * a file with a Place column needs no place chosen for the whole file.
 */
final class ImportOption
{
    /**
     * @param array{en: string, ar: string} $label
     * @param array{en: string, ar: string}|null $hint
     * @param list<array{value: string|int|null, label: array{en: string, ar: string}}> $choices
     * @param (\Closure(int): list<array{value: string|int, label: array{en: string, ar: string}}>)|null $source
     * @param 'string'|'int' $valueType what the check request carries
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly array $label,
        public readonly ?array $hint = null,
        public readonly array $choices = [],
        public readonly ?\Closure $source = null,
        public readonly string|int|null $default = null,
        public readonly ?string $requiredUnless = null,
        public readonly string $valueType = 'string',
        public readonly ?string $placeholder = null,
    ) {
        if (!in_array($type, ['select', 'text'], true)) {
            throw new \LogicException('An import option is a select or a text');
        }
    }

    /** @return array<string, mixed> the API shape, its choices for this workspace */
    public function toApi(int $tenantId): array
    {
        $choices = $this->choices;
        if ($this->source !== null) {
            try {
                $choices = ($this->source)($tenantId);
            } catch (\Throwable $e) {
                error_log('[ImportOption] choices for ' . $this->key . ' failed: ' . $e->getMessage());
                $choices = [];
            }
        }

        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'hint' => $this->hint,
            'choices' => $this->type === 'select' ? $choices : null,
            'default' => $this->default,
            'required_unless' => $this->requiredUnless,
            'value_type' => $this->valueType,
            'placeholder' => $this->placeholder,
        ];
    }
}
