<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelEndpoint;
use oihana\zitadel\schema\constants\Zitadel;
use oihana\zitadel\traits\client\ZitadelClientUserTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientUserTrait::createUser()} payload against the
 * Zitadel v2 User API contract (`POST /v2/users/human`).
 *
 * Key shape differences vs the legacy v1 `_import` endpoint :
 *
 * - top-level `username` (lowercase) instead of `userName`
 * - `email.address` instead of `email.email`
 * - `email.isVerified` instead of `email.isEmailVerified`
 * - no password block at all — the v2 endpoint creates a credential-less
 *   user without putting it in INITIAL state, so Zitadel does not send any
 *   init/verification mail
 *
 * The v2 endpoint is the cornerstone of the "no parasite Zitadel mail"
 * invariant : a wrong field name silently fails with a 400 and the user
 * vertex would land in Arango without an `identifier`, breaking login +
 * Casbin keying for that account.
 */
#[CoversTrait( ZitadelClientUserTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientUserTraitTest extends TestCase
{
    /**
     * Builds a ZitadelClient mock that captures the body passed to the
     * underlying protected request() method.
     *
     * @param array<string,mixed> $captured Reference to receive the captured payload.
     */
    private function createCapturingClient( array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'request' ])
            ->getMock() ;

        $client->expects( $this->any() )
            ->method( 'request' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$captured )
                {
                    $captured =
                    [
                        'method' => $method ,
                        'path'   => $path ,
                        'body'   => $body ,
                    ] ;

                    return (object) [ 'userId' => 'zitadel-fake-id' ] ;
                }
            ) ;

        return $client ;
    }

    public function testCreateUserHitsTheV2Endpoint() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'alice@test.com' , 'Alice' , 'Bee' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( ZitadelEndpoint::USERS_HUMAN_V2 , $captured[ 'path' ] ) ;
        $this->assertNotSame( ZitadelEndpoint::USERS_HUMAN_IMPORT , $captured[ 'path' ] , 'must NOT use the legacy v1 import endpoint' ) ;
    }

    public function testCreateUserUsesV2UsernameFieldNotV1UserName() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'alice@test.com' , 'Alice' , 'Bee' ) ;

        $this->assertArrayHasKey   ( Zitadel::USERNAME  , $captured[ 'body' ] , 'v2 expects lowercase `username`' ) ;
        $this->assertArrayNotHasKey( Zitadel::USER_NAME , $captured[ 'body' ] , 'must NOT send the v1 camelCase `userName`' ) ;
        $this->assertSame( 'alice@test.com' , $captured[ 'body' ][ Zitadel::USERNAME ] ) ;
    }

    public function testCreateUserUsesV2EmailShape() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'alice@test.com' , 'Alice' , 'Bee' , isEmailVerified: true ) ;

        $email = $captured[ 'body' ][ Zitadel::EMAIL ] ;

        // v2 keeps the inner `email` key for the address (the "address"
        // name from some doc pages is misleading — the proto field is
        // `email`) but renames the verified flag to `isVerified`. The v1
        // `isEmailVerified` must not leak into the v2 payload.
        $this->assertArrayHasKey   ( Zitadel::EMAIL             , $email , 'inner `email` key must hold the address' ) ;
        $this->assertArrayHasKey   ( Zitadel::IS_VERIFIED       , $email ) ;
        $this->assertArrayNotHasKey( Zitadel::IS_EMAIL_VERIFIED , $email , 'must NOT use the v1 `isEmailVerified`' ) ;
        $this->assertSame( 'alice@test.com' , $email[ Zitadel::EMAIL ] ) ;
        $this->assertTrue( $email[ Zitadel::IS_VERIFIED ] ) ;
    }

    public function testCreateUserDefaultsIsVerifiedToFalse() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'bob@test.com' , 'Bob' , 'Cee' ) ;

        $this->assertFalse( $captured[ 'body' ][ Zitadel::EMAIL ][ Zitadel::IS_VERIFIED ] ) ;
    }

    public function testCreateUserNeverSendsAPassword() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'carol@test.com' , 'Carol' , 'Dee' , isEmailVerified: true ) ;

        // The v2 contract creates the user credential-less when no password
        // is sent, which keeps Zitadel silent on init/verification mails —
        // the whole point of the v2 migration. Sending an unsolicited
        // password block would re-introduce the very behaviour we removed.
        $this->assertArrayNotHasKey( Zitadel::PASSWORD , $captured[ 'body' ] ) ;
        $this->assertArrayNotHasKey( Zitadel::PASSWORD_CHANGE_REQUIRED , $captured[ 'body' ] ) ;
    }

    public function testCreateUserProfileShape() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'eve@test.com' , 'Eve' , 'Eff' ) ;

        $profile = $captured[ 'body' ][ Zitadel::PROFILE ] ;

        // v2 uses Schema.org-flavoured `givenName` / `familyName` ; the v1
        // `firstName` / `lastName` fail proto validation with
        // `invalid SetHumanProfile.GivenName: value length must be …`.
        $this->assertArrayHasKey   ( Zitadel::GIVEN_NAME  , $profile ) ;
        $this->assertArrayHasKey   ( Zitadel::FAMILY_NAME , $profile ) ;
        $this->assertArrayNotHasKey( Zitadel::FIRST_NAME  , $profile , 'must NOT use the v1 `firstName`' ) ;
        $this->assertArrayNotHasKey( Zitadel::LAST_NAME   , $profile , 'must NOT use the v1 `lastName`'  ) ;

        $this->assertSame( 'Eve'      , $profile[ Zitadel::GIVEN_NAME   ] ) ;
        $this->assertSame( 'Eff'      , $profile[ Zitadel::FAMILY_NAME  ] ) ;
        $this->assertSame( 'Eve Eff'  , $profile[ Zitadel::DISPLAY_NAME ] ) ;
    }

    public function testCreateUserNormalizesEmailToLowercase() :void
    {
        $captured = [] ;
        $client   = $this->createCapturingClient( $captured ) ;

        $client->createUser( 'Dave@TEST.COM' , 'Dave' , 'Eff' ) ;

        // Both the username slot and the inner email address are lowercased.
        $this->assertSame( 'dave@test.com' , $captured[ 'body' ][ Zitadel::USERNAME ] ) ;
        $this->assertSame( 'dave@test.com' , $captured[ 'body' ][ Zitadel::EMAIL ][ Zitadel::EMAIL ] ) ;
    }

    // =========================================================================
    // U3 — setEmail / setUsername / verifyEmail
    // =========================================================================

    /**
     * Builds a ZitadelClient mock that captures the body passed to the
     * underlying protected requestRaw() method (the U2/U3 flow uses the
     * structured-response variant rather than request()).
     */
    private function createRequestRawCapturingClient( array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client->expects( $this->any() )
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$captured )
                {
                    $captured =
                    [
                        'method' => $method ,
                        'path'   => $path ,
                        'body'   => $body ,
                    ] ;

                    return [ 'success' => true , 'status' => 200 , 'body' => null , 'rawBody' => '' , 'error' => null ] ;
                }
            ) ;

        return $client ;
    }

    public function testSetEmailHitsDedicatedV2EmailEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setEmail( 'zitadel-user-id' , 'new@example.com' ) ;

        // Dedicated SetEmail endpoint — NOT the unified UpdateUser
        // (`PATCH /v2/users/{id}`). UpdateUser silently swallows the
        // verification discriminator on `human.email.{...}` and
        // returns 200 OK without a verificationCode in the body.
        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/zitadel-user-id/email' , $captured[ 'path' ] ) ;
    }

    public function testSetEmailUsesFlatBodyWithReturnCode() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setEmail( 'uid' , 'new@example.com' ) ;

        // SetEmailRequest proto: flat `email` + `returnCode` discriminator.
        // The proto `oneof verification` is the discriminator name, NOT a
        // JSON wrapper — `verification: { returnCode: {} }` would be
        // silently dropped by Zitadel.
        $body = $captured[ 'body' ] ?? null ;
        $this->assertIsArray( $body ) ;
        $this->assertSame( 'new@example.com' , $body[ Zitadel::EMAIL ] ?? null ) ;
        $this->assertArrayHasKey( Zitadel::RETURN_CODE , $body ) ;

        // Defensive: ensure none of the unified-UpdateUser keys leak in.
        $this->assertArrayNotHasKey( Zitadel::HUMAN , $body , 'must NOT wrap under `human` — that is the UpdateUser shape, not SetEmail' ) ;
        $this->assertArrayNotHasKey( Zitadel::VERIFICATION , $body , 'must NOT wrap returnCode under `verification` (proto oneof name, not a JSON key)' ) ;
    }

    public function testSetEmailNormalizesAddressToLowercase() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setEmail( 'uid' , 'Admin@Example.COM' ) ;

        $this->assertSame( 'admin@example.com' , $captured[ 'body' ][ Zitadel::EMAIL ] ) ;
    }

    public function testSetEmailDoesNotTouchUsernameOrProfile() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setEmail( 'uid' , 'new@example.com' ) ;

        // The dedicated SetEmail endpoint cannot touch username or
        // profile by construction — its proto contract has no such
        // fields. The assertion documents the invariant: a wrong
        // setEmail() invocation can never wipe the login key or the
        // profile, even if the underlying contract evolves.
        $this->assertArrayNotHasKey( Zitadel::USERNAME , $captured[ 'body' ] ) ;
        $this->assertArrayNotHasKey( Zitadel::PROFILE  , $captured[ 'body' ] ) ;
    }

    public function testSetUsernameHitsTheV2UpdateEndpointWithPatch() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setUsername( 'uid' , 'new@example.com' ) ;

        $this->assertSame( 'PATCH' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid' , $captured[ 'path' ] ) ;
    }

    public function testSetUsernamePutsItAtTopLevelLowercased() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->setUsername( 'uid' , 'Admin@Example.COM' ) ;

        // Top-level username — V2 does NOT nest username under `human`.
        $this->assertSame( 'admin@example.com' , $captured[ 'body' ][ Zitadel::USERNAME ] ) ;
        $this->assertArrayNotHasKey( Zitadel::HUMAN , $captured[ 'body' ] ) ;
    }

    public function testVerifyEmailHitsDedicatedV2EmailVerifyEndpoint() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->verifyEmail( 'uid' , 'XXXXXX' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid/email/verify' , $captured[ 'path' ] ) ;
    }

    public function testVerifyEmailSendsTheVerificationCodeFieldOnly() :void
    {
        $captured = [] ;
        $client   = $this->createRequestRawCapturingClient( $captured ) ;

        $client->verifyEmail( 'uid' , 'XXXXXX' ) ;

        // The dedicated verify endpoint expects exactly one field — a
        // top-level `verificationCode`. No nesting under `human`, no
        // `email` block. A wrong shape would silently 400 in Zitadel.
        $this->assertSame( [ Zitadel::VERIFICATION_CODE => 'XXXXXX' ] , $captured[ 'body' ] ) ;
    }

    // =========================================================================
    // lockUser / unlockUser — V2 lock dance for refresh-token invalidation
    // =========================================================================

    /**
     * Builds a ZitadelClient mock that captures the triplet passed to
     * the underlying protected requestRaw() method.
     *
     * Distinct from {@see createCapturingClient()} because lockUser /
     * unlockUser route through `requestRaw` (to keep the structured
     * status/body distinction the caller needs), not `request`.
     *
     * @param array<string,mixed> $captured Reference to receive the captured call.
     */
    private function createRawCapturingClient( array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client->expects( $this->any() )
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$captured )
                {
                    $captured =
                    [
                        'method' => $method ,
                        'path'   => $path ,
                        'body'   => $body ,
                    ] ;

                    return
                    [
                        'success' => true  ,
                        'status'  => 200   ,
                        'body'    => null  ,
                        'rawBody' => ''    ,
                        'error'   => null  ,
                    ] ;
                }
            ) ;

        return $client ;
    }

    public function testLockUserHitsTheV2Endpoint() :void
    {
        $captured = [] ;
        $client   = $this->createRawCapturingClient( $captured ) ;

        $client->lockUser( 'zitadel-user-123' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/zitadel-user-123/lock' , $captured[ 'path' ] ) ;
        $this->assertNull( $captured[ 'body' ] , 'lockUser must send no body' ) ;
    }

    public function testUnlockUserHitsTheV2Endpoint() :void
    {
        $captured = [] ;
        $client   = $this->createRawCapturingClient( $captured ) ;

        $client->unlockUser( 'zitadel-user-123' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/zitadel-user-123/unlock' , $captured[ 'path' ] ) ;
        $this->assertNull( $captured[ 'body' ] , 'unlockUser must send no body' ) ;
    }

    public function testLockUserPropagatesRequestRawResult() :void
    {
        // Covers the 409 "already locked" idempotent case the caller
        // (UserTokensRevokerTrait) must be able to distinguish from a
        // genuine failure to retry. lockUser must surface the
        // requestRaw() result verbatim — no swallowing, no rewriting.
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $expected =
        [
            'success' => false ,
            'status'  => 409   ,
            'body'    => (object) [ 'message' => 'user is already locked' ] ,
            'rawBody' => '{"message":"user is already locked"}' ,
            'error'   => null  ,
        ] ;

        $client->expects( $this->once() )
            ->method( 'requestRaw' )
            ->willReturn( $expected ) ;

        $this->assertSame( $expected , $client->lockUser( 'u' ) ) ;
    }
}
