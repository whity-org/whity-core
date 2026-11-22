<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\HandleRules;
use Whity\Core\Tenant\ReservedHandles;

/**
 * Every first path segment of the web app is a reserved handle (design
 * section 36, HD.2), so a future `/{handle}` short link can never shadow a
 * page. Add a top-level page and this fails until its segment is in
 * {@see ReservedHandles::PAGES}.
 */
final class ReservedHandlesTest extends TestCase
{
    public function testEveryTopLevelPageIsReserved(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/web/routes.json'), true);
        self::assertIsArray($manifest);

        $missing = [];
        foreach ($this->paths($manifest) as $path) {
            $segment = strtolower(explode('/', ltrim($path, '/'))[0]);
            $segment = preg_replace('/\.[a-z]+$/', '', ltrim($segment, '.')) ?? '';
            if ($segment === '' || str_starts_with($segment, ':') || strlen($segment) < HandleRules::MIN) {
                continue;
            }
            if (!ReservedHandles::isBuiltIn($segment)) {
                $missing[$segment] = true;
            }
        }

        self::assertSame([], array_keys($missing), 'Add these top-level pages to ReservedHandles::PAGES.');
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function paths(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if ($key === 'path' && is_string($value)) {
                $out[] = $value;
            } elseif (is_array($value)) {
                array_push($out, ...$this->paths($value));
            }
        }

        return $out;
    }
}
