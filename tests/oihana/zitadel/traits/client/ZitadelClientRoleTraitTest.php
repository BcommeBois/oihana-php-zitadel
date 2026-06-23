<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\schema\constants\Zitadel;
use oihana\zitadel\traits\client\ZitadelClientRoleTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientRoleTrait} contract against the Zitadel
 * Management API role surface.
 *
 * Every endpoint is project-scoped, so the resolved paths must interpolate
 * the configured `$projectId` — the mock is therefore built with the real
 * constructor (`setConstructorArgs`) rather than a disabled one, so
 * `$this->projectId` is populated.
 */
#[CoversTrait( ZitadelClientRoleTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientRoleTraitTest extends TestCase
{
    private const string PROJECT_ID = 'project-1' ;

    /**
     * Builds a ZitadelClient (real constructor, so `$projectId` is set)
     * whose protected request() returns the supplied value and captures
     * the call triplet.
     *
     * @param array<string,mixed> $captured
     */
    private function createClient( mixed $return , array &$captured ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->setConstructorArgs([ 'https://issuer.example' , self::PROJECT_ID , [] ])
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

    // =========================================================================
    // createRole()
    // =========================================================================

    public function testCreateRoleHitsTheProjectScopedRolesEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->createRole( 'admin' , 'Administrator' ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/roles' , $captured[ 'path' ] ) ;
    }

    public function testCreateRoleBodyShapeAndDefaultGroup() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->createRole( 'admin' , 'Administrator' ) ;

        $body = $captured[ 'body' ] ;
        $this->assertSame( 'admin'         , $body[ Zitadel::ROLE_KEY ] ) ;
        $this->assertSame( 'Administrator' , $body[ Zitadel::DISPLAY_NAME ] ) ;
        $this->assertSame( 'app'           , $body[ Zitadel::GROUP ] , 'group defaults to "app"' ) ;
    }

    public function testCreateRoleForwardsExplicitGroup() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->createRole( 'admin' , 'Administrator' , 'backoffice' ) ;

        $this->assertSame( 'backoffice' , $captured[ 'body' ][ Zitadel::GROUP ] ) ;
    }

    // =========================================================================
    // deleteRole()
    // =========================================================================

    public function testDeleteRoleHitsTheRoleByKeyEndpointWithDelete() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->deleteRole( 'admin' ) ;

        $this->assertSame( 'DELETE' , $captured[ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/roles/admin' , $captured[ 'path' ] ) ;
    }

    // =========================================================================
    // updateRole()
    // =========================================================================

    public function testUpdateRoleHitsTheRoleByKeyEndpointWithPut() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->updateRole( 'admin' , 'Renamed' , 'ops' ) ;

        $this->assertSame( 'PUT' , $captured[ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/roles/admin' , $captured[ 'path' ] ) ;
    }

    public function testUpdateRoleSendsOnlyDisplayNameAndGroup() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->updateRole( 'admin' , 'Renamed' ) ;

        $body = $captured[ 'body' ] ;
        $this->assertSame( 'Renamed' , $body[ Zitadel::DISPLAY_NAME ] ) ;
        $this->assertSame( 'app'     , $body[ Zitadel::GROUP ] ) ;

        // The role key is immutable — it lives in the URL, never the body.
        $this->assertArrayNotHasKey( Zitadel::ROLE_KEY , $body ) ;
    }

    // =========================================================================
    // grantUserRoles()
    // =========================================================================

    public function testGrantUserRolesHitsTheUserGrantsEndpointWithPost() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->grantUserRoles( 'uid-7' , [ 'admin' , 'reader' ] ) ;

        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/management/v1/users/uid-7/grants' , $captured[ 'path' ] ) ;
    }

    public function testGrantUserRolesSendsProjectIdAndRoleKeys() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [] , $captured ) ;

        $client->grantUserRoles( 'uid-7' , [ 'admin' , 'reader' ] ) ;

        $body = $captured[ 'body' ] ;
        $this->assertSame( self::PROJECT_ID , $body[ Zitadel::PROJECT_ID ] ) ;
        $this->assertSame( [ 'admin' , 'reader' ] , $body[ Zitadel::ROLE_KEYS ] ) ;
    }

    // =========================================================================
    // listRoles()
    // =========================================================================

    public function testListRolesHitsTheRolesSearchEndpointAndReturnsResults() :void
    {
        $captured = [] ;
        $roles    = [ (object) [ 'key' => 'admin' ] , (object) [ 'key' => 'reader' ] ] ;
        $client   = $this->createClient( (object) [ 'result' => $roles ] , $captured ) ;

        $this->assertSame( $roles , $client->listRoles() ) ;
        $this->assertSame( 'POST' , $captured[ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/roles/_search' , $captured[ 'path' ] ) ;
        $this->assertSame( '0' , $captured[ 'body' ][ Zitadel::QUERY ][ Zitadel::OFFSET ] ) ;
        $this->assertSame( 100 , $captured[ 'body' ][ Zitadel::QUERY ][ Zitadel::LIMIT  ] ) ;
    }

    public function testListRolesReturnsEmptyArrayWhenResponseHasNoResult() :void
    {
        $captured = [] ;
        $client   = $this->createClient( (object) [ 'details' => (object) [] ] , $captured ) ;

        $this->assertSame( [] , $client->listRoles() ) ;
    }

    public function testListRolesReturnsEmptyArrayWhenRequestFails() :void
    {
        $captured = [] ;
        $client   = $this->createClient( null , $captured ) ;

        $this->assertSame( [] , $client->listRoles() ) ;
    }
}
