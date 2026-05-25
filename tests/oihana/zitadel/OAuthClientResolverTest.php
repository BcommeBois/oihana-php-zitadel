<?php

namespace tests\oihana\zitadel;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use xyz\oihana\schema\auth\OAuthClient;

use oihana\zitadel\OAuthClientResolver;
use oihana\zitadel\ZitadelClient;

use org\schema\constants\Schema;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the hybrid resolution strategy of {@see OAuthClientResolver}:
 * local-first (Arango), remote fallback (Zitadel Management API), and
 * negative caching (do not hammer Zitadel on unknown clientIds).
 */
#[CoversClass( OAuthClientResolver::class )]
#[AllowMockObjectsWithoutExpectations]
class OAuthClientResolverTest extends TestCase
{
    /**
     * A null clientId short-circuits the resolution path entirely.
     */
    public function testResolveReturnsNullForNullClientId() :void
    {
        $resolver = new OAuthClientResolver( $this->createDocumentsMock() ) ;

        $this->assertNull( $resolver->resolve( null ) ) ;
    }

    /**
     * A local hit returns the document name and never calls Zitadel.
     */
    public function testResolveReturnsNameFromLocalLookup() :void
    {
        $model   = $this->createDocumentsMock() ;
        $zitadel = $this->createZitadelMock() ;

        $document = (object) [ '_key' => 'k1' , 'name' => 'NextJS Web' ] ;

        $model->method( 'get' )->willReturn( $document ) ;
        $zitadel->method( 'findApplicationByClientId' )
                ->willReturnCallback( function() { throw new \RuntimeException( 'should not be called' ) ; } ) ;

        $resolver = new OAuthClientResolver( $model , $zitadel ) ;

        $this->assertSame( 'NextJS Web' , $resolver->resolve( 'client-1' ) ) ;
    }

    /**
     * A local miss must fall back to Zitadel, upsert the result locally and
     * return the freshly resolved name.
     */
    public function testResolveFallsBackToZitadelAndUpserts() :void
    {
        $inserted = [] ;

        $model   = $this->createDocumentsMock() ;
        $zitadel = $this->createZitadelMock() ;

        $model->method( 'get' )->willReturn( null ) ;
        $model->method( 'insert' )->willReturnCallback( function( array $params ) use ( &$inserted )
        {
            $inserted[] = $params[ Arango::DOC ] ?? [] ;
            return null ;
        } ) ;

        $zitadel->method( 'findApplicationByClientId' )->willReturn( (object)
        [
            'appId'       => 'zitadel-app-id' ,
            'clientId'    => 'client-2' ,
            'name'        => 'My API' ,
            'description' => null ,
            'active'      => true ,
        ]) ;

        $resolver = new OAuthClientResolver( $model , $zitadel ) ;

        $this->assertSame( 'My API' , $resolver->resolve( 'client-2' ) ) ;
        $this->assertCount( 1 , $inserted ) ;
        $this->assertSame( 'My API'  , $inserted[ 0 ][ Schema::NAME ] ?? null ) ;
        $this->assertSame( 'client-2'        , $inserted[ 0 ][ OAuthClient::CLIENT_ID ] ?? null ) ;
        $this->assertSame( 'zitadel-app-id'  , $inserted[ 0 ][ OAuthClient::APP_ID ] ?? null ) ;
    }

    /**
     * An unknown clientId returns null and caches the negative result so
     * subsequent calls do not hit Zitadel again.
     */
    public function testResolveCachesNegativeResult() :void
    {
        $zitadelCalls = 0 ;

        $model   = $this->createDocumentsMock() ;
        $zitadel = $this->createZitadelMock() ;

        $model->method( 'get' )->willReturn( null ) ;
        $zitadel->method( 'findApplicationByClientId' )->willReturnCallback( function() use ( &$zitadelCalls )
        {
            $zitadelCalls++ ;
            return null ;
        } ) ;

        $resolver = new OAuthClientResolver( $model , $zitadel ) ;

        $this->assertNull( $resolver->resolve( 'unknown' ) ) ;
        $this->assertNull( $resolver->resolve( 'unknown' ) ) ;
        $this->assertSame( 1 , $zitadelCalls , 'Zitadel should only be queried once for a missing clientId.' ) ;
    }

    /**
     * Positive cache: a resolved name is served from memory on subsequent calls.
     */
    public function testResolveCachesPositiveResult() :void
    {
        $getCalls = 0 ;

        $model = $this->createDocumentsMock() ;

        $model->method( 'get' )->willReturnCallback( function() use ( &$getCalls )
        {
            $getCalls++ ;
            return (object) [ '_key' => 'k1' , 'name' => 'Cached App' ] ;
        } ) ;

        $resolver = new OAuthClientResolver( $model ) ;

        $this->assertSame( 'Cached App' , $resolver->resolve( 'client-3' ) ) ;
        $this->assertSame( 'Cached App' , $resolver->resolve( 'client-3' ) ) ;
        $this->assertSame( 1 , $getCalls , 'The second call must be served from the in-process cache.' ) ;
    }

