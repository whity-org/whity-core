<?php

declare(strict_types=1);

namespace Whity\Core\Document\Signing;

use PDO;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteStepKind;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RouteTrailChain;
use Whity\Core\Document\Routing\RouteVersionChangedException;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * The signing act (WHIT-922, #1726): an electronic signature with an audit
 * trail, made by a signed-in member or customer on a `sign` step.
 *
 * A signature is made only when, at that moment:
 *  1. the signer holds an open item on a `sign` step of the route;
 *  2. they ticked the consent line, in its current wording;
 *  3. they gave a drawn signature (their own, or the one they saved);
 *  4. the content they were shown is still the current content: the hash they
 *     send must equal the hash of the subject now, so a signature is always on
 *     exactly what was on the screen;
 *  5. they passed the second check ({@see SigningSecondFactor}).
 *
 * The act and the signature record are written in one transaction
 * ({@see DocumentRouter::sign()}). The record is bound to the hash of the
 * subject version, sealed with the instance's Ed25519 key, and points at its
 * trail entry, whose hash the seal also covers.
 */
final class SigningService
{
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentRouter $router,
        private readonly RouteRecipientRepository $recipients,
        private readonly RouteStepRepository $steps,
        private readonly RouteSubjectRegistry $subjects,
        private readonly RouteSubjectVersionRepository $versions,
        private readonly SignatureRepository $signatures,
        private readonly SavedSignatureRepository $saved,
        private readonly SigningSecondFactor $secondFactor,
        private readonly SigningChallengeRepository $challenges,
        private readonly InstanceSealKeys $keys,
        private readonly ?SigningCodeMailer $mailer = null,
    ) {
    }

    /**
     * What the signer needs to see before signing: the step, its capacity, the
     * content's current hash, the consent wording's version, the check they
     * will confirm with, and their saved signature.
     *
     * @param array<string, mixed> $route
     * @return array<string, mixed>
     */
    public function context(int $tenantId, int $actorId, array $route): array
    {
        $item = $this->openSignItem($tenantId, $actorId, $route);
        $subject = $this->currentContent($tenantId, $route);
        $factor = $this->secondFactor->methodFor($actorId);

        return [
            'route_id' => (int) $route['id'],
            'can_sign' => $item !== null && $subject !== null,
            'step' => $item === null ? null : [
                'id' => (int) $item['step']['id'],
                'position' => (int) $item['step']['position'],
                'label' => $item['step']['label'],
                'capacity' => $item['step']['sign_capacity'] ?? null,
                'on_behalf_of' => $item['step']['sign_on_behalf_of'] ?? null,
                'quorum' => ($item['step']['decision_quorum'] ?? null) === RouteQuorum::ANY
                    ? RouteQuorum::ANY
                    : RouteQuorum::ALL,
            ],
            'subject' => $subject,
            'consent_version' => SigningConsent::CURRENT,
            'second_factor' => [
                'method' => $factor['method'],
                'provider_key' => $factor['provider_key'],
                'email_hint' => $factor['method'] === SigningSecondFactor::EMAIL_CODE
                    ? self::maskEmail($this->signer($actorId)['email'])
                    : null,
                'signed_in_again' => $factor['method'] === SigningSecondFactor::FRESH_SIGN_IN
                    && $this->challenges->hasFreshSignIn($tenantId, $actorId, (int) $route['id']),
            ],
            'saved_signature' => $this->saved->find($tenantId, $actorId),
        ];
    }

    /**
     * Send the signer an email code.
     *
     * @param array<string, mixed> $route
     * @return array{email_hint: ?string}
     *
     * @throws SigningRefusedException
     */
    public function sendCode(int $tenantId, int $actorId, array $route): array
    {
        if ($this->openSignItem($tenantId, $actorId, $route) === null) {
            throw new SigningRefusedException(
                SigningRefusedException::NOT_SIGNER,
                'Nothing on this route is waiting for your signature.',
            );
        }
        if ($this->secondFactor->methodFor($actorId)['method'] !== SigningSecondFactor::EMAIL_CODE) {
            throw new SigningRefusedException(
                SigningRefusedException::SECOND_FACTOR_REQUIRED,
                'This account confirms signatures another way.',
            );
        }
        $email = $this->signer($actorId)['email'];
        if ($email === null) {
            throw new SigningRefusedException(
                SigningRefusedException::SECOND_FACTOR_REQUIRED,
                'This account has no email address to send a code to.',
            );
        }

        // The code is good for this route and for the content as it is now.
        $content = $this->currentContent($tenantId, $route);
        if ($content === null) {
            throw RoutingRejectedException::because('This cannot be signed: its content could not be read.');
        }

        $code = $this->challenges->issueEmailCode($tenantId, $actorId, (int) $route['id'], $content['content_hash']);
        if ($code === null) {
            throw new SigningRefusedException(
                SigningRefusedException::THROTTLED,
                'Too many codes were sent. Wait a few minutes and try again.',
                429,
            );
        }

        $title = (string) ($this->subjects->get(self::subjectType($route))
            ?->title($tenantId, self::subjectId($route)) ?? $route['title'] ?? '');
        $this->mailer?->send($email, $code, $title);

        return ['email_hint' => self::maskEmail($email)];
    }

    /**
     * Sign.
     *
     * @param array<string, mixed> $route
     * @param array{content_hash: mixed, consent: mixed, consent_version: mixed, appearance?: mixed,
     *              use_saved_signature?: mixed, save_signature?: mixed,
     *              second_factor?: mixed} $input
     *
     * @return array{event: array<string, mixed>, signature: array<string, mixed>, decided: ?string,
     *               resolved: int, delivered: int}
     *
     * @throws SigningRefusedException
     * @throws RoutingRejectedException
     */
    public function sign(int $tenantId, int $actorId, array $route, array $input, ?string $ip, ?string $userAgent): array
    {
        $item = $this->openSignItem($tenantId, $actorId, $route);
        if ($item === null) {
            throw new SigningRefusedException(
                SigningRefusedException::NOT_SIGNER,
                'Nothing on this route is waiting for your signature.',
            );
        }

        if (($input['consent'] ?? false) !== true) {
            throw new SigningRefusedException(
                SigningRefusedException::CONSENT_REQUIRED,
                'Tick the consent line to sign.',
            );
        }
        $consentVersion = $input['consent_version'] ?? null;
        if (!is_string($consentVersion) || !SigningConsent::isCurrent($consentVersion)) {
            throw new SigningRefusedException(
                SigningRefusedException::CONSENT_REQUIRED,
                'The consent wording has changed. Read it again and tick it to sign.',
            );
        }

        $appearance = $this->appearance($tenantId, $actorId, $input);

        // Recorded as a version now if it changed since the last one, so the
        // signature is bound to exactly what is there.
        $subjectType = self::subjectType($route);
        $subjectId = self::subjectId($route);
        $snapshot = $this->subjects->get($subjectType)?->snapshot($tenantId, $subjectId);
        if ($snapshot === null) {
            throw RoutingRejectedException::because('This cannot be signed: its content could not be read.');
        }
        $expected = $input['content_hash'] ?? null;
        if (!is_string($expected) || !hash_equals(RouteSubjectVersionRepository::hash($snapshot), $expected)) {
            throw new SigningRefusedException(
                SigningRefusedException::CONTENT_CHANGED,
                'The content changed since you opened it. Read it again before signing.',
                409,
            );
        }

        $factor = is_array($input['second_factor'] ?? null) ? $input['second_factor'] : [];
        $method = is_string($factor['method'] ?? null) ? $factor['method'] : '';
        $code = is_string($factor['code'] ?? null) ? $factor['code'] : null;
        $confirmedBy = $this->secondFactor->confirm($tenantId, $actorId, $method, $code, $ip, (int) $route['id'], $expected);

        // The version the signer saw. The router checks it is still the current
        // one inside the act's transaction.
        $seen = $this->versions->record($tenantId, $subjectType, $subjectId, $snapshot, $actorId);

        $signer = $this->signer($actorId);
        $documentId = (int) $route['document_id'];
        $encodedAppearance = SignatureAppearance::encode($appearance);
        $written = null;

        try {
            $outcome = $this->router->sign(
                $tenantId,
                $actorId,
                $route,
                function (array $event, array $actStep) use (
                    $tenantId, $documentId, $route, $subjectType, $subjectId, $signer, $actorId,
                    $confirmedBy, $ip, $userAgent, $encodedAppearance, $expected, &$written
                ): void {
                    if (!hash_equals($expected, (string) ($event['content_hash'] ?? ''))) {
                        throw new RouteVersionChangedException('The content changed since you opened it.');
                    }
                    $record = [
                        'tenant_id' => $tenantId,
                        'document_id' => $documentId,
                        'route_id' => (int) $route['id'],
                        'event_id' => (int) $event['id'],
                        'step_id' => (int) $actStep['id'],
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                        'subject_version' => (int) $event['subject_version'],
                        'content_hash' => (string) $event['content_hash'],
                        'signer_profile_id' => $actorId,
                        'signer_name' => $signer['name'],
                        'signer_email' => $signer['email'],
                        'capacity' => $actStep['sign_capacity'] ?? null,
                        'on_behalf_of' => $actStep['sign_on_behalf_of'] ?? null,
                        'confirmed_by' => $confirmedBy,
                        // The trail entry's own time, to the second: one instant for
                        // the act and the signature.
                        'signed_at' => RouteTrailChain::second((string) $event['occurred_at']),
                        'ip_address' => $ip === null ? null : mb_substr($ip, 0, 64),
                        'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                        'consent_version' => SigningConsent::CURRENT,
                        'appearance_hash' => hash('sha256', $encodedAppearance),
                        'appearance' => $encodedAppearance,
                    ];
                    $message = SignatureRepository::sealedMessage($record, $event['entry_hash'] ?? null);
                    $seal = $this->keys->seal($message);
                    $record['record_hash'] = hash('sha256', $message);
                    $record['seal'] = $seal['seal'];
                    $record['seal_key_id'] = $seal['key_id'];
                    $record['id'] = $this->signatures->append($record);
                    $written = $record;
                },
                $seen['version'],
                $seen['content_hash'],
            );
        } catch (RouteVersionChangedException) {
            throw new SigningRefusedException(
                SigningRefusedException::CONTENT_CHANGED,
                'The content changed since you opened it. Read it again before signing.',
                409,
            );
        }

        if (($input['save_signature'] ?? false) === true) {
            $this->saved->save($tenantId, $actorId, $appearance);
        }

        if ($written === null) {
            throw new \RuntimeException('The signature was not recorded.');
        }

        return [
            'event' => $outcome['event'],
            'signature' => $written,
            'decided' => $outcome['decided'],
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
        ];
    }

    /**
     * The audit of a document: every act, every signature with whether its seal
     * checks, and whether the trail's chain is intact.
     *
     * A `signed` trail entry with no signature record is reported, and counts
     * as a break: every signature is written with its act.
     *
     * `$withDetails` adds each signer's email address, IP address and browser;
     * the caller decides who may see them.
     *
     * @return array{chain: array<string, mixed>, signatures: list<array<string, mixed>>}
     */
    public function audit(int $tenantId, int $documentId, bool $withDetails = false): array
    {
        $chain = RouteTrailChain::verify($this->db, $tenantId, $documentId);
        $out = [];
        $recorded = [];
        foreach ($this->signatures->listForDocument($tenantId, $documentId) as $row) {
            $out[] = self::present($row, $withDetails) + $this->check($row);
            $recorded[(int) $row['event_id']] = true;
        }

        $missing = [];
        foreach ($this->signedEventIds($tenantId, $documentId) as $eventId) {
            if (!isset($recorded[$eventId])) {
                $missing[] = $eventId;
            }
        }
        $chain['signed_without_record'] = $missing;
        if ($missing !== []) {
            $chain['intact'] = false;
            $brokenAt = $chain['broken_at_event_id'];
            $chain['broken_at_event_id'] = is_int($brokenAt) ? min($brokenAt, $missing[0]) : $missing[0];
        }

        return ['chain' => $chain, 'signatures' => $out];
    }

    /** @return list<int> The document's `signed` trail entries, oldest first. */
    private function signedEventIds(int $tenantId, int $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id AND action = 'signed'
              ORDER BY id ASC"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Whether one record's seal and hashes check.
     *
     * @param array<string, mixed> $row From {@see SignatureRepository}.
     * @return array{seal_valid: bool}
     */
    public function check(array $row): array
    {
        $message = SignatureRepository::sealedMessage($row, isset($row['event_entry_hash']) ? (string) $row['event_entry_hash'] : null);
        $valid = hash_equals((string) $row['record_hash'], hash('sha256', $message))
            && hash_equals((string) $row['appearance_hash'], hash('sha256', (string) $row['appearance']))
            && $this->keys->verify($message, (string) $row['seal'], (string) $row['seal_key_id']);

        return ['seal_valid' => $valid];
    }

    /**
     * The signatures standing on a subject as it is now, for `GET` on a
     * document: capacity, on behalf of, who and when. Matched on the hash of
     * the subject's current content, not on the newest recorded version, so a
     * subject changed since it was signed shows no signatures.
     *
     * @return list<array<string, mixed>>
     */
    public function current(int $tenantId, string $subjectType, int $subjectId): array
    {
        $snapshot = $this->subjects->get($subjectType)?->snapshot($tenantId, $subjectId);
        if ($snapshot === null) {
            return [];
        }

        return array_map(
            self::presentCurrent(...),
            $this->signatures->listForContent($tenantId, $subjectType, $subjectId, RouteSubjectVersionRepository::hash($snapshot)),
        );
    }

    /**
     * One standing signature as `GET` on a subject publishes it.
     *
     * @param array<string, mixed> $row From {@see SignatureRepository}.
     * @return array{id: int, capacity: ?string, on_behalf_of: ?string,
     *               by: array{profile_id: int, name: string}, at: string, subject_version: int}
     */
    public static function presentCurrent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'capacity' => isset($row['capacity']) ? (string) $row['capacity'] : null,
            'on_behalf_of' => isset($row['on_behalf_of']) ? (string) $row['on_behalf_of'] : null,
            'by' => ['profile_id' => (int) $row['signer_profile_id'], 'name' => (string) $row['signer_name']],
            'at' => (string) $row['signed_at'],
            'subject_version' => (int) $row['subject_version'],
        ];
    }

    /**
     * One signature as the audit page shows it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row, bool $withDetails = true): array
    {
        $appearance = json_decode((string) ($row['appearance'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'route_id' => (int) $row['route_id'],
            'event_id' => (int) $row['event_id'],
            'step_id' => (int) $row['step_id'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (int) $row['subject_id'],
            'subject_version' => (int) $row['subject_version'],
            'content_hash' => (string) $row['content_hash'],
            'by' => [
                'profile_id' => (int) $row['signer_profile_id'],
                'name' => (string) $row['signer_name'],
                'email' => $withDetails ? $row['signer_email'] : null,
            ],
            'capacity' => $row['capacity'],
            'on_behalf_of' => $row['on_behalf_of'],
            'confirmed_by' => (string) $row['confirmed_by'],
            'at' => (string) $row['signed_at'],
            'ip_address' => $withDetails ? $row['ip_address'] : null,
            'user_agent' => $withDetails ? $row['user_agent'] : null,
            // True when the email, IP address and browser were left out for this reader.
            'details_withheld' => !$withDetails,
            'consent_version' => (string) $row['consent_version'],
            'consent_wording' => SigningConsent::wording((string) $row['consent_version']),
            'appearance' => is_array($appearance) ? $appearance : null,
            'record_hash' => (string) $row['record_hash'],
            'seal' => (string) $row['seal'],
            'seal_key_id' => (string) $row['seal_key_id'],
        ];
    }

    /**
     * The signer's open item and its step, when the step is a `sign` step.
     *
     * @param array<string, mixed> $route
     * @return array{recipient: array<string, mixed>, step: array<string, mixed>}|null
     */
    private function openSignItem(int $tenantId, int $actorId, array $route): ?array
    {
        $recipient = $this->recipients->findOpenForProfile($tenantId, (int) $route['id'], $actorId);
        if ($recipient === null || $recipient['role'] === RouteRecipientRepository::ROLE_AUTHOR) {
            return null;
        }
        $step = $this->steps->findById((int) $recipient['step_id'], $tenantId);
        if ($step === null || $step['kind'] !== RouteStepKind::SIGN) {
            return null;
        }

        return ['recipient' => $recipient, 'step' => $step];
    }

    /**
     * The subject's content as it is now: its title, its hash, and the version
     * that hash is (the latest recorded one, or the next one when it changed).
     *
     * @param array<string, mixed> $route
     * @return array{type: string, id: int, title: ?string, version: int, content_hash: string}|null
     */
    private function currentContent(int $tenantId, array $route): ?array
    {
        $type = self::subjectType($route);
        $id = self::subjectId($route);
        $provider = $this->subjects->get($type);
        $snapshot = $provider?->snapshot($tenantId, $id);
        if ($provider === null || $snapshot === null) {
            return null;
        }
        $hash = RouteSubjectVersionRepository::hash($snapshot);
        $latest = $this->versions->latest($tenantId, $type, $id);
        $version = $latest === null ? 1 : ($latest['content_hash'] === $hash ? $latest['version'] : $latest['version'] + 1);

        return [
            'type' => $type,
            'id' => $id,
            'title' => $provider->title($tenantId, $id),
            'version' => $version,
            'content_hash' => $hash,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{width: int, height: int, strokes: list<list<array{0: float|int, 1: float|int}>>}
     *
     * @throws SigningRefusedException
     */
    private function appearance(int $tenantId, int $actorId, array $input): array
    {
        if (($input['use_saved_signature'] ?? false) === true) {
            $saved = $this->saved->find($tenantId, $actorId);
            if ($saved === null) {
                throw new SigningRefusedException(
                    SigningRefusedException::APPEARANCE_REQUIRED,
                    'You have no saved signature. Draw your signature to sign.',
                );
            }

            return $saved;
        }

        try {
            return SignatureAppearance::normalise($input['appearance'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new SigningRefusedException(SigningRefusedException::APPEARANCE_REQUIRED, $e->getMessage());
        }
    }

    /** @return array{name: string, email: ?string} */
    private function signer(int $profileId): array
    {
        // @tenant-guard-ignore: profiles / profile_emails are sanctioned GLOBAL identity tables (ADR 0005 §1-2)
        $stmt = $this->db->prepare(
            'SELECT p.display_name, pe.email
               FROM profiles p
               LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = TRUE
              WHERE p.id = :id'
        );
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return [
            'name' => is_array($row) && is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
            'email' => is_array($row) && is_string($row['email'] ?? null) ? $row['email'] : null,
        ];
    }

    private static function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . str_repeat('•', max(1, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    /** @param array<string, mixed> $route */
    private static function subjectType(array $route): string
    {
        return (string) ($route['subject_type'] ?? DocumentRouteSubject::TYPE);
    }

    /** @param array<string, mixed> $route */
    private static function subjectId(array $route): int
    {
        return (int) ($route['subject_id'] ?? $route['document_id']);
    }
}
