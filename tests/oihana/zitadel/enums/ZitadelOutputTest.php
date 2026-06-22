<?php

namespace tests\oihana\zitadel\enums;

use oihana\zitadel\enums\ZitadelError;
use oihana\zitadel\enums\ZitadelOutput;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelOutput} — the canonical structured-response
 * factory used by every Zitadel client call.
 *
 * The whole client surface branches on this exact shape, so the factories
 * must produce every documented key with the right sentinel defaults.
 */
#[CoversClass( ZitadelOutput::class )]
class ZitadelOutputTest extends TestCase
{
    // =========================================================================
    // success()
    // =========================================================================

    public function testSuccessProducesTheCanonicalSuccessShape() :void
    {
        $body   = (object) [ 'id' => 'user-1' ] ;
        $result = ZitadelOutput::success( 200 , $body , '{"id":"user-1"}' ) ;

        $this->assertSame
        (
            [
                ZitadelOutput::SUCCESS  => true ,
                ZitadelOutput::STATUS   => 200 ,
                ZitadelOutput::BODY     => $body ,
                ZitadelOutput::RAW_BODY => '{"id":"user-1"}' ,
                ZitadelOutput::ERROR    => ZitadelError::NONE ,
            ] ,
            $result
        ) ;
    }

    public function testSuccessErrorTagIsNull() :void
    {
        // ZitadelError::NONE is null — callers rely on `if ( $error )` checks.
        $this->assertNull( ZitadelOutput::success( 204 )[ ZitadelOutput::ERROR ] ) ;
    }

    public function testSuccessDefaultsBodyToNullAndRawBodyToEmptyString() :void
    {
        $result = ZitadelOutput::success( 204 ) ;

        $this->assertTrue( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 204 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertNull( $result[ ZitadelOutput::BODY ] ) ;
        $this->assertSame( '' , $result[ ZitadelOutput::RAW_BODY ] ) ;
    }

    // =========================================================================
    // failure()
    // =========================================================================

    public function testFailureProducesTheCanonicalFailureShape() :void
    {
        $body   = (object) [ 'message' => 'permission denied' ] ;
        $result = ZitadelOutput::failure( ZitadelError::HTTP_ERROR , 403 , $body , '{"message":"permission denied"}' ) ;

        $this->assertSame
        (
            [
                ZitadelOutput::SUCCESS  => false ,
                ZitadelOutput::STATUS   => 403 ,
                ZitadelOutput::BODY     => $body ,
                ZitadelOutput::RAW_BODY => '{"message":"permission denied"}' ,
                ZitadelOutput::ERROR    => ZitadelError::HTTP_ERROR ,
            ] ,
            $result
        ) ;
    }

    public function testFailureDefaultsStatusToZeroBodyToNullRawBodyToEmpty() :void
    {
        // Missing-token / transport failures: no response was received.
        $result = ZitadelOutput::failure( ZitadelError::NO_TOKEN ) ;

        $this->assertFalse( $result[ ZitadelOutput::SUCCESS ] ) ;
        $this->assertSame( 0 , $result[ ZitadelOutput::STATUS ] ) ;
        $this->assertNull( $result[ ZitadelOutput::BODY ] ) ;
        $this->assertSame( '' , $result[ ZitadelOutput::RAW_BODY ] ) ;
        $this->assertSame( ZitadelError::NO_TOKEN , $result[ ZitadelOutput::ERROR ] ) ;
    }

    // =========================================================================
    // Key constants
    // =========================================================================

    public function testKeyConstantsMatchTheWireFieldNames() :void
    {
        $this->assertSame( 'success' , ZitadelOutput::SUCCESS ) ;
        $this->assertSame( 'status'  , ZitadelOutput::STATUS ) ;
        $this->assertSame( 'body'    , ZitadelOutput::BODY ) ;
        $this->assertSame( 'rawBody' , ZitadelOutput::RAW_BODY ) ;
        $this->assertSame( 'error'   , ZitadelOutput::ERROR ) ;
    }
}
