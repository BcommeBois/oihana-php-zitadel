<?php

namespace oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelEndpoint;

/**
 * Zitadel Management API methods for Service Accounts (Machine
 * Users) and their Application Keys.
 *
 * The Service Account is the modern OAuth client pattern for
 * machine-to-machine auth on Zitadel : an org-level user with
 * `MACHINE` login type owns one or more Application Keys. The
 * token endpoint accepts a JWT bearer assertion signed by an
 * active key and exchanges it for an access token whose `sub`
 * claim is the Machine User's `userId`.
 *
 * Requires {@see ZitadelClientTrait} (provides request(),
 * resolveEndpoint(), $projectId).
 *
 * @package oihana\zitadel\traits\client
 * @author  Marc Alcaraz
 */
trait ZitadelClientServiceTrait
{
    /**
     * Opaque Bearer access token type. The default Zitadel returns
     * for Machine Users when no explicit type is provided — the
     * token is a random string that the resource server must
     * introspect against the IdP to validate.
     */
    public const string ACCESS_TOKEN_TYPE_BEARER = 'ACCESS_TOKEN_TYPE_BEARER' ;

    /**
     * Self-contained JWT access token type. Zitadel returns a
     * signed JWT carrying the user's claims, validatable locally
     * by any API that trusts the IdP's JWKS — no roundtrip to the
     * introspection endpoint needed.
     *
     * Default for our Service Accounts because our API exactly that :
     * verifies the signature against the cached JWKS, then reads `iss` / `aud` / `exp` / `sub` directly.
     */
    public const string ACCESS_TOKEN_TYPE_JWT = 'ACCESS_TOKEN_TYPE_JWT' ;

    /**
     * Default keyfile type — Zitadel currently only exposes
     * `KEY_TYPE_JSON` for user keys (the response carries a JSON
     * document with `type=serviceaccount`, `keyId`, `key`,
     * `userId`).
     */
    public const string KEY_TYPE_JSON = 'KEY_TYPE_JSON' ;

    /**
     * Raw response body from the most recent Zitadel call that
     * failed inside this trait. `null` until something fails.
     * Useful for surfacing a precise Zitadel error message in
     * a CLI command or a controller catch block without having
     * to parse logs.
     */
    protected ?string $lastServiceErrorBody = null ;

    /**
     * HTTP status of the most recent Zitadel call that failed
     * inside this trait. `0` when no call has failed yet.
     */
    protected int $lastServiceErrorStatus = 0 ;

    /**
     * Memoized organization id of the calling Service Account.
     * Resolved lazily by {@see getOrgId()} from
     * `GET /management/v1/orgs/me`.
     */
    protected ?string $orgId = null ;

    /**
     * Creates a Machine User (Service Account) at the org level
     * via the V2 user API.
     *
     * @param string $userName    The login name (alphanumeric +
     *                            dashes, must be unique within the
     *                            org). When empty, Zitadel falls
     *                            back to the generated `userId` —
     *                            usable but not human-readable, so
     *                            we always pass an explicit one.
     * @param string $name        The display name.
     * @param string $description Optional free-text description.
     *
     * @return object|null The created Service Account as `{ userId }`,
     *                     or `null` on failure (cf.
     *                     {@see self::getLastServiceErrorBody()}).
     */
    public function createMachineUser( string $userName , string $name , string $description = '' ) :?object
    {
        $orgId = $this->getOrgId() ;

        if( $orgId === null )
        {
            return null ;
        }

        $machine =
        [
            'name'            => $name ,
            'accessTokenType' => self::ACCESS_TOKEN_TYPE_JWT ,
        ] ;

        if( $description !== '' )
        {
            $machine[ 'description' ] = $description ;
        }

        $body =
        [
            'organizationId' => $orgId ,
            'username'       => $userName ,
            'machine'        => $machine ,
        ] ;

        $raw = $this->requestRaw( 'POST' , ZitadelEndpoint::USERS_NEW_V2 , $body ) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return null ;
        }

