<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Render\DocumentRenderer;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\IssuedDocument;
use Whity\Sdk\Render\RenderedDocument;

/**
 * The SDK rendering seam for `queue:work` and CLI commands (#1151): the host's
 * {@see SdkDocumentRenderer}, built on the first call. Registering it costs
 * nothing, so a command that never renders needs no render service, storage
 * or key; a job that does gets the same seam a request gets.
 */
final class DeferredSdkDocumentRenderer implements DocumentRenderer, HostWiredService
{
    private ?DocumentRenderer $inner = null;

    /** @param \Closure(): DocumentRenderer $build */
    public function __construct(private readonly \Closure $build)
    {
    }

    public function isAvailable(): bool
    {
        return $this->inner()->isAvailable();
    }

    public function render(FlowDocument $document): RenderedDocument
    {
        return $this->inner()->render($document);
    }

    /** @deprecated 1.49 Use `Documents::issue()`; see the SDK contract. */
    public function issue(FlowDocument $document, string $title, ?string $originKey = null): IssuedDocument
    {
        return $this->inner()->issue($document, $title, $originKey);
    }

    private function inner(): DocumentRenderer
    {
        return $this->inner ??= ($this->build)();
    }
}
