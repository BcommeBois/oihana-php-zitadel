<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\traits\client\ZitadelClientTargetTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientTargetTrait} contract against the Zitadel
 * V2 Actions API (Targets + Executions).
 *
 * Every method routes through {@see requestRaw()} (the structured-result
 * variant) so the webhook provisioning command can distinguish a 403
 * permission denial from a genuine "not found". These tests pin the wire
 * shape (method, path, body) so a regression surfaces without a live
 * Zitadel instance.
 */
#[CoversTrait( ZitadelClientTargetTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientTargetTraitTest extends TestCase
{
    /**
     * Builds a ZitadelClient mock capturing every requestRaw() call and
     * returning a customizable raw response (default: generic 200 OK).
     *
     * @param array<int,array{method:string,path:string,body:mixed}> $calls
     * @param callable|null $responder function( string $method , string $path , mixed $body ): array
     */
    private function createCapturingClient( array &$calls , ?callable $responder = null ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$calls , $responder )
                {
                    $calls[] = [ 'method' => $method , 'path' => $path , 'body' => $body ] ;

                    return $responder !== null
                        ? $responder( $method , $path , $body )
                        : [ 'success' => true , 'status' => 200 , 'body' => null , 'rawBody' => '' , 'error' => null ] ;
                }
            ) ;

        return $client ;
    }

    // =========================================================================
    // createTarget()
    // =========================================================================

    public function testCreateTargetHitsTheV2TargetsEndpointWithPost() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->createTarget( 'webhook-pw-changed' , 'https://api.example.com/hooks/zitadel' ) ;

        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'POST' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/v2/actions/targets' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testCreateTargetBodyShapeMatchesTheRestWebhookContract() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->createTarget( 'my-target' , 'https://api.example.com/hook' ) ;

        $body = $calls[ 0 ][ 'body' ] ;

        $this->assertSame( 'my-target'                      , $body[ 'name' ] ) ;
        $this->assertSame( 'https://api.example.com/hook'   , $body[ 'endpoint' ] ) ;
        $this->assertSame( '10s'                            , $body[ 'timeout' ] , 'timeout is the default 10s, formatted as a Go duration' ) ;
        $this->assertSame( 'PAYLOAD_TYPE_JSON'              , $body[ 'payloadType' ] ) ;
        $this->assertEquals( (object) [] , $body[ 'restWebhook' ] , 'restWebhook is an empty object discriminator' ) ;
    }

    public function testCreateTargetFormatsTheTimeoutAsGoDuration() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->createTarget( 't' , 'https://x' , 30 ) ;

        $this->assertSame( '30s' , $calls[ 0 ][ 'body' ][ 'timeout' ] ) ;
    }

    public function testCreateTargetClampsNonPositiveTimeoutToOneSecond() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->createTarget( 't' , 'https://x' , 0 ) ;

        // max( 1 , $timeoutSeconds ) — a 0/negative timeout would make
        // Zitadel reject the Target, so it is floored to 1s.
        $this->assertSame( '1s' , $calls[ 0 ][ 'body' ][ 'timeout' ] ) ;
    }

    public function testCreateTargetReturnsTheRawResultVerbatim() :void
    {
        $calls    = [] ;
        $expected =
        [
            'success' => true ,
            'status'  => 201 ,
            'body'    => (object) [ 'id' => 'target-1' , 'signingKey' => 'secret-hmac' ] ,
            'rawBody' => '{"id":"target-1","signingKey":"secret-hmac"}' ,
            'error'   => null ,
        ] ;
        $client = $this->createCapturingClient( $calls , fn() => $expected ) ;

        $this->assertSame( $expected , $client->createTarget( 't' , 'https://x' ) ) ;
    }

    // =========================================================================
    // deleteTarget()
    // =========================================================================

    public function testDeleteTargetHitsTheTargetUrlWithDeleteAndNoBody() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->deleteTarget( 'target-42' ) ;

        $this->assertSame( 'DELETE' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/v2/actions/targets/target-42' , $calls[ 0 ][ 'path' ] ) ;
        $this->assertNull( $calls[ 0 ][ 'body' ] ) ;
    }

    // =========================================================================
    // listTargets()
    // =========================================================================

    public function testListTargetsHitsTheSearchEndpointWithAnEmptyObjectBody() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->listTargets() ;

        $this->assertSame( 'POST' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/v2/actions/targets/search' , $calls[ 0 ][ 'path' ] ) ;
        $this->assertEquals( (object) [] , $calls[ 0 ][ 'body' ] ) ;
    }

    // =========================================================================
    // setEventExecution()
    // =========================================================================

    public function testSetEventExecutionHitsTheExecutionsEndpointWithPut() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->setEventExecution( 'user.human.password.changed' , 'target-1' ) ;

        $this->assertSame( 'PUT' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/v2/actions/executions' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testSetEventExecutionBuildsTheEventConditionAndTargetBinding() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $client->setEventExecution( 'user.human.password.changed' , 'target-1' ) ;

        $body = $calls[ 0 ][ 'body' ] ;

        $this->assertSame( 'user.human.password.changed' , $body[ 'condition' ][ 'event' ][ 'event' ] ) ;
        $this->assertSame( [ 'target-1' ] , $body[ 'targets' ] ) ;
    }
}
