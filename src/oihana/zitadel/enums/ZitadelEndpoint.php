<?php

namespace oihana\zitadel\enums;

/**
 * Defines the Zitadel Management API v1 endpoints.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelEndpoint
{
    // ------- OAuth2 / OIDC

    public const string AUTHORIZE   = '/oauth/v2/authorize' ;
    public const string TOKEN       = '/oauth/v2/token' ;
    public const string END_SESSION = '/oidc/v1/end_session' ;

    // ------- Users

    public const string USERS_SEARCH       = '/management/v1/users/_search' ;
    public const string USERS_HUMAN_IMPORT = '/management/v1/users/human/_import' ;
    public const string USER_BY_ID         = '/management/v1/users/{userId}' ;
    public const string USER_PROFILE       = '/management/v1/users/{userId}/profile' ;
    public const string USER_GRANTS        = '/management/v1/users/{userId}/grants' ;

    // ------- Users (Zitadel v2 User API)
    //
    // Unlike the v1 `_import` endpoint, the v2 endpoint does NOT put the user
    // in INITIAL state on creation, so a user with `email.isVerified=true`
    // and no password does NOT trigger an automatic init code mail. This is
    // exactly the contract we need to drive activation through our own MJML
    // invitation pipeline.
    public const string USERS_HUMAN_V2 = '/v2/users/human' ;

    // V2 unified UpdateUser endpoint. Single call covers profile / email /
    // phone / password through a discriminated `human` / `machine` body.
    // Used by `updateUserProfile` since the V2 migration (replaces V1
    // `PUT /management/v1/users/{userId}/profile` which only handled
    // first/last names).
    public const string USER_BY_ID_V2 = '/v2/users/{userId}' ;

    // V2 dedicated SetEmail endpoint. Distinct from the unified
    // `USER_BY_ID_V2` (UpdateUser) so the body stays minimal and
    // matches Zitadel's `SetEmailRequest` proto exactly :
    //
    //   { "email": "new@example.com", "returnCode": {} }   ← returnCode discriminator
    //   { "email": "new@example.com", "sendCode": {...} }  ← sendCode discriminator
    //   { "email": "new@example.com", "isVerified": true } ← isVerified discriminator
    //
    // The `verification` field is a proto `oneof` name, NOT a JSON
    // wrapper. Trying to use the unified UpdateUser endpoint with
    // `human.email.{...}` returns 200 OK but silently drops the
    // verification discriminator and never produces a code in the
    // response — debugging clue : Step 7 of the U3 E2E test reports
    // success without a captured code.
    public const string USER_EMAIL_V2 = '/v2/users/{userId}/email' ;

    // V2 dedicated email verification endpoint. The verification code is
    // either returned by a previous SetEmail with `returnCode: {}`
    // (our /me/email flow — we deliver the code through our own MJML
    // mail) or sent by Zitadel itself with `sendCode`. Either way,
    // calling this endpoint with a valid code flips `email.isVerified`
    // to true server-side.
    public const string USER_EMAIL_VERIFY_V2 = '/v2/users/{userId}/email/verify' ;

    // V2 user lock/unlock endpoints. Both invalidate every active refresh
    // token of the user (Zitadel ≥ 2.17.3 / CVE-2023-22492) so they are
    // the canonical Login V1 lever to forcibly cut the refresh chain of
    // another user — used by the bulk session revocation flow paired
    // with an immediate unlock so the user can still re-login normally.
    public const string USER_LOCK_V2   = '/v2/users/{userId}/lock'   ;
    public const string USER_UNLOCK_V2 = '/v2/users/{userId}/unlock' ;

    // ------- Password reset (Zitadel v2 User API)
    //
    // Two distinct endpoints — do not confuse:
    //   - USER_PASSWORD_RESET issues a verification code (or sends a reset
    //     link) so the user can later set a new password. No password is
    //     accepted on this endpoint.
    //   - USER_PASSWORD applies a new password using a previously issued
    //     verification code (invitation activation flow), or via the user's
    //     current password. This is the final set-password call.

    public const string USER_PASSWORD_RESET = '/v2/users/{userId}/password_reset' ;
    public const string USER_PASSWORD       = '/v2/users/{userId}/password' ;

    // ------- Sessions

    public const string MY_SESSIONS     = '/auth/v1/users/me/sessions/_search' ;
    public const string SESSIONS_SEARCH = '/v2/sessions/search' ;
    public const string SESSION_BY_ID   = '/v2/sessions/{sessionId}' ;
    public const string SESSION_DELETE  = '/v2/sessions/{sessionId}' ;

    // ------- Roles

    public const string ROLES_SEARCH = '/management/v1/projects/{projectId}/roles/_search' ;
    public const string ROLES        = '/management/v1/projects/{projectId}/roles' ;
    public const string ROLE_BY_KEY  = '/management/v1/projects/{projectId}/roles/{roleKey}' ;

    // ------- Projects

    public const string PROJECT_BY_ID = '/management/v1/projects/{projectId}' ;

    // ------- Organizations

    /**
     * Resolves the organization the calling service account belongs to.
     * Used to suffix application clientIds with the canonical
     * `<numericId>@<orgName>` form Zitadel expects on the OAuth token
     * endpoint.
     */
    public const string MY_ORG = '/management/v1/orgs/me' ;

    // ------- Applications

    public const string APPS_API              = '/management/v1/projects/{projectId}/apps/api' ;
    public const string APPS_SEARCH           = '/management/v1/projects/{projectId}/apps/_search' ;
    public const string APP_BY_ID             = '/management/v1/projects/{projectId}/apps/{appId}' ;
    public const string APP_REGENERATE_SECRET = '/management/v1/projects/{projectId}/apps/{appId}/api_config/_generate_client_secret' ;
    public const string APP_DEACTIVATE        = '/management/v1/projects/{projectId}/apps/{appId}/_deactivate' ;

    // ------- Application keys (PRIVATE_KEY_JWT)
    //
    // These endpoints back the M2M JWT bearer assertion flow : creating a
    // key generates an RSA key pair on Zitadel side and returns the
    // private key (the JSON keyfile) ONCE. The client uses it locally to
    // sign short-lived JWT assertions exchanged at /oauth/v2/token for
    // access tokens.
    //
    // Multiple keys can co-exist on the same app (zero-downtime rotation
    // pattern). Listing / deleting per keyId allows operators to rotate
    // without an outage.

    public const string APP_KEYS       = '/management/v1/projects/{projectId}/apps/{appId}/keys' ;
    public const string APP_KEY_BY_ID  = '/management/v1/projects/{projectId}/apps/{appId}/keys/{keyId}' ;

    // ------- Service accounts (Zitadel v2 Machine User API)
    //
    // The modern OAuth client pattern for machine-to-machine auth: a
    // Zitadel Machine User (org-level user with `MACHINE` login type)
    // owns one or more Application Keys. The token endpoint accepts
    // a JWT bearer assertion signed by an active key, exchanges it
    // for an access token whose `sub` is the Machine User's `userId`.
    //
    // Distinct from `APPS_API` (= API App = Zitadel-side audience
    // validator, NOT an OAuth client). API Apps cannot mint tokens
    // via the token endpoint — service accounts can.
    //
    // The user must be granted on the project via `USER_GRANTS`
    // (with empty `roleKeys` for an audience-only grant) so the
    // resulting access token carries the project id in `aud`.

    /**
     * V2 endpoint to create a user — generic discriminator that
     * accepts either a `human` or a `machine` body. For Service
     * Accounts the body must carry the `machine` block :
     *
     * ```
     * {
     *   "username": "<login>",
     *   "machine": {
     *     "name": "Display Name",
     *     "description": "...",
     *     "accessTokenType": "ACCESS_TOKEN_TYPE_BEARER"
     *   }
     * }
     * ```
     *
     * Returns `{ userId, details }`. Replaces the deprecated V1
     * `POST /management/v1/users/machine`.
     *
     * @see https://zitadel.com/docs/reference/api/user/zitadel.user.v2.UserService.CreateUser
     */
    public const string USERS_NEW_V2 = '/v2/users/new' ;

    /**
     * V1 endpoint to manage Application Keys on a Zitadel user
     * (Machine User in our case). Multiple keys can co-exist on
     * the same user for zero-downtime rotation. Listing / creating
     * a key — `POST { type: KEY_TYPE_JSON, expirationDate }` returns
     * the freshly minted keyfile (RSA private key) ONCE.
     */
    public const string USER_KEYS = '/management/v1/users/{userId}/keys' ;

    /**
     * V1 endpoint to read or delete a single Application Key by id.
     */
    public const string USER_KEY_BY_ID = '/management/v1/users/{userId}/keys/{keyId}' ;
}
