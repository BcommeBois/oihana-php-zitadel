<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\traits\client\ZitadelClientApplicationTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientApplicationTrait} contract against the
 * Zitadel "Project Apps" surface (project-name resolution, app listing,
 * clientId lookup, app deletion).
 *
 * All endpoints are project-scoped, so the mock uses the real constructor
 * to populate `$projectId`.
 */
#[CoversTrait( ZitadelClientApplicationTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientApplicationTraitTest extends TestCase
{
    private const string PROJECT_ID = 'project-1' ;

    /**
     * Builds a ZitadelClient (real constructor) capturing every request()
     * call and returning a customizable value.
     *
     * @param array<int,array{method:string,path:string,body:mixed}> $calls
     * @param callable|null $responder function( string $method , string $path , mixed $body ): mixed
     */
    private function createClient( array &$calls , ?callable $responder = null ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->setConstructorArgs([ 'https://issuer.example' , self::PROJECT_ID , [] ])
            ->onlyMethods([ 'request' ])
            ->getMock() ;

        $client->expects( $this->any() )
            ->method( 'request' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$calls , $responder )
                {
                    $calls[] = [ 'method' => $method , 'path' => $path , 'body' => $body ] ;
                    return $responder !== null ? $responder( $method , $path , $body ) : null ;
                }
            ) ;

        return $client ;
    }

    // =========================================================================
    // getProjectName()
    // =========================================================================

    public function testGetProjectNameHitsTheProjectEndpointWithGet() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'project' => (object) [ 'name' => 'My Project' ] ] ) ;

        $client->getProjectName() ;

        $this->assertSame( 'GET' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testGetProjectNameReturnsTheName() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'project' => (object) [ 'name' => 'My Project' ] ] ) ;

        $this->assertSame( 'My Project' , $client->getProjectName() ) ;
    }

    public function testGetProjectNameMemoizesAfterFirstResolution() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'project' => (object) [ 'name' => 'Memo' ] ] ) ;

        $first  = $client->getProjectName() ;
        $second = $client->getProjectName() ;

        $this->assertSame( 'Memo' , $first ) ;
        $this->assertSame( $first , $second ) ;
        $this->assertCount( 1 , $calls , 'project name must be resolved at most once per client lifetime' ) ;
    }

    public function testGetProjectNameReturnsNullWhenRequestFails() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => null ) ;

        $this->assertNull( $client->getProjectName() ) ;
    }

    public function testGetProjectNameReturnsNullWhenNameMissing() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'project' => (object) [ 'id' => 'x' ] ] ) ;

        $this->assertNull( $client->getProjectName() ) ;
    }

    // =========================================================================
    // deleteApplication()
    // =========================================================================

    public function testDeleteApplicationHitsTheAppByIdEndpointWithDelete() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [] ) ;

        $client->deleteApplication( 'app-9' ) ;

        $this->assertSame( 'DELETE' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/apps/app-9' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testDeleteApplicationReturnsTrueOnSuccess() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [] ) ;

        $this->assertTrue( $client->deleteApplication( 'app-9' ) ) ;
    }

    public function testDeleteApplicationReturnsFalseOnFailure() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => null ) ;

        $this->assertFalse( $client->deleteApplication( 'app-9' ) ) ;
    }

    // =========================================================================
    // searchApplications()
    // =========================================================================

    public function testSearchApplicationsHitsTheAppsSearchEndpointWithPost() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => [] ] ) ;

        $client->searchApplications() ;

        $this->assertSame( 'POST' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/projects/project-1/apps/_search' , $calls[ 0 ][ 'path' ] ) ;
        $this->assertEquals( (object) [] , $calls[ 0 ][ 'body' ] ) ;
    }

    public function testSearchApplicationsReturnsTheResultArray() :void
    {
        $apps   = [ (object) [ 'id' => 'a1' ] , (object) [ 'id' => 'a2' ] ] ;
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => $apps ] ) ;

        $this->assertSame( $apps , $client->searchApplications() ) ;
    }

    public function testSearchApplicationsReturnsEmptyArrayWhenRequestFails() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => null ) ;

        $this->assertSame( [] , $client->searchApplications() ) ;
    }

    public function testSearchApplicationsReturnsEmptyArrayWhenResultMissing() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'details' => (object) [] ] ) ;

        $this->assertSame( [] , $client->searchApplications() ) ;
    }

    public function testSearchApplicationsReturnsEmptyArrayWhenResultIsNotAnArray() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => 'oops' ] ) ;

        $this->assertSame( [] , $client->searchApplications() ) ;
    }

    // =========================================================================
    // findApplicationByClientId()
    // =========================================================================

    public function testFindApplicationByClientIdMatchesOnOidcConfig() :void
    {
        $apps =
        [
            (object) [ 'id' => 'a1' , 'name' => 'Web' , 'state' => ZitadelClient::APP_STATE_ACTIVE ,
                       'oidcConfig' => (object) [ 'clientId' => 'oidc-123' ] ] ,
        ] ;
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => $apps ] ) ;

        $found = $client->findApplicationByClientId( 'oidc-123' ) ;

        $this->assertNotNull( $found ) ;
        $this->assertSame( 'a1'       , $found->appId ) ;
        $this->assertSame( 'oidc-123' , $found->clientId ) ;
        $this->assertSame( 'Web'      , $found->name ) ;
        $this->assertNull( $found->description ) ;
        $this->assertTrue( $found->active ) ;
    }

    public function testFindApplicationByClientIdMatchesOnApiConfig() :void
    {
        $apps =
        [
            (object) [ 'id' => 'a2' , 'name' => 'M2M' , 'state' => 'APP_STATE_INACTIVE' ,
                       'apiConfig' => (object) [ 'clientId' => 'api-456' ] ] ,
        ] ;
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => $apps ] ) ;

        $found = $client->findApplicationByClientId( 'api-456' ) ;

        $this->assertNotNull( $found ) ;
        $this->assertSame( 'a2' , $found->appId ) ;
        $this->assertFalse( $found->active , 'non-active state must map to active=false' ) ;
    }

    public function testFindApplicationByClientIdReturnsNullWhenNoMatch() :void
    {
        $apps =
        [
            (object) [ 'id' => 'a1' , 'oidcConfig' => (object) [ 'clientId' => 'other' ] ] ,
        ] ;
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => (object) [ 'result' => $apps ] ) ;

        $this->assertNull( $client->findApplicationByClientId( 'missing' ) ) ;
    }

    public function testFindApplicationByClientIdReturnsNullWhenListIsEmpty() :void
    {
        $calls  = [] ;
        $client = $this->createClient( $calls , fn() => null ) ;

        $this->assertNull( $client->findApplicationByClientId( 'whatever' ) ) ;
    }
}
