<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

/*
 * {@see \Whity\Sdk\Testing\TenantIsolationConformanceTestCase} under the name
 * it keeps in 2.0, where the SDK says workspace, not tenant (SDK 1.161,
 * decision D6).
 *
 * `class_alias` makes the two names one type: a plugin may extend either, and
 * `instanceof` answers the same for both. The contract is documented on {@see
 * \Whity\Sdk\Testing\TenantIsolationConformanceTestCase}; the old name keeps
 * working through 2.0.x (docs/wiki/Plugin-SDK-2.0-Plan.md).
 */
\class_alias(\Whity\Sdk\Testing\TenantIsolationConformanceTestCase::class, WorkspaceIsolationConformanceTestCase::class);

// Never runs: it tells IDEs and static analysis what the line above defines.
if (false) { // @phpstan-ignore if.alwaysFalse
    /** @see \Whity\Sdk\Testing\TenantIsolationConformanceTestCase */
    abstract class WorkspaceIsolationConformanceTestCase extends \Whity\Sdk\Testing\TenantIsolationConformanceTestCase
    {
    }
}
