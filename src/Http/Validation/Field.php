<?php

declare(strict_types=1);

namespace Whity\Http\Validation;

use Whity\Core\PasswordPolicy;

/**
 * One field's rules, for {@see FieldValidator} (#2174).
 *
 * Declared once per field, in the order a person reads the form, so the first
 * problem a handler reports is the first one on the screen. Each kind
 * normalises its value the way the hand-written checks did, so valid input
 * reaches the handler exactly as before:
 *
 *  - `text`: trimmed.
 *  - `email`: trimmed and (unless asked not to) lower-cased, then FILTER_VALIDATE_EMAIL.
 *  - `phone`: trimmed, then the handler's pattern.
 *  - `emailOrPhone`: trimmed; either an email address or a phone number.
 *  - `password`: never trimmed; {@see PasswordPolicy} decides.
 *
 * A value that is not a string is read as a string when it is a number and as
 * empty otherwise, so `{"email": ["x"]}` is "missing", never "Array".
 */
final class Field
{
    public const KIND_TEXT = 'text';
    public const KIND_EMAIL = 'email';
    public const KIND_PHONE = 'phone';
    public const KIND_EMAIL_OR_PHONE = 'email_or_phone';
    public const KIND_PASSWORD = 'password';

    /** The phone pattern when a handler doesn't name its own. */
    public const PHONE_PATTERN = '/^\+?[0-9 ()\-]{6,40}$/';

    private bool $required = false;
    private ?int $maxChars = null;
    private ?int $maxBytes = null;
    /** Cut to this many characters instead of refusing (a name a chat keeps short). */
    private ?int $truncateTo = null;
    /** @var list<string> Body keys read, first present wins (`tenant_name`, `tenantName`). */
    private array $keys = [];
    /** @var array<string, array{0: string, 1: string}> Code => [English, Arabic], this field's own wording. */
    private array $wording = [];

    private function __construct(
        public readonly string $kind,
        public readonly string $pattern = self::PHONE_PATTERN,
        private readonly bool $lowercase = true,
    ) {
    }

    public static function text(): self
    {
        return new self(self::KIND_TEXT);
    }

    /** @param bool $lowercase False keeps the address as typed (chat stores it so). */
    public static function email(bool $lowercase = true): self
    {
        return new self(self::KIND_EMAIL, self::PHONE_PATTERN, $lowercase);
    }

    public static function phone(string $pattern = self::PHONE_PATTERN): self
    {
        return new self(self::KIND_PHONE, $pattern);
    }

    public static function emailOrPhone(string $phonePattern = self::PHONE_PATTERN): self
    {
        return new self(self::KIND_EMAIL_OR_PHONE, $phonePattern);
    }

    public static function password(): self
    {
        return new self(self::KIND_PASSWORD);
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    /** At most this many characters (multibyte-aware). */
    public function max(int $chars): self
    {
        $this->maxChars = $chars;

        return $this;
    }

    /** At most this many bytes, for a value stored in a byte-limited column. */
    public function maxBytes(int $bytes): self
    {
        $this->maxBytes = $bytes;

        return $this;
    }

    /** Keep the first `$chars` characters rather than refusing a longer value. */
    public function truncate(int $chars): self
    {
        $this->truncateTo = $chars;

        return $this;
    }

    /** Read the value from the first of these body keys that is present. */
    public function from(string ...$keys): self
    {
        $this->keys = array_values($keys);

        return $this;
    }

    /**
     * This field's own words for one problem, in English and Arabic, instead of
     * the general ones ("Please write your name." rather than "Please fill this in.").
     */
    public function say(string $code, string $english, string $arabic): self
    {
        $this->wording[$code] = [$english, $arabic];

        return $this;
    }

    /**
     * Read and check the value.
     *
     * @param array<string, mixed> $body
     * @return array{value: string, problem: ?array{code: string, params: array<string, int>}}
     */
    public function check(string $name, array $body): array
    {
        $raw = null;
        foreach ($this->keys !== [] ? $this->keys : [$name] as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null) {
                $raw = $body[$key];
                break;
            }
        }
        $value = is_string($raw) ? $raw : (is_int($raw) || is_float($raw) ? (string) $raw : '');
        if ($this->kind !== self::KIND_PASSWORD) {
            $value = trim($value);
        }
        if ($this->kind === self::KIND_EMAIL && $this->lowercase) {
            $value = strtolower($value);
        }
        if ($this->truncateTo !== null) {
            $value = mb_substr($value, 0, $this->truncateTo);
        }

        $problem = $this->problem($value);

        return ['value' => $value, 'problem' => $problem];
    }

    /** @return array{0: string, 1: string}|null This field's own words for `$code`. */
    public function wordingFor(string $code): ?array
    {
        return $this->wording[$code] ?? null;
    }

    /** @return array{code: string, params: array<string, int>}|null */
    private function problem(string $value): ?array
    {
        if ($value === '') {
            return $this->required ? ['code' => Messages::REQUIRED, 'params' => []] : null;
        }

        if ($this->kind === self::KIND_PASSWORD) {
            if (strlen($value) < PasswordPolicy::MIN_LENGTH) {
                return ['code' => Messages::TOO_SHORT, 'params' => ['min' => PasswordPolicy::MIN_LENGTH]];
            }
            if (strlen($value) > PasswordPolicy::MAX_LENGTH) {
                return ['code' => Messages::TOO_LONG, 'params' => ['max' => PasswordPolicy::MAX_LENGTH]];
            }

            return null;
        }

        if ($this->maxChars !== null && mb_strlen($value) > $this->maxChars) {
            return ['code' => Messages::TOO_LONG, 'params' => ['max' => $this->maxChars]];
        }
        if ($this->maxBytes !== null && strlen($value) > $this->maxBytes) {
            return ['code' => Messages::TOO_LONG, 'params' => ['max' => $this->maxBytes]];
        }

        return match ($this->kind) {
            self::KIND_EMAIL => self::isEmail($value) ? null : ['code' => Messages::INVALID_EMAIL, 'params' => []],
            self::KIND_PHONE => preg_match($this->pattern, $value) === 1 ? null : ['code' => Messages::INVALID_PHONE, 'params' => []],
            self::KIND_EMAIL_OR_PHONE => self::isEmail($value) || preg_match($this->pattern, $value) === 1
                ? null
                : ['code' => Messages::INVALID_CONTACT, 'params' => []],
            default => null,
        };
    }

    /** The one place core asks PHP whether a string is an email address. */
    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
