<?php

namespace oihana\zitadel\traits\client;

use Throwable;

use Firebase\JWT\JWT;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

use oihana\enums\http\AuthScheme;
use oihana\enums\http\GuzzleOption;
use oihana\enums\http\HttpHeader;
use oihana\enums\oauth2\OAuth2GrantType;
use oihana\enums\oauth2\OAuth2Parameter;
use oihana\enums\oauth2\OAuth2TokenField;
use oihana\files\enums\FileMimeType;
use oihana\zitadel\enums\ZitadelError;
use oihana\zitadel\enums\ZitadelOutput;

use xyz\oihana\schema\auth\Keyfile;
use xyz\oihana\schema\constants\JWTAlgorithm;
use xyz\oihana\schema\constants\JwtClaim;

use oihana\zitadel\enums\ZitadelEndpoint;
use oihana\zitadel\enums\ZitadelScope;

use Psr\Log\LoggerInterface;

/**
 * Client for the Zitadel Management API (v1).
 *
 * Uses a service account (JWT Bearer grant) to authenticate and manage
 * users, roles, and projects in Zitadel.
 *
 * @package oihana\zitadel
 * @author  Marc Alcaraz
 */
trait ZitadelClientTrait
{
    /**
     * Creates a new ZitadelClient instance.
     *
     * @param string               $issuer         The Zitadel issuer URL.
     * @param string               $projectId      The Zitadel project ID.
     * @param array                $serviceAccount The service account data (from zitadel-sa.json).
     * @param LoggerInterface|null $logger         Optional PSR logger.
     */
    public function __construct
    (
        protected string           $issuer ,
        protected string           $projectId ,
        protected array            $serviceAccount ,
        protected ?LoggerInterface $logger = null
    ) {}

    /**
     * Default per-request timeout (seconds) for calls issued through
     * {@see request()} and {@see requestRaw()}.
     *
     * Callers can override this per call with the optional `$timeout`
     * parameter — used for example by
     * {@see ZitadelClientSessionTrait::revokeSession()} to bound the
     * worst-case latency of bulk session revocation propagation.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 10 ;

    /**
     * Cached management access token.
     */
    private ?string $accessToken = null ;

    /**
     * Token expiration timestamp.
     */
    private int $tokenExpires = 0 ;

    /**
     * Returns a valid management access token.
     */
    public function getAccessToken() :?string
    {
        if( $this->accessToken && time() < $this->tokenExpires )
        {
            return $this->accessToken ;
        }
        return $this->refreshToken() ;
    }

    /**
     * Obtains a fresh management access token using JWT Bearer grant.
     */
    protected function refreshToken() :?string
    {
        try
        {
            $now       = time() ;
            $assertion = JWT::encode
            (
                [
                    JwtClaim::ISSUER     => $this->serviceAccount[ Keyfile::USER_ID ] ,
                    JwtClaim::SUBJECT    => $this->serviceAccount[ Keyfile::USER_ID ] ,
                    JwtClaim::AUDIENCE   => $this->issuer ,
                    JwtClaim::ISSUED_AT  => $now ,
                    JwtClaim::EXPIRES_AT => $now + 3600 ,
                ] ,
                $this->serviceAccount[ Keyfile::KEY ] ,
                JWTAlgorithm::RS256 ,
                $this->serviceAccount[ Keyfile::KEY_ID ]
            ) ;

            $client   = new Client([ GuzzleOption::TIMEOUT => self::DEFAULT_TIMEOUT_SECONDS ]) ;
            $response = $client->post( $this->issuer . ZitadelEndpoint::TOKEN ,
            [
                GuzzleOption::FORM_PARAMS =>
                [
                    OAuth2Parameter::GRANT_TYPE => OAuth2GrantType::JWT_BEARER ,
                    OAuth2Parameter::SCOPE      => ZitadelScope::OPENID . ' ' . ZitadelScope::ZITADEL_MANAGEMENT ,
                    OAuth2Parameter::ASSERTION  => $assertion ,
                ]
            ]) ;

            $data = json_decode( $response->getBody()->getContents() , true ) ;

            $this->accessToken  = $data[ OAuth2TokenField::ACCESS_TOKEN ] ?? null ;
            $this->tokenExpires = $now + ( $data[ OAuth2TokenField::EXPIRES_IN ] ?? 3600 ) - 60 ;

            return $this->accessToken ;
        }
        catch( Throwable $e )
        {
            $this->logger?->error( "ZitadelClient: token refresh failed: {$e->getMessage()}" ) ;
            return null ;
        }
    }

    /**
     * Resolves an endpoint template by replacing placeholders.
     *
     * @param string $endpoint The endpoint template (e.g., '/management/v1/users/{userId}').
     * @param array  $params   The parameters to replace (e.g., ['userId' => '123']).
     *
     * @return string The resolved endpoint.
     */
    protected function resolveEndpoint( string $endpoint , array $params = [] ) :string
    {
        foreach( $params as $key => $value )
        {
            $endpoint = str_replace( '{' . $key . '}' , $value , $endpoint ) ;
        }

        return $endpoint ;
    }

