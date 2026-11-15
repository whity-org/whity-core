<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Psr\Log\LoggerInterface;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\BrandedEmailSender;

/**
 * Registers the built-in (core) notification transports into a
 * {@see TransportRegistry} — the transport analogue of {@see \Whity\Core\Queue\CoreJobs}.
 *
 * `in_app` is the inbox itself. `email` is delivered through core mail
 * ({@see NotificationEmailTransport}) whenever the caller hands over the sender,
 * as the web app and the queue worker do (E2); without one (a bare test registry)
 * it falls back to a {@see LogTransport} (log + report-sent), so nothing
 * HARD-fails merely for lack of a provider. Real transports (SMTP email, in-app, push, …) register
 * themselves for their channel in their own slices; last-registration-wins, so
 * they transparently override the log default for that channel.
 *
 * Called at BOTH ends of the queue so the web boot path and the `queue:work`
 * worker resolve the same transport per channel.
 */
final class CoreTransports
{
    /**
     * Channels core ships a built-in (log) transport for out of the box. A smart
     * default, not a hard limit — any channel a real transport registers for is
     * served regardless of this list.
     *
     * @var list<string>
     */
    public const DEFAULT_CHANNELS = ['in_app', 'email'];

    public static function register(
        TransportRegistry $registry,
        ?LoggerInterface $logger = null,
        ?BrandedEmailSender $sender = null,
        ?ProfileEmailRepository $emails = null,
    ): void {
        // `in_app` is served by the built-in inbox transport (the notification row
        // IS the delivery). Every OTHER core channel gets the log transport by
        // default until a real provider registers for it (last-registration-wins).
        $registry->register(new InAppTransport());
        foreach (self::DEFAULT_CHANNELS as $channel) {
            if ($channel !== InAppTransport::CHANNEL) {
                $registry->register(new LogTransport($channel, $logger));
            }
        }
        // E2: email through core mail when the caller hands over the sender.
        if ($sender !== null && $emails !== null) {
            $registry->register(new NotificationEmailTransport($sender, $emails));
        }
    }

    public static function make(
        ?LoggerInterface $logger = null,
        ?BrandedEmailSender $sender = null,
        ?ProfileEmailRepository $emails = null,
    ): TransportRegistry {
        $registry = new TransportRegistry();
        self::register($registry, $logger, $sender, $emails);

        return $registry;
    }
}
