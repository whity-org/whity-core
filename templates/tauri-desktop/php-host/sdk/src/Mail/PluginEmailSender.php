<?php

declare(strict_types=1);

namespace Whity\Sdk\Mail;

use Whity\Sdk\PluginInterface;

/**
 * Host service (WHIT-959). In a route handler or job:
 *
 *     \Whity\app(PluginEmailSender::class)->for($this)
 *         ->send('invoice', 'accounts@example-bakery.test', ['number' => 'INV-0042', ...], 'ar');
 *
 * Pass the plugin itself. An object the host did not load is refused.
 */
interface PluginEmailSender
{
    public function for(PluginInterface $plugin): EmailSender;
}