        $result = $raw[ 'body' ] ;

        // V2 CreateUser returns `{ id, creationDate, ... }` — note
        // the `id` key (not `userId` like the deprecated V1 endpoint).
        // We normalize it back to `userId` so consumers reason about
        // a single field name across the codebase.
        if( !$result || empty( $result->id ) )
        {
            return null ;
        }

        return (object) [ 'userId' => (string) $result->id ] ;
    }

    /**
     * Returns the raw response body of the most recent Zitadel
     * call that failed inside this trait. `null` until something
     * fails.
     */
    public function getLastServiceErrorBody() :?string
    {
        return $this->lastServiceErrorBody ;
    }

    /**
     * Returns the HTTP status of the most recent Zitadel call that
     * failed inside this trait. `0` when no call has failed yet.
     */
    public function getLastServiceErrorStatus() :int
    {
        return $this->lastServiceErrorStatus ;
    }

    /**
     * Resolves the organization id of the calling Service Account
     * via `GET /management/v1/orgs/me`, memoizing the result on the
     * consuming class.
     *
     * The V2 `CreateUser` endpoint requires `organizationId` in
     * the body — without it Zitadel rejects the request with
     * `invalid CreateUserRequest.OrganizationId: value length must
     * be between 1 and 200 runes, inclusive`.
     *
     * @return string|null The organization id, or `null` when
     *                     Zitadel cannot resolve it (auth failure,
     *                     transport error, etc.).
     */
    public function getOrgId() :?string
    {
        if( is_string( $this->orgId ) && $this->orgId !== '' )
        {
            return $this->orgId ;
        }

        $raw = $this->requestRaw( 'GET' , ZitadelEndpoint::MY_ORG ) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return null ;
        }

        $result = $raw[ 'body' ] ;
        $orgId  = is_object( $result?->org ?? null ) && is_string( $result->org->id ?? null )
            ? (string) $result->org->id
            : null ;

        if( $orgId === null || $orgId === '' )
        {
            return null ;
        }

        $this->orgId = $orgId ;

        return $this->orgId ;
    }

    /**
     * Creates a fresh Application Key on a Service Account. Returns
     * the freshly minted keyfile (RSA private key + IdP metadata)
     * ONCE — Zitadel never returns the private key again.
     *
     * The decoded keyfile carries native fields :
     * `type=serviceaccount`, `keyId`, `key`, `userId`. Connection
     * metadata (`issuer`, `apiBaseUrl`, `audience`, `scope`) is
     * injected by the API at the response-shaping layer, not here.
     *
     * @param string      $userId         The Machine User's `userId`.
     * @param string|null $expirationDate Optional ISO 8601 timestamp.
     *                                     `null` = no expiration on
     *                                     Zitadel side (we still
     *                                     enforce our own `expiresAt`
     *                                     at the application layer).
     *
     * @return object|null `{ keyId, keyfile (array<string, mixed>) }`
     *                     or `null` on failure.
     */
    public function createUserKey( string $userId , ?string $expirationDate = null ) :?object
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::USER_KEYS ,
            [ 'userId' => $userId ]
        ) ;

        $body = [ 'type' => self::KEY_TYPE_JSON ] ;

        if( is_string( $expirationDate ) && $expirationDate !== '' )
        {
            $body[ 'expirationDate' ] = $expirationDate ;
        }

        $raw = $this->requestRaw( 'POST' , $endpoint , $body ) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return null ;
        }

        $result = $raw[ 'body' ] ;

        if( !$result || empty( $result->keyId ) || empty( $result->keyDetails ) )
        {
            return null ;
        }

        // `keyDetails` is a base64-encoded JSON document. Decode it
        // so the caller never has to know about the wire format.
        $decoded = base64_decode( (string) $result->keyDetails , true ) ;
        $keyfile = is_string( $decoded ) ? json_decode( $decoded , true ) : null ;

        if( !is_array( $keyfile ) )
        {
            return null ;
        }

        return (object)
        [
            'keyId'   => (string) $result->keyId ,
            'keyfile' => $keyfile ,
        ] ;
    }

    /**
     * Deletes a Service Account on Zitadel. Cascades to its keys
     * and grants on the IdP side — no manual cleanup needed.
     *
     * @param string $userId The Machine User's `userId`.
     *
     * @return bool `true` on success, `false` otherwise.
     */
    public function deleteServiceAccount( string $userId ) :bool
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::USER_BY_ID ,
            [ 'userId' => $userId ]
        ) ;

        $raw = $this->requestRaw( 'DELETE' , $endpoint ) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return false ;
        }

        return true ;
    }

    /**
     * Deletes a single Application Key on a Service Account.
     *
     * Called by the keyfile rotation flow to revoke the previous
     * key once the new one has been minted — without this delete
     * the old key would stay valid until its expiry, which defeats
     * the purpose of rotation. {@see deleteServiceAccount()} cascades
     * keys on its own ; this method targets a single key surgically
     * while the Machine User itself stays alive.
     *
     * @param string $userId The Machine User's `userId`.
     * @param string $keyId  The key id to remove.
     *
     * @return bool `true` on success, `false` otherwise (cf.
     *              {@see self::getLastServiceErrorBody()}).
     */
    public function deleteUserKey( string $userId , string $keyId ) :bool
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::USER_KEY_BY_ID ,
            [
                'userId' => $userId ,
                'keyId'  => $keyId  ,
            ]
        ) ;

        $raw = $this->requestRaw( 'DELETE' , $endpoint ) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return false ;
        }

        return true ;
    }

    /**
     * Grants a user (Machine or Human) on a Zitadel project.
     *
     * Without an explicit grant, a Machine User's access token
     * carries no `aud` claim for the project — and the API
     * middleware therefore rejects it. Pass an empty `$roleKeys`
     * to obtain an audience-only grant (no role materialized in
     * Zitadel — RBAC stays in the API's own Casbin layer).
     *
     * @param string        $userId    The user's `userId`.
     * @param string|null   $projectId Optional override for
     *                                  `$this->projectId` (the
     *                                  Zitadel project the user
     *                                  is granted on).
     * @param array<string> $roleKeys  Optional list of role keys
     *                                  to materialize the grant
     *                                  on. Empty = audience-only.
     *
     * @return object|null The grant `{ userGrantId }` or `null` on
     *                     failure.
     */
    public function grantUserOnProject( string $userId , ?string $projectId = null , array $roleKeys = [] ) :?object
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::USER_GRANTS ,
            [ 'userId' => $userId ]
        ) ;

        $raw = $this->requestRaw( 'POST' , $endpoint ,
        [
            'projectId' => $projectId ?? $this->projectId ,
            'roleKeys'  => $roleKeys ,
        ]) ;

        if( !$raw[ 'success' ] )
        {
            $this->captureServiceError( $raw ) ;
            return null ;
        }

        $result = $raw[ 'body' ] ;

        if( !$result || empty( $result->userGrantId ) )
        {
            return null ;
        }

        return (object) [ 'userGrantId' => (string) $result->userGrantId ] ;
    }

    /**
     * Captures the structured response of a failed Zitadel call so
     * the consumer can surface the precise error message without
     * trawling logs.
     *
     * @param array{success: bool, status: int, body: mixed, rawBody: string, error: ?string} $raw
     */
    private function captureServiceError( array $raw ) :void
    {
        $this->lastServiceErrorStatus = is_int( $raw[ 'status' ] ?? null ) ? $raw[ 'status' ] : 0 ;
        $this->lastServiceErrorBody   = is_string( $raw[ 'rawBody' ] ?? null ) ? $raw[ 'rawBody' ] : null ;
    }
}