    /**
     * Makes an authenticated request to the Zitadel Management API.
     *
     * Convenience wrapper around {@see requestRaw()} that flattens the
     * structured response: returns the decoded body on success, `null`
     * on any failure (HTTP error, transport error, missing token).
     *
     * Use this when you do not need to differentiate between failure modes
     * (e.g. fire-and-forget calls). When you need to map specific HTTP
     * status codes to your own error response (e.g. 401 invalid_code →
     * 410 Gone, 5xx → 502 Bad Gateway), use {@see requestRaw()} instead.
     *
     * @param string                 $method  HTTP verb.
     * @param string                 $path    Resolved endpoint path.
     * @param array|object|null      $body    Optional JSON body.
     * @param int|null               $timeout Optional per-call timeout in seconds, overrides {@see DEFAULT_TIMEOUT_SECONDS}.
     */
    protected function request( string $method , string $path , array|object|null $body = null , ?int $timeout = null ) :?object
    {
        $result = $this->requestRaw( $method , $path , $body , $timeout ) ;
        return $result[ ZitadelOutput::SUCCESS ] ? $result[ ZitadelOutput::BODY ] : null ;
    }

    /**
     * Makes an authenticated request and returns a structured result.
     *
     * Unlike {@see request()}, this method never collapses errors to `null`.
     * It always returns an associative array describing the outcome so the
     * caller can map specific HTTP status codes (or transport failures) to
     * its own error contract.
     *
     * The returned shape is:
     *
     * ```
     * [
     *     'success'   => bool,        // true iff a 2xx response was received
     *     'status'    => int,         // HTTP status (0 if no response — auth/transport failure)
     *     'body'      => mixed|null,  // decoded JSON body when present, else null
     *     'rawBody'   => string,      // raw response body, useful for diagnostics
     *     'error'     => string|null, // null on success, short error tag otherwise
     *                                 // ('no_token' | 'http_error' | 'transport_error')
     * ]
     * ```
     *
     * Logging behaviour matches {@see request()} (errors are still logged
     * via the PSR-3 logger). The caller decides how to surface the failure
     * to its own consumers.
     *
     * @param string                 $method  HTTP verb.
     * @param string                 $path    Resolved endpoint path.
     * @param array|object|null      $body    Optional JSON body.
     * @param int|null               $timeout Optional per-call timeout in seconds, overrides {@see DEFAULT_TIMEOUT_SECONDS}.
     */
    protected function requestRaw( string $method , string $path , array|object|null $body = null , ?int $timeout = null ) :array
    {
        $token = $this->getAccessToken() ;

        if( !$token )
        {
            $this->logger?->error( 'ZitadelClient: no access token available' ) ;
            return ZitadelOutput::failure( ZitadelError::NO_TOKEN ) ;
        }

        try
        {
            $client = new Client([ GuzzleOption::BASE_URI => $this->issuer , GuzzleOption::TIMEOUT => $timeout ?? self::DEFAULT_TIMEOUT_SECONDS ]) ;

            $options =
            [
                GuzzleOption::HEADERS =>
                [
                    HttpHeader::AUTHORIZATION => AuthScheme::prefix( AuthScheme::BEARER ) . $token ,
                    HttpHeader::CONTENT_TYPE  => FileMimeType::JSON
                ]
            ] ;

            if( $body !== null )
            {
                $options[ GuzzleOption::JSON ] = $body ;
            }

            $response = $client->request( $method , $path , $options ) ;
            $rawBody  = $response->getBody()->getContents() ;

            return ZitadelOutput::success
            (
                $response->getStatusCode() ,
                $rawBody !== '' ? json_decode( $rawBody ) : null ,
                $rawBody ,
            ) ;
        }
        catch( BadResponseException $e )
        {
            $status  = $e->getResponse()?->getStatusCode() ?? 0 ;
            $rawBody = (string) ( $e->getResponse()?->getBody() ?? '' ) ;

            $this->logger?->error
            (
                "ZitadelClient: $method $path → HTTP $status — "
                . $e->getMessage()
                . ( $rawBody !== '' ? " — response body: $rawBody" : '' )
            ) ;

            return ZitadelOutput::failure
            (
                ZitadelError::HTTP_ERROR ,
                $status ,
                $rawBody !== '' ? json_decode( $rawBody ) : null ,
                $rawBody ,
            ) ;
        }
        catch( GuzzleException $e )
        {
            $this->logger?->error( "ZitadelClient: $method $path failed (transport): {$e->getMessage()}" ) ;
            return ZitadelOutput::failure( ZitadelError::TRANSPORT_ERROR ) ;
        }
    }
}
