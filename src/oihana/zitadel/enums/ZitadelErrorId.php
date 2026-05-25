<?php

namespace oihana\zitadel\enums;

/**
 * Catalogue of stable Zitadel error identifiers.
 *
 * Zitadel formats every error message as `"<human-readable> (KIND-XXXXXX)"`,
 * for example `"Code is expired (CODE-QvUQ4P)"`. The trailing
 * `KIND-XXXXXX` tag is the **internal identifier** of the underlying
 * Go `zerrors.Throw*` call — it is stable across releases and **not
 * localized** (the human-readable part is, the ID is not).
 *
 * Matching on these IDs is therefore the most reliable way to discriminate
 * Zitadel error kinds without relying on substrings of a translated
 * message. Use this catalogue to drive any heuristic that needs to map
 * a Zitadel failure to an API-side outcome on the consuming application.
 *
 * Audited against the public `zitadel/zitadel` repository @ `main` on
 * 2026-04-28. See the constants' PHPDoc for the source file each ID
 * comes from.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelErrorId
{
    // -------------------------------------------------------------------------
    // Verification code errors
    // -------------------------------------------------------------------------

    /**
     * Code TTL exhausted.
     *
     * Source: `internal/crypto/code.go` —
     * `zerrors.ThrowPreconditionFailed(nil, "CODE-QvUQ4P", "Errors.User.Code.Expired")`.
     */
    public const string CODE_EXPIRED = 'CODE-QvUQ4P' ;

    /**
     * Decrypted code does not match the provided `verificationCode`
     * (typo, tampered URL, code regenerated in the meantime).
     *
     * Source: `internal/crypto/code.go` —
     * `zerrors.ThrowInvalidArgument(nil, "CODE-woT0xc", "Errors.User.Code.Invalid")`.
     */
    public const string CODE_INVALID = 'CODE-woT0xc' ;

    /**
     * No verification code stored for the user — either none was ever
     * issued or the previous one was already consumed.
     *
     * Source: `internal/command/user_human.go` —
     * `zerrors.ThrowPreconditionFailed(nil, "COMMAND-2M9fs", "Errors.User.Code.NotFound")`.
     */
    public const string CODE_NOT_FOUND = 'COMMAND-2M9fs' ;

    // -------------------------------------------------------------------------
    // Caller-side errors (caught by Zitadel before the code is even checked)
    // -------------------------------------------------------------------------

    /**
     * `userId` argument missing on the call.
     *
     * Source: `internal/command/user_human_password.go` —
     * `zerrors.ThrowInvalidArgument(nil, "COMMAND-3M9fs", "Errors.IDMissing")`.
     */
    public const string ID_MISSING = 'COMMAND-3M9fs' ;

    /**
     * `password` field empty on the call.
     *
     * Source: `internal/command/user_human_password.go` —
     * `zerrors.ThrowInvalidArgument(nil, "COMMAND-Mf0sd", "Errors.User.Password.Empty")`.
     */
    public const string PASSWORD_EMPTY = 'COMMAND-Mf0sd' ;

    // -------------------------------------------------------------------------
    // Authenticated password-change errors
    // -------------------------------------------------------------------------

    /**
     * The `currentPassword` supplied with a self-service password change does
     * not match the user's current password (typo, wrong account, brute-force).
     *
     * Source: `internal/command/user_human_password.go` —
     * `zerrors.ThrowInvalidArgument(err, "COMMAND-3M0fs", "Errors.User.Password.Invalid")`
     * (wrapping `passwap.ErrPasswordMismatch`).
     */
    public const string PASSWORD_INVALID = 'COMMAND-3M0fs' ;

    /**
     * The new password supplied is identical to the previous one.
     *
     * Source: `internal/command/user_human_password.go` —
     * `zerrors.ThrowPreconditionFailed(err, "COMMAND-Aesh5", "Errors.User.Password.NotChanged")`.
     */
    public const string PASSWORD_NOT_CHANGED = 'COMMAND-Aesh5' ;

    // -------------------------------------------------------------------------
    // Aggregates
    // -------------------------------------------------------------------------

    /**
     * IDs that semantically mean "the verification code cannot be used"
     * (expired / invalid / no longer available). Endpoints that proxy a
     * Zitadel verification-code call should map these to **410 Gone** so
     * the UI can prompt the user to request a fresh activation link.
     *
     * @var string[]
     */
    public const array VERIFICATION_CODE_FAILURE_IDS =
    [
        self::CODE_EXPIRED ,
        self::CODE_INVALID ,
        self::CODE_NOT_FOUND ,
    ] ;

    /**
     * IDs that semantically mean "the supplied current password is not
     * usable to authorize the change" (mismatch / unchanged). Endpoints
     * that proxy a Zitadel self-service password change should map these
     * to **401 Unauthorized** so the UI can surface a "wrong current
     * password" error under the dedicated form field.
     *
     * @var string[]
     */
    public const array WRONG_CURRENT_PASSWORD_IDS =
    [
        self::PASSWORD_INVALID ,
        self::PASSWORD_NOT_CHANGED ,
    ] ;

    /**
     * Regular expression matching the trailing `(KIND-XXXXXX)` tag in a
     * Zitadel error message. The kind segment is uppercase letters and
     * digits, the suffix is alphanumeric (mixed case observed:
     * `QvUQ4P`, `woT0xc`, `Mf0sd`, …).
     */
    private const string ERROR_ID_PATTERN = '/\(([A-Z][A-Z0-9]*-[A-Za-z0-9]+)\)/' ;

    /**
     * Extracts the first stable Zitadel error identifier embedded in the
     * given raw body. Returns null when no identifier is present (e.g.
     * older Zitadel releases that strip the tag, or non-Zitadel responses).
     *
     * Reusable by any controller that needs to map a Zitadel failure to
     * an API-side outcome — works for both legacy `SetPassword` and
     * future `UpdateUser` proxies, since both endpoints surface the same
     * internal Go errors verbatim.
     *
     * @param string $rawBody The raw Zitadel response body (JSON or text).
     *
     * @return string|null The matched identifier (e.g. "CODE-QvUQ4P"), or null.
     */
    public static function extractErrorId( string $rawBody ) :?string
    {
        if( $rawBody === '' )
        {
            return null ;
        }

        if( preg_match( self::ERROR_ID_PATTERN , $rawBody , $matches ) === 1 )
        {
            return $matches[ 1 ] ;
        }

        return null ;
    }

    /**
     * Returns true when the given raw body carries a Zitadel error
     * identifier present in `$ids`.
     *
     * Convenience wrapper around {@see extractErrorId()} for the common
     * "is this one of N expected failure modes?" check.
     *
     * @param string   $rawBody Raw response body to inspect.
     * @param string[] $ids     List of expected identifiers (e.g.
     *                          {@see VERIFICATION_CODE_FAILURE_IDS}).
     */
    public static function bodyMatchesAny( string $rawBody , array $ids ) :bool
    {
        $extracted = self::extractErrorId( $rawBody ) ;

        return $extracted !== null && in_array( $extracted , $ids , true ) ;
    }
}
