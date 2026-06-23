<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelError;
use oihana\zitadel\enums\ZitadelOutput;
use oihana\zitadel\traits\client\ZitadelClientTrait;
use oihana\zitadel\ZitadelClient;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;

use Psr\Log\LoggerInterface;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the base {@see ZitadelClientTrait} HTTP layer — the token-refresh
 * (JWT Bearer grant) and the structured request/requestRaw plumbing.
 *
 * These methods instantiate Guzzle internally, so the trait exposes a
 * single overridable {@see ZitadelClientTrait::createHttpClient()} factory
 * as a testability seam : the harness below overrides it to return a
 * Guzzle client backed by a `MockHandler`, letting every branch (success,
 * 4xx/5xx HTTP error, transport failure, missing token, body present/empty,
 * custom timeout) be exercised without a live Zitadel instance. A Guzzle
 * history middleware captures the outgoing requests so the wire contract
 * (token endpoint, Authorization header, JSON body) can be asserted.
 */
#[CoversTrait( ZitadelClientTrait::class )]
class ZitadelClientTraitTest extends TestCase
{
    /** @var array<string,mixed> Service account used to sign the JWT assertion. */
    private array $serviceAccount ;

    /** @var array<int,array{request:Request,response:mixed}> Guzzle request/response history. */
    private array $history = [] ;

    protected function setUp() :void
    {
        // A real RSA key is required: refreshToken() signs the JWT bearer
        // assertion with RS256, which needs a valid PEM private key.
        $res = openssl_pkey_new([ 'private_key_bits' => 2048 , 'private_key_type' => OPENSSL_KEYTYPE_RSA ]) ;
        openssl_pkey_export( $res , $pem ) ;

        $this->serviceAccount =
        [
            'userId' => 'svc-user' ,
            'keyId'  => 'kid-1' ,
            'key'    => $pem ,
        ] ;
    }

    /**
     * Builds a ZitadelClient subclass whose createHttpClient() returns a
     * Guzzle client driven by the supplied MockHandler queue, recording
     * each createHttpClient() config in `$harness->configs`.
     *
     * @param array<int,mixed>     $queue  MockHandler queue (Response / Exception).
     * @param LoggerInterface|null $logger Optional PSR logger to observe error logging.
     */
    private function harness( array $queue , ?LoggerInterface $logger = null ) :ZitadelClient
    {
        $this->history = [] ;

        $stack = HandlerStack::create( new MockHandler( $queue ) ) ;
        $stack->push( Middleware::history( $this->history ) ) ;
        $guzzle = new Client([ 'handler' => $stack ]) ;

        $harness = new class( 'https://issuer.example' , 'project-1' , $this->serviceAccount , $logger ) extends ZitadelClient
        {
            /** @var array<int,array<string,mixed>> */
            public array $configs = [] ;
            public ?Client $stub = null ;

            protected function createHttpClient( array $config ) :Client
            {
                $this->configs[] = $config ;
                return $this->stub ;
            }

            public function exposeResolveEndpoint( string $endpoint , array $params = [] ) :string
            {
                return $this->resolveEndpoint( $endpoint , $params ) ;
            }

            public function exposeRequest( string $method , string $path , array|object|null $body = null , ?int $timeout = null ) :?object
            {
                return $this->request( $method , $path , $body , $timeout ) ;
            }

            public function exposeRequestRaw( string $method , string $path , array|object|null $body = null , ?int $timeout = null ) :array
            {
                return $this->requestRaw( $method , $path , $body , $timeout ) ;
            }
        } ;

        $harness->stub = $guzzle ;

        return $harness ;
    }

    private function tokenResponse( array $payload = [ 'access_token' => 'tok-abc' , 'expires_in' => 3600 ] ) :Response
    {
        return new Response( 200 , [] , json_encode( $payload ) ) ;
    }

    // =========================================================================
    // resolveEndpoint()
    // =========================================================================

    public function testResolveEndpointReplacesASinglePlaceholder() :void
    {
        $client = $this->harness( [] ) ;

        $this->assertSame
        (
            '/management/v1/users/u-1' ,
            $client->exposeResolveEndpoint( '/management/v1/users/{userId}' , [ 'userId' => 'u-1' ] )
        ) ;
    }

    public function testResolveEndpointReplacesMultiplePlaceholders() :void
    {
        $client = $this->harness( [] ) ;

        $this->assertSame
        (
            '/management/v1/projects/p-1/roles/admin' ,
            $client->exposeResolveEndpoint
            (
                '/management/v1/projects/{projectId}/roles/{roleKey}' ,
                [ 'projectId' => 'p-1' , 'roleKey' => 'admin' ]
            )
        ) ;
    }

    public function testResolveEndpointReturnsTemplateUnchangedWithoutParams() :void
    {
        $client = $this->harness( [] ) ;

        $this->assertSame( '/v2/sessions/search' , $client->exposeResolveEndpoint( '/v2/sessions/search' ) ) ;
    }

