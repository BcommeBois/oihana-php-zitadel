<?php

namespace oihana\zitadel\enums;

/**
 * Defines the supported OAuth2 grant types for Zitadel.
 *
 * Grant types specify how a client application obtains an access token
 * from the authorization server.
 *
 * Each grant type corresponds to a different authentication flow,
 * depending on the use case (machine-to-machine, user delegation, etc.).
 *
 * @see https://zitadel.com/docs/apis/openidoauth/grant-types
 *
 * @package oihana\zitadel\enums
 *
 * @author  Marc Alcaraz
 */
class ZitadelGrant
{
    /**
     * Client Credentials Grant.
     *
     * Used for machine-to-machine (M2M) communication where no user is involved.
     *
     * The client authenticates using its own credentials (client_id + secret or JWT)
     * and receives an access token representing the application itself.
     *
     * Typical use cases:
     * - Backend services
     * - CRON jobs
     * - API-to-API communication
     *
     * ⚠️ No user context is included in the token.
     */
    public const string CLIENT_CREDENTIALS = 'client_credentials' ;

    /**
     * JWT Bearer Grant (RFC 7523).
     *
     * Uses a signed JWT as an authorization grant to obtain an access token.
     *
     * The client submits a JWT assertion to the token endpoint, which must:
     * - Be signed with a trusted key
     * - Contain required claims (iss, sub, aud, exp, etc.)
     *
     * This grant is commonly used for:
     * - Service account authentication
     * - Federated identity flows
     * - Advanced M2M scenarios with stronger security
     *
     * In Zitadel, this is often used with service users and key-based authentication.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7523
     */
    public const string JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer' ;
}