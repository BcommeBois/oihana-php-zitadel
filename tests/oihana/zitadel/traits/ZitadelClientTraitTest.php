<?php

namespace tests\oihana\zitadel\traits;

use DI\Container;

use oihana\zitadel\traits\ZitadelClientTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelClientTrait} — the standalone DI trait that wires
 * a {@see ZitadelClient} dependency from an `init` array (delegating the
 * actual resolution to {@see \oihana\zitadel\helpers\getZitadelClient()}).
 */
#[CoversTrait( ZitadelClientTrait::class )]
class ZitadelClientTraitTest extends TestCase
{
    /**
     * Anonymous host exposing the protected initializer and the resolved
     * client for assertions.
     */
    private function host() :object
    {
        return new class
        {
            use ZitadelClientTrait ;

            public function init( array $init , ?Container $container ) :static
            {
                return $this->initializeZitadelClient( $init , $container ) ;
            }

            public function client() :?ZitadelClient
            {
                return $this->zitadelClient ;
            }
        } ;
    }

    private function makeClient() :ZitadelClient
    {
        return new ZitadelClient( 'https://issuer.example' , 'project-1' , [] ) ;
    }

    public function testInitializesTheClientFromTheInitArray() :void
    {
        $client = $this->makeClient() ;
        $host   = $this->host() ;

        // Trait constants are accessed through the using class, not the trait.
        $result = $host->init( [ $host::ZITADEL_CLIENT => $client ] , null ) ;

        $this->assertSame( $host , $result , 'initializer must be fluent (returns $this)' ) ;
        $this->assertSame( $client , $host->client() ) ;
    }

    public function testLeavesClientNullWhenInitArrayHasNoClient() :void
    {
        $host = $this->host() ;

        $host->init( [] , null ) ;

        $this->assertNull( $host->client() ) ;
    }

    public function testInitDefaultsToNullBeforeInitialization() :void
    {
        $this->assertNull( $this->host()->client() ) ;
    }

    public function testZitadelClientInitKeyConstant() :void
    {
        $host = $this->host() ;

        $this->assertSame( 'zitadelClient' , $host::ZITADEL_CLIENT ) ;
    }
}
