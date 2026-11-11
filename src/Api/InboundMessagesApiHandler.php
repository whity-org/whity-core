<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Sdk\Messaging\DeliveryStatusUpdate;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * Where providers deliver to a channel (ADR 0016 §3-4):
 * `POST /api/public/messages/inbound/{channel}/{workspace}`.
 *
 * WHAT MAKES IT SAFE IS NOT A SESSION. A provider cannot hold one. The
 * workspace comes from the slug; the CHANNEL decides authenticity, with the
 * workspace's own secret, before anything is read (core's email channel checks
 * an HMAC; a WhatsApp plugin checks Meta's signature). The host never parses a
 * provider's payload. Every answer is the same 202, authentic or not, so the
 * route tells a stranger nothing about which workspaces or secrets exist.
 */
final class InboundMessagesApiHandler
{
    private const SYSTEM_TENANT_ID = 0;
    private const MAX_BODY_BYTES = 10 * 1024 * 1024;
    private const MAX_ITEMS = 100;
    private const WINDOW = 60;
    private const PER_IP = 120;

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly TenantHostRepository $tenants,
        private readonly SharedStoreInterface $store,
    ) {
    }

    /** @param array<string, string> $params */
    public function receive(Request $request, array $params = []): Response
    {
        $ip = ClientIp::fromRequest($request);
        if ($ip !== null) {
            $key = 'inbound:ip:' . $ip;
            if ($this->store->count($key) >= self::PER_IP) {
                return Response::error('Too many requests', 429)->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
            }
            $this->store->increment($key, self::WINDOW);
        }

        $accepted = Response::json(['data' => ['accepted' => true]], 202);

        $channelKey = rawurldecode((string) ($params['channel'] ?? ''));
        $slug = strtolower(trim((string) ($params['workspace'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1 || strlen($slug) > 255) {
            return $accepted;
        }
        $channel = $this->messaging->channels()->get($channelKey);
        $raw = $request->getBody();
        if ($channel === null || $raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return $accepted;
        }

        try {
            $tenantId = $this->tenants->findIdBySlug($slug);
            if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
                return $accepted;
            }

            $headers = [];
            foreach ($request->getHeaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }

            $items = $channel->receive($tenantId, $raw, $headers);
        } catch (\Throwable $e) {
            error_log("[InboundMessages] channel {$channelKey} failed to read a request: " . $e->getMessage());

            return $accepted;
        }

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            try {
                if ($item instanceof InboundMessage) {
                    $this->messaging->ingest($tenantId, $channelKey, $item);
                } elseif ($item instanceof DeliveryStatusUpdate) {
                    $this->messaging->applyStatus($tenantId, $channelKey, $item);
                }
            } catch (MessagingException $e) {
                error_log("[InboundMessages] {$channelKey}: an item was not filed: " . $e->getMessage());
            } catch (\Throwable $e) {
                error_log("[InboundMessages] {$channelKey}: filing failed: " . $e->getMessage());
            }
        }

        return $accepted;
    }
}
