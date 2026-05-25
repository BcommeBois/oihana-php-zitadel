<?php

namespace oihana\zitadel\traits\client;

use oihana\enums\http\HttpMethod;
use oihana\zitadel\enums\ZitadelEndpoint;

/**
 * Client for the Zitadel v2 User API — password management.
 *
 * Provides password-reset and password-change flows needed by the
 * invitation workflow (Phase 5c), the password-reset routes (Phase 5e)
 * and the authenticated `/me/password` endpoint:
 *
 * - {@see requestPasswordResetCode()}      : returns a verification code
 *   for the invitation flow. The code is embedded in the activation URL
 *   and the API sends its own custom email (MJML template).
 * - {@see sendPasswordResetLink()}         : delegates email dispatch to
 *   Zitadel, which sends its standard branded "reset your password"
 *   email directly.
 * - {@see setPasswordWithCurrentPassword()}: changes the password of the
 *   authenticated user by validating their current one — drives
 *   `POST /me/password`.
 * - {@see setPasswordWithVerificationCode()}: applies a new password
 *   using a previously issued verification code — drives
 *   `POST /invitations/accept-password`.
 *
 * @package oihana\zitadel\traits\client
 * @author  Marc Alcaraz
 */
trait ZitadelClientPasswordTrait
{
    use ZitadelClientTrait ;

    /**
     * Requests a password-reset verification code from Zitadel.
     *
     * Zitadel returns a one-time `verificationCode` that the caller is
     * responsible for embedding in a custom activation URL and emailing.
     *
     * Used by the invitation workflow (POST /users/{id}/invite): the code
     * is hashed (SHA-256) before storage and transmitted in clear via the
     * activation URL only once.
     *
     * @param string $userId The Zitadel user identifier.
     *
     * @return string|null The plaintext verification code, or null if the
     *                     request failed or the response was malformed.
     */
    public function requestPasswordResetCode( string $userId ) :?string
    {
        $response = $this->request
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_PASSWORD_RESET , [ 'userId' => $userId ] ) ,
            [ 'returnCode' => (object) [] ]
        ) ;

        return $response->verificationCode ?? null ;
    }

    /**
     * Asks Zitadel to email a password-reset link to the user directly.
     *
     * Relies on Zitadel's own notification templates and branding (configured
     * in the Zitadel console). No verification code is returned to the caller.
     *
     * Used by the password-reset routes (POST /me/password-reset,
     * POST /users/{id}/password-reset, POST /password-reset).
     *
     * @param string      $userId      The Zitadel user identifier.
     * @param string|null $urlTemplate Optional override of the reset URL template.
     *                                 When null, Zitadel falls back to its
     *                                 instance-wide configuration.
     *
     * @return bool True if Zitadel acknowledged the request, false otherwise.
     */
    public function sendPasswordResetLink( string $userId , ?string $urlTemplate = null ) :bool
    {
        $sendLink = (object) ( $urlTemplate !== null ? [ 'urlTemplate' => $urlTemplate ] : [] ) ;

        $response = $this->request
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_PASSWORD_RESET , [ 'userId' => $userId ] ) ,
            [ 'sendLink' => $sendLink ]
        ) ;

        return $response !== null ;
    }

    /**
     * Changes a user's password by validating the current one (self-service).
     *
     * Used by the authenticated `POST /me/password` endpoint: the user
     * supplies their current password as proof of knowledge, then picks a
     * new one. Zitadel verifies the current password against the stored
     * hash and either accepts the change or returns an error mapped via
     * {@see \oihana\zitadel\enums\ZitadelErrorId::WRONG_CURRENT_PASSWORD_IDS}.
     *
     * Uses {@see requestRaw()} so the caller can map specific Zitadel
     * failure modes to its own HTTP contract (e.g. wrong current password →
     * 401, password policy violation → 400 with Zitadel message, Bearer KO
     * or transport failure → 502 Bad Gateway).
     *
     * @param string $userId          The Zitadel user identifier.
     * @param string $currentPassword The plaintext current password.
     * @param string $newPassword     The plaintext new password (subject to
     *                                Zitadel's password complexity policy).
     *
     * @return array Structured result from {@see requestRaw()}:
     *               `[ 'success' => bool , 'status' => int , 'body' => mixed ,
     *                  'rawBody' => string , 'error' => ?string ]`.
     */
    public function setPasswordWithCurrentPassword
    (
        string $userId ,
        string $currentPassword ,
        string $newPassword
    )
    :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_PASSWORD , [ 'userId' => $userId ] ) ,
            [
                'newPassword'     => [ 'password' => $newPassword ] ,
                'currentPassword' => $currentPassword ,
            ]
        ) ;
    }

    /**
     * Sets a user's password using a previously issued verification code.
     *
     * This is the second leg of the invitation activation flow: the API
     * obtains a verification code via {@see requestPasswordResetCode()},
     * embeds it in the activation URL, and the NextJS UI calls back this
     * method (through the public POST /invitations/accept-password
     * endpoint) so the user can pick their first password.
     *
     * Uses {@see requestRaw()} so the caller can map specific Zitadel
     * failure modes to its own HTTP contract (e.g. invalid/expired code →
     * 410 Gone, password policy violation → 400 with Zitadel message,
     * Bearer KO or transport failure → 502 Bad Gateway).
     *
     * @param string $userId           The Zitadel user identifier.
     * @param string $verificationCode The plaintext code returned by
     *                                 {@see requestPasswordResetCode()}.
     * @param string $password         The new password (subject to Zitadel's
     *                                 password complexity policy).
     *
     * @return array Structured result from {@see requestRaw()}:
     *               `[ 'success' => bool , 'status' => int , 'body' => mixed ,
     *                  'rawBody' => string , 'error' => ?string ]`.
     */
    public function setPasswordWithVerificationCode
    (
        string $userId ,
        string $verificationCode ,
        string $password
    )
    :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_PASSWORD , [ 'userId' => $userId ] ) ,
            [
                'newPassword'      => [ 'password' => $password ] ,
                'verificationCode' => $verificationCode ,
            ]
        ) ;
    }
}
