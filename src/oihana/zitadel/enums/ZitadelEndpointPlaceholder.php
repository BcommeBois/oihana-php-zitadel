<?php

namespace oihana\zitadel\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Placeholder names used inside the URL templates declared in
 * {@see ZitadelEndpoint} (e.g. `{userId}` in `/v2/users/{userId}`).
 *
 * The values are passed to
 * {@see \oihana\zitadel\traits\client\ZitadelClientTrait::resolveEndpoint()}
 * as the keys of the substitution array — exposing them as typed
 * constants keeps the call sites free of magic strings.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelEndpointPlaceholder
{
    use ConstantsTrait ;

    /**
     * `{appId}` — Zitadel application identifier.
     */
    public const string APP_ID = 'appId' ;

    /**
     * `{keyId}` — application or user key identifier (PRIVATE_KEY_JWT
     * rotation).
     */
    public const string KEY_ID = 'keyId' ;

    /**
     * `{projectId}` — Zitadel project identifier.
     */
    public const string PROJECT_ID = 'projectId' ;

    /**
     * `{roleKey}` — Zitadel project role key.
     */
    public const string ROLE_KEY = 'roleKey' ;

    /**
     * `{sessionId}` — Zitadel session identifier (mirror of the `sid`
     * claim carried by OIDC tokens).
     */
    public const string SESSION_ID = 'sessionId' ;

    /**
     * `{userId}` — Zitadel user identifier (mirror of the `sub` claim
     * carried by OIDC tokens).
     */
    public const string USER_ID = 'userId' ;
}
