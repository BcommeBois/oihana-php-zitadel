<?php

namespace oihana\zitadel\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Enumeration of error tags produced by the Zitadel client.
 *
 * These short, machine-readable tags are returned in the `error` field of
 * the structured response built by `ZitadelClientTrait::requestRaw()` and
 * related helpers. They classify the *kind* of failure so callers can map
 * each case to their own error contract (e.g. HTTP status, exception type,
 * retry policy) without having to parse log messages or response bodies.
 *
 * Tags are intentionally coarse-grained: they describe the failure mode
 * (transport vs. HTTP vs. authentication vs. decoding), not the specific
 * Zitadel error code. For fine-grained diagnostics, consumers should also
 * inspect the response body (`body` / `rawBody`) and HTTP status carried
 * by the structured result.
 *
 * Typical mapping to HTTP responses:
 *
 * | Constant            | Meaning                                       | Suggested status |
 * | ------------------- | --------------------------------------------- | ---------------- |
 * | {@see NONE}             | No error — operation succeeded                | 2xx              |
 * | {@see NO_TOKEN}         | Could not obtain a management access token   | 401 / 502        |
 * | {@see TOKEN_EXPIRED}    | Access token rejected as expired by Zitadel  | 401              |
 * | {@see HTTP_ERROR}       | Zitadel responded with a 4xx/5xx status      | passthrough      |
 * | {@see TRANSPORT_ERROR}  | Network-level failure (DNS, timeout, refused)| 502 / 504        |
 * | {@see DECODE_ERROR}     | Response body could not be parsed as JSON    | 502              |
 * | {@see INVALID_RESPONSE} | Response missing expected fields/shape       | 502              |
 * | {@see UNKNOWN_ERROR}    | Unclassified failure (fallback)              | 500              |
 *
 * Usage example:
 * ```php
 * $result = $this->requestRaw( HttpMethod::POST , $path , $body ) ;
 *
 * if ( $result[ ZitadelOutput::SUCCESS ] )
 * {
 *     return $result[ ZitadelOutput::BODY ] ;
 * }
 *
 * return match( $result[ ZitadelOutput::ERROR ] )
 * {
 *     ZitadelError::NO_TOKEN        => $this->fail( HttpStatusCode::UNAUTHORIZED ) ,
 *     ZitadelError::TRANSPORT_ERROR => $this->fail( HttpStatusCode::BAD_GATEWAY ) ,
 *     ZitadelError::HTTP_ERROR      => $this->forward( $result ) ,
 *     default                       => $this->fail( HttpStatusCode::INTERNAL_SERVER_ERROR ) ,
 * } ;
 * ```
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelError
{
    use ConstantsTrait ;

    /**
     * The response body could not be decoded as JSON.
     *
     * Zitadel responded with a 2xx status but the payload was malformed
     * (truncated, wrong content-type, HTML error page from an upstream
     * proxy, …). Almost always indicates a misbehaving intermediary
     * (reverse proxy, load balancer) rather than Zitadel itself.
     */
    public const string DECODE_ERROR = 'decode_error' ;

    /**
     * The Zitadel server returned an HTTP error status (4xx or 5xx).
     *
     * The transport layer worked correctly and a response was received,
     * but Zitadel rejected the request. The accompanying structured
     * response carries the actual status code, the decoded JSON body
     * (when present — typically `{"code":N,"message":"...","details":[...]}`),
     * and the raw body for diagnostics.
     *
     * Callers should inspect the status to differentiate retryable cases
     * (e.g. 429, 503) from terminal ones (e.g. 400, 403, 404, 409).
     */
    public const string HTTP_ERROR = 'http_error' ;

    /**
     * The response was parseable but did not match the expected shape.
     *
     * Used when a successful response is missing a required field (e.g.
     * a token response without `access_token`, a user lookup without
     * `userId`). Surfacing this as a distinct tag — rather than letting
     * a `null` propagate — helps callers detect API contract drift
     * after a Zitadel upgrade.
     */
    public const string INVALID_RESPONSE = 'invalid_response' ;

    /**
     * Sentinel value indicating the absence of any error.
     *
     * Returned in the `error` field of a successful structured response
     * (alongside `success => true` and a 2xx status). Kept as `null` so
     * callers can write simple truthy checks (`if ( $error ) { ... }`)
     * to detect failures.
     */
    public const null NONE = null ;

    /**
     * No usable Zitadel HTTP client could be obtained.
     *
     * Distinguished from {@see NO_TOKEN} (which covers failures during
     * the *token exchange*): here, the client itself is unavailable —
     * Guzzle could not be instantiated, the lazy factory threw, the
     * injected client was `null`, or a required configuration value
     * (issuer URL, base URI) was missing. The structured response has
     * status `0` because no Zitadel API request was actually issued.
     *
     * Typically indicates a configuration or wiring problem (missing
     * service binding, malformed issuer URL, unreadable key file)
     * rather than a transient runtime error.
     */
    public const string NO_CLIENT = 'no_client' ;

    /**
     * No management access token could be obtained.
     *
     * Returned when the JWT Bearer exchange against the token endpoint
     * failed (rejected assertion, malformed key file, clock skew, network
     * failure during token refresh, …). The structured response has
     * status `0` because no Zitadel API request was actually issued.
     *
     * Typically indicates a configuration problem (invalid service account,
     * wrong issuer, revoked key) rather than a transient runtime error,
     * though clock-skew issues can produce this code intermittently.
     */
    public const string NO_TOKEN = 'no_token' ;

    /**
     * A previously valid access token was rejected as expired or revoked.
     *
     * Distinguished from {@see NO_TOKEN} (which covers failures during
     * token acquisition): here, a token was successfully obtained but
     * Zitadel later refused it — typically yielding a 401 with an
     * `invalid_token` body. Callers can use this signal to force a
     * one-time token refresh and retry the original request.
     */
    public const string TOKEN_EXPIRED = 'token_expired' ;

    /**
     * Network-level failure prevented the request from reaching Zitadel.
     *
     * Covers DNS resolution failures, connection refused/reset, TLS
     * handshake errors, and timeouts. No HTTP response was received,
     * so the structured response carries status `0` and an empty raw
     * body. These failures are generally transient and safe to retry
     * with backoff for idempotent operations.
     */
    public const string TRANSPORT_ERROR = 'transport_error' ;

    /**
     * Unclassified failure — used as a fallback.
     *
     * Should remain rare. If it appears in logs, consider whether a new,
     * more specific tag should be introduced rather than broadening this
     * one.
     */
    public const string UNKNOWN_ERROR = 'unknown_error' ;
}