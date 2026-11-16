<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Messaging;

use PHPUnit\Framework\TestCase;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Sdk\Messaging\ChannelCapabilities;
use Whity\Sdk\Messaging\DeliveryStatusUpdate;
use Whity\Sdk\Messaging\MessageChannel;
use Whity\Sdk\Messaging\OutboundMessage;
use Whity\Sdk\Messaging\SendOutcome;

/** Channels as core and plugins register them (SDK 1.97, ADR 0016 §3). */
final class MessageChannelRegistryTest extends TestCase
{
    private static function channel(string $key, bool $throws = false): MessageChannel
    {
        return new class ($key, $throws) implements MessageChannel {
            public function __construct(private string $key, private bool $throws)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): array
            {
                if ($this->throws) {
                    throw new \RuntimeException('broken plugin');
                }

                return ['en' => ucfirst($this->key), 'ar' => 'قناة'];
            }

            public function isEnabled(int $tenantId): bool
            {
                return $tenantId === 7;
            }

            public function capabilities(): ChannelCapabilities
            {
                return new ChannelCapabilities(requiresTemplate: true, freeformWindowSeconds: 86400);
            }

            public function send(OutboundMessage $message): SendOutcome
            {
                return SendOutcome::sent('x');
            }

            public function receive(int $tenantId, string $rawBody, array $headers): array
            {
                return [];
            }

            public function templates(int $tenantId): array
            {
                return [];
            }
        };
    }

    public function testCoreKeysAreBareAndPluginKeysAreNamespaced(): void
    {
        $registry = new MessageChannelRegistry();
        $registry->registerCore(self::channel('email'));
        $registry->register('Whity WhatsApp', [self::channel('whatsapp')]);

        self::assertSame(['email', 'whity_whatsapp:whatsapp'], $registry->keys());
        self::assertSame('core', $registry->origin('email'));
        self::assertSame('Whity WhatsApp', $registry->origin('whity_whatsapp:whatsapp'));
    }

    public function testAPluginCannotTakeACoreKeyOrCallItselfCore(): void
    {
        $registry = new MessageChannelRegistry();
        $registry->registerCore(self::channel('email'));
        $registry->register('Evil', [self::channel('email')]);

        self::assertNotSame('Evil', $registry->origin('email'), 'the plugin got evil:email, not email');

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('core', [self::channel('sms')]);
    }

    public function testABadBatchStoresNothing(): void
    {
        $registry = new MessageChannelRegistry();
        foreach ([[self::channel('ok'), self::channel('Bad Key')], [self::channel('ok'), 'nope'], [self::channel('ok'), self::channel('ok')]] as $batch) {
            try {
                $registry->register('Acme', $batch);
                self::fail('should have been refused');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $registry->keys());
            }
        }
    }

    public function testAReloadReplacesOnlyThePluginsOwnChannels(): void
    {
        $registry = new MessageChannelRegistry();
        $registry->register('Acme', [self::channel('sms'), self::channel('whatsapp')]);
        $registry->register('Other', [self::channel('sms')]);
        $registry->register('Acme', [self::channel('sms')]);

        self::assertSame(['acme:sms', 'other:sms'], $registry->keys());
    }

    public function testCatalogueSkipsAChannelThatThrowsAndReportsPerWorkspaceState(): void
    {
        $registry = new MessageChannelRegistry();
        $registry->registerCore(self::channel('email'));
        $registry->register('Broken', [self::channel('sms', true)]);

        $catalogue = $registry->catalogue(7);
        self::assertCount(1, $catalogue);
        self::assertTrue($catalogue[0]['enabled']);
        self::assertTrue($catalogue[0]['capabilities']['requires_template']);
        self::assertFalse($registry->catalogue(8)[0]['enabled']);
    }

    public function testFreeformWindow(): void
    {
        $caps = new ChannelCapabilities(requiresTemplate: true, freeformWindowSeconds: 86400);
        self::assertTrue($caps->allowsFreeform(100));
        self::assertFalse($caps->allowsFreeform(86401));
        self::assertFalse($caps->allowsFreeform(null), 'never wrote: only a template');
        self::assertTrue((new ChannelCapabilities())->allowsFreeform(null));
    }

    public function testDeliveryStatusRefusesUnknownStatuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeliveryStatusUpdate('abc', 'bounced');
    }
}
