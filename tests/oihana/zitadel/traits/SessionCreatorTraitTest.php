<?php

namespace tests\oihana\zitadel\traits;

use oihana\arango\models\Documents;
use oihana\enums\HashAlgorithm;
use xyz\oihana\schema\auth\Session;
use oihana\zitadel\OAuthClientResolver;
use oihana\zitadel\traits\SessionCreatorTrait;

use org\schema\constants\Schema;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;

use Slim\Psr7\Factory\ServerRequestFactory;

use stdClass;

/**
 * Named fixture exposing the trait under test. Declared outside the test class
 * so the trait methods can be reached via a public wrapper — PHP 8.2+ forbids
 * `TraitName::CONST` references.
 */
class SessionCreatorFixture
{
    use SessionCreatorTrait
    {
        createSession as public ;
        isSidRevoked  as public ;
    }

    public ?Documents            $sessionsModel       = null ;
    public ?Documents            $usersModel          = null ;
    public ?Documents            $invitationsModel    = null ;
    public ?OAuthClientResolver  $oauthClientResolver = null ;
    public int                   $sessionDuration     = 2592000 ;
    public ?LoggerInterface      $logger              = null ;
}

#[CoversTrait( SessionCreatorTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class SessionCreatorTraitTest extends TestCase
{
    private function createFixture
    (
        ?Documents $sessionsModel = null ,
        ?Documents $usersModel    = null
    )
    :SessionCreatorFixture
    {
        $fixture = new SessionCreatorFixture() ;

        $fixture->sessionsModel = $sessionsModel ;
        $fixture->usersModel    = $usersModel ;

        return $fixture ;
    }

    private function createSessionsMock( ?array $listResult = null ) :Documents&MockObject
    {
        return $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' , 'insert' , 'update' ])
            ->getMock() ;
    }

    private function createUsersMock( ?object $getResult = null ) :Documents&MockObject
    {
        $mock = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' ])
            ->getMock() ;

        $mock->expects( $this->any() )->method( 'get' )->willReturn( $getResult ) ;

        return $mock ;
    }

    private function createRequest( string $userAgent = 'Mozilla/5.0 (Macintosh)' ) :\Psr\Http\Message\ServerRequestInterface
    {
        return ( new ServerRequestFactory() )
            ->createServerRequest( 'GET' , '/me' )
            ->withHeader( 'User-Agent' , $userAgent ) ;
    }

    /**
     * Builds a JWT-shaped string whose payload decodes to the given claims.
     * The signature part is irrelevant — the trait never verifies it.
     */
    private function buildJwt( array $claims ) :string
    {
        $payload = rtrim( strtr( base64_encode( json_encode( $claims ) ) , '+/' , '-_' ) , '=' ) ;

        return "header.$payload.signature" ;
    }

    // =========================================================================
    // Guards
    // =========================================================================

    public function testCreateSessionReturnsNullWithoutSessionsModel() :void
    {
        $fixture = $this->createFixture() ;

        $result = $fixture->createSession( $this->createRequest() , 'some-token' , 'sub-1' , 'client-1' ) ;

        $this->assertNull( $result ) ;
    }

    public function testCreateSessionReturnsNullWithoutAccessToken() :void
    {
        $fixture = $this->createFixture( $this->createSessionsMock() ) ;

        $result = $fixture->createSession( $this->createRequest() , null ) ;

        $this->assertNull( $result ) ;
    }

    public function testCreateSessionReturnsNullWhenIdentifierCannotBeExtracted() :void
    {
        $fixture = $this->createFixture( $this->createSessionsMock() ) ;

        $result = $fixture->createSession( $this->createRequest() , 'not-a-jwt' ) ;

        $this->assertNull( $result ) ;
    }

    // =========================================================================
    // Insert new session
    // =========================================================================

    public function testCreateSessionInsertsWhenNoMatchingRow() :void
    {
        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list'   )->willReturn( [] ) ;
        $sessions->expects( $this->once() )->method( 'insert' )->with( $this->callback
        (
            function( array $args ) :bool
            {
                $doc = $args[ 'doc' ] ?? [] ;

                return
                    ( $doc[ Session::CLIENT_ID  ] ?? null ) === 'client-api'
                 && ( $doc[ Schema::IDENTIFIER  ] ?? null ) === 'user-sub'
                 && ( $doc[ Session::USER_AGENT ] ?? null ) === 'Mozilla/5.0 (Macintosh)'
                 && ( $doc[ Session::TOKEN_HASH ] ?? null ) === hash( HashAlgorithm::SHA256 , 'raw-token' )
                 && ( $doc[ Schema::ACTIVE      ] ?? null ) === true ;
            }
        )) ;
        $sessions->expects( $this->never() )->method( 'update' ) ;

        $user         = new stdClass() ;
        $user->_key   = '42' ;
        $user->status = 'active' ;

        $fixture = $this->createFixture( $sessions , $this->createUsersMock( $user ) ) ;

        $userKey = $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ;

        $this->assertSame( '42' , $userKey ) ;
    }

    // =========================================================================
    // Upsert existing session
    // =========================================================================

    public function testCreateSessionRefreshesExistingMatchingRow() :void
    {
        $existing            = new stdClass() ;
        $existing->_key      = 's-existing' ;
        $existing->userId    = '42' ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list'   )->willReturn( [ $existing ] ) ;
        $sessions->expects( $this->never() )->method( 'insert' ) ;
        $sessions->expects( $this->once() )->method( 'update' )->with( $this->callback
        (
            function( array $args ) :bool
            {
                return ( $args[ 'value' ] ?? null ) === 's-existing'
                    && isset( $args[ 'doc' ][ Session::TOKEN_HASH ] )
                    && isset( $args[ 'doc' ][ Session::EXPIRES_AT ] ) ;
            }
        )) ;

        $fixture = $this->createFixture( $sessions ) ;

        $userKey = $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ;

        $this->assertSame( '42' , $userKey ) ;
    }

    // =========================================================================
    // Discriminator checks — filters passed to list()
    // =========================================================================

    public function testCreateSessionFiltersListOnIdentifierClientIdAndUserAgent() :void
    {
        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list' )->with( $this->callback
        (
            function( array $args ) :bool
            {
                $conditions = $args[ 'conditions' ] ?? [] ;
                $binds      = $args[ 'binds'      ] ?? [] ;

                return in_array( 'doc.identifier == @sessionIdentifier' , $conditions , true )
                    && in_array( 'doc.clientId == @sessionClientId'     , $conditions , true )
                    && in_array( 'doc.userAgent == @sessionUserAgent'   , $conditions , true )
                    && in_array( 'doc.active == true'                   , $conditions , true )
                    && ( $binds[ 'sessionIdentifier' ] ?? null ) === 'user-sub'
                    && ( $binds[ 'sessionClientId'   ] ?? null ) === 'client-api'
                    && ( $binds[ 'sessionUserAgent'  ] ?? null ) === 'Mozilla/5.0 (Macintosh)' ;
            }
        ))->willReturn( [] ) ;

        $fixture = $this->createFixture( $sessions ) ;

        $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ;
    }

    // =========================================================================
    // JWT claims extraction
    // =========================================================================

    public function testCreateSessionExtractsSubAndAzpFromJwtWhenNotProvided() :void
    {
        $jwt = $this->buildJwt([ 'sub' => 'extracted-sub' , 'azp' => 'extracted-client' ]) ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list' )->with( $this->callback
        (
            fn( array $args ) :bool => ( $args[ 'binds' ][ 'sessionIdentifier' ] ?? null ) === 'extracted-sub'
                                    && ( $args[ 'binds' ][ 'sessionClientId'   ] ?? null ) === 'extracted-client'
        ))->willReturn( [] ) ;
        $sessions->expects( $this->once() )->method( 'insert' ) ;

        $fixture = $this->createFixture( $sessions ) ;

        $fixture->createSession( $this->createRequest() , $jwt ) ;
    }

    public function testCreateSessionFallsBackToClientIdClaimWhenAzpAbsent() :void
    {
        // Zitadel access tokens carry `client_id`, not `azp`.
        $jwt = $this->buildJwt([ 'sub' => 'zitadel-sub' , 'client_id' => 'zitadel-client-id' ]) ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list' )->with( $this->callback
        (
            fn( array $args ) :bool => ( $args[ 'binds' ][ 'sessionClientId' ] ?? null ) === 'zitadel-client-id'
        ))->willReturn( [] ) ;
        $sessions->expects( $this->once() )->method( 'insert' ) ;

        $fixture = $this->createFixture( $sessions ) ;

        $fixture->createSession( $this->createRequest() , $jwt ) ;
    }

    public function testCreateSessionPrefersProvidedClaimsOverJwtExtraction() :void
    {
        $jwt = $this->buildJwt([ 'sub' => 'jwt-sub' , 'azp' => 'jwt-client' ]) ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->once() )->method( 'list' )->with( $this->callback
        (
            fn( array $args ) :bool => ( $args[ 'binds' ][ 'sessionIdentifier' ] ?? null ) === 'override-sub'
                                    && ( $args[ 'binds' ][ 'sessionClientId'   ] ?? null ) === 'override-client'
        ))->willReturn( [] ) ;
        $sessions->expects( $this->once() )->method( 'insert' ) ;

        $fixture = $this->createFixture( $sessions ) ;

        $fixture->createSession( $this->createRequest() , $jwt , 'override-sub' , 'override-client' ) ;
    }

    // =========================================================================
    // isSidRevoked — the sid-match guardrail for silent-refresh post-revoke
    // =========================================================================

    /**
     * Builds a sessions model mock that exposes `get()` with a single
     * predetermined return value. Used by every `isSidRevoked` test so
     * each scenario can pin the exact row shape the lookup yields.
     */
    private function createSessionsGetMock( ?object $getResult ) :Documents&MockObject
    {
        $mock = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' ])
            ->getMock() ;

        $mock->expects( $this->any() )->method( 'get' )->willReturn( $getResult ) ;

        return $mock ;
    }

    public function testIsSidRevokedReturnsFalseWhenIdTokenClaimsAreNull() :void
    {
        $fixture = $this->createFixture( $this->createSessionsGetMock( null ) ) ;

        $this->assertFalse( $fixture->isSidRevoked( null ) ) ;
    }

    public function testIsSidRevokedReturnsFalseWhenSessionsModelIsMissing() :void
    {
        $fixture = $this->createFixture() ;

        $this->assertFalse( $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ) ;
    }

    public function testIsSidRevokedReturnsFalseWhenSidClaimIsMissing() :void
    {
        $fixture = $this->createFixture( $this->createSessionsGetMock( null ) ) ;

        $this->assertFalse( $fixture->isSidRevoked( [ 'sub' => 'user-1' ] ) ) ;
    }

    public function testIsSidRevokedReturnsFalseWhenSidIsUnknownInTheCollection() :void
    {
        // Fresh PKCE login : the sid has never been observed by the API.
        // The bootstrap must proceed (legitimate new session).
        $fixture = $this->createFixture( $this->createSessionsGetMock( null ) ) ;

        $this->assertFalse( $fixture->isSidRevoked( [ 'sid' => 'never-seen-before' ] ) ) ;
    }

    public function testIsSidRevokedReturnsFalseWhenMatchingRowIsActive() :void
    {
        $row         = new stdClass() ;
        $row->id     = 'session-abc' ;
        $row->active = true ;

        $fixture = $this->createFixture( $this->createSessionsGetMock( $row ) ) ;

        $this->assertFalse( $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ) ;
    }

    public function testIsSidRevokedReturnsTrueWhenMatchingRowIsRevoked() :void
    {
        $row         = new stdClass() ;
        $row->id     = 'session-abc' ;
        $row->active = false ;

        $fixture = $this->createFixture( $this->createSessionsGetMock( $row ) ) ;

        $this->assertTrue( $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ) ;
    }

    public function testIsSidRevokedReturnsTrueWhenMatchingRowHasNoActiveProperty() :void
    {
        // Defensive: a row without `active` is treated as not-active (safer).
        $row     = new stdClass() ;
        $row->id = 'session-abc' ;

        $fixture = $this->createFixture( $this->createSessionsGetMock( $row ) ) ;

        $this->assertTrue( $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ) ;
    }

    public function testIsSidRevokedAcceptsObjectClaims() :void
    {
        $row         = new stdClass() ;
        $row->id     = 'session-abc' ;
        $row->active = false ;

        $fixture = $this->createFixture( $this->createSessionsGetMock( $row ) ) ;

        $claims      = new stdClass() ;
        $claims->sid = 'session-abc' ;

        $this->assertTrue( $fixture->isSidRevoked( $claims ) ) ;
    }

    public function testIsSidRevokedQueriesTheCollectionByIdField() :void
    {
        $sessions = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' ])
            ->getMock() ;

        $sessions->expects( $this->once() )->method( 'get' )->with( $this->callback
        (
            function( array $args ) :bool
            {
                return ( $args[ 'key'   ] ?? null ) === Schema::ID
                    && ( $args[ 'value' ] ?? null ) === 'session-abc' ;
            }
        ))->willReturn( null ) ;

        $fixture = $this->createFixture( $sessions ) ;

        $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ;
    }
}
