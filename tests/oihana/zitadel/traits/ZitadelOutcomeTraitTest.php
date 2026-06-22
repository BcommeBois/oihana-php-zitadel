<?php

namespace tests\oihana\zitadel\traits;

use oihana\zitadel\enums\ZitadelOutput;
use oihana\zitadel\traits\ZitadelOutcomeTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelOutcomeTrait::extractZitadelMessage()} — the thin
 * accessor that forwards Zitadel's own `message` verbatim when (and only
 * when) the structured result carries a non-empty string message on an
 * object body.
 */
#[CoversTrait( ZitadelOutcomeTrait::class )]
class ZitadelOutcomeTraitTest extends TestCase
{
    /**
     * Exposes the protected method on an anonymous host class.
     */
    private function host() :object
    {
        return new class
        {
            use ZitadelOutcomeTrait ;

            /** @param array<string,mixed> $result */
            public function call( array $result ) :?string
            {
                return $this->extractZitadelMessage( $result ) ;
            }
        } ;
    }

    public function testReturnsTheMessageWhenBodyIsAnObjectWithNonEmptyMessage() :void
    {
        $result = [ ZitadelOutput::BODY => (object) [ 'message' => 'Code is expired (CODE-QvUQ4P)' ] ] ;

        $this->assertSame( 'Code is expired (CODE-QvUQ4P)' , $this->host()->call( $result ) ) ;
    }

    public function testReturnsNullWhenMessageIsAnEmptyString() :void
    {
        $result = [ ZitadelOutput::BODY => (object) [ 'message' => '' ] ] ;

        $this->assertNull( $this->host()->call( $result ) ) ;
    }

    public function testReturnsNullWhenObjectHasNoMessageProperty() :void
    {
        $result = [ ZitadelOutput::BODY => (object) [ 'code' => 9 ] ] ;

        $this->assertNull( $this->host()->call( $result ) ) ;
    }

    public function testReturnsNullWhenMessageIsNotAString() :void
    {
        $result = [ ZitadelOutput::BODY => (object) [ 'message' => [ 'nested' => true ] ] ] ;

        $this->assertNull( $this->host()->call( $result ) ) ;
    }

    public function testReturnsNullWhenBodyIsNotAnObject() :void
    {
        $result = [ ZitadelOutput::BODY => [ 'message' => 'array not object' ] ] ;

        $this->assertNull( $this->host()->call( $result ) ) ;
    }

    public function testReturnsNullWhenBodyIsNull() :void
    {
        $this->assertNull( $this->host()->call( [ ZitadelOutput::BODY => null ] ) ) ;
    }

    public function testReturnsNullWhenBodyKeyIsAbsent() :void
    {
        $this->assertNull( $this->host()->call( [] ) ) ;
    }
}
