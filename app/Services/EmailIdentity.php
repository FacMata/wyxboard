<?php

namespace App\Services;

use App\Models\User;

class EmailIdentity
{
    public static function normalize(string $email): string
    {
        $email = trim($email);
        $separator = strrpos($email, '@');
        $domain = idn_to_ascii(substr($email, $separator + 1),
            IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);
        if ($domain === false) abort(422, __('Email format is incorrect'));

        // Preserve existing ASCII case-insensitive identities, never fold accents.
        return strtolower(substr($email, 0, $separator)) . '@' . strtolower($domain);
    }

    public static function matches(string $input, string $stored): bool
    {
        return self::normalize($input) === self::normalize($stored);
    }

    public static function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([
            (int) $user->id, $user->email, $user->password, $user->password_algo, $user->password_salt,
        ]));
    }
}
