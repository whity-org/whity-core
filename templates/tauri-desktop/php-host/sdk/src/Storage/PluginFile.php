<?php

declare(strict_types=1);

namespace Whity\Sdk\Storage;

/** A file read back from a {@see PluginFileStore} (SDK 1.63, WHIT-886). */
final class PluginFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        public readonly string $contentType,
    ) {
    }
}
