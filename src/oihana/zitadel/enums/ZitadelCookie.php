<?php

namespace oihana\zitadel\enums;

/**
 * Defines the cookie names used for Zitadel OAuth2 authentication.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelCookie
{
    /**
     * Cookie name for the JWT access token.
     */
    public const string ACCESS_TOKEN = 'access_token' ;

    /**
     * Cookie name for the OAuth2 refresh token.
     */
    public const string REFRESH_TOKEN = 'refresh_token' ;
}