    public function testResolveEndpointLeavesUnmatchedPlaceholdersIntact() :void
    {
        $client = $this->harness( [] ) ;

        $this->assertSame
        (
            '/x/u-1/{roleKey}' ,
            $client->exposeResolveEndpoint( '/x/{userId}/{roleKey}' , [ 'userId' => 'u-1' ] )
        ) ;
    }

    // =========================================================================
    // getAccessToken() / refreshToken()
    // =========================================================================

    public function testGetAccessTokenFetchesATokenFromTheTokenEndpoint() :void
    {
        $client = $this->harness( [ $this->tokenResponse() ] ) ;

        $this->assertSame( 'tok-abc' , $client->getAccessToken() ) ;

        // The token call hit the OAuth2 token endpoint with the JWT Bearer
        // grant assertion in the form body.
        $request = $this->history[ 0 ][ 'request' ] ;
        $this->assertSame( 'POST' , $request->getMethod() ) ;
        $this->assertSame( 'https://issuer.example/oauth/v2/token' , (string) $request->getUri() ) ;

        $form = (string) $request->getBody() ;
        $this->assertStringContainsString( 'grant_type=' , $form ) ;
        $this->assertStringContainsString( 'assertion=' , $form ) ;
    }

    public function testGetAccessTokenCachesTheTokenAcrossCalls() :void
    {
        $client = $this->harness( [ $this->tokenResponse() ] ) ;

        $first  = $client->getAccessToken() ;
        $second = $client->getAccessToken() ;

        $this->assertSame( 'tok-abc' , $first ) ;
        $this->assertSame( $first , $second ) ;
        // Cached: no second HTTP client built, no second token request.
        $this->assertCount( 1 , $client->configs , 'token must be fetched at most once while still valid' ) ;
        $this->assertCount( 1 , $this->history ) ;
    }

    public function testRefreshTokenDefaultsExpiryWhenExpiresInIsMissing() :void
    {
        // No expires_in → the 3600s default kicks in; the token is still
        // returned and cached (a missing expiry must not break the flow).
        $client = $this->harness( [ $this->tokenResponse( [ 'access_token' => 'tok-noexp' ] ) ] ) ;

        $this->assertSame( 'tok-noexp' , $client->getAccessToken() ) ;
        $this->assertSame( 'tok-noexp' , $client->getAccessToken() , 'token cached despite missing expires_in' ) ;
        $this->assertCount( 1 , $this->history ) ;
    }

    public function testRefreshTokenReturnsNullWhenResponseHasNoAccessToken() :void
    {
        $client = $this->harness( [ $this->tokenResponse( [ 'token_type' => 'Bearer' ] ) ] ) ;

        $this->assertNull( $client->getAccessToken() ) ;
    }

    public function testRefreshTokenReturnsNullAndLogsOnTransportFailure() :void
    {
        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->atLeastOnce() )->method( 'error' ) ;

        $client = $this->harness( [ new ConnectException( 'refused' , new Request( 'POST' , 'token' ) ) ] , $logger ) ;

