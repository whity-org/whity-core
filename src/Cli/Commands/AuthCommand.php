<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use PDO;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Audit\AuditOrigin;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Identity\OperatorSignIn;
use Whity\Core\Identity\PasswordSignIn;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Database\Database;

/**
 * `whity-cli auth` — sign-in switches an operator may need from the server
 * itself, when nobody can sign in to the web app to change them.
 *
 * Subcommands:
 *   password-sign-in status        Show whether password sign-in is on, and operators with no Whity ID.
 *   password-sign-in on            Turn password sign-in back on (the break-glass, WHIT-1168).
 *   password-sign-in off [--force] Sign people in only through the operator's identity provider.
 *   operator-link <email>          Print a one-time sign-in link for an operator with no Whity ID (WHIT-1350).
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
        return ['--force'];
    }

    /**
     * @param array<int, string> $argv
     */
    public function execute(array $argv): int
    {
        $topic = $argv[0] ?? 'help';
        if ($topic !== 'password-sign-in' && $topic !== 'operator-link') {
            $this->showHelp();

            return $topic === 'help' || $topic === '--help' || $topic === '-h' ? 0 : 1;
        }

        $pdo = Database::connect()->getPdo();

        return $this->run($argv, $pdo, self::jwtFromEnv(), FrontendUrls::fromEnv()->base());
    }

    /**
     * The command against a given database, so tests can run it.
     *
     * @param array<int, string> $argv
     */
    public function run(array $argv, PDO $pdo, JwtParser $jwt, string $frontendBase): int
    {
        $force = in_array('--force', $argv, true);
        $args = array_values(array_filter($argv, static fn (string $a): bool => $a !== '--force'));
        $audit = new AuditLogger($pdo, null, AuditOrigin::cli('auth'));

        if (($args[0] ?? '') === 'operator-link') {
            return $this->operatorLink($pdo, $jwt, $audit, $frontendBase, $args[1] ?? '');
        }

        $action = $args[1] ?? 'status';
        $settings = new GlobalSettingsRepository($pdo);

        switch ($action) {
            case 'status':
                $this->printStatus($settings->get(PasswordSignIn::SETTING) !== 'false', $pdo);

                return 0;

            case 'on':
                $settings->set(PasswordSignIn::SETTING, 'true');
                echo "Password sign-in is on. People can sign in with their email and password again.\n";
                echo "Turn it off again from Settings > Sign-in & SSO once the identity provider is back.\n";

                return 0;

            case 'off':
                $problem = PasswordSignIn::cannotTurnOff($pdo, null, $force);
                if ($problem !== null) {
                    echo "Not changed. {$problem}\n";
                    if (OperatorSignIn::unlinked($pdo) !== []) {
                        echo "An operator can get in to link it with: whity-cli auth operator-link <email>\n";
                        echo "To turn it off anyway, locking them out: whity-cli auth password-sign-in off --force\n";
                    }

                    return 1;
                }
                $unlinked = $force ? OperatorSignIn::unlinked($pdo) : [];
                $settings->set(PasswordSignIn::SETTING, 'false');
                echo "Password sign-in is off. People sign in only with the identity provider.\n";
                if ($unlinked !== []) {
                    echo "WARNING: turned off with --force. These platform operators have no Whity ID and now cannot sign in: "
                        . OperatorSignIn::names($unlinked) . ".\n";
                    echo "Give each of them a one-time link to sign in and link Whity ID: whity-cli auth operator-link <email>\n";
                    $audit->record('auth.password_sign_in.forced_off', [
                        'tenant_id' => 0,
                        'target_type' => 'setting',
                        'metadata' => ['key' => PasswordSignIn::SETTING, 'unlinked_operators' => array_column($unlinked, 'profile_id')],
                    ]);
                }
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

    private function operatorLink(PDO $pdo, JwtParser $jwt, AuditLogger $audit, string $frontendBase, string $email): int
    {
        if (trim($email) === '') {
            echo "Usage: whity-cli auth operator-link <email>\n";

            return 1;
        }

        $operator = OperatorSignIn::findOperatorByEmail($pdo, $email);
        if ($operator === null) {
            echo "No link made. {$email} is not an active member of the system workspace.\n";
            echo "The link is only for platform operators.\n";
            $audit->record(OperatorSignIn::AUDIT_REFUSED, [
                'tenant_id' => 0,
                'target_type' => 'profile',
                'metadata' => ['reason' => 'not_operator', 'stage' => 'issue'],
            ]);

            return 1;
        }
        if ($operator['linked']) {
            echo "No link made. {$operator['email']} already has Whity ID linked: they sign in with it.\n";

            return 1;
        }
        if ($frontendBase === '') {
            echo "No link made. Set FRONTEND_URL or APP_URL so the link can name this server.\n";

            return 1;
        }

        $token = OperatorSignIn::issueLink($jwt, $operator['profile_id']);
        $audit->record(OperatorSignIn::AUDIT_ISSUED, [
            'tenant_id' => 0,
            'target_type' => 'profile',
            'target_id' => $operator['profile_id'],
            'metadata' => ['expires_in' => OperatorSignIn::LINK_TTL],
        ]);

        $minutes = intdiv(OperatorSignIn::LINK_TTL, 60);
        echo "One-time sign-in link for {$operator['email']}. It works once, for the next {$minutes} minutes:\n\n";
        echo rtrim($frontendBase, '/') . '/operator-sign-in#token=' . $token . "\n\n";
        echo "It signs them in to the system workspace and opens Settings > Security, where they link Whity ID.\n";
        echo "Anyone with the link can use it: send it only to {$operator['email']}, over a channel you trust.\n";

        return 0;
    }

    private function printStatus(bool $setting, PDO $pdo): void
    {
        echo 'Password sign-in setting: ' . ($setting ? 'on' : 'off') . "\n";
        if (PasswordSignIn::forcedOn()) {
            echo PasswordSignIn::BREAK_GLASS_ENV . "=force is set, so password sign-in is on whatever the setting says.\n";
        }

        $unlinked = OperatorSignIn::unlinked($pdo);
        if ($unlinked === []) {
            echo "Every platform operator has Whity ID linked.\n";

            return;
        }
        echo 'Platform operators with no Whity ID linked'
            . ($setting ? " (they can't sign in once password sign-in is off):\n" : " (they can't sign in now):\n");
        foreach ($unlinked as $operator) {
            echo '  ' . ($operator['email'] !== '' ? $operator['email'] : 'profile ' . $operator['profile_id'])
                . ($operator['display_name'] !== '' ? " ({$operator['display_name']})" : '') . "\n";
        }
        echo "Give one a way in with: whity-cli auth operator-link <email>\n";
    }

    private static function jwtFromEnv(): JwtParser
    {
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        if ($appEnv !== 'development' && empty($_ENV['JWT_SECRET'])) {
            throw new \RuntimeException('JWT_SECRET environment variable must be set in production environments');
        }

        return new JwtParser((string) ($_ENV['JWT_SECRET'] ?? 'dev_secret_key_change_in_production'));
    }

    private function showHelp(): void
    {
        echo "Usage: whity-cli auth password-sign-in <status|on|off> [--force]\n";
        echo "       whity-cli auth operator-link <email>\n\n";
        echo "  password-sign-in status  Show whether password sign-in is on, and operators with no Whity ID\n";
        echo "  password-sign-in on      Turn password sign-in back on (use when the identity provider is down)\n";
        echo "  password-sign-in off     Sign people in only through the server's identity provider.\n";
        echo "                           Refuses while a platform operator has no Whity ID; --force turns it off anyway\n";
        echo "  operator-link <email>    Print a one-time, 15-minute sign-in link for a platform operator\n";
        echo "                           with no Whity ID, so they can sign in and link it\n\n";
        echo "Setting " . PasswordSignIn::BREAK_GLASS_ENV . "=force in the environment also keeps password sign-in on, whatever the setting says.\n";
    }
}
