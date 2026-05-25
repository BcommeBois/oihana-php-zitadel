<?php

namespace oihana\zitadel\enums;

/**
 * Defines the available OAuth2 / OpenID Connect scopes for Zitadel.
 *
 * These scopes are used when requesting authorization tokens from Zitadel.
 * They determine which identity claims and API audiences are included
 * in the issued access token or ID token.
 *
 * @see https://zitadel.com/docs/apis/openidoauth/scopes
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelScope
{
    /**
     * Requests access to the user's email address.
     *
     * When granted, the `email` and `email_verified` claims
     * may be included in the ID token or userinfo response.
     */
    public const string EMAIL = 'email' ;

    /**
     * Standard OpenID Connect scope.
     *
     * This scope is REQUIRED to perform an OpenID Connect authentication request.
     * It enables the issuance of an ID token containing user identity claims.
     */
    public const string OPENID = 'openid' ;

    /**
     * Requests access to the user's basic profile information.
     *
     * May include claims such as:
     * - name
     * - family_name
     * - given_name
     * - preferred_username
     * - picture
     * - locale
     */
    public const string PROFILE = 'profile' ;

    /**
     * Requests a refresh token from the token endpoint.
     *
     * When this scope is included, the authorization server may issue
     * a `refresh_token` alongside the access token, allowing long-lived sessions.
     *
     * ⚠️ Requires appropriate client configuration in Zitadel.
     */
    public const string OFFLINE_ACCESS = 'offline_access' ;

    /**
     * Includes the Zitadel Management API in the token audience.
     *
     * This scope allows the access token to be used against
     * Zitadel's management API endpoints.
     * Typically required for administrative or automation tasks.
     */
    public const string ZITADEL_MANAGEMENT = 'urn:zitadel:iam:org:project:id:zitadel:aud' ;

    /**
     * Composes a list of scope tokens into the space-separated form expected
     * by the OAuth2 / OIDC `scope` parameter on the token / authorize endpoints.
     *
     * Centralises the « space separator » convention so callers no longer
     * have to spell out `implode( ' ' , [ ... ] )` everywhere — and so any
     * future tightening (deduplication, normalisation, validation) only has
     * to change here.
     *
     * Example:
     * ```php
     * $scope = ZitadelScope::compose
     * (
     *     ZitadelScope::OPENID ,
     *     ZitadelScope::PROFILE ,
     *     ZitadelScope::projectAudience( $projectId ) ,
     * ) ;
     * // → "openid profile urn:zitadel:iam:org:project:id:123456789:aud"
     * ```
     *
     * @param string ...$scopes The scope tokens to combine.
     *
     * @return string The space-separated scope string.
     */
    public static function compose( string ...$scopes ) :string
    {
        return implode( ' ' , $scopes ) ;
    }

    /**
     * Generates a scope to include a specific project in the JWT audience.
     *
     * This allows the issued access token to be accepted by a specific
     * Zitadel project (resource server).
     *
     * Example:
     * ```php
     * $scope = ZitadelScope::projectAudience('123456789');
     * // urn:zitadel:iam:org:project:id:123456789:aud
     * ```
     *
     * @param string $projectId The Zitadel project identifier. Must match the target resource server configuration.
     *
     * @return string The audience scope formatted for the given project.
     */
    public static function projectAudience( string $projectId ) :string
    {
        return "urn:zitadel:iam:org:project:id:$projectId:aud" ;
    }
}
