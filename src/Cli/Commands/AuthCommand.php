<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Identity\PasswordSignIn;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Database\Database;

/**
 * `whity-cli auth` — sign-in switches an operator may need from the server
 * itself, when nobody can sign in to the web app to change them.
 *
 * Subcommands:
 *   password-sign-in status   Show whether password sign-in is on.
 *   password-sign-in on       Turn password sign-in back on (the break-glass, WHIT-1168).
 *   password-sign-in off      Sign people in only through the operator's identity provider.
 */
class AuthCommand extends BaseCommand implements CliCommand
{
    public function printHelp(string $commandName): bool
    {
        $this->showHelp();

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return [];
    }

    /**
     * @param array<int, string> $argv
     */
    public function execute(array $argv): int
    {
        $topic = $argv[0] ?? 'help';
        if ($topic !== 'password-sign-in') {
            $this->showHelp();

            return $topic === 'help' || $topic === '--help' || $topic === '-h' ? 0 : 1;
        }

        $action = $argv[1] ?? 'status';
        $pdo = Database::connect()->getPdo();
        $settings = new GlobalSettingsRepository($pdo);

        switch ($action) {
            case 'status':
                $this->printStatus($settings->get(PasswordSignIn::SETTING) !== 'false');

                return 0;

            case 'on':
                $settings->set(PasswordSignIn::SETTING, 'true');
                echo "Password sign-in is on. People can sign in with their email and password again.\n";
                echo "Turn it off again from Settings > Sign-in & SSO once the identity provider is back.\n";

                return 0;

            case 'off':
                $problem = PasswordSignIn::cannotTurnOff($pdo, null);
                if ($problem !== null) {
                    echo "Not changed. {$problem}\n";

                    return 1;
                }
                $settings->set(PasswordSignIn::SETTING, 'false');
                echo "Password sign-in is off. People sign in only with the identity provider.\n";
                echo "To turn it back on: whity-cli auth password-sign-in on\n";
                if (PasswordSignIn::forcedOn()) {
                    echo "Note: " . PasswordSignIn::BREAK_GLASS_ENV . "=force is set here, which keeps password sign-in on until it is removed.\n";
                }

                return 0;
        }

        echo "Unknown action: {$action}\n";
        $this->showHelp();

        return 1;
    }

    private function printStatus(bool $setting): void
    {
        echo 'Password sign-in setting: ' . ($setting ? 'on' : 'off') . "\n";
        if (PasswordSignIn::forcedOn()) {
            echo PasswordSignIn::BREAK_GLASS_ENV . "=force is set, so password sign-in is on whatever the setting says.\n";
        }
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli auth password-sign-in <status|on|off>\n\n";
        echo "  status   Show whether password sign-in is on\n";
        echo "  on       Turn password sign-in back on (use when the identity provider is down)\n";
        echo "  off      Sign people in only through the server's identity provider\n\n";
        echo "Setting " . PasswordSignIn::BREAK_GLASS_ENV . "=force in the environment also keeps it on, whatever the setting says.\n";
    }
}
