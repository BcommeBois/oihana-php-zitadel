<?php

namespace oihana\zitadel\traits\client;

use oihana\enums\http\HttpMethod;
use oihana\zitadel\enums\ZitadelEndpoint;
use oihana\zitadel\enums\ZitadelEndpointPlaceholder;
use oihana\zitadel\enums\ZitadelSessionField;
use oihana\zitadel\enums\ZitadelSessionSearchParam;

/**
 * Zitadel Session management methods.
 *
 * Uses the Zitadel Sessions API v2 to list, get, and revoke user sessions.
 *
 * @package oihana\zitadel\traits\client
 * @author  Marc Alcaraz
 *
 * @see https://zitadel.com/docs/apis/resources/session_service_v2
 */
trait ZitadelClientSessionTrait
{
    use ZitadelClientTrait ;

    /**
     * Gets a specific session by ID.
     *
     * @param string $sessionId The session ID.
     *
     * @return object|null The session object or null.
     */
    public function getSession( string $sessionId ) :?object
    {
        return $this->request
        (
            HttpMethod::GET ,
            $this->resolveEndpoint( ZitadelEndpoint::SESSION_BY_ID , [ ZitadelEndpointPlaceholder::SESSION_ID => $sessionId ] )
        ) ;
    }

    /**
     * Fetches a session by ID and returns the raw HTTP envelope.
     *
     * Mirrors {@see revokeSession()} on the read side : uses
     * {@see requestRaw()} so the caller can distinguish a true
     * `200` (the session still exists in Zitadel) from a `404`
     * (the session was revoked, expired, or never existed — the
     * row in the local store is now an orphan) and from transient
     * `5xx` / transport failures that warrant a skip rather than a
     * destructive flip on the local side.
     *
     * Designed for reconcile sweeps where {@see getSession()}'s `null`
     * return value would conflate a real `404` with a transient outage
     * and produce false-positive orphan detections.
     *
     * @param string   $sessionId The Zitadel `sid` to look up.
     * @param int|null $timeout   Optional per-call timeout in seconds; falls back to
     *                            {@see ZitadelClientTrait::DEFAULT_TIMEOUT_SECONDS} when null.
     *
     * @return array Structured result from {@see requestRaw()} :
     *               `[ 'success' => bool , 'status' => int ,
     *                  'body' => mixed , 'rawBody' => string ,
     *                  'error' => ?string ]`.
     */
    public function getSessionRaw( string $sessionId , ?int $timeout = null ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::GET ,
            $this->resolveEndpoint( ZitadelEndpoint::SESSION_BY_ID , [ ZitadelEndpointPlaceholder::SESSION_ID => $sessionId ] ) ,
            null ,
            $timeout ,
        ) ;
    }

    /**
     * Lists sessions for a specific user.
     *
     * @param string $userId The Zitadel user ID.
     *
     * @return array Array of session objects.
     */
    public function listUserSessions( string $userId ) :array
    {
        $response = $this->request
        (
            HttpMethod::POST ,
            ZitadelEndpoint::SESSIONS_SEARCH ,
            [
                ZitadelSessionSearchParam::QUERIES =>
                [
                    [
                        ZitadelSessionSearchParam::USER_ID_QUERY =>
                        [
                            ZitadelSessionSearchParam::USER_ID => $userId ,
                        ] ,
                    ] ,
                ] ,
                ZitadelSessionSearchParam::SORTING_COLUMN => ZitadelSessionField::CREATION_DATE ,
            ]
        ) ;

        return $response->sessions ?? [] ;
    }

    /**
     * Revokes a Zitadel session (`DELETE /v2/sessions/{sessionId}`).
     *
     * Uses {@see requestRaw()} so the caller can distinguish a true
     * success (`200`/`204`) from an idempotent miss (`404`, the
     * session is already gone — already revoked, expired, or never
     * existed) and from transient `5xx` / transport failures that
     * may warrant a retry or a fail-soft fallback.
     *
     * Typical mapping for the propagation caller
     * ({@see SessionsRevokerTrait}) :
     *
     * - `200` / `204`         → success, propagation done.
     * - `404`                 → idempotent success, treat as done.
     * - `401` / `403`         → permission issue on the PAT, log + skip.
     * - `5xx`, transport KO   → transient, log + skip. The
     *                           `isSidRevoked` guardrail catches the
     *                           residual silent refresh attempts.
     *
     * The optional `$timeout` parameter bounds the per-call wait, which
     * matters when the caller iterates revocation across N devices in a
     * bulk flow — a short timeout caps the bulk worst-case latency when
     * Zitadel is unresponsive, the local `isSidRevoked` guardrail catching
     * anything that slips through.
     *
     * @param string   $sessionId The Zitadel `sid` to revoke.
     * @param int|null $timeout   Optional per-call timeout in seconds; falls back to
     *                            {@see ZitadelClientTrait::DEFAULT_TIMEOUT_SECONDS} when null.
     *
     * @return array Structured result from {@see requestRaw()} :
     *               `[ 'success' => bool , 'status' => int ,
     *                  'body' => mixed , 'rawBody' => string ,
     *                  'error' => ?string ]`.
     */
    public function revokeSession( string $sessionId , ?int $timeout = null ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::DELETE ,
            $this->resolveEndpoint( ZitadelEndpoint::SESSION_DELETE , [ ZitadelEndpointPlaceholder::SESSION_ID => $sessionId ] ) ,
            null ,
            $timeout ,
        ) ;
    }
}
