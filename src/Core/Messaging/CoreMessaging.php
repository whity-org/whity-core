<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Closure;
use PDO;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Mail\ThreadedMailer;
use Whity\Core\Messaging\Email\EmailChannel;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Builds Messages the same way in every entry point (ADR 0016): the web
 * request, the CLI kernel and the queue worker. Core's two channels are
 * registered here; plugin channels join the same registry when plugins load.
 */
final class CoreMessaging
{
    private function __construct()
    {
    }

    /**
     * @param Closure(string, array<string, mixed>, array<string, mixed>): mixed $enqueue
     */
    public static function build(
        PDO $pdo,
        MessageChannelRegistry $channels,
        ThreadedMailer $mailer,
        EncryptedSecretStore $secrets,
        Closure $enqueue,
        ?MeterService $meters = null,
        ?string $appUrl = null,
        // E2: emails go out as the workspace's HTML card through the one sender.
        ?\Whity\Core\Mail\BrandedEmailSender $sender = null,
    ): MessagingService {
        $settings = new MessagingSettings($pdo, $secrets);

        if (!$channels->has(EmailChannel::KEY)) {
            $channels->registerCore(new EmailChannel(
                $mailer,
                $settings,
                static function (int $tenantId) use ($pdo): ?string {
                    $stmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :tid');
                    $stmt->execute([':tid' => $tenantId]);
                    $name = $stmt->fetchColumn();

                    return is_string($name) ? $name : null;
                },
                self::messageIdHost($appUrl),
                $sender,
                static function (int $tenantId, int $messageId) use ($pdo): ?string {
                    $stmt = $pdo->prepare(
                        'SELECT p.display_name FROM messages m JOIN profiles p ON p.id = m.author_profile_id
                          WHERE m.tenant_id = :tid AND m.id = :id'
                    );
                    $stmt->execute([':tid' => $tenantId, ':id' => $messageId]);
                    $name = $stmt->fetchColumn();

                    return is_string($name) ? $name : null;
                },
            ));
        }
        if (!$channels->has(WebChatChannel::KEY)) {
            $channels->registerCore(new WebChatChannel($settings));
        }

        $service = new MessagingService(
            $pdo,
            new ConversationRepository($pdo),
            new MessageRepository($pdo),
            $channels,
            $enqueue,
            $meters,
        );
        // One chat (design 3.5, WHIT-1097): blocked senders and the workspace's bots.
        $blocking = new \Whity\Core\Chat\ChatBlocking($pdo);
        $bots = new \Whity\Core\Chat\ChatBots($pdo);
        $service->useInboundRules(
            static fn (int $tenantId, string $channel, string $address): bool => $blocking->isBlocked($tenantId, $channel, $address),
            static function (MessagingService $messaging, int $tenantId, int $conversationId, bool $isNew) use ($bots): void {
                $bots->onInbound($messaging, $tenantId, $conversationId, $isNew);
            },
        );

        return $service;
    }

    /** The host part of our Message-IDs: the instance's own host, or a stable fallback. */
    public static function messageIdHost(?string $appUrl): string
    {
        $host = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

        return is_string($host) && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1 ? strtolower($host) : 'whity.local';
    }
}
