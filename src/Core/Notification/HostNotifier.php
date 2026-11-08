<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Ou\OuSubtree;
use Whity\Core\Sandbox\SideEffectGuard;
use Whity\Sdk\Notification\Notice;
use Whity\Sdk\Notification\Notifier;
use Whity\Sdk\PluginNamespace;

/**
 * One plugin's notifier (WHIT-1030). Recipients are read from the workspace's
 * ACTIVE memberships, whatever the plugin named, and every send goes through
 * {@see NotificationDispatcher}, which applies each person's preferences.
 *
 * Only the in-app channel for now: a plugin holds no address, and core ships no
 * mail transport for notifications yet.
 */
final class HostNotifier implements Notifier
{
    /** A send happens on the caller's request or job, so it stays bounded. */
    public const MAX_RECIPIENTS = 1000;

    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_DATA_BYTES = 16384;

    /**
     * @param array<string, array{en: string, ar: string}> $types namespaced type => label
     * @param \Closure(): ?int                             $tenant
     */
    public function __construct(
        private readonly PDO $db,
        private readonly NotificationDispatcher $dispatcher,
        private readonly string $slug,
        private readonly array $types,
        private readonly \Closure $tenant,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function toPerson(string $type, int $profileId, Notice $notice): int
    {
        $tenantId = $this->workspace();

        return $this->send($tenantId, $type, $notice, 'm.profile_id = :target', [':target' => $profileId]);
    }

    public function toRole(string $type, int $roleId, Notice $notice): int
    {
        $tenantId = $this->workspace();

        return $this->send($tenantId, $type, $notice, 'm.role_id = :target', [':target' => $roleId]);
    }

    public function toUnit(string $type, int $unitId, Notice $notice): int
    {
        $tenantId = $this->workspace();
        $units = OuSubtree::descendantIds($this->db, $tenantId, [$unitId]);
        $where = $units === [] ? '1 = 0' : 'm.ou_id IN (' . implode(', ', $units) . ')';

        return $this->send($tenantId, $type, $notice, $where, []);
    }

    /**
     * @param array<string, int> $params
     */
    private function send(int $tenantId, string $type, Notice $notice, string $where, array $params): int
    {
        $canonical = $this->slug . PluginNamespace::SEPARATOR . $type;
        if (!isset($this->types[$canonical])) {
            throw new \InvalidArgumentException("This plugin has not declared the notification type {$type}.");
        }
        self::assertText($notice);
        // Checked once up front: the per-person catch below must not swallow it.
        SideEffectGuard::assertAllowed('Sending a notification');

        $data = $notice->data;
        $data['_plugin'] = $this->slug;
        if (strlen((string) json_encode($data)) > self::MAX_DATA_BYTES) {
            throw new \InvalidArgumentException('Notice data is too large.');
        }

        $recipients = $this->recipients($tenantId, $where, $params);
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('Too many recipients for one send (at most ' . self::MAX_RECIPIENTS . ').');
        }

        $sent = 0;
        foreach ($recipients as $profileId => $language) {
            $language = $language === 'ar' ? 'ar' : 'en';
            try {
                $this->dispatcher->dispatch($tenantId, $profileId, $canonical, [
                    'channels' => ['in_app'],
                    'subject' => $notice->subject[$language],
                    'body' => $notice->body[$language],
                    'data' => $data,
                    'locale' => $language,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                // One person's failure must not stop the rest.
                $this->logger?->error('[notifications] plugin notice failed', [
                    'plugin' => $this->slug,
                    'tenant_id' => $tenantId,
                    'profile_id' => $profileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Active members of this workspace who match, profile id => language.
     *
     * @param array<string, int> $params
     * @return array<int, ?string>
     */
    private function recipients(int $tenantId, string $where, array $params): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.profile_id, p.language_code
               FROM memberships m
               JOIN profiles p ON p.id = m.profile_id
              WHERE m.tenant_id = :tenant_id AND m.status = 'active' AND {$where}
              ORDER BY m.profile_id ASC
              LIMIT " . (self::MAX_RECIPIENTS + 1)
        );
        $stmt->execute([':tenant_id' => $tenantId] + $params);

        $recipients = [];
        /** @var array{profile_id: int|string, language_code: ?string} $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recipients[(int) $row['profile_id']] = $row['language_code'];
        }

        return $recipients;
    }

    private function workspace(): int
    {
        $tenantId = ($this->tenant)();
        if ($tenantId === null || $tenantId === 0) {
            throw new \RuntimeException('Sending a notification needs a workspace, and none is in scope.');
        }

        return $tenantId;
    }

    /** The plugin's Notice is untrusted: its array shapes are PHPDoc only. */
    private static function assertText(Notice $notice): void
    {
        foreach (['en', 'ar'] as $language) {
            /** @var array<string, mixed> $subjects */
            $subjects = $notice->subject;
            /** @var array<string, mixed> $bodies */
            $bodies = $notice->body;
            $subject = $subjects[$language] ?? null;
            $body = $bodies[$language] ?? null;
            if (!is_string($subject) || trim($subject) === '' || mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                throw new \InvalidArgumentException("A Notice needs a subject of up to 255 characters in '{$language}'.");
            }
            if (!is_string($body) || trim($body) === '') {
                throw new \InvalidArgumentException("A Notice needs a body in '{$language}'.");
            }
        }
    }
}
