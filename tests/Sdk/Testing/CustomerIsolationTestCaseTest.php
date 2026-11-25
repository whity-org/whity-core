<?php

declare(strict_types=1);

namespace Tests\Sdk\Testing;

use PHPUnit\Framework\AssertionFailedError;
use Whity\Sdk\Http\CustomerCall;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Testing\CustomerIsolationTestCase;

/**
 * The SDK's customer isolation helper (SDK 1.173, WHIT-1424): it passes a
 * route that keeps each customer to their own rows, and fails one that
 * answers another customer's row.
 */
final class CustomerIsolationTestCaseTest extends CustomerIsolationTestCase
{
    /** enrolment id => contact id */
    private const ENROLMENTS = [71 => 61, 72 => 64];

    public function testARouteScopedByTheCustomerPasses(): void
    {
        $ahmad = $this->customer(31, contactIds: [61]);
        $handler = $this->handler(scoped: true);

        $this->assertNotYours($handler, $this->customerRequest('GET', '/api/courses/mine/72', $ahmad), ['id' => '72'], ['Ibrahim']);
        $this->assertYours($handler, $this->customerRequest('GET', '/api/courses/mine/71', $ahmad), ['id' => '71']);
        $this->assertNeedsACustomer($handler, $this->customerRequest('GET', '/api/courses/mine/71', $ahmad), ['id' => '71']);
        self::assertSame(1, $this->customerRequest('GET', '/x', $ahmad)->getWorkspaceId());
    }

    public function testARouteThatIgnoresTheCustomerFails(): void
    {
        $ahmad = $this->customer(31, contactIds: [61]);

        $this->expectException(AssertionFailedError::class);
        $this->assertNotYours($this->handler(scoped: false), $this->customerRequest('GET', '/api/courses/mine/72', $ahmad), ['id' => '72']);
    }

    /** @return callable(Request, array<string, string>): Response */
    private function handler(bool $scoped): callable
    {
        return static function (Request $request, array $params) use ($scoped): Response {
            $call = CustomerCall::from($request);
            $id = (int) ($params['id'] ?? 0);
            $owner = self::ENROLMENTS[$id] ?? null;
            if ($owner === null || ($scoped && ($call === null || !$call->ownsContact($owner)))) {
                return Response::error('Not found', 404);
            }

            return Response::json(['data' => ['id' => $id, 'learner' => $owner === 64 ? 'Ibrahim' : 'Ahmad']]);
        };
    }
}
