<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelSessionField;
use oihana\zitadel\enums\ZitadelSessionSearchParam;
use oihana\zitadel\traits\client\ZitadelClientSessionTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientSessionTrait} contract against the Zitadel
 * Sessions API v2.
 *
 * The trait mixes the two return conventions on purpose : the read/list
 * helpers ({@see getSession()}, {@see listUserSessions()}) collapse to
 * object|array via {@see request()}, while the reconcile-grade helpers
 * ({@see getSessionRaw()}, {@see revokeSession()}) keep the structured
 * envelope via {@see requestRaw()} so the caller can tell a real 404 from
 * a transient outage — and forward an optional per-call timeout.
 */
#[CoversTrait( ZitadelClientSessionTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientSessionTraitTest extends TestCase
{
    /**
     * Mock whose protected request() returns the supplied value and
     * captures the call triplet.
     *
     * @param array<string,mixed> $captured
     */
    private function createRequestClient( mixed $return , array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'request' ])
            ->getMock() ;

        $client
            ->method( 'request' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$captured , $return )
                {
                    $captured = [ 'method' => $method , 'path' => $path , 'body' => $body ] ;
                    return $return ;
                }
            ) ;

        return $client ;
    }

    /**
     * Mock whose protected requestRaw() returns a generic 200 OK (or a
     * supplied result) and captures method/path/body/timeout.
     *
     * @param array<string,mixed> $captured
     */
    private function createRawClient( array &$captured , ?array $return = null ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null , ?int $timeout = null ) use ( &$captured , $return )
                {
                    $captured = [ 'method' => $method , 'path' => $path , 'body' => $body , 'timeout' => $timeout ] ;
                    return $return ?? [ 'success' => true , 'status' => 200 , 'body' => null , 'rawBody' => '' , 'error' => null ] ;
                }
            ) ;

        return $client ;
    }

    // =========================================================================
    // getSession()
    // =========================================================================

    public function testGetSessionHitsTheSessionByIdEndpointWithGet() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'session' => (object) [ 'id' => 's-1' ] ] , $captured ) ;

        $client->getSession( 's-1' ) ;

        $this->assertSame( 'GET' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/sessions/s-1' , $captured[ 'path' ] ) ;
    }

    public function testGetSessionReturnsTheDecodedBody() :void
    {
        $captured = [] ;
        $session  = (object) [ 'session' => (object) [ 'id' => 's-1' ] ] ;
        $client   = $this->createRequestClient( $session , $captured ) ;

        $this->assertSame( $session , $client->getSession( 's-1' ) ) ;
    }

    public function testGetSessionReturnsNullWhenRequestFails() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( null , $captured ) ;

        $this->assertNull( $client->getSession( 's-1' ) ) ;
    }

    // =========================================================================
    // getSessionRaw()
    // =========================================================================

    public function testGetSessionRawHitsTheSessionByIdEndpointWithGetAndNoBody() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->getSessionRaw( 's-1' ) ;

        $this->assertSame( 'GET' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/sessions/s-1' , $captured[ 'path' ] ) ;
        $this->assertNull( $captured[ 'body' ] ) ;
        $this->assertNull( $captured[ 'timeout' ] , 'no timeout passed → null (falls back to the client default)' ) ;
    }

    public function testGetSessionRawForwardsTheTimeout() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->getSessionRaw( 's-1' , 3 ) ;

        $this->assertSame( 3 , $captured[ 'timeout' ] ) ;
    }

    public function testGetSessionRawReturnsTheStructuredEnvelopeVerbatim() :void
    {
        $captured = [] ;
        $envelope = [ 'success' => false , 'status' => 404 , 'body' => null , 'rawBody' => '' , 'error' => 'http_error' ] ;
        $client   = $this->createRawClient( $captured , $envelope ) ;

        $this->assertSame( $envelope , $client->getSessionRaw( 's-gone' ) ) ;
    }

    // =========================================================================
    // listUserSessions()
    // =========================================================================

    public function testListUserSessionsHitsTheSessionsSearchEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'sessions' => [] ] , $captured ) ;

        $client->listUserSessions( 'uid-1' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/sessions/search' , $captured[ 'path' ] ) ;
    }

    public function testListUserSessionsBuildsAUserIdQuerySortedByCreationDate() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'sessions' => [] ] , $captured ) ;

        $client->listUserSessions( 'uid-1' ) ;

        $body  = $captured[ 'body' ] ;
        $query = $body[ ZitadelSessionSearchParam::QUERIES ][ 0 ][ ZitadelSessionSearchParam::USER_ID_QUERY ] ;

        $this->assertSame( 'uid-1' , $query[ ZitadelSessionSearchParam::USER_ID ] ) ;
        $this->assertSame( ZitadelSessionField::CREATION_DATE , $body[ ZitadelSessionSearchParam::SORTING_COLUMN ] ) ;
    }

    public function testListUserSessionsReturnsTheSessionsArray() :void
    {
        $captured = [] ;
        $sessions = [ (object) [ 'id' => 's-1' ] , (object) [ 'id' => 's-2' ] ] ;
        $client   = $this->createRequestClient( (object) [ 'sessions' => $sessions ] , $captured ) ;

        $this->assertSame( $sessions , $client->listUserSessions( 'uid-1' ) ) ;
    }

    public function testListUserSessionsReturnsEmptyArrayWhenNoSessions() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'details' => (object) [] ] , $captured ) ;

        $this->assertSame( [] , $client->listUserSessions( 'uid-1' ) ) ;
    }

    public function testListUserSessionsReturnsEmptyArrayWhenRequestFails() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( null , $captured ) ;

        $this->assertSame( [] , $client->listUserSessions( 'uid-1' ) ) ;
    }

    // =========================================================================
    // revokeSession()
    // =========================================================================

    public function testRevokeSessionHitsTheSessionDeleteEndpointWithDeleteAndNoBody() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->revokeSession( 's-1' ) ;

        $this->assertSame( 'DELETE' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/sessions/s-1' , $captured[ 'path' ] ) ;
        $this->assertNull( $captured[ 'body' ] ) ;
    }

    public function testRevokeSessionForwardsTheTimeout() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->revokeSession( 's-1' , 2 ) ;

        $this->assertSame( 2 , $captured[ 'timeout' ] ) ;
    }

    public function testRevokeSessionReturnsTheStructuredEnvelopeVerbatim() :void
    {
        $captured = [] ;
        $envelope = [ 'success' => true , 'status' => 204 , 'body' => null , 'rawBody' => '' , 'error' => null ] ;
        $client   = $this->createRawClient( $captured , $envelope ) ;

        $this->assertSame( $envelope , $client->revokeSession( 's-1' ) ) ;
    }
}
