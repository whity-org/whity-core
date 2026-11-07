<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\TotpService;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteResume;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RouteTrailChain;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RouteVersionChangedException;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Document\Signing\InstanceSealKeys;
use Whity\Core\Document\Signing\SavedSignatureRepository;
use Whity\Core\Document\Signing\SignatureRepository;
use Whity\Core\Document\Signing\SigningChallengeRepository;
use Whity\Core\Document\Signing\SigningConsent;
use Whity\Core\Document\Signing\SigningRefusedException;
use Whity\Core\Document\Signing\SigningSecondFactor;
use Whity\Core\Document\Signing\SigningService;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * WHIT-922 (#1726): the signing act, its sealed record, and the trail's hash
 * chain.
 *
 * Runs on SQLite, and on real PostgreSQL when PHPUNIT_PG_DSN is set
 * ({@see SchemaFromMigrations::make()}).
 */
final class RouteSigningRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const AUTHOR = 10;
    private const SIGNER_A = 11;
    private const SIGNER_B = 12;
    private const ENCRYPTION_KEY = 'whity-922-test-encryption-key-0123456789';

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteRecipientRepository $recipients;
    private RouteSubjectVersionRepository $versions;
    private SigningChallengeRepository $challenges;
    private SignatureRepository $signatures;
    private InstanceSealKeys $keys;
    private TotpService $totp;
    private SigningService $signing;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->versions = new RouteSubjectVersionRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );

        $subjects = new RouteSubjectRegistry();
        $subjects->register(new DocumentRouteSubject($this->pdo));

        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $steps,
            new RouteEventRepository($this->pdo),
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            null,
            $subjects,
            $this->versions,
        );

        $this->challenges = new SigningChallengeRepository($this->pdo);
        $this->signatures = new SignatureRepository($this->pdo);
        $this->keys = new InstanceSealKeys($this->pdo, new EncryptedSecretStore(['v1' => self::ENCRYPTION_KEY], 'v1'));
        $this->totp = new TotpService(self::ENCRYPTION_KEY);
        $this->signing = new SigningService(
            $this->pdo,
            $this->router,
            $this->recipients,
            $steps,
            $subjects,
            $this->versions,
            $this->signatures,
            new SavedSignatureRepository($this->pdo),
            new SigningSecondFactor($this->pdo, $this->challenges, $this->totp),
            $this->challenges,
            $this->keys,
        );
    }

    public function testQuorumAllWaitsForEverySigner(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        $first = $this->sign(self::SIGNER_A, $route);
        self::assertNull($first['decided'], 'One of two signatures settles nothing under all.');
        self::assertSame('signed', $first['event']['action']);
        self::assertSame(RouteVerdict::APPROVED, $first['event']['verdict']);
        self::assertNotNull($this->open(self::SIGNER_B, $route));

        $second = $this->sign(self::SIGNER_B, $route);
        self::assertSame(RouteVerdict::APPROVED, $second['decided']);

        $record = $second['signature'];
        self::assertSame('Director', $record['capacity']);
        self::assertSame('Example Bakery', $record['on_behalf_of']);
        self::assertSame(SigningSecondFactor::EMAIL_CODE, $record['confirmed_by']);
        self::assertSame(SigningConsent::CURRENT, $record['consent_version']);
        self::assertSame('203.0.113.7', $record['ip_address']);
        self::assertSame('TestBrowser/1.0', $record['user_agent']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $record['signed_at']);
        self::assertSame(1, $record['subject_version']);
        self::assertSame(64, strlen($record['content_hash']));
        self::assertCount(2, $this->signing->current(self::TENANT, 'document', (int) $route['document_id']));
    }

    public function testQuorumAnyIsSettledByOneSignature(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'any');

        $outcome = $this->sign(self::SIGNER_B, $route);

        self::assertSame(RouteVerdict::APPROVED, $outcome['decided']);
        self::assertNull($this->open(self::SIGNER_A, $route), 'The other signer is no longer asked.');
    }

    public function testDecliningNeedsAReasonAndEndsTheRouteUnderAll(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        try {
            $this->router->act(self::TENANT, self::SIGNER_A, $route, RouteAction::DECLINED, ' ');
            self::fail('A decline needs a reason.');
        } catch (RoutingRejectedException) {
        }

        $declined = $this->router->act(self::TENANT, self::SIGNER_A, $route, RouteAction::DECLINED, 'The amount is wrong.');
        self::assertSame('declined', $declined['event']['action']);
        self::assertSame(RouteVerdict::REJECTED, $declined['decided']);
        self::assertNull($this->open(self::SIGNER_B, $route));
        self::assertSame([], $this->signing->current(self::TENANT, 'document', (int) $route['document_id']));
    }

    public function testASignatureIsMadeOnlyThroughSigningAndOnlyOnASignStep(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        foreach ([[RouteAction::SIGNED, null], [RouteAction::ACKNOWLEDGED, null], [RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED], [RouteAction::FORWARDED, null]] as [$action, $verdict]) {
            try {
                $this->router->act(self::TENANT, self::SIGNER_A, $route, $action, null, $verdict);
                self::fail("'{$action}' must not answer a sign step.");
            } catch (RoutingRejectedException) {
            }
        }
        self::assertNotNull($this->open(self::SIGNER_A, $route));
    }

    public function testASignatureIsBoundToItsVersionAndNotCarriedToANewOne(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'all');

        $this->sign(self::SIGNER_A, $route);
        self::assertCount(1, $this->signing->current(self::TENANT, 'document', $documentId));

        $this->router->act(self::TENANT, self::SIGNER_B, $route, RouteAction::SENT_BACK, 'Fix the total.', null, RouteResume::SENDER);
        $this->pdo->exec("UPDATE documents SET title = 'Supply agreement (revised)' WHERE id = " . $documentId);
        $this->router->act(self::TENANT, self::AUTHOR, $route, RouteAction::RESUBMITTED, null);

        self::assertSame(2, $this->versions->latest(self::TENANT, 'document', $documentId)['version'] ?? null);
        self::assertSame([], $this->signing->current(self::TENANT, 'document', $documentId), 'The earlier signature does not carry over.');
        self::assertNotNull($this->open(self::SIGNER_A, $route), 'The first signer is asked again.');

        // A signature made on what the signer saw before the change is refused.
        try {
            $this->sign(self::SIGNER_A, $route, ['content_hash' => $this->audit($documentId)['signatures'][0]['content_hash']]);
            self::fail('A signature on old content must be refused.');
        } catch (SigningRefusedException $e) {
            self::assertSame(SigningRefusedException::CONTENT_CHANGED, $e->reason);
        }

        $again = $this->sign(self::SIGNER_A, $route);
        self::assertSame(2, $again['signature']['subject_version']);
        $current = $this->signing->current(self::TENANT, 'document', $documentId);
        self::assertCount(1, $current);
        self::assertSame(2, $current[0]['subject_version']);
        self::assertCount(2, $this->audit($documentId)['signatures'], 'The audit keeps both.');
    }

    public function testAMissingOrStaleSecondFactorIsRefused(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route, ['second_factor' => ['method' => 'email_code']]);
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_REQUIRED, $route, ['second_factor' => ['method' => 'totp', 'code' => '123456']]);

        $code = $this->codeFor(self::SIGNER_A, $route);
        self::assertNotNull($code);
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route, ['second_factor' => ['method' => 'email_code', 'code' => $wrong]]);

        // Expired.
        $this->pdo->exec("UPDATE signing_challenges SET expires_at = '2000-01-01 00:00:00'");
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route, ['second_factor' => ['method' => 'email_code', 'code' => $code]]);

        // A code works once.
        $fresh = (string) $this->codeFor(self::SIGNER_A, $route);
        $this->sign(self::SIGNER_A, $route, ['second_factor' => ['method' => 'email_code', 'code' => $fresh]]);
        self::assertFalse($this->challenges->consumeEmailCode(self::TENANT, self::SIGNER_A, $fresh, (int) $route['id'], $this->hashOf($route)));
    }

    public function testAnAuthenticatorUserSignsWithACurrentCodeOnce(): void
    {
        $secret = $this->totp->generateSecret();
        if ($secret === '') {
            self::fail('No secret was generated.');
        }
        $this->pdo->prepare('UPDATE profiles SET two_factor_enabled = TRUE, two_factor_secret = ? WHERE id = ?')
            ->execute([$this->totp->encryptSecret($secret), self::SIGNER_A]);
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        // An email code is not accepted from someone who has an authenticator.
        $email = (string) $this->codeFor(self::SIGNER_A, $route);
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_REQUIRED, $route, ['second_factor' => ['method' => 'email_code', 'code' => $email]]);

        $code = TOTP::createFromSecret($secret)->now();
        $signed = $this->sign(self::SIGNER_A, $route, ['second_factor' => ['method' => 'totp', 'code' => $code]]);
        self::assertSame('totp', $signed['signature']['confirmed_by']);

        // The same code cannot be used again.
        $route2 = $this->issueSignStep($this->seedDocument(), 'all');
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route2, ['second_factor' => ['method' => 'totp', 'code' => $code]]);
    }

    public function testAProviderAccountMustHaveSignedInAgainRecently(): void
    {
        $this->pdo->exec("UPDATE profiles SET auth_method = 'idp' WHERE id = " . self::SIGNER_A);
        $this->pdo->exec(
            "INSERT INTO external_identities (profile_id, provider_key, issuer, subject) VALUES ("
            . self::SIGNER_A . ", 'whity_id', 'https://id.example.test', 'sub-a')"
        );
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        $context = $this->signing->context(self::TENANT, self::SIGNER_A, $route);
        self::assertSame('fresh_sign_in', $context['second_factor']['method']);
        self::assertSame('whity_id', $context['second_factor']['provider_key']);

        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route, ['second_factor' => ['method' => 'fresh_sign_in']]);

        $this->challenges->recordFreshSignIn(self::TENANT, self::SIGNER_A, (int) $route['id']);
        $this->pdo->exec("UPDATE signing_challenges SET expires_at = '2000-01-01 00:00:00'");
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $route, ['second_factor' => ['method' => 'fresh_sign_in']]);

        $this->challenges->recordFreshSignIn(self::TENANT, self::SIGNER_A, (int) $route['id']);
        $signed = $this->sign(self::SIGNER_A, $route, ['second_factor' => ['method' => 'fresh_sign_in']]);
        self::assertSame('fresh_sign_in', $signed['signature']['confirmed_by']);
    }

    public function testADrawnSignatureAndConsentAreRequired(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');

        $this->assertRefused(SigningRefusedException::APPEARANCE_REQUIRED, $route, ['appearance' => null]);
        $this->assertRefused(SigningRefusedException::APPEARANCE_REQUIRED, $route, ['appearance' => ['width' => 300, 'height' => 100, 'strokes' => [[[1, 1]]]]]);
        $this->assertRefused(SigningRefusedException::APPEARANCE_REQUIRED, $route, ['appearance' => null, 'use_saved_signature' => true]);
        $this->assertRefused(SigningRefusedException::CONSENT_REQUIRED, $route, ['consent' => false]);
        $this->assertRefused(SigningRefusedException::CONSENT_REQUIRED, $route, ['consent_version' => '1999-01-1']);

        self::assertSame([], $this->audit((int) $route['document_id'])['signatures']);
        self::assertNotNull($this->open(self::SIGNER_A, $route), 'A refused signature changes nothing.');

        // Saved for reuse, then used.
        $this->sign(self::SIGNER_A, $route, ['save_signature' => true]);
        $route2 = $this->issueSignStep($this->seedDocument(), 'all');
        $reused = $this->sign(self::SIGNER_A, $route2, ['appearance' => null, 'use_saved_signature' => true]);
        self::assertSame(hash('sha256', $reused['signature']['appearance']), $reused['signature']['appearance_hash']);
    }

    public function testTheSealVerifiesWithThePublishedKey(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'any');
        $this->sign(self::SIGNER_A, $route);

        $published = $this->keys->published();
        self::assertCount(1, $published);
        self::assertSame('Ed25519', $published[0]['algorithm']);

        $row = $this->signatures->listForDocument(self::TENANT, $documentId)[0];
        $message = SignatureRepository::sealedMessage($row, $row['event_entry_hash']);
        self::assertTrue(InstanceSealKeys::verifyWith($message, (string) $row['seal'], $published[0]['public_key']));
        self::assertTrue($this->audit($documentId)['signatures'][0]['seal_valid']);

        // Any change to the record no longer checks.
        $this->pdo->exec("UPDATE route_signatures SET capacity = 'Clerk'");
        self::assertFalse($this->audit($documentId)['signatures'][0]['seal_valid']);
    }

    public function testTheTrailChainDetectsABrokenLink(): void
    {
        $documentId = $this->seedDocument();
        // An entry written before the chain existed: the first linked entry
        // anchors to it.
        $this->pdo->exec(
            'INSERT INTO document_routes (tenant_id, document_id, subject_type, subject_id, title, created_by, created_at)
             VALUES (1, ' . $documentId . ", 'document', " . $documentId . ", 'Old route', 10, " . $this->now() . ')'
        );
        $oldRoute = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            'INSERT INTO document_route_events (tenant_id, document_id, route_id, actor_profile_id, action, note, occurred_at)
             VALUES (1, ' . $documentId . ', ' . $oldRoute . ", 10, 'noted', 'Before the chain.', " . $this->now() . ')'
        );

        $route = $this->issueSignStep($documentId, 'all');
        $this->router->act(self::TENANT, self::SIGNER_A, $route, RouteAction::NOTED, 'Reading it.');
        $this->sign(self::SIGNER_A, $route);

        $chain = RouteTrailChain::verify($this->pdo, self::TENANT, $documentId);
        self::assertTrue($chain['intact']);
        self::assertSame(1, $chain['earlier']);
        self::assertSame(3, $chain['linked']);

        $stmt = $this->pdo->prepare('SELECT id, prev_hash, entry_hash FROM document_route_events WHERE document_id = ? ORDER BY id');
        $stmt->execute([$documentId]);
        /** @var list<array<string, mixed>> $events */
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertNull($events[0]['entry_hash']);
        self::assertSame($events[1]['entry_hash'], $events[2]['prev_hash'], 'Each entry stores the hash of the one before.');

        // A removed unit or person is not a break.
        $this->pdo->exec('UPDATE document_route_events SET from_ou_id = NULL WHERE id = ' . (int) $events[1]['id']);
        $afterRemoval = RouteTrailChain::verify($this->pdo, self::TENANT, $documentId);
        self::assertTrue($afterRemoval['intact']);

        // An edited note is.
        $this->pdo->exec("UPDATE document_route_events SET note = 'Edited.' WHERE id = " . (int) $events[2]['id']);
        $broken = RouteTrailChain::verify($this->pdo, self::TENANT, $documentId);
        self::assertFalse($broken['intact']);
        self::assertSame((int) $events[2]['id'], $broken['broken_at_event_id']);

        // So is an edited entry from before the chain.
        $this->pdo->exec("UPDATE document_route_events SET note = 'Before the chain.' WHERE id = " . (int) $events[2]['id']);
        $this->pdo->exec("UPDATE document_route_events SET note = 'Rewritten.' WHERE id = " . (int) $events[0]['id']);
        self::assertSame((int) $events[1]['id'], RouteTrailChain::verify($this->pdo, self::TENANT, $documentId)['broken_at_event_id']);
    }

    public function testSignaturesArePublishedOnTheSubject(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'all');
        $this->sign(self::SIGNER_A, $route);

        $current = $this->signing->current(self::TENANT, 'document', $documentId);
        self::assertSame('Director', $current[0]['capacity']);
        self::assertSame('Example Bakery', $current[0]['on_behalf_of']);
        self::assertSame(['profile_id' => self::SIGNER_A, 'name' => 'signer a'], $current[0]['by']);
        self::assertNotSame('', $current[0]['at']);

        $step = RoutingPresenter::step((new RouteStepRepository($this->pdo))->listForRoute((int) $route['id'], self::TENANT)[0]);
        self::assertSame('sign', $step['kind']);
        self::assertSame('Director', $step['sign_capacity']);
    }

    public function testASignStepRefusesMajorityAndEdges(): void
    {
        $documentId = $this->seedDocument();
        foreach ([['decision_quorum' => 'majority'], ['on_rejected' => 1], ['decision' => true]] as $extra) {
            try {
                $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Agreement', [
                    ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::SIGNER_A]]],
                    ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::SIGNER_A]], 'kind' => 'sign'] + $extra,
                ]);
                self::fail('Refused: ' . json_encode($extra));
            } catch (RoutingRejectedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAVersionRecordedBetweenTheCheckAndTheWriteIsRefused(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'all');
        $seen = $this->versions->latest(self::TENANT, 'document', $documentId);
        self::assertNotNull($seen);

        // Someone records a newer version after the signer's check passed.
        $this->versions->record(self::TENANT, 'document', $documentId, ['title' => 'Changed meanwhile'], self::AUTHOR);

        $written = false;
        try {
            $this->router->sign(
                self::TENANT,
                self::SIGNER_A,
                $route,
                static function () use (&$written): void {
                    $written = true;
                },
                $seen['version'],
                $seen['content_hash'],
            );
            self::fail('A signature must not bind to a version the signer did not see.');
        } catch (RouteVersionChangedException) {
        }

        self::assertFalse($written);
        self::assertSame([], $this->column("SELECT id FROM document_route_events WHERE action = 'signed'"));
        self::assertNotNull($this->open(self::SIGNER_A, $route), 'Nothing changed.');
    }

    public function testAnEmailCodeCountsOnlyOnTheRouteAndContentItWasSentFor(): void
    {
        $routeA = $this->issueSignStep($this->seedDocument(), 'all');
        $routeB = $this->issueSignStep($this->seedDocument(), 'all');

        $codeForA = (string) $this->codeFor(self::SIGNER_A, $routeA);
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $routeB, ['second_factor' => ['method' => 'email_code', 'code' => $codeForA]]);

        // Nor for other content on the same route.
        self::assertFalse($this->challenges->consumeEmailCode(self::TENANT, self::SIGNER_A, $codeForA, (int) $routeA['id'], str_repeat('0', 64)));

        $signed = $this->sign(self::SIGNER_A, $routeA, ['second_factor' => ['method' => 'email_code', 'code' => $codeForA]]);
        self::assertSame('email_code', $signed['signature']['confirmed_by']);
    }

    public function testAFreshSignInCountsOnlyOnItsRoute(): void
    {
        $this->pdo->exec("UPDATE profiles SET auth_method = 'idp' WHERE id = " . self::SIGNER_A);
        $this->pdo->exec(
            "INSERT INTO external_identities (profile_id, provider_key, issuer, subject) VALUES ("
            . self::SIGNER_A . ", 'whity_id', 'https://id.example.test', 'sub-a')"
        );
        $routeA = $this->issueSignStep($this->seedDocument(), 'all');
        $routeB = $this->issueSignStep($this->seedDocument(), 'all');

        $this->challenges->recordFreshSignIn(self::TENANT, self::SIGNER_A, (int) $routeA['id']);
        $this->assertRefused(SigningRefusedException::SECOND_FACTOR_INVALID, $routeB, ['second_factor' => ['method' => 'fresh_sign_in']]);
        self::assertFalse($this->signing->context(self::TENANT, self::SIGNER_A, $routeB)['second_factor']['signed_in_again']);

        $this->sign(self::SIGNER_A, $routeA, ['second_factor' => ['method' => 'fresh_sign_in']]);
    }

    public function testACodeIsRefusedAfterItsTriesAreUsed(): void
    {
        $route = $this->issueSignStep($this->seedDocument(), 'all');
        $code = (string) $this->codeFor(self::SIGNER_A, $route);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < SigningChallengeRepository::MAX_ATTEMPTS; $i++) {
            self::assertFalse($this->challenges->consumeEmailCode(self::TENANT, self::SIGNER_A, $wrong, (int) $route['id'], $this->hashOf($route)));
        }
        self::assertFalse($this->challenges->consumeEmailCode(self::TENANT, self::SIGNER_A, $code, (int) $route['id'], $this->hashOf($route)));
    }

    public function testASignedEntryWithoutARecordIsABreakAndASignedEntryCannotBeRemoved(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'any');
        $signed = $this->sign(self::SIGNER_A, $route);
        $eventId = (int) $signed['event']['id'];

        // The record is kept: its trail entry cannot be removed on its own.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
        try {
            $this->pdo->exec('DELETE FROM document_route_events WHERE id = ' . $eventId);
            self::fail('A signed trail entry must not be removable while its record exists.');
        } catch (\PDOException) {
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }

        self::assertTrue($this->audit($documentId)['chain']['intact']);

        $this->pdo->exec('DELETE FROM route_signatures WHERE event_id = ' . $eventId);
        $chain = $this->audit($documentId)['chain'];
        self::assertFalse($chain['intact']);
        self::assertSame([$eventId], $chain['signed_without_record']);
        self::assertSame($eventId, $chain['broken_at_event_id']);
    }

    public function testTheAuditWithholdsContactDetailsUnlessAsked(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'any');
        $this->sign(self::SIGNER_A, $route);

        $plain = $this->signing->audit(self::TENANT, $documentId)['signatures'][0];
        self::assertNull($plain['ip_address']);
        self::assertNull($plain['user_agent']);
        self::assertNull($plain['by']['email']);
        self::assertTrue($plain['details_withheld']);
        self::assertSame('signer a', $plain['by']['name']);
        self::assertSame('Director', $plain['capacity']);
        self::assertTrue($plain['seal_valid']);

        $full = $this->signing->audit(self::TENANT, $documentId, true)['signatures'][0];
        self::assertSame('203.0.113.7', $full['ip_address']);
        self::assertSame('signer.a@example.test', $full['by']['email']);
    }

    public function testSignaturesFollowTheContentAsItIsNow(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueSignStep($documentId, 'any');
        $this->sign(self::SIGNER_A, $route);
        self::assertCount(1, $this->signing->current(self::TENANT, 'document', $documentId));

        // Changed without a new version being recorded: the signature no
        // longer stands on what is there.
        $this->pdo->exec("UPDATE documents SET title = 'Edited' WHERE id = " . $documentId);
        self::assertSame([], $this->signing->current(self::TENANT, 'document', $documentId));
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sign(int $signer, array $route, array $overrides = []): array
    {
        $input = $overrides + [
            'content_hash' => $this->signing->context(self::TENANT, $signer, $route)['subject']['content_hash'] ?? '',
            'consent' => true,
            'consent_version' => SigningConsent::CURRENT,
            'appearance' => self::drawing(),
        ];
        if (!array_key_exists('second_factor', $overrides)) {
            $input['second_factor'] = [
                'method' => 'email_code',
                'code' => $this->codeFor($signer, $route),
            ];
        }

        return $this->signing->sign(self::TENANT, $signer, $route, $input, '203.0.113.7', 'TestBrowser/1.0');
    }

    /** @param array<string, mixed> $route */
    private function hashOf(array $route): string
    {
        return (string) ($this->signing->context(self::TENANT, self::SIGNER_A, $route)['subject']['content_hash'] ?? '');
    }

    /** @param array<string, mixed> $route */
    private function codeFor(int $signer, array $route): ?string
    {
        return $this->challenges->issueEmailCode(self::TENANT, $signer, (int) $route['id'], $this->hashOf($route));
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $overrides
     */
    private function assertRefused(string $reason, array $route, array $overrides): void
    {
        // A refusal that comes before the second check spends no code.
        $overrides += ['second_factor' => ['method' => 'email_code', 'code' => '000000']];
        try {
            $this->sign(self::SIGNER_A, $route, $overrides);
            self::fail('Expected a refusal: ' . $reason);
        } catch (SigningRefusedException $e) {
            self::assertSame($reason, $e->reason, $e->clientMessage);
        }
    }

    /** @return array<string, mixed> */
    private function audit(int $documentId): array
    {
        return $this->signing->audit(self::TENANT, $documentId);
    }

    /** @return array{width: int, height: int, strokes: list<list<list<int>>>} */
    private static function drawing(): array
    {
        return ['width' => 300, 'height' => 100, 'strokes' => [[[10, 50], [40, 20], [70, 60], [100, 30], [130, 55]]]];
    }

    /** @return array<string, mixed> */
    private function issueSignStep(int $documentId, string $quorum): array
    {
        return $this->router->issue(self::TENANT, self::AUTHOR, ['id' => $documentId], 'Supply agreement', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::SIGNER_A, self::SIGNER_B]],
                'kind' => 'sign',
                'decision_quorum' => $quorum,
                'sign_capacity' => 'Director',
                'sign_on_behalf_of' => 'Example Bakery',
            ],
        ])['route'];
    }

    /**
     * @param array<string, mixed> $route
     * @return array<string, mixed>|null
     */
    private function open(int $profileId, array $route): ?array
    {
        return $this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], $profileId);
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Agreement') . ', ' . $this->pdo->quote('Supply agreement')
             . ', 20, ' . self::AUTHOR . ', ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Example Bakery') . ') ON CONFLICT DO NOTHING');
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Kitchen') . ', ' . $quote('kitchen') . ', ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('member') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        foreach ([[10, 'author'], [11, 'signer a'], [12, 'signer b']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
            $pdo->exec(
                'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES ('
                . $id . ', ' . $quote(str_replace(' ', '.', $name) . '@example.test') . ', true, true, ' . $now . ')'
            );
            $pdo->exec(
                "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                    (" . (1000 + $id) . ", {$id}, 1, 100, 20, true, 'active', {$now})"
            );
        }

        return $pdo;
    }
}
