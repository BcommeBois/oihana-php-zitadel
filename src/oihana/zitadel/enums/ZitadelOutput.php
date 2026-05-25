<?php

namespace oihana\zitadel\enums;

use oihana\enums\Char;
use oihana\reflect\traits\ConstantsTrait;

/**
 * Enumeration of field names for the structured response produced by the
 * Zitadel client.
 *
 * `ZitadelClientTrait::requestRaw()` always returns an associative array
 * describing the outcome of a request, regardless of whether it succeeded
 * or failed. These constants name the keys of that array, so consumers
 * can read it without sprinkling magic strings throughout their code.
 *
 * The structured response is intentionally uniform across all outcomes
 * (2xx success, 4xx/5xx HTTP error, transport failure, missing token):
 * every field is always present, with documented sentinel values when
 * not applicable. This lets callers branch on a single shape rather than
 * juggling multiple return types.
 *
 * Response shape:
 *
 * ```
 * [
 *     ZitadelOutput::SUCCESS  => bool,        // true iff a 2xx response was received
 *     ZitadelOutput::STATUS   => int,         // HTTP status; 0 when no response was received
 *     ZitadelOutput::BODY     => mixed|null,  // decoded JSON body, or null when absent/empty
 *     ZitadelOutput::RAW_BODY => string,      // raw response body verbatim, '' when absent
 *     ZitadelOutput::ERROR    => string|null, // null on success, a ZitadelError tag otherwise
 * ]
 * ```
 *
 * Field semantics by outcome:
 *
 * | Outcome             | success | status | body         | rawBody | error                              |
 * | ------------------- | ------- | ------ | ------------ | ------- | ---------------------------------- |
 * | 2xx response        | true    | 2xx    | decoded JSON | raw     | {@see ZitadelError::NONE}            |
 * | 4xx/5xx response    | false   | 4xx/5xx| decoded JSON | raw     | {@see ZitadelError::HTTP_ERROR}      |
 * | Transport failure   | false   | 0      | null         | ''      | {@see ZitadelError::TRANSPORT_ERROR} |
 * | Missing token       | false   | 0      | null         | ''      | {@see ZitadelError::NO_TOKEN}        |
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
 * $this->logger?->warning( sprintf
 * (
 *     'Zitadel call failed: %s (status=%d, body=%s)' ,
 *     $result[ ZitadelOutput::ERROR    ] ,
 *     $result[ ZitadelOutput::STATUS   ] ,
 *     $result[ ZitadelOutput::RAW_BODY ] ,
 * )) ;
 * ```
 *
 * @package oihana\zitadel\enums
 *
 * @author  Marc Alcaraz
 */
class ZitadelOutput
{
    use ConstantsTrait ;

    /**
     * Decoded response body.
     *
     * When the response carried a non-empty JSON payload, this field
     * holds the result of `json_decode()` — typically an `stdClass`
     * (Zitadel's resource representations) or an array (for list
     * endpoints). When the body was empty or the request never
     * received a response, this field is `null`.
     *
     * Note: even on HTTP error outcomes, this field carries Zitadel's
     * own error payload (`{"code":N,"message":"...","details":[...]}`)
     * when present, which is usually the most useful diagnostic.
     */
    public const string BODY = 'body' ;

    /**
     * Short, machine-readable failure tag.
     *
     * `null` on success (see {@see ZitadelError::NONE}). On failure,
     * holds one of the {@see ZitadelError} constants identifying the
     * *kind* of failure (HTTP error, transport error, missing token, …)
     * so callers can map outcomes to their own error contract without
     * inspecting status codes or response bodies.
     */
    public const string ERROR = 'error' ;

    /**
     * Raw response body, verbatim.
     *
     * The unmodified bytes received from Zitadel, as a string. Useful
     * for diagnostics (logging, replay, debugging encoding issues) and
     * for re-parsing the payload with a different decoder when needed.
     * Always an empty string when no response was received (transport
     * failure, missing token).
     */
    public const string RAW_BODY = 'rawBody' ;

