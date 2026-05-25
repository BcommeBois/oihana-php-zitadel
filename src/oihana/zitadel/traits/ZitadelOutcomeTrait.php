<?php

namespace oihana\zitadel\traits;

use oihana\enums\http\GuzzleOption;

/**
 * Shared helpers operating on the structured result returned by
 * {@see \oihana\zitadel\ZitadelClient} `request*` methods.
 *
 * Every Zitadel client call returns an array shaped as:
 *
 * ```
 * [
 *   'success' => bool ,
 *   'status'  => int ,        // HTTP status returned by Zitadel
 *   'body'    => ?object ,    // decoded JSON body (when JSON)
 *   'rawBody' => string ,     // raw response body
 *   'error'   => ?string ,    // 'transport_error', 'no_token', 'no_client', 'http_error', null
 * ]
 * ```
 *
 * This trait exposes thin accessors against that shape that several
 * controllers were duplicating locally.
 *
 * @package oihana\zitadel\traits
 * @author  Marc Alcaraz
 */
trait ZitadelOutcomeTrait
{
    /**
     * Returns Zitadel's own error message when present, else null.
     *
     * Used to forward Zitadel's diagnostic message verbatim to the API caller (e.g. password-policy violations on
     *
     * @param array $result The full structured result from a Zitadel client call.
     */
    protected function extractZitadelMessage( array $result ) :?string
    {
        $body = $result[ GuzzleOption::BODY ] ?? null ;

        if( is_object( $body ) && isset( $body->message ) && is_string( $body->message ) && $body->message !== '' )
        {
            return $body->message ;
        }

        return null ;
    }
}
