<?php

namespace tests\oihana\zitadel\enums;

use oihana\zitadel\enums\ZitadelOutcome;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelOutcome} — the controller-facing outcome factory
 * that maps an interpreted Zitadel result onto an HTTP response contract.
 */
#[CoversClass( ZitadelOutcome::class )]
class ZitadelOutcomeTest extends TestCase
{
    public function testCreateProducesTheCanonicalOutcomeShape() :void
    {
        $outcome = ZitadelOutcome::create( 401 , 'WRONG_CURRENT_PASSWORD' , 'Current password is incorrect.' ) ;

        $this->assertSame
        (
            [
                ZitadelOutcome::CODE    => 401 ,
                ZitadelOutcome::MESSAGE => 'Current password is incorrect.' ,
                ZitadelOutcome::OUTCOME => 'WRONG_CURRENT_PASSWORD' ,
            ] ,
            $outcome
        ) ;
    }

    public function testCreateDefaultsMessageToNull() :void
    {
        // Success convention: 2xx code, no user-facing message.
        $outcome = ZitadelOutcome::create( 204 , 'SUCCESS' ) ;

        $this->assertSame( 204 , $outcome[ ZitadelOutcome::CODE ] ) ;
        $this->assertSame( 'SUCCESS' , $outcome[ ZitadelOutcome::OUTCOME ] ) ;
        $this->assertNull( $outcome[ ZitadelOutcome::MESSAGE ] ) ;
    }

    public function testKeyConstants() :void
    {
        $this->assertSame( 'code'    , ZitadelOutcome::CODE ) ;
        $this->assertSame( 'message' , ZitadelOutcome::MESSAGE ) ;
        $this->assertSame( 'outcome' , ZitadelOutcome::OUTCOME ) ;
    }
}
