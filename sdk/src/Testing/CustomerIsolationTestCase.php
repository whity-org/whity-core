<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\CustomerCall;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Prove your customer routes keep each customer to their own records (SDK
 * 1.174, WHIT-1424; design section 41 §9, CA.18).
 *
 * The host only lets the customer area reach routes you declared
 * `'customer' => true`, and tells you who is calling with
 * {@see CustomerCall}. Which rows are theirs is up to your queries. This
 * case calls your handlers as one customer with another customer's ids and
 * expects nothing back:
 *
 * ```php
 * final class CoursesIsolationTest extends CustomerIsolationTestCase
 * {
 *     public function testAnotherLearnersEnrolmentIsNotHere(): void
 *     {
 *         $ahmad = $this->customer(31, contactIds: [61]);
 *         $handler = [new CoursesApi($this->pdo), 'showEnrolment'];
 *
 *         // Ibrahim's enrolment 72, asked for by Ahmad.
 *         $this->assertNotYours($handler, $this->customerRequest('GET', '/api/courses/mine/72', $ahmad), ['id' => '72']);
 *         // And his own still opens.
 *         $this->assertYours($handler, $this->customerRequest('GET', '/api/courses/mine/71', $ahmad), ['id' => '71']);
 *     }
 * }
 * ```
 *
 * Verified plugins in the store must have such a test for every customer
 * route that takes an id (CA.18, Q10).
 */
abstract class CustomerIsolationTestCase extends TestCase
{
    /** The workspace every customer here belongs to, unless one is given. */
    protected int $workspaceId = 1;

    /** @param list<int> $contactIds */
    protected function customer(int $profileId, array $contactIds, ?int $workspaceId = null): CustomerCall
    {
        return new CustomerCall($profileId, $workspaceId ?? $this->workspaceId, $contactIds);
    }

    /**
     * A request as the host would hand it to a customer route: the customer
     * attached, the workspace set.
     *
     * @param array<string, mixed>|null $body
     */
    protected function customerRequest(string $method, string $path, CustomerCall $as, ?array $body = null): Request
    {
        $request = new Request(
            strtoupper($method),
            $path,
            ['Content-Type' => 'application/json'],
            $body === null ? '' : (string) json_encode($body)
        );
        $request->setAttribute(CustomerCall::ATTRIBUTE, $as);
        $request->setAttribute(Request::ATTR_WORKSPACE_ID, $as->workspaceId);

        return $request;
    }

    /**
     * The handler answers 404 (or 403), and says nothing of the record: no
     * field of it appears in the body.
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @param array<string, string> $params
     * @param list<string> $mustNotContain values of the other customer's record that must not leak
     */
    protected function assertNotYours(callable $handler, Request $request, array $params = [], array $mustNotContain = []): void
    {
        $response = $handler($request, $params);
        self::assertInstanceOf(Response::class, $response);
        self::assertContains(
            $response->getStatusCode(),
            [403, 404],
            sprintf('%s %s answered %d as another customer; it must answer 404 like a record that does not exist.', $request->getMethod(), $request->getPath(), $response->getStatusCode())
        );
        foreach ($mustNotContain as $value) {
            self::assertStringNotContainsString($value, $response->getBody(), 'Another customer\'s data appeared in the answer.');
        }
    }

    /**
     * The handler answers the customer's own record (2xx).
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @param array<string, string> $params
     */
    protected function assertYours(callable $handler, Request $request, array $params = []): void
    {
        $response = $handler($request, $params);
        self::assertInstanceOf(Response::class, $response);
        self::assertGreaterThanOrEqual(200, $response->getStatusCode());
        self::assertLessThan(300, $response->getStatusCode(), sprintf('%s %s refused the customer their own record.', $request->getMethod(), $request->getPath()));
    }

    /**
     * Without a customer on the request (a caller the host did not admit as
     * one), the handler must not answer as if it had one.
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @param array<string, string> $params
     */
    protected function assertNeedsACustomer(callable $handler, Request $request, array $params = []): void
    {
        $bare = new Request($request->getMethod(), $request->getPath(), $request->getHeaders(), $request->getBody());
        $response = $handler($bare, $params);
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), 'The route answered a request with no customer on it.');
    }
}
