<?php

namespace oihana\zitadel\traits\client;

use oihana\enums\http\HttpMethod;
use oihana\zitadel\enums\ZitadelEndpointPlaceholder;
use oihana\zitadel\enums\ZitadelQueryMethod;
use oihana\zitadel\schema\constants\Zitadel;

use oihana\zitadel\enums\ZitadelEndpoint;

/**
 * Client for the Zitadel Management API (v1).
 *
 * Uses a service account (JWT Bearer grant) to authenticate and manage
 * users, roles, and projects in Zitadel.
 *
 * @package oihana\zitadel
 * @author  Marc Alcaraz
 */
trait ZitadelClientUserTrait
{
    use ZitadelClientTrait ;

    /**
     * Creates a human user in Zitadel through the v2 User API.
     *
     * Migrated from `/management/v1/users/human/_import` (which puts a no-
     * password user in INITIAL state and triggers a parasite Zitadel init
     * mail) to `/v2/users/human` (no INITIAL state, no auto-mail). With
     * the v2 endpoint the user is created credential-less and Zitadel
     * stays silent — the activation flow is fully driven by our own
     * invitation pipeline (MJML email + password-reset code), which is
     * the design intent.
     *
     * The v2 payload differs from v1 in several places:
     *
     * - `username`            (lowercase 'n') instead of `userName`
     * - `profile.givenName`   instead of `profile.firstName`  (Schema.org names)
     * - `profile.familyName`  instead of `profile.lastName`
     * - `email.isVerified`    instead of `email.isEmailVerified`
     * - `email.email`         keeps the same inner key as v1 (the
     *                         "address" name from some Zitadel doc pages
     *                         is misleading — the proto field is `email`)
     * - `password`            field is now nested as `{password: "..."}`
     *                         when set, but we omit it entirely
     *
     * @param string $email           The user email (used as username too).
     * @param string $firstName       The user given name.
     * @param string $lastName        The user family name.
     * @param bool   $isEmailVerified When true, the user is marked as
     *                                already-verified and Zitadel does NOT
     *                                send a verification email. Defaults to
     *                                false : Zitadel sends a verify mail.
     *                                For our admin-driven creation flow we
     *                                always pass true and let our invitation
     *                                pipeline take over.
     *
     * @return object|null The created user object — `userId` at the root
     *                     of the response (v2 keeps the same field name as v1).
     */
    public function createUser
    (
        string $email ,
        string $firstName ,
        string $lastName ,
        bool   $isEmailVerified = false
    )
    :?object
    {
        $body =
        [
            Zitadel::USERNAME => strtolower( $email ) ,
            Zitadel::PROFILE  =>
            [
                Zitadel::GIVEN_NAME   => $firstName ,
                Zitadel::FAMILY_NAME  => $lastName ,
                Zitadel::DISPLAY_NAME => "$firstName $lastName" ,
            ] ,
            Zitadel::EMAIL =>
            [
                Zitadel::EMAIL       => strtolower( $email ) ,
                Zitadel::IS_VERIFIED => $isEmailVerified ,
            ]
        ] ;

        return $this->request( HttpMethod::POST , ZitadelEndpoint::USERS_HUMAN_V2 , $body ) ;
    }