        $this->assertNull( $client->getAccessToken() ) ;
    }

    // =========================================================================
    // requestRaw() — success branches
    // =========================================================================

    public function testRequestRawSuccessDecodesTheJsonBody() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{"id":"x"}' ) ] ) ;

        $result = $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $this->assertTrue( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 200 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertSame( 'x' , $result[ ZitadelOutput::BODY ]->id ?? null ) ;
        $this->assertSame( '{"id":"x"}' , $result[ ZitadelOutput::RAW_BODY ] ) ;
        $this->assertNull( $result[ ZitadelOutput::ERROR ] ) ;
    }

    public function testRequestRawSuccessWithEmptyBodyYieldsNullBody() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 204 , [] , '' ) ] ) ;

        $result = $client->exposeRequestRaw( 'DELETE' , '/v2/x' ) ;

        $this->assertTrue( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 204 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertNull( $result[ ZitadelOutput::BODY ] ) ;
        $this->assertSame( '' , $result[ ZitadelOutput::RAW_BODY ] ) ;
    }

    public function testRequestRawSendsBearerAuthAndJsonBodyWhenBodyProvided() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{}' ) ] ) ;

        $client->exposeRequestRaw( 'POST' , '/v2/x' , [ 'a' => 1 ] ) ;

        // history[0] is the token call; history[1] is the API call.
        $request = $this->history[ 1 ][ 'request' ] ;
        $this->assertSame( 'POST' , $request->getMethod() ) ;
        // base_uri is forwarded through the client config (asserted in the
        // timeout test); the request carries the resolved path.
        $this->assertSame( '/v2/x' , (string) $request->getUri() ) ;
        $this->assertSame( 'Bearer tok-abc' , $request->getHeaderLine( 'Authorization' ) ) ;
        $this->assertStringContainsString( 'application/json' , $request->getHeaderLine( 'Content-Type' ) ) ;
        $this->assertSame( [ 'a' => 1 ] , json_decode( (string) $request->getBody() , true ) ) ;
    }

    public function testRequestRawOmitsJsonBodyWhenNoBodyProvided() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{}' ) ] ) ;

        $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $request = $this->history[ 1 ][ 'request' ] ;
        $this->assertSame( '' , (string) $request->getBody() , 'GET with no body must not carry a JSON payload' ) ;
    }

    // =========================================================================
    // requestRaw() — timeout forwarding
    // =========================================================================

    public function testRequestRawForwardsACustomTimeoutToTheClientConfig() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{}' ) ] ) ;

        $client->exposeRequestRaw( 'GET' , '/v2/x' , null , 5 ) ;

        // configs[0] = token client ; configs[1] = API client.
        $this->assertSame( 'https://issuer.example' , $client->configs[ 1 ][ 'base_uri' ] ) ;
        $this->assertSame( 5 , $client->configs[ 1 ][ 'timeout' ] ) ;
    }

    public function testRequestRawUsesTheDefaultTimeoutWhenNoneProvided() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{}' ) ] ) ;

        $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $this->assertSame( ZitadelClient::DEFAULT_TIMEOUT_SECONDS , $client->configs[ 1 ][ 'timeout' ] ) ;
    }

    // =========================================================================
    // requestRaw() — failure branches
    // =========================================================================

    public function testRequestRawReturnsNoTokenFailureWhenTokenUnavailable() :void
    {
        // The token call fails → getAccessToken() returns null → requestRaw
        // must short-circuit with a NO_TOKEN failure and never attempt the
        // API call.
        $client = $this->harness( [ new ConnectException( 'refused' , new Request( 'POST' , 'token' ) ) ] ) ;

        $result = $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $this->assertFalse( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 0 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertSame( ZitadelError::NO_TOKEN , $result[ ZitadelOutput::ERROR ] ) ;
    }

    public function testRequestRawMapsHttpErrorToHttpErrorFailureWithBody() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 403 , [] , '{"message":"denied"}' ) ] ) ;

        $result = $client->exposeRequestRaw( 'POST' , '/v2/x' , [ 'a' => 1 ] ) ;

        $this->assertFalse( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 403 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertSame( ZitadelError::HTTP_ERROR , $result[ ZitadelOutput::ERROR ] ) ;
        $this->assertSame( 'denied' , $result[ ZitadelOutput::BODY ]->message ?? null ) ;
        $this->assertSame( '{"message":"denied"}' , $result[ ZitadelOutput::RAW_BODY ] ) ;
    }

    public function testRequestRawHttpErrorWithEmptyBodyYieldsNullBody() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 500 , [] , '' ) ] ) ;

        $result = $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $this->assertFalse( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 500 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertSame( ZitadelError::HTTP_ERROR , $result[ ZitadelOutput::ERROR ] ) ;
        $this->assertNull( $result[ ZitadelOutput::BODY ] ) ;
        $this->assertSame( '' , $result[ ZitadelOutput::RAW_BODY ] ) ;
    }

    public function testRequestRawLogsTheHttpError() :void
    {
        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->atLeastOnce() )->method( 'error' ) ;

        $client = $this->harness( [ $this->tokenResponse() , new Response( 404 , [] , '{"message":"nope"}' ) ] , $logger ) ;

        $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;
    }

    public function testRequestRawMapsTransportFailureToTransportError() :void
    {
        $client = $this->harness
        (
            [ $this->tokenResponse() , new ConnectException( 'reset' , new Request( 'GET' , '/v2/x' ) ) ]
        ) ;

        $result = $client->exposeRequestRaw( 'GET' , '/v2/x' ) ;

        $this->assertFalse( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 0 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertSame( ZitadelError::TRANSPORT_ERROR , $result[ ZitadelOutput::ERROR ] ) ;
    }

    // =========================================================================
    // request() — collapsed variant
    // =========================================================================

    public function testRequestReturnsTheDecodedBodyOnSuccess() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 200 , [] , '{"ok":true}' ) ] ) ;

        $body = $client->exposeRequest( 'GET' , '/v2/x' ) ;

        $this->assertSame( true , $body->ok ?? null ) ;
    }

    public function testRequestReturnsNullOnFailure() :void
    {
        $client = $this->harness( [ $this->tokenResponse() , new Response( 404 , [] , '{"message":"nope"}' ) ] ) ;

        $this->assertNull( $client->exposeRequest( 'GET' , '/v2/x' ) ) ;
    }

    // =========================================================================
    // createHttpClient() — the production factory body (the seam itself)
    // =========================================================================

    public function testCreateHttpClientBuildsARealGuzzleClient() :void
    {
        // The harness overrides createHttpClient(), so its production body is
        // never run by the other tests. Exercise it directly on a real
        // client — instantiating Guzzle performs no I/O.
        $client = new ZitadelClient( 'https://issuer.example' , 'project-1' , $this->serviceAccount ) ;

        $method = new \ReflectionMethod( ZitadelClient::class , 'createHttpClient' ) ;
        $guzzle = $method->invoke( $client , [ 'base_uri' => 'https://issuer.example' , 'timeout' => 10 ] ) ;

        $this->assertInstanceOf( Client::class , $guzzle ) ;
    }
}
