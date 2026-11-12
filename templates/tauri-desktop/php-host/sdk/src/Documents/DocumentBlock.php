<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

use Whity\Sdk\Manifest\DocumentBlockValidator;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * A block a code plugin adds to the document editor (SDK 1.103; editor M7,
 * E.17–E.20). The same declaration a workspace plugin writes under
 * `documents.blocks` in its manifest: a label, its fields, a view and an
 * optional print view in Whity's display blocks. Whity's own renderer draws
 * it; the plugin ships no code to the browser.
 *
 * The host addresses it as `<plugin name>/<name>`. A plugin's Verified mark
 * shows on its blocks; without a print view a block is for notes and wiki
 * pages only (E.18). See {@see DocumentBlockValidator} for the shape.
 *
 *     new DocumentBlock('delivery_status', [
 *         'label' => ['en' => 'Delivery status', 'ar' => 'حالة التوصيل'],
 *         'fields' => [['key' => 'order', 'label' => ['en' => 'Order', 'ar' => 'الطلب'], 'type' => 'text']],
 *         'view' => [['type' => 'keyValue', 'items' => [['label' => 'Order', 'value' => '{{data.order}}']]]],
 *     ]);
 */
final class DocumentBlock
{
    /**
     * @param array<string, mixed> $definition
     * @throws \InvalidArgumentException naming every problem, when the declaration isn't valid
     */
    public function __construct(
        public readonly string $name,
        public readonly array $definition,
    ) {
        $errors = [];
        DocumentBlockValidator::checkOne(
            $name,
            $definition,
            "block '{$name}'",
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                ManifestValidator::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
        );
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
    }
}