    /**
     * HTTP status code returned by Zitadel.
     *
     * Carries the actual status (200, 201, 400, 401, 404, 409, 500, …)
     * when a response was received. Set to `0` as a sentinel value
     * when no response was received at all — i.e. transport failures
     * and missing-token outcomes — to distinguish those from any real
     * HTTP status.
     */
    public const string STATUS = 'status' ;

    /**
     * Boolean flag indicating whether the request succeeded.
     *
     * `true` iff Zitadel returned a 2xx response *and* the client
     * managed to read the body without error. `false` for any other
     * outcome (HTTP error, transport failure, missing token).
     *
     * This is the primary branching field: callers should check it
     * before reading {@see BODY}, since the body's semantics differ
     * between success and failure outcomes.
     */
    public const string SUCCESS = 'success' ;

    /**
     * Builds a structured response for a failed request.
     *
     * Produces the canonical failure shape, with `success => false` and
     * the supplied {@see ZitadelError} tag. Use sentinel values when a
     * field does not apply to the outcome:
     *
     * - `$status = 0` when no HTTP response was received (transport
     *   failure, missing token);
     * - `$body = null` and `$rawBody = ''` when no payload is available.
     *
     * For HTTP errors (4xx/5xx), pass through Zitadel's own response so
     * callers can read its `{"code":N,"message":"...","details":[...]}`
     * payload for diagnostics.
     *
     * Example:
     * ```php
     * // Missing token — no API call was issued
     * return ZitadelOutput::failure( ZitadelError::NO_TOKEN ) ;
     *
     * // HTTP error — preserve Zitadel's response body
     * return ZitadelOutput::failure
     * (
     *     ZitadelError::HTTP_ERROR ,
     *     $status ,
     *     $rawBody !== '' ? json_decode( $rawBody ) : null ,
     *     $rawBody ,
     * ) ;
     * ```
     *
     * @param string     $error   One of the {@see ZitadelError} tags.
     * @param int        $status  HTTP status code; `0` when no response was received.
     * @param mixed|null $body    Decoded response body, when available.
     * @param string     $rawBody Raw response body, verbatim; empty string when none.
     *
     * @return array<string,mixed> Structured response with `success => false`.
     */
    public static function failure( string $error , int $status = 0 , mixed $body = null , string $rawBody = Char::EMPTY ) :array
    {
        return
        [
            self::SUCCESS  => false ,
            self::STATUS   => $status ,
            self::BODY     => $body ,
            self::RAW_BODY => $rawBody ,
            self::ERROR    => $error ,
        ] ;
    }

    /**
     * Builds a structured response for a successful request (2xx).
     *
     * Produces the canonical success shape, with `success => true` and
     * `error => ZitadelError::NONE`. The body is left as `null` when the
     * response carried no payload (e.g. 204 No Content).
     *
     * Example:
     * ```php
     * $rawBody = $response->getBody()->getContents() ;
     *
     * return ZitadelOutput::success
     * (
     *     $response->getStatusCode() ,
     *     $rawBody !== '' ? json_decode( $rawBody ) : null ,
     *     $rawBody ,
     * ) ;
     * ```
     *
     * @param int        $status  HTTP status code (typically 200, 201, 204).
     * @param mixed|null $body    Decoded response body, or `null` if empty.
     * @param string     $rawBody Raw response body, verbatim.
     *
     * @return array<string,mixed> Structured response with `success => true`.
     */
    public static function success( int $status , mixed $body = null , string $rawBody = Char::EMPTY ) :array
    {
        return
        [
            self::SUCCESS  => true ,
            self::STATUS   => $status ,
            self::BODY     => $body ,
            self::RAW_BODY => $rawBody ,
            self::ERROR    => ZitadelError::NONE ,
        ] ;
    }
}