    /**
     * `forget()` drops a single entry; the next call reloads through the model.
     */
    public function testForgetClearsSingleEntry() :void
    {
        $getCalls = 0 ;

        $model = $this->createDocumentsMock() ;

        $model->method( 'get' )->willReturnCallback( function() use ( &$getCalls )
        {
            $getCalls++ ;
            return (object) [ '_key' => 'k1' , 'name' => 'App' ] ;
        } ) ;

        $resolver = new OAuthClientResolver( $model ) ;

        $resolver->resolve( 'client-4' ) ;
        $resolver->forget( 'client-4' ) ;
        $resolver->resolve( 'client-4' ) ;

        $this->assertSame( 2 , $getCalls ) ;
    }

    /**
     * `upsertLocal()` inserts when no document exists yet.
     */
    public function testUpsertLocalInsertsOnMiss() :void
    {
        $inserted = [] ;

        $model = $this->createDocumentsMock() ;

        $model->method( 'get' )->willReturn( null ) ;
        $model->method( 'insert' )->willReturnCallback( function( array $params ) use ( &$inserted )
        {
            $inserted[] = $params[ Arango::DOC ] ?? [] ;
            return null ;
        } ) ;

        $resolver = new OAuthClientResolver( $model ) ;

        $resolver->upsertLocal( (object)
        [
            'appId'    => 'z-1' ,
            'clientId' => 'client-5' ,
            'name'     => 'Freshly Seeded' ,
            'active'   => true ,
        ]) ;

        $this->assertCount( 1 , $inserted ) ;
        $this->assertSame( 'client-5' , $inserted[ 0 ][ OAuthClient::CLIENT_ID ] ?? null ) ;
    }

    /**
     * `upsertLocal()` updates an existing document (matched by `_key`).
     */
    public function testUpsertLocalUpdatesOnHit() :void
    {
        $updates = [] ;

        $model = $this->createDocumentsMock() ;

        $model->method( 'get' )->willReturn( (object) [ '_key' => 'existing-key' , 'name' => 'Old Name' ] ) ;
        $model->method( 'update' )->willReturnCallback( function( array $params ) use ( &$updates )
        {
            $updates[] = $params ;
            return null ;
        } ) ;

        $resolver = new OAuthClientResolver( $model ) ;

        $resolver->upsertLocal( (object)
        [
            'appId'    => 'z-2' ,
            'clientId' => 'client-6' ,
            'name'     => 'New Name' ,
            'active'   => true ,
        ]) ;

        $this->assertCount( 1 , $updates ) ;
        $this->assertSame( 'existing-key' , $updates[ 0 ][ Arango::VALUE ] ?? null ) ;
        $this->assertSame( 'New Name'     , $updates[ 0 ][ Arango::DOC ][ Schema::NAME ] ?? null ) ;
    }

    /**
     * `flush()` drops every cached entry, forcing re-resolution on the next call.
     */
    public function testFlushClearsAllEntries() :void
    {
        $getCalls = 0 ;

        $model = $this->createDocumentsMock() ;

        $model->method( 'get' )->willReturnCallback( function() use ( &$getCalls )
        {
            $getCalls++ ;
            return (object) [ '_key' => 'k' , 'name' => 'App' ] ;
        } ) ;

        $resolver = new OAuthClientResolver( $model ) ;

        $resolver->resolve( 'a' ) ;
        $resolver->resolve( 'b' ) ;
        $resolver->flush() ;
        $resolver->resolve( 'a' ) ;
        $resolver->resolve( 'b' ) ;

        $this->assertSame( 4 , $getCalls ) ;
    }

    /**
     * Without a Zitadel client, a miss returns null and is negatively cached.
     */
    public function testResolveWithoutZitadelReturnsNull() :void
    {
        $model = $this->createDocumentsMock() ;
        $model->method( 'get' )->willReturn( null ) ;

        $resolver = new OAuthClientResolver( $model , null ) ;

        $this->assertNull( $resolver->resolve( 'client-7' ) ) ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createDocumentsMock() :Documents&MockObject
    {
        return $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' , 'insert' , 'update' ])
            ->getMock() ;
    }

    private function createZitadelMock() :ZitadelClient&MockObject
    {
        return $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'findApplicationByClientId' ])
            ->getMock() ;
    }
}
