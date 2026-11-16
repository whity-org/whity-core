<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Messaging;

use PHPUnit\Framework\TestCase;
use Whity\Core\Messaging\HostCustomerMessages;
use Whity\Core\Messaging\MessagingService;

/** WHIT-967: which channels a plugin's customer message is tried on, in order. */
final class HostCustomerMessagesTest extends TestCase
{
    private function host(): HostCustomerMessages
    {
        return new HostCustomerMessages(static fn (): MessagingService => throw new \LogicException('not used'));
    }

    public function testABareNameFindsAnInstalledPluginChannelBeforeEmail(): void
    {
        $keys = ['email', 'webchat', 'whitywhatsapp:whatsapp'];

        self::assertSame(['whitywhatsapp:whatsapp', 'email'], $this->host()->candidates($keys, ['whatsapp', 'email']));
    }

    public function testWithoutWhatsAppItFallsBackToEmail(): void
    {
        self::assertSame(['email'], $this->host()->candidates(['email', 'webchat'], ['whatsapp', 'email']));
    }

    public function testAFullKeyMatchesOnlyThatChannelAndNothingIsRepeated(): void
    {
        $keys = ['acme:whatsapp', 'email', 'other:whatsapp'];

        self::assertSame(['other:whatsapp', 'email'], $this->host()->candidates($keys, ['other:whatsapp', 'email', 'email', '', 7]));
        self::assertSame([], $this->host()->candidates($keys, ['sms']));
    }
}
