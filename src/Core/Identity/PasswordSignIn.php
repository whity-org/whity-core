<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Whether this server still signs people in with a password (WHIT-1168).
 *
 * `auth.password_sign_in` is an operator setting, on by default: a self-hosted
 * core keeps its own sign-in (ADR 0019). An operator who signs everyone in
 * through an identity provider, as app.whity.dev does with Whity ID, turns it
 * off. Then password sign-in, password reset, registration with a password and
 * the local two-step set-up all refuse, and the sign-in and sign-up pages show
 * only the provider. Sessions, API tokens, MCP tokens and agent credentials
 * that already exist keep working: none of them is a password.
 *
 * Break-glass: `WHITY_PASSWORD_SIGN_IN=force` in the environment turns password
 * sign-in back on whatever the setting says, so an outage at the identity
 * provider can't lock the operator out. `whity-cli auth password-sign-in on`
 * flips the setting itself from the server.
 */
final class PasswordSignIn
{
    public const SETTING = SettingsRegistry::PASSWORD_SIGN_IN;

    /** The environment variable that forces password sign-in on. */
    public const BREAK_GLASS_ENV = 'WHITY_PASSWORD_SIGN_IN';

    /** The error code every refusal carries, so pages can show the provider instead. */
    public const REFUSED_CODE = 'password_sign_in_off';

    public static function forcedOn(): bool
    {
        $value = $_ENV[self::BREAK_GLASS_ENV] ?? getenv(self::BREAK_GLASS_ENV);

        return is_string($value) && strtolower(trim($value)) === 'force';
    }

    /**
     * True unless the operator turned password sign-in off. A settings read
     * that fails counts as on: the setting only ever takes a way in away, so
     * failing open here keeps the server reachable rather than locking it.
     */
    public static function allowed(?SettingsService $settings): bool
    {
        if (self::forcedOn() || $settings === null) {
            return true;
        }

        try {
            return ($settings->getGlobal()[self::SETTING] ?? 'true') !== 'false';
        } catch (\Throwable $e) {
            error_log('[auth] reading ' . self::SETTING . ' failed: ' . $e->getMessage());

            return true;
        }
    }

    public static function refusal(): Response
    {
        return Response::error(
            'Password sign-in is turned off on this server. Continue with your identity provider instead.',
            403,
            ['code' => self::REFUSED_CODE],
        );
    }

    /**
     * Why turning password sign-in off now would lock people out, or null when
     * it is safe: the server needs an enabled identity provider of its own
     * (workspace 0), and the operator turning it off must already have signed
     * in with one, or they would lose their own way back in.
     *
     * WHIT-1350: every other platform operator (active member of workspace 0)
     * needs a link too, or they are locked out; the refusal names them.
     * `$allowUnlinkedOperators` skips only that check, for the CLI's `--force`.
     */
    public static function cannotTurnOff(\PDO $pdo, ?int $operatorProfileId, bool $allowUnlinkedOperators = false): ?string
    {
        $provider = $pdo->query('SELECT 1 FROM identity_providers WHERE tenant_id = 0 AND enabled = TRUE LIMIT 1');
        if ($provider === false || $provider->fetchColumn() === false) {
            return 'Turn on an identity provider for the whole server first. Without one, nobody could sign in.';
        }

        if ($operatorProfileId !== null) {
            $link = $pdo->prepare('SELECT 1 FROM external_identities WHERE profile_id = :profile AND provider_id IS NULL LIMIT 1');
            $link->execute([':profile' => $operatorProfileId]);
            if ($link->fetchColumn() === false) {
                return 'Sign in once with the identity provider before turning password sign-in off, so you keep a way in.';
            }
        }

        if (!$allowUnlinkedOperators) {
            return OperatorSignIn::unlinkedProblem($pdo);
        }

        return null;
    }

    /**
     * Wrap a route handler so it refuses while password sign-in is off.
     *
     * @return callable(Request, array<string, string>=): Response
     */
    public static function guard(callable $handler, SettingsService $settings): callable
    {
        return static function (Request $request, array $params = []) use ($handler, $settings): Response {
            if (!self::allowed($settings)) {
                return self::refusal();
            }

            return $handler($request, $params);
        };
    }
}
