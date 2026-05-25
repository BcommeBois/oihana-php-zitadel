<?php

namespace oihana\zitadel\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Catalogue of substrings used as **keyword fallback** to discriminate
 * Zitadel error responses when the stable error-id catalogue
 * ({@see ZitadelErrorId}) cannot match.
 *
 * Zitadel ships error messages of the form `"<human-readable> (KIND-XXXXXX)"`
 * — the `(KIND-XXXXXX)` tag is stable and not localized, the human-readable
 * part *is* localized. The first-line discrimination is always done on
 * {@see ZitadelErrorId} (cheap, stable, language-independent). When that
 * fails (instance with the tag stripped, future Zitadel release with a
 * new uncatalogued id, etc.), the keyword fallback below is consulted on
 * the lowercased response body as a defense-in-depth safety net.
 *
 * Each scalar constant carries a single needle (lowercased). Grouped
 * arrays ({@see VERIFICATION_CODE_FAILURE}, {@see WRONG_CURRENT_PASSWORD})
 * collect the needles that drive a given outcome and are meant to be
 * passed to {@see bodyMatchesAny()}.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelMessageKeyword
{
    use ConstantsTrait ;

    /**
     * Compact form (no `is`): `"code expired"`.
     *
     * Variant produced by some Zitadel error payloads where the message
     * is rephrased without the copula.
     */
    public const string CODE_EXPIRED = 'code expired' ;

    /**
     * Compact form (no `is`): `"code invalid"`.
     *
     * Variant produced by some Zitadel error payloads where the message
     * is rephrased without the copula.
     */
    public const string CODE_INVALID = 'code invalid' ;

    /**
     * Canonical Zitadel wording: `"code is expired"`.
     *
     * Emitted when a verification code has passed its TTL.
     */
    public const string CODE_IS_EXPIRED = 'code is expired' ;

    /**
     * Canonical Zitadel wording: `"code is invalid"`.
     *
     * Emitted when a verification code is malformed or does not match
     * the stored hash.
     */
    public const string CODE_IS_INVALID = 'code is invalid' ;

    /**
     * Canonical Zitadel wording: `"code is used"`.
     *
     * Emitted when a one-shot verification code has already been
     * consumed (replay attempt).
     */
    public const string CODE_IS_USED = 'code is used' ;

    /**
     * Variant: `"code not found"`.
     *
     * Emitted when no verification code is associated with the
     * subject (typically after a successful consumption or a
     * server-side cleanup).
     */
    public const string CODE_NOT_FOUND = 'code not found' ;

    /**
     * Compact form: `"password invalid"`.
     *
     * Variant produced by some Zitadel error payloads where the message
     * is rephrased without the copula.
     */
    public const string PASSWORD_INVALID = 'password invalid' ;

    /**
     * Canonical Zitadel wording: `"password is invalid"`.
     *
     * Emitted when the supplied `currentPassword` does not match the
     * stored hash on a password-change operation.
     */
    public const string PASSWORD_IS_INVALID = 'password is invalid' ;

    /**
     * Variant: `"password mismatch"`.
     *
     * Alternate wording produced by some Zitadel releases for the same
     * underlying condition as {@see PASSWORD_IS_INVALID}.
     */
    public const string PASSWORD_MISMATCH = 'password mismatch' ;

    /**
     * Variant: `"password not changed"`.
     *
     * Emitted when the new password is identical to the current one
     * (rejected as a no-op change).
     */
    public const string PASSWORD_NOT_CHANGED = 'password not changed' ;

    /**
     * Compact variant: `"password unchanged"`.
     *
     * Alternate wording for the same condition as
     * {@see PASSWORD_NOT_CHANGED}.
     */
    public const string PASSWORD_UNCHANGED = 'password unchanged' ;

    /**
     * Generic prefix: `"verification code"`.
     *
     * Coarse catch-all for any sentence whose subject is the verification
     * code itself but whose exact predicate is not catalogued above.
     */
    public const string VERIFICATION_CODE = 'verification code' ;

    /**
     * Compact form (no space): `"verificationcode"`.
     *
     * Observed in some Zitadel logs and stack traces where the noun is
     * rendered as a single token.
     */
    public const string VERIFICATION_CODE_COMPACT = 'verificationcode' ;

    /**
     * Needles that signal a verification-code rejection (expired,
     * malformed, consumed, mismatch). Drives the **410 Gone** outcome of
     * the invitation-accept and password-reset controllers on the
     * consuming application side, when
     * {@see ZitadelErrorId::VERIFICATION_CODE_FAILURE_IDS} did not catch
     * it first.
     *
     * @var array<int,string>
     */
    public const array VERIFICATION_CODE_FAILURE =
    [
        self::CODE_EXPIRED              ,
        self::CODE_INVALID              ,
        self::CODE_IS_EXPIRED           ,
        self::CODE_IS_INVALID           ,
        self::CODE_IS_USED              ,
        self::CODE_NOT_FOUND            ,
        self::VERIFICATION_CODE         ,
        self::VERIFICATION_CODE_COMPACT ,
    ] ;

    /**
     * Needles that signal a wrong / unchanged `currentPassword` on the
     * password-change controller. Drives the **401 Unauthorized** outcome
     * (`wrong_current_password`) when
     * {@see ZitadelErrorId::WRONG_CURRENT_PASSWORD_IDS} did not catch it
     * first.
     *
     * @var array<int,string>
     */
    public const array WRONG_CURRENT_PASSWORD =
    [
        self::PASSWORD_INVALID     ,
        self::PASSWORD_IS_INVALID  ,
        self::PASSWORD_MISMATCH    ,
        self::PASSWORD_NOT_CHANGED ,
        self::PASSWORD_UNCHANGED   ,
    ] ;

    /**
     * Returns true when `$lowercaseBody` contains at least one needle
     * from `$keywords`. Caller is responsible for lowercasing the body
     * once (cheap) before iterating.
     *
     * @param string            $lowercaseBody Lowercased Zitadel response body.
     * @param array<int,string> $keywords      Constant from this class
     *                                          (or any keyword list the
     *                                          caller wants to test).
     */
    public static function bodyMatchesAny( string $lowercaseBody , array $keywords ) :bool
    {
        return array_any( $keywords , fn( string $needle ) => str_contains( $lowercaseBody , $needle ) ) ;
    }
}