<?php

namespace tests\oihana\zitadel\traits;

use oihana\arango\models\Documents;
use oihana\enums\HashAlgorithm;
use xyz\oihana\schema\auth\Invitation;
use xyz\oihana\schema\auth\Session;
use xyz\oihana\schema\auth\User;
use xyz\oihana\schema\constants\InvitationStatus;
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
        createSession                as public ;
        extractClaimsFromAccessToken as public ;
        isSidRevoked                 as public ;
        recordSuccessfulLogin        as public ;
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
    // extractClaimsFromAccessToken
    // =========================================================================

    public function testExtractClaimsFromValidJwt() :void
    {
        $fixture = $this->createFixture() ;
        $token   = $this->buildJwt( [ 'sub' => 'user-1' , 'sid' => 'session-abc' ] ) ;

        $claims = $fixture->extractClaimsFromAccessToken( $token ) ;

        $this->assertIsArray( $claims ) ;
        $this->assertSame( 'user-1'      , $claims[ 'sub' ] ) ;
        $this->assertSame( 'session-abc' , $claims[ 'sid' ] ) ;
    }

    public function testExtractClaimsReturnsNullWhenNotThreeSegments() :void
    {
        $fixture = $this->createFixture() ;

        $this->assertNull( $fixture->extractClaimsFromAccessToken( 'only.two'   ) ) ;
        $this->assertNull( $fixture->extractClaimsFromAccessToken( 'no-dots-at-all' ) ) ;
    }

    public function testExtractClaimsReturnsNullOnMalformedBase64UrlPayload() :void
    {
        // The middle segment carries a '+' — outside the base64url alphabet —
        // so base64UrlDecode() returns false and the claims cannot be parsed.
        $fixture = $this->createFixture() ;

        $this->assertNull( $fixture->extractClaimsFromAccessToken( 'header.aGVsbG8+.signature' ) ) ;
    }

    public function testExtractClaimsReturnsNullWhenPayloadIsNotAJsonObject() :void
    {
        // A valid base64url payload that decodes to a JSON scalar (here `123`)
        // is not an associative array of claims — the helper rejects it.
        $fixture = $this->createFixture() ;
        $payload = rtrim( strtr( base64_encode( '123' ) , '+/' , '-_' ) , '=' ) ;

        $this->assertNull( $fixture->extractClaimsFromAccessToken( "header.$payload.signature" ) ) ;
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

    public function testIsSidRevokedReturnsFalseWhenLookupThrows() :void
    {
        $sessions = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' ])
            ->getMock() ;

        $sessions->method( 'get' )->willThrowException( new \RuntimeException( 'arango down' ) ) ;

        $fixture = $this->createFixture( $sessions ) ;

        // Fail-open: a lookup error must not block a bootstrap.
        $this->assertFalse( $fixture->isSidRevoked( [ 'sid' => 'session-abc' ] ) ) ;
    }

    // =========================================================================
    // createSession — lifecycle gate
    // =========================================================================

    public function testCreateSessionRefusedWhenUserStatusIsNotActive() :void
    {
        $user         = new stdClass() ;
        $user->_key   = '42' ;
        $user->status = 'disabled' ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->never() )->method( 'list'   ) ;
        $sessions->expects( $this->never() )->method( 'insert' ) ;

        $fixture = $this->createFixture( $sessions , $this->createUsersMock( $user ) ) ;

        $this->assertNull( $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ) ;
    }

    public function testCreateSessionRefusedWhenUserStatusIsNull() :void
    {
        // Legacy user never backfilled with a lifecycle status: a null status
        // blocks too (operators must run the backfill once).
        $user       = new stdClass() ;
        $user->_key = '42' ;

        $sessions = $this->createSessionsMock() ;
        $sessions->expects( $this->never() )->method( 'insert' ) ;

        $fixture = $this->createFixture( $sessions , $this->createUsersMock( $user ) ) ;

        $this->assertNull( $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ) ;
    }

    // =========================================================================
    // createSession — app label refresh on update
    // =========================================================================

    public function testCreateSessionUpdatesAppLabelWhenResolverNameDiffers() :void
    {
        $existing         = new stdClass() ;
        $existing->_key   = 's-existing' ;
        $existing->userId = '42' ;
        $existing->name   = 'Old Label' ;

        $captured = [] ;

        $sessions = $this->createSessionsMock() ;
        $sessions->method( 'list' )->willReturn( [ $existing ] ) ;
        $sessions->method( 'update' )->willReturnCallback( function( array $args ) use ( &$captured )
        {
            $captured = $args ;
            return null ;
        } ) ;

        $resolver = $this->getMockBuilder( OAuthClientResolver::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'resolve' ])
            ->getMock() ;
        $resolver->method( 'resolve' )->willReturn( 'New Label' ) ;

        $fixture = $this->createFixture( $sessions ) ;
        $fixture->oauthClientResolver = $resolver ;

        $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ;

        // A resolved name that differs from the stored one must be written on
        // refresh so a Zitadel-side rename propagates without a full resync.
        $this->assertSame( 'New Label' , $captured[ 'doc' ][ Schema::NAME ] ?? null ) ;
    }

    // =========================================================================
    // createSession — best-effort error swallowing
    // =========================================================================

    public function testCreateSessionReturnsNullWhenModelThrows() :void
    {
        $sessions = $this->createSessionsMock() ;
        $sessions->method( 'list' )->willThrowException( new \RuntimeException( 'arango down' ) ) ;

        $fixture = $this->createFixture( $sessions ) ;

        // Session creation is best-effort: a storage failure must collapse to
        // null, never propagate and break the authentication flow.
        $this->assertNull( $fixture->createSession( $this->createRequest() , 'raw-token' , 'user-sub' , 'client-api' ) ) ;
    }

    // =========================================================================
    // recordSuccessfulLogin
    // =========================================================================

    /**
     * Builds a users model mock with get() returning $getResult and update()
     * capturing the doc into $captured.
     *
     * @param array<string,mixed> $captured
     */
    private function createUsersGetUpdateMock( ?object $getResult , array &$captured ) :Documents&MockObject
    {
        $mock = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' , 'update' ])
            ->getMock() ;

        $mock->method( 'get' )->willReturn( $getResult ) ;
        $mock->method( 'update' )->willReturnCallback( function( array $args ) use ( &$captured )
        {
            $captured = $args ;
            return null ;
        } ) ;

        return $mock ;
    }

    public function testRecordSuccessfulLoginReturnsEarlyWithoutUsersModel() :void
    {
        $fixture = $this->createFixture() ;

        // No users model → no-op, must not throw.
        $fixture->recordSuccessfulLogin( '42' ) ;

        $this->assertNull( $fixture->usersModel ) ;
    }

    public function testRecordSuccessfulLoginReturnsEarlyWhenUserNotFound() :void
    {
        $captured = [] ;
        $users    = $this->createUsersGetUpdateMock( null , $captured ) ;
        $users->expects( $this->never() )->method( 'update' ) ;

        $fixture = $this->createFixture( null , $users ) ;

        $fixture->recordSuccessfulLogin( 'missing' ) ;

        $this->assertSame( [] , $captured ) ;
    }

    public function testRecordSuccessfulLoginBumpsCountersOnSubsequentLogin() :void
    {
        $user              = new stdClass() ;
        $user->activated   = true ;
        $user->loginsCount = 5 ;

        $captured = [] ;
        $users    = $this->createUsersGetUpdateMock( $user , $captured ) ;

        $fixture = $this->createFixture( null , $users ) ;

        $fixture->recordSuccessfulLogin( '42' ) ;

        $doc = $captured[ 'doc' ] ?? [] ;
        $this->assertSame( 6 , $doc[ User::LOGINS_COUNT ] ?? null , 'counter must increment' ) ;
        $this->assertArrayHasKey( User::LAST_LOGIN , $doc ) ;

        // An already-activated user must NOT be re-activated.
        $this->assertArrayNotHasKey( User::ACTIVATED         , $doc ) ;
        $this->assertArrayNotHasKey( User::FIRST_LOGIN_AT    , $doc ) ;
        $this->assertArrayNotHasKey( User::INVITATION_STATUS , $doc ) ;
    }

    public function testRecordSuccessfulLoginActivatesOnFirstLogin() :void
    {
        // activated absent → first login. No invitations model → the
        // invitation bookkeeping is skipped (covered separately).
        $user = new stdClass() ;

        $captured = [] ;
        $users    = $this->createUsersGetUpdateMock( $user , $captured ) ;

        $fixture = $this->createFixture( null , $users ) ;

        $fixture->recordSuccessfulLogin( '42' ) ;

        $doc = $captured[ 'doc' ] ?? [] ;
        $this->assertSame( 1 , $doc[ User::LOGINS_COUNT ] ?? null , 'first login starts the counter at 1' ) ;
        $this->assertTrue( $doc[ User::ACTIVATED ] ?? null ) ;
        $this->assertArrayHasKey( User::FIRST_LOGIN_AT , $doc ) ;
        $this->assertSame( InvitationStatus::ACCEPTED , $doc[ User::INVITATION_STATUS ] ?? null ) ;
    }

    public function testRecordSuccessfulLoginSwallowsUpdateFailure() :void
    {
        $user            = new stdClass() ;
        $user->activated = true ;

        $users = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' , 'update' ])
            ->getMock() ;
        $users->method( 'get' )->willReturn( $user ) ;
        $users->method( 'update' )->willThrowException( new \RuntimeException( 'write conflict' ) ) ;

        $fixture = $this->createFixture( null , $users ) ;

        // A race / write failure must not propagate.
        $fixture->recordSuccessfulLogin( '42' ) ;

        $this->expectNotToPerformAssertions() ;
    }

    // =========================================================================
    // markInvitationAccepted (reached via first-login recordSuccessfulLogin)
    // =========================================================================

    public function testFirstLoginMarksPendingInvitationAccepted() :void
    {
        $user = new stdClass() ; // activated absent → first login

        $userCaptured = [] ;
        $users        = $this->createUsersGetUpdateMock( $user , $userCaptured ) ;

        $invitation       = new stdClass() ;
        $invitation->_key = 'inv-1' ;

        $invCaptured = [] ;
        $invitations = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' , 'update' ])
            ->getMock() ;
        $invitations->expects( $this->once() )->method( 'list' )->with( $this->callback
        (
            fn( array $args ) :bool => ( $args[ 'binds' ][ 'invitationUserKey' ] ?? null ) === '42'
        ))->willReturn( [ $invitation ] ) ;
        $invitations->method( 'update' )->willReturnCallback( function( array $args ) use ( &$invCaptured )
        {
            $invCaptured = $args ;
            return null ;
        } ) ;

        $fixture = $this->createFixture( null , $users ) ;
        $fixture->invitationsModel = $invitations ;

        $fixture->recordSuccessfulLogin( '42' ) ;

        $this->assertSame( 'inv-1' , $invCaptured[ 'value' ] ?? null ) ;
        $this->assertSame( Invitation::ACTION_STATUS_ACCEPTED , $invCaptured[ 'doc' ][ Schema::ACTION_STATUS ] ?? null ) ;
    }

    public function testFirstLoginWithNoPendingInvitationDoesNotUpdate() :void
    {
        $user = new stdClass() ;

        $userCaptured = [] ;
        $users        = $this->createUsersGetUpdateMock( $user , $userCaptured ) ;

        $invitations = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' , 'update' ])
            ->getMock() ;
        $invitations->method( 'list' )->willReturn( [] ) ;
        // No pending invitation → update must never be called (this mock
        // expectation is the assertion of the test).
        $invitations->expects( $this->never() )->method( 'update' ) ;

        $fixture = $this->createFixture( null , $users ) ;
        $fixture->invitationsModel = $invitations ;

        $fixture->recordSuccessfulLogin( '42' ) ;
    }

    public function testFirstLoginInvitationLookupFailureIsSwallowed() :void
    {
        $user = new stdClass() ;

        $userCaptured = [] ;
        $users        = $this->createUsersGetUpdateMock( $user , $userCaptured ) ;

        $invitations = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' , 'update' ])
            ->getMock() ;
        $invitations->method( 'list' )->willThrowException( new \RuntimeException( 'arango down' ) ) ;

        $fixture = $this->createFixture( null , $users ) ;
        $fixture->invitationsModel = $invitations ;

        // Invitation bookkeeping must never break the login flow.
        $fixture->recordSuccessfulLogin( '42' ) ;

        $this->expectNotToPerformAssertions() ;
    }
}