    /**
     * Deletes a user from Zitadel.
     */
    public function deleteUser( string $userId ) :?object
    {
        return $this->request
        (
            HttpMethod::DELETE ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_BY_ID , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] )
        ) ;
    }

    /**
     * Searches for a user by email address.
     *
     * @return object|null The user object or null if not found.
     */
    public function findUserByEmail( string $email ) :?object
    {
        $response = $this->request( HttpMethod::POST , ZitadelEndpoint::USERS_SEARCH ,
        [
            Zitadel::QUERY   => [ Zitadel::OFFSET => '0' , Zitadel::LIMIT => 1 ] ,
            Zitadel::QUERIES =>
            [
                [
                    Zitadel::EMAIL_QUERY =>
                        [
                            Zitadel::EMAIL_ADDRESS => strtolower( $email ) ,
                            Zitadel::METHOD        => ZitadelQueryMethod::EQUALS
                        ]
                ]
            ]
        ]) ;

        $results = $response->result ?? [] ;

        return !empty( $results ) ? $results[0] : null ;
    }

    /**
     * Gets a user by their Zitadel ID.
     *
     * @return object|null The user object or null if not found.
     */
    public function getUser( string $userId ) :?object
    {
        return $this->request
        (
            HttpMethod::GET ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_BY_ID , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] )
        ) ;
    }

    /**
     * Lists users registered in the Zitadel project, paginated.
     *
     * Thin wrapper around the `users/_search` Management API : sends
     * a paginated query without any filter so every user (human +
     * machine + deactivated) comes back. The caller paginates by
     * incrementing `$offset` until the returned chunk is shorter
     * than `$limit`.
     *
     * @param int $limit  Maximum users per page (Zitadel caps at 1000).
     * @param int $offset Skip the first N users.
     *
     * @return array<int, object> The user objects in this page.
     */
    public function listUsers( int $limit = 100 , int $offset = 0 ) :array
    {
        $response = $this->request
        (
            HttpMethod::POST ,
            ZitadelEndpoint::USERS_SEARCH ,
            [
                Zitadel::QUERY =>
                    [
                        Zitadel::OFFSET => (string) max( 0 , $offset ) ,
                        Zitadel::LIMIT  => max( 1 , $limit ) ,
                    ] ,
            ]
        ) ;

        $results = $response->result ?? [] ;

        return is_array( $results ) ? $results : [] ;
    }

    /**
     * Locks a Zitadel user account, invalidating every active refresh
     * token the user holds.
     *
     * Used by the bulk session revocation flow paired with an immediate
     * {@see unlockUser()} call : the lock+unlock dance is the only
     * Login V1 lever to invalidate another user's refresh tokens
     * without owning them in the clear (see CVE-2023-22492 fix in
     * Zitadel ≥ 2.17.3). The user can re-login normally after the
     * unlock — the goal of the pair is refresh-token invalidation,
     * not account suspension.
     *
     * Uses {@see requestRaw()} so the caller can distinguish a
     * successful lock from a `409` "already locked" idempotent case
     * and from `5xx` transient failures that must be retried.
     *
     * @param string $userId The Zitadel user identifier.
     *
     * @return array Structured result from {@see requestRaw()}:
     *               `[ 'success' => bool , 'status' => int , 'body' => mixed ,
     *                  'rawBody' => string , 'error' => ?string ]`.
     */
    public function lockUser( string $userId ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_LOCK_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] )
        ) ;
    }

    /**
     * Updates the human user's email address through the V2 UpdateUser
     * endpoint and asks Zitadel to **return** the verification code rather
     * than send its own templated mail. The caller is responsible for
     * delivering the code to the user (typically through a custom MJML
     * mail) and for later confirming the change with {@see verifyEmail()}.
     *
     * Pattern aligned with the invitation flow which uses the same
     * `returnCode` discriminator on `password_reset` — same trade-off :
     * we trade Zitadel's built-in templated mail for full control over the
     * branding, the link target, and the deliverability of the message.
     *
     * The new address is normalised to lowercase to match the
     * `username = strtolower(email)` convention enforced by
     * {@see createUser()}. The username itself is NOT updated by this
     * call — it is aligned later by the controller, after the
     * verification code has been confirmed, via {@see setUsername()}.
     * This ordering avoids a window where the username login key would
     * point at an email Zitadel has not yet accepted.
     *
     * Uses {@see requestRaw()} so the caller can map specific Zitadel
     * outcomes to its own HTTP contract — same pattern as
     * {@see updateUserProfile()}.
     *
     * @param string $userId   The Zitadel user identifier.
     * @param string $email    The new email address.
     * @param bool   $verified When `false` (default) Zitadel generates a
     *                         verification code returned in the response
     *                         body — used by the standard self-service
     *                         flow where the user must confirm via a
     *                         link. When `true` the email is marked
     *                         verified directly with no code generated —
     *                         used by admin-trusted scenarios such as
     *                         the inactive-user direct change branch.
     *
     * @return array Structured result from {@see requestRaw()}. On
     *               success with `$verified = false`, the parsed body
     *               exposes the verification code at the root
     *               (e.g. `{ verificationCode: "..." }`) that the
     *               controller must capture, hash and persist. With
     *               `$verified = true` the body carries no code.
     */
    public function setEmail( string $userId , string $email , bool $verified = false ) :array
    {
        // Hits the dedicated `POST /v2/users/{userId}/email` (SetEmail)
        // endpoint, NOT the unified UpdateUser (`PATCH
        // /v2/users/{userId}`). Reasons:
        //
        // 1. The body is shaped exactly like `SetEmailRequest`
        //    (flat `email` + `returnCode`/`sendCode`/`isVerified`
        //    discriminator) without any `human.email.{...}` nesting.
        //    The proto `oneof verification` is just a discriminator
        //    name — it is NOT a JSON key.
        //
        // 2. UpdateUser silently drops the verification field on
        //    `human.email.{...}` and falls back to `isVerified=false`
        //    without ever returning a code. The call answers 200 OK
        //    but the response body carries no `verificationCode` —
        //    a hard-to-debug failure caught during the U3 E2E test.
        $payload = [ Zitadel::EMAIL => strtolower( $email ) ] ;

        if( $verified )
        {
            // Admin-trusted scenario : the email is marked verified
            // immediately, no code is generated. Used when the target
            // user has not activated yet — there is nothing to
            // protect against (no session, no password, no validated
            // identity), so the round-trip through a verification
            // code would be ceremony without value.
            $payload[ Zitadel::IS_VERIFIED ] = true ;
        }
        else
        {
            $payload[ Zitadel::RETURN_CODE ] = (object) [] ;
        }

        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_EMAIL_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] ) ,
            $payload
        ) ;
    }

    /**
     * Updates the human user's `username` (login key) through the V2
     * UpdateUser endpoint. The `username` field is top-level on V2 (not
     * nested under `human`) — same shape as on {@see createUser()}.
     *
     * The body also carries an **empty `human` object** : the proto
     * `UpdateUserRequest` exposes a `oneof type` (`human` | `machine`)
     * discriminator that Zitadel resolves from the request body, and a
     * payload with only the top-level `username` leaves the oneof unset —
     * the call is then rejected with HTTP 501 `user type is not
     * implemented` and the login key is never updated. The empty object
     * selects the human branch without touching any profile / email /
     * phone field. This makes the method human-only by construction ;
     * renaming a machine user is out of scope.
     *
     * Called by the email-change controller right after a verification
     * code has been confirmed, to align the login key with the new
     * email — the project convention is `username = strtolower(email)`.
     * Updating the username before the email has been verified would
     * leave the user unable to log in with either address.
     *
     * @param string $userId   The Zitadel user identifier.
     * @param string $username The new login key.
     *
     * @return array Structured result from {@see requestRaw()}.
     */
    public function setUsername( string $userId , string $username ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::PATCH ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_BY_ID_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] ) ,
            [
                Zitadel::USERNAME => strtolower( $username ) ,
                Zitadel::HUMAN    => (object) [] ,
            ]
        ) ;
    }

    /**
     * Unlocks a Zitadel user account previously locked via {@see lockUser()}.
     *
     * Called in the same admin gesture as `lockUser()` to keep the user
     * able to re-login through the standard authorization flow. The
     * refresh tokens that were invalidated by the lock stay invalidated
     * after the unlock — Zitadel does not resurrect them.
     *
     * Uses {@see requestRaw()} so the caller can retry on transient
     * `5xx` / transport failures without misinterpreting the body shape.
     *
     * @param string $userId The Zitadel user identifier.
     *
     * @return array Structured result from {@see requestRaw()}.
     */
    public function unlockUser( string $userId ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_UNLOCK_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] )
        ) ;
    }

    /**
     * Updates a human user profile (givenName / familyName / displayName)
     * through the V2 UpdateUser endpoint.
     *
     * Migrated 2026-04-30 from V1
     * `PUT /management/v1/users/{userId}/profile` (which used the legacy
     * `firstName` / `lastName` field names) to V2
     * `POST /v2/users/{userId}` (which uses the Schema.org-aligned
     * `givenName` / `familyName`). The `displayName` is recomputed from
     * the new given+family pair so the rendered name in mails / console
     * stays in sync.
     *
     * Uses {@see requestRaw()} so the caller can map specific Zitadel
     * outcomes (4xx validation, 5xx, transport, no_token) to its own HTTP
     * contract — same pattern as `setPasswordWithVerificationCode`. The
     * method therefore returns the structured raw result instead of an
     * object ; callers must inspect `success` / `status` / `body` /
     * `error` to decide whether to commit the local mirror.
     *
     * @param string $userId     The Zitadel user identifier.
     * @param string $givenName  New given name (Schema.org `givenName`).
     * @param string $familyName New family name (Schema.org `familyName`).
     *
     * @return array Structured result from {@see requestRaw()}:
     *               `[ 'success' => bool , 'status' => int , 'body' => mixed ,
     *                  'rawBody' => string , 'error' => ?string ]`.
     */
    public function updateUserProfile
    (
        string $userId ,
        string $givenName ,
        string $familyName
    )
    :array
    {
        return $this->requestRaw
        (
            HttpMethod::PATCH ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_BY_ID_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] ) ,
            [
                Zitadel::HUMAN =>
                    [
                        Zitadel::PROFILE =>
                            [
                                Zitadel::GIVEN_NAME   => $givenName ,
                                Zitadel::FAMILY_NAME  => $familyName ,
                                Zitadel::DISPLAY_NAME => "$givenName $familyName" ,
                            ] ,
                    ] ,
            ]
        ) ;
    }

    /**
     * Confirms a pending email change by submitting the verification
     * code returned by a previous {@see setEmail()} call. On success,
     * Zitadel flips `email.isVerified` to true server-side ; the caller
     * is then responsible for swapping the local mirror
     * (`email ← pendingEmail`) and aligning the username via
     * {@see setUsername()}.
     *
     * The endpoint is distinct from the unified UpdateUser so that the
     * verification call cannot accidentally mutate other user fields —
     * a misformed body cannot, for instance, wipe the profile.
     *
     * Uses {@see requestRaw()} so the caller can discriminate
     * "code expired" / "code invalid" / transient Zitadel errors and
     * map them to dedicated HTTP responses (typically `400` +
     * `invalid_or_expired_code` for the public confirm route).
     *
     * @param string $userId The Zitadel user identifier.
     * @param string $code   The verification code provided by the user.
     *
     * @return array Structured result from {@see requestRaw()}.
     */
    public function verifyEmail( string $userId , string $code ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_EMAIL_VERIFY_V2 , [ ZitadelEndpointPlaceholder::USER_ID => $userId ] ) ,
            [
                Zitadel::VERIFICATION_CODE => $code ,
            ]
        ) ;
    }
}
