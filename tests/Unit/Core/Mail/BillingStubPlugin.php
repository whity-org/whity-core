<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use Whity\Sdk\PluginInterface;

/** A minimal plugin for the billing email tests. */
abstract class BillingStubPlugin implements PluginInterface
{
    public function __construct(private string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }
}
