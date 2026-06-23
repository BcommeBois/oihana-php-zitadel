<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\traits\client\ZitadelClientPasswordTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientPasswordTrait} contract against the Zitadel
 * v2 password endpoints (`/v2/users/{id}/password_reset` and
 * `/v2/users/{id}/password`).
 *
 * Two distinct return conventions coexist here, and the tests pin both :
 *
 * - `requestPasswordResetCode()` / `sendPasswordResetLink()` use the
 *   collapsed {@see request()} variant (object|null) — convenient for the
 *   invitation / reset-link flows that only need the code or a boolean ack.
 * - `setPasswordWith*()` use the structured {@see requestRaw()} variant so
 *   the controller can map "wrong current password" / "invalid code" /
 *   "policy violation" to its own HTTP contract.
 */
#[CoversTrait( ZitadelClientPasswordTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientPasswordTraitTest extends TestCase
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
     * Mock whose protected requestRaw() returns a generic 200 OK and
     * captures the call triplet.
     *
     * @param array<string,mixed> $captured
     */
    private function createRawClient( array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$captured )
                {
                    $captured = [ 'method' => $method , 'path' => $path , 'body' => $body ] ;
                    return [ 'success' => true , 'status' => 200 , 'body' => null , 'rawBody' => '' , 'error' => null ] ;
                }
            ) ;

        return $client ;
    }

    // =========================================================================
    // requestPasswordResetCode()
    // =========================================================================

    public function testRequestPasswordResetCodeHitsThePasswordResetEndpointWithReturnCode() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'verificationCode' => 'CODE-123' ] , $captured ) ;

        $client->requestPasswordResetCode( 'uid-1' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid-1/password_reset' , $captured[ 'path' ] ) ;
        $this->assertArrayHasKey( 'returnCode' , $captured[ 'body' ] ) ;
        $this->assertEquals( (object) [] , $captured[ 'body' ][ 'returnCode' ] ) ;
    }

    public function testRequestPasswordResetCodeReturnsThePlaintextCode() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'verificationCode' => 'CODE-XYZ' ] , $captured ) ;

        $this->assertSame( 'CODE-XYZ' , $client->requestPasswordResetCode( 'uid-1' ) ) ;
    }

    public function testRequestPasswordResetCodeReturnsNullWhenCodeMissing() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'details' => (object) [] ] , $captured ) ;

        $this->assertNull( $client->requestPasswordResetCode( 'uid-1' ) ) ;
    }

    public function testRequestPasswordResetCodeReturnsNullWhenRequestFails() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( null , $captured ) ;

        $this->assertNull( $client->requestPasswordResetCode( 'uid-1' ) ) ;
    }

    // =========================================================================
    // sendPasswordResetLink()
    // =========================================================================

    public function testSendPasswordResetLinkHitsThePasswordResetEndpointWithSendLink() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [] , $captured ) ;

        $client->sendPasswordResetLink( 'uid-2' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid-2/password_reset' , $captured[ 'path' ] ) ;
        $this->assertArrayHasKey( 'sendLink' , $captured[ 'body' ] ) ;
    }

    public function testSendPasswordResetLinkOmitsUrlTemplateWhenNull() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [] , $captured ) ;

        $client->sendPasswordResetLink( 'uid-2' ) ;

        // No urlTemplate → empty sendLink object → Zitadel falls back to its
        // instance-wide configuration.
        $this->assertEquals( (object) [] , $captured[ 'body' ][ 'sendLink' ] ) ;
    }

    public function testSendPasswordResetLinkForwardsUrlTemplateWhenProvided() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [] , $captured ) ;

        $client->sendPasswordResetLink( 'uid-2' , 'https://app.example.com/reset?code={{.Code}}' ) ;

        $this->assertEquals
        (
            (object) [ 'urlTemplate' => 'https://app.example.com/reset?code={{.Code}}' ] ,
            $captured[ 'body' ][ 'sendLink' ]
        ) ;
    }

    public function testSendPasswordResetLinkReturnsTrueWhenAcknowledged() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( (object) [ 'details' => (object) [] ] , $captured ) ;

        $this->assertTrue( $client->sendPasswordResetLink( 'uid-2' ) ) ;
    }

    public function testSendPasswordResetLinkReturnsFalseWhenRequestFails() :void
    {
        $captured = [] ;
        $client   = $this->createRequestClient( null , $captured ) ;

        $this->assertFalse( $client->sendPasswordResetLink( 'uid-2' ) ) ;
    }

    // =========================================================================
    // setPasswordWithCurrentPassword()
    // =========================================================================

    public function testSetPasswordWithCurrentPasswordHitsThePasswordEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->setPasswordWithCurrentPassword( 'uid-3' , 'old-pw' , 'new-pw' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid-3/password' , $captured[ 'path' ] ) ;
    }

    public function testSetPasswordWithCurrentPasswordBodyShape() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->setPasswordWithCurrentPassword( 'uid-3' , 'old-pw' , 'new-pw' ) ;

        $body = $captured[ 'body' ] ;
        $this->assertSame( [ 'password' => 'new-pw' ] , $body[ 'newPassword' ] , 'newPassword is nested as { password }' ) ;
        $this->assertSame( 'old-pw' , $body[ 'currentPassword' ] ) ;
        $this->assertArrayNotHasKey( 'verificationCode' , $body , 'self-service change must not carry a verification code' ) ;
    }

    public function testSetPasswordWithCurrentPasswordReturnsTheRawResult() :void
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $expected = [ 'success' => false , 'status' => 400 , 'body' => null , 'rawBody' => '{"message":"Password is invalid (COMMAND-x)"}' , 'error' => 'http_error' ] ;

        $client->expects( $this->once() )->method( 'requestRaw' )->willReturn( $expected ) ;

        $this->assertSame( $expected , $client->setPasswordWithCurrentPassword( 'u' , 'a' , 'b' ) ) ;
    }

    // =========================================================================
    // setPasswordWithVerificationCode()
    // =========================================================================

    public function testSetPasswordWithVerificationCodeHitsThePasswordEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->setPasswordWithVerificationCode( 'uid-4' , 'CODE-9' , 'new-pw' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/v2/users/uid-4/password' , $captured[ 'path' ] ) ;
    }

    public function testSetPasswordWithVerificationCodeBodyShape() :void
    {
        $captured = [] ;
        $client   = $this->createRawClient( $captured ) ;

        $client->setPasswordWithVerificationCode( 'uid-4' , 'CODE-9' , 'new-pw' ) ;

        $body = $captured[ 'body' ] ;
        $this->assertSame( [ 'password' => 'new-pw' ] , $body[ 'newPassword' ] ) ;
        $this->assertSame( 'CODE-9' , $body[ 'verificationCode' ] ) ;
        $this->assertArrayNotHasKey( 'currentPassword' , $body , 'code-based reset must not carry a current password' ) ;
    }
}
