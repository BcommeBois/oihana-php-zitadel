<?php

namespace oihana\zitadel\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Enumeration of field names for the structured outcome produced by
 * Zitadel-aware controllers when mapping a {@see ZitadelOutput} result
 * to their own HTTP response contract.
 *
 * Where {@see ZitadelOutput} describes *what Zitadel returned* (transport,
 * status, body, error tag), `ZitadelOutcome` describes *what the API will
 * answer to the client* after that result has been interpreted: the HTTP
 * status code to emit, the user-facing message, and a stable outcome
 * label used for audit logging and metrics.
 *
 * The two shapes are layered:
 *
 * ```
 *     ZitadelClient  ──► ZitadelOutput  ──► (controller mapping) ──► ZitadelOutcome ──► HTTP response
 * ```
 *
 * Outcome shape:
 *
 * ```
 * [
 *     ZitadelOutcome::CODE    => int,         // HTTP status code to emit (e.g. 204, 401, 502)
 *     ZitadelOutcome::MESSAGE => string|null, // user-facing reason, or null on success
 *     ZitadelOutcome::OUTCOME => string,      // stable audit / metrics label (e.g. WRONG_CURRENT_PASSWORD)
 * ]
 * ```
 *
 * Field semantics:
 *
 * | Field    | Purpose                                | On success                 | On failure                |
 * | -------- | -------------------------------------- | -------------------------- | ------------------------- |
 * | CODE     | HTTP status to send to the client      | 2xx                        | 4xx / 5xx                 |
 * | MESSAGE  | Human-readable explanation             | `null`                     | localized / safe sentence |
 * | OUTCOME  | Machine-readable, stable, audit label  | controller-specific SUCCESS| controller-specific FAIL  |
 *
 * The `OUTCOME` value is intentionally a *string* rather than an enum-typed
 * value: each consuming controller defines its own outcome catalogue
 * (e.g. `MePasswordChangeOutcome`, `InvitationAcceptPasswordOutcome`,
 * `PasswordResetConfirmOutcome` declared on the application side) so
 * audit logs and metrics carry the precise business meaning of each
 * failure mode without leaking Zitadel internals.
 *
 * Usage example (in a controller's `mapZitadelOutcome()`):
 *
 * ```php
 * if( $error === ZitadelError::TRANSPORT_ERROR )
 * {
 *     return ZitadelOutcome::create
 *     (
 *         HttpStatusCode::BAD_GATEWAY ,
 *         MePasswordChangeOutcome::ZITADEL_UNREACHABLE ,
 *         'Identity provider unreachable.' ,
 *     ) ;
 * }
 * ```
 *
 * @package oihana\zitadel\enums
 *
 * @author  Marc Alcaraz
 */
class ZitadelOutcome
{
    use ConstantsTrait ;

    /**
     * HTTP status code the controller will emit on the response.
     *
     * Always an integer in the 1xx–5xx range. Drives the eventual
     * `$response->withStatus( ... )` call (or the equivalent failure
     * helper such as `FailWithReasonTrait::failWithReason()`).
     */
    public const string CODE = 'code' ;

    /**
     * User-facing message explaining the outcome.
     *
     * `null` on success (the body itself carries the result) or when
     * the controller intentionally falls back to a generic message at
     * the response layer. On failure, holds a short sentence safe to
     * surface to end-users — never raw Zitadel diagnostics.
     */
    public const string MESSAGE = 'message' ;

    /**
     * Stable, machine-readable label classifying the outcome.
     *
     * Drawn from a controller-specific catalogue (e.g.
     * `MePasswordChangeOutcome::WRONG_CURRENT_PASSWORD`,
     * `MePasswordChangeOutcome::ZITADEL_UNREACHABLE`). Persisted in
     * audit logs and emitted as a metric label, so its value must
     * remain stable across releases — changing it is a breaking
     * change for downstream dashboards and SIEM rules.
     */
    public const string OUTCOME = 'outcome' ;

    /**
     * Builds a structured outcome.
     *
     * Convenience factory that returns the canonical outcome shape with
     * named keys, sparing callers from repeating the array literal at
     * every branch of their mapping logic.
     *
     * Conventions:
     * - On success, pass a 2xx `$code`, the controller's `SUCCESS`
     *   outcome label, and `$message = null`.
     * - On failure, pass the appropriate 4xx/5xx `$code`, a stable
     *   outcome label, and a short user-facing message.
     *
     * Example:
     * ```php
     * return ZitadelOutcome::create
     * (
     *     HttpStatusCode::UNAUTHORIZED ,
     *     MePasswordChangeOutcome::WRONG_CURRENT_PASSWORD ,
     *     'Current password is incorrect.' ,
     * ) ;
     * ```
     *
     * @param int         $code    HTTP status code (1xx–5xx).
     * @param string      $outcome Stable outcome label drawn from a
     *                             controller-specific catalogue.
     * @param string|null $message User-facing message, or `null` when
     *                             none applies (typically on success).
     *
     * @return array{ code: int , message: string|null , outcome: string }
     */
    public static function create( int $code , string $outcome , ?string $message = null ) :array
    {
        return
        [
            self::CODE    => $code    ,
            self::MESSAGE => $message ,
            self::OUTCOME => $outcome ,
        ] ;
    }
}