<?php

namespace tests\oihana\zitadel\helpers;

use oihana\zitadel\ZitadelClient;

use Psr\Container\ContainerInterface;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

use function oihana\zitadel\helpers\getZitadelClient;

/**
 * Tests for the {@see getZitadelClient()} DI resolver.
 *
 * The helper is the single entry-point used by commands / controllers /
 * traits to obtain a {@see ZitadelClient} from heterogeneous inputs (a
 * direct instance, an `init` array, a PSR-11 service name, or a default
 * fallback). Each resolution branch is pinned here so a wiring regression
 * surfaces without booting a real container.
 */
#[CoversFunction( 'oihana\zitadel\helpers\getZitadelClient' )]
class getZitadelClientTest extends TestCase
{
    /**
     * Builds a harmless ZitadelClient — the constructor only stores its
     * arguments, no I/O is performed, so a real instance is cheaper and
     * more faithful than a mock here.
     */
    private function makeClient() :ZitadelClient
    {
        return new ZitadelClient( 'https://issuer.example' , 'project-1' , [] ) ;
    }

    /**
     * Minimal PSR-11 container backed by a fixed map.
     *
     * @param array<string,mixed> $services
     */
    private function makeContainer( array $services ) :ContainerInterface
    {
        return new class( $services ) implements ContainerInterface
        {
            /** @param array<string,mixed> $services */
            public function __construct( private array $services ) {}

            public function get( string $id ) :mixed
            {
                return $this->services[ $id ] ;
            }

            public function has( string $id ) :bool
            {
                return array_key_exists( $id , $this->services ) ;
            }
        } ;
    }

    // =========================================================================
    // Direct instance
    // =========================================================================

    public function testReturnsTheInstanceAsIsWhenDefinitionIsAlreadyAClient() :void
    {
        $client = $this->makeClient() ;

        $this->assertSame( $client , getZitadelClient( $client ) ) ;
    }

    // =========================================================================
    // Array definition
    // =========================================================================

    public function testResolvesFromArrayUnderTheDefaultKey() :void
    {
        $client = $this->makeClient() ;

        $this->assertSame( $client , getZitadelClient( [ 'zitadelClient' => $client ] ) ) ;
    }

    public function testResolvesFromArrayUnderACustomKey() :void
    {
        $client = $this->makeClient() ;

        $this->assertSame
        (
            $client ,
            getZitadelClient( [ 'idp' => $client ] , null , 'idp' )
        ) ;
    }

    public function testReturnsDefaultWhenArrayKeyIsMissing() :void
    {
        $fallback = $this->makeClient() ;

        $this->assertSame
        (
            $fallback ,
            getZitadelClient( [ 'somethingElse' => 'x' ] , null , 'zitadelClient' , $fallback )
        ) ;
    }

    public function testReturnsNullWhenArrayKeyIsMissingAndNoDefault() :void
    {
        $this->assertNull( getZitadelClient( [ 'somethingElse' => 'x' ] ) ) ;
    }

    // =========================================================================
    // Container (string) definition
    // =========================================================================

    public function testResolvesAStringServiceNameFromTheContainer() :void
    {
        $client    = $this->makeClient() ;
        $container = $this->makeContainer( [ 'zitadel.client' => $client ] ) ;

        $this->assertSame( $client , getZitadelClient( 'zitadel.client' , $container ) ) ;
    }

    public function testResolvesTheStringFetchedFromAnArrayThroughTheContainer() :void
    {
        $client    = $this->makeClient() ;
        $container = $this->makeContainer( [ 'zitadel.client' => $client ] ) ;

        // Array carries a service *name* under the key, not the instance.
        $this->assertSame
        (
            $client ,
            getZitadelClient( [ 'zitadelClient' => 'zitadel.client' ] , $container )
        ) ;
    }

    public function testReturnsDefaultWhenContainerDoesNotHaveTheService() :void
    {
        $fallback  = $this->makeClient() ;
        $container = $this->makeContainer( [] ) ;

        $this->assertSame
        (
            $fallback ,
            getZitadelClient( 'missing.service' , $container , 'zitadelClient' , $fallback )
        ) ;
    }

    public function testReturnsDefaultWhenStringButNoContainerProvided() :void
    {
        $this->assertNull( getZitadelClient( 'zitadel.client' , null ) ) ;
    }

    public function testReturnsDefaultOnEmptyStringDefinition() :void
    {
        $container = $this->makeContainer( [ '' => $this->makeClient() ] ) ;

        // Empty string is rejected before the container lookup.
        $this->assertNull( getZitadelClient( '' , $container ) ) ;
    }

    public function testReturnsDefaultWhenServiceFromContainerIsNotAClient() :void
    {
        $fallback  = $this->makeClient() ;
        $container = $this->makeContainer( [ 'zitadel.client' => 'not-a-client' ] ) ;

        $this->assertSame
        (
            $fallback ,
            getZitadelClient( 'zitadel.client' , $container , 'zitadelClient' , $fallback )
        ) ;
    }

    // =========================================================================
    // Null / default
    // =========================================================================

    public function testReturnsDefaultWhenDefinitionIsNull() :void
    {
        $fallback = $this->makeClient() ;

        $this->assertSame( $fallback , getZitadelClient( null , null , 'zitadelClient' , $fallback ) ) ;
    }

    public function testReturnsNullByDefaultWhenNothingResolves() :void
    {
        $this->assertNull( getZitadelClient() ) ;
    }
}
