<?php

declare(strict_types=1);

namespace Whity\Http\Validation;

/**
 * The general words for each field problem, in English and Arabic (#2174).
 *
 * Both languages travel in the response. A request from someone who isn't
 * signed in is always answered in the source language
 * ({@see \Whity\Core\i18n\RequestLanguageResolver}), so a public page in
 * Arabic could not otherwise show the server's field errors in Arabic. A field
 * can replace these with its own words ({@see Field::say()}).
 */
final class Messages
{
    public const REQUIRED = 'required';
    public const TOO_LONG = 'too_long';
    public const TOO_SHORT = 'too_short';
    public const INVALID_EMAIL = 'invalid_email';
    public const INVALID_PHONE = 'invalid_phone';
    public const INVALID_CONTACT = 'invalid_contact';
    /** Checked by the handler itself: the value is the right kind but can't be used. */
    public const INVALID = 'invalid';
    /** Checked by the handler itself: this value is not accepted here. */
    public const NOT_ALLOWED = 'not_allowed';

    /** @var array<string, array{0: string, 1: string}> */
    private const WORDS = [
        self::REQUIRED => ['Please fill this in.', 'يرجى تعبئة هذا الحقل.'],
        self::TOO_LONG => ['This is too long. Keep it to {max} characters or fewer.', 'هذا طويل جدًا. اجعله {max} حرفًا أو أقل.'],
        self::TOO_SHORT => ['This is too short. Use at least {min} characters.', 'هذا قصير جدًا. استخدم {min} أحرف على الأقل.'],
        self::INVALID_EMAIL => ['That doesn\'t look like an email address.', 'لا يبدو هذا عنوان بريد إلكتروني.'],
        self::INVALID_PHONE => ['That doesn\'t look like a phone number.', 'لا يبدو هذا رقم هاتف.'],
        self::INVALID_CONTACT => ['That doesn\'t look like an email address or a phone number.', 'لا يبدو هذا عنوان بريد إلكتروني أو رقم هاتف.'],
        self::INVALID => ['This doesn\'t look right. Please check it.', 'يبدو أن هذا غير صحيح. يرجى التحقق منه.'],
        self::NOT_ALLOWED => ['This isn\'t accepted here.', 'هذا غير مقبول هنا.'],
    ];

    /**
     * A problem the handler found itself, worded like the validator's own, for
     * {@see FieldValidator::refusal()}.
     *
     * @param array{0: string, 1: string}|null $own
     * @return array{code: string, en: string, ar: string}
     */
    public static function problem(string $code, ?array $own = null): array
    {
        [$en, $ar] = self::words($code, [], $own);

        return ['code' => $code, 'en' => $en, 'ar' => $ar];
    }

    /** @return list<string> Every code a field can report. */
    public static function codes(): array
    {
        return array_keys(self::WORDS);
    }

    /**
     * @param array<string, int> $params
     * @param array{0: string, 1: string}|null $own The field's own words, if it has them.
     * @return array{0: string, 1: string} [English, Arabic]
     */
    public static function words(string $code, array $params = [], ?array $own = null): array
    {
        [$en, $ar] = $own ?? self::WORDS[$code] ?? self::WORDS[self::REQUIRED];
        foreach ($params as $name => $value) {
            // Western digits in both languages (the design's rule for numbers).
            $en = str_replace('{' . $name . '}', (string) $value, $en);
            $ar = str_replace('{' . $name . '}', (string) $value, $ar);
        }

        return [$en, $ar];
    }
}
