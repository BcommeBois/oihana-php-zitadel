<?php

namespace tests\oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelEndpoint;
use oihana\zitadel\traits\client\ZitadelClientServiceTrait;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the {@see ZitadelClientServiceTrait} contract against the Zitadel
 * Management + V2 User APIs.
 *
 * The trait is the foundation of the Service Account (Machine User) M2M
 * pattern : a Machine User owns User Keys (private_key_jwt assertions per
 * RFC 7523) and is granted on the API project audience-only so the API
 * middleware accepts its tokens. Wrong payload shape on any of the four
 * methods covered here breaks token issuance silently — the upstream
 * `auth:test:service:poc` smoke command catches it end-to-end, but these
 * unit tests pin the contract at the wire level so a regression surfaces
 * without a Zitadel staging round-trip.
 */
#[CoversTrait( ZitadelClientServiceTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelClientServiceTraitTest extends TestCase
{
    /**
     * Builds a ZitadelClient mock that captures every requestRaw() call and
     * returns a stubbed `success` response. The optional `$responder` lets
     * a test customize the raw response per (method, path, body) tuple —
     * useful for failure paths and for exercising methods that chain two
     * Zitadel calls (e.g. createMachineUser → getOrgId + USERS_NEW_V2).
     *
     * @param array<int,array{method:string,path:string,body:mixed}> $calls
     *        Reference filled with one entry per requestRaw() invocation,
     *        in call order.
     * @param callable|null $responder
     *        Optional `function( string $method, string $path, array|object|null $body ): array`
     *        returning the raw array shape Guzzle adapter normally produces.
     *        Defaults to a generic 200 OK with `body = null`.
     */
    private function createCapturingClient( array &$calls , ?callable $responder = null ) :ZitadelClient
    {
        $client = $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'requestRaw' ])
            ->getMock() ;

        $client->expects( $this->any() )
            ->method( 'requestRaw' )
            ->willReturnCallback
            (
                function( string $method , string $path , array|object|null $body = null ) use ( &$calls , $responder )
                {
                    $calls[] =
                    [
                        'method' => $method ,
                        'path'   => $path  ,
                        'body'   => $body  ,
                    ] ;

                    if( $responder !== null )
                    {
                        return $responder( $method , $path , $body ) ;
                    }

                    return
                    [
                        'success' => true  ,
                        'status'  => 200   ,
                        'body'    => null  ,
                        'rawBody' => ''    ,
                        'error'   => null  ,
                    ] ;
                }
            ) ;

        return $client ;
    }

    /**
     * Convenience builder for the canonical `success` response shape.
     *
     * @param mixed $body Decoded JSON body (object / array / scalar / null).
     */
    private function ok( mixed $body ) :array
    {
        return
        [
            'success' => true  ,
            'status'  => 200   ,
            'body'    => $body ,
            'rawBody' => ''    ,
            'error'   => null  ,
        ] ;
    }

    /**
     * Convenience builder for a transport-level failure response shape.
     */
    private function ko( int $status , string $rawBody = '' , ?string $error = null ) :array
    {
        return
        [
            'success' => false    ,
            'status'  => $status  ,
            'body'    => null     ,
            'rawBody' => $rawBody ,
            'error'   => $error   ,
        ] ;
    }

    // =========================================================================
    // getOrgId()
    // =========================================================================

    public function testGetOrgIdHitsTheManagementMyOrgEndpointWithGet() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'org' => (object) [ 'id' => 'org-123' ] ] )
        ) ;

        $client->getOrgId() ;

        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'GET' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( ZitadelEndpoint::MY_ORG , $calls[ 0 ][ 'path' ] ) ;
        $this->assertSame( '/management/v1/orgs/me' , $calls[ 0 ][ 'path' ] , 'V1 management endpoint, NOT a V2 path' ) ;
    }

    public function testGetOrgIdReturnsTheOrgIdFromResponseBody() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'org' => (object) [ 'id' => 'org-abc' ] ] )
        ) ;

        $this->assertSame( 'org-abc' , $client->getOrgId() ) ;
    }

    public function testGetOrgIdMemoizesAfterFirstResolution() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'org' => (object) [ 'id' => 'org-memo' ] ] )
        ) ;

        $first  = $client->getOrgId() ;
        $second = $client->getOrgId() ;
        $third  = $client->getOrgId() ;

        // Memoization invariant: subsequent calls must NOT re-hit Zitadel.
        // createMachineUser() resolves the org id on every call, so a missing
        // memo would multiply Management API hits per service creation.
        $this->assertSame( 'org-memo' , $first ) ;
        $this->assertSame( $first , $second ) ;
        $this->assertSame( $first , $third ) ;
        $this->assertCount( 1 , $calls , 'getOrgId() must hit /orgs/me at most once per client lifetime' ) ;
    }

    public function testGetOrgIdReturnsNullOnHttpFailureAndCapturesError() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 401 , '{"message":"unauthenticated"}' )
        ) ;

        $this->assertNull( $client->getOrgId() ) ;
        $this->assertSame( 401 , $client->getLastServiceErrorStatus() ) ;
        $this->assertSame( '{"message":"unauthenticated"}' , $client->getLastServiceErrorBody() ) ;
    }

    public function testGetOrgIdReturnsNullWhenBodyIsMalformed() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'org' => (object) [] ] )
        ) ;

        $this->assertNull( $client->getOrgId() ) ;
    }

    // =========================================================================
    // createMachineUser()
    // =========================================================================

    public function testCreateMachineUserHitsTheV2NewUserEndpointWithPost() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'id' => 'machine-user-1' ] )
        ) ;

        $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        // Two calls expected: getOrgId() then USERS_NEW_V2 — order matters
        // because the V2 contract requires `organizationId` in the body.
        $this->assertCount( 2 , $calls ) ;
        $this->assertSame( ZitadelEndpoint::MY_ORG     , $calls[ 0 ][ 'path' ] ) ;
        $this->assertSame( 'POST'                      , $calls[ 1 ][ 'method' ] ) ;
        $this->assertSame( ZitadelEndpoint::USERS_NEW_V2 , $calls[ 1 ][ 'path' ] ) ;
        $this->assertSame( '/v2/users/new' , $calls[ 1 ][ 'path' ] , 'V2 path, NOT the deprecated V1 _import' ) ;
    }

    public function testCreateMachineUserBodyShapeMatchesV2Contract() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-XYZ' , (object) [ 'id' => 'mu' ] )
        ) ;

        $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        $body = $calls[ 1 ][ 'body' ] ;

        $this->assertIsArray( $body ) ;
        $this->assertSame( 'org-XYZ'   , $body[ 'organizationId' ] ?? null ) ;
        $this->assertSame( 'svc-cron'  , $body[ 'username' ]       ?? null ) ;
        $this->assertIsArray( $body[ 'machine' ] ?? null ) ;
        $this->assertSame( 'Cron service' , $body[ 'machine' ][ 'name' ] ?? null ) ;
    }

    public function testCreateMachineUserDefaultsAccessTokenTypeToJwt() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'id' => 'mu' ] )
        ) ;

        $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        // Default JWT — our middleware (CheckJwtAuthentication) validates JWT
        // signatures against the JWKS. Switching to opaque ACCESS_TOKEN_TYPE_BEARER
        // would force an introspection roundtrip per request.
        $this->assertSame
        (
            ZitadelClient::ACCESS_TOKEN_TYPE_JWT ,
            $calls[ 1 ][ 'body' ][ 'machine' ][ 'accessTokenType' ] ?? null
        ) ;
    }

    public function testCreateMachineUserOmitsDescriptionWhenEmpty() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'id' => 'mu' ] )
        ) ;

        $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        $this->assertArrayNotHasKey( 'description' , $calls[ 1 ][ 'body' ][ 'machine' ] ) ;
    }

    public function testCreateMachineUserSendsDescriptionWhenProvided() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'id' => 'mu' ] )
        ) ;

        $client->createMachineUser( 'svc-cron' , 'Cron service' , 'Nightly product harvest' ) ;

        $this->assertSame
        (
            'Nightly product harvest' ,
            $calls[ 1 ][ 'body' ][ 'machine' ][ 'description' ] ?? null
        ) ;
    }

    public function testCreateMachineUserNormalizesV2IdToUserId() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'id' => 'machine-user-77' ] )
        ) ;

        $result = $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        // V2 CreateUser returns `id`, V1 used `userId`. The trait normalizes
        // the field so callers reason about a single name across the codebase.
        $this->assertNotNull( $result ) ;
        $this->assertSame( 'machine-user-77' , $result->userId ?? null ) ;
    }

    public function testCreateMachineUserReturnsNullWhenOrgResolutionFails() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 401 , '{"message":"unauthenticated"}' )
        ) ;

        $result = $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        // No fallback path: a missing org id MUST short-circuit before any
        // V2 user creation, otherwise Zitadel rejects the request body and
        // we'd surface an opaque 400 instead of the real auth error.
        $this->assertNull( $result ) ;
        $this->assertCount( 1 , $calls , 'must NOT issue a USERS_NEW_V2 call when /orgs/me failed' ) ;
        $this->assertSame( 401 , $client->getLastServiceErrorStatus() ) ;
    }

    public function testCreateMachineUserReturnsNullOnHttpFailure() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate
            (
                'org-1' ,
                /* createBody */ null ,
                /* createSuccess */ false ,
                /* createStatus */ 409 ,
                /* createRawBody */ '{"id":"COMMA-XYZ","message":"username already exists"}'
            )
        ) ;

        $result = $client->createMachineUser( 'svc-cron' , 'Cron service' ) ;

        $this->assertNull( $result ) ;
        $this->assertSame( 409 , $client->getLastServiceErrorStatus() ) ;
        $this->assertStringContainsString( 'username already exists' , (string) $client->getLastServiceErrorBody() ) ;
    }

    public function testCreateMachineUserReturnsNullWhenResponseHasNoId() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            $this->orgThenCreate( 'org-1' , (object) [ 'creationDate' => '2026-05-06T00:00:00Z' ] )
        ) ;

        $this->assertNull( $client->createMachineUser( 'svc-cron' , 'Cron service' ) ) ;
    }

    // =========================================================================
    // createUserKey()
    // =========================================================================

    public function testCreateUserKeyHitsTheResolvedKeysEndpointWithPost() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'key-1' ,
                    'keyDetails' => $this->encodeKeyfile( [ 'type' => 'serviceaccount' ] ) ,
                ]
            )
        ) ;

        $client->createUserKey( 'machine-user-77' ) ;

        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'POST' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/users/machine-user-77/keys' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testCreateUserKeyDefaultsToJsonKeyTypeWithoutExpiration() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'k' ,
                    'keyDetails' => $this->encodeKeyfile( [] ) ,
                ]
            )
        ) ;

        $client->createUserKey( 'mu' ) ;

        $body = $calls[ 0 ][ 'body' ] ;
        $this->assertSame( ZitadelClient::KEY_TYPE_JSON , $body[ 'type' ] ?? null ) ;
        $this->assertArrayNotHasKey
        (
            'expirationDate' ,
            $body ,
            'must NOT send an expirationDate key when the caller passes null — Zitadel interprets absence as no upstream expiry'
        ) ;
    }

    public function testCreateUserKeySendsExpirationDateWhenProvided() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'k' ,
                    'keyDetails' => $this->encodeKeyfile( [] ) ,
                ]
            )
        ) ;

        $client->createUserKey( 'mu' , '2027-01-01T00:00:00Z' ) ;

        $this->assertSame( '2027-01-01T00:00:00Z' , $calls[ 0 ][ 'body' ][ 'expirationDate' ] ?? null ) ;
    }

    public function testCreateUserKeyDecodesBase64KeyDetailsToKeyfileArray() :void
    {
        $payload =
        [
            'type'   => 'serviceaccount' ,
            'keyId'  => 'key-1'          ,
            'key'    => "-----BEGIN RSA PRIVATE KEY-----\nfake\n-----END RSA PRIVATE KEY-----" ,
            'userId' => 'machine-user-77' ,
        ] ;

        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'key-1' ,
                    'keyDetails' => $this->encodeKeyfile( $payload ) ,
                ]
            )
        ) ;

        $result = $client->createUserKey( 'machine-user-77' ) ;

        $this->assertNotNull( $result ) ;
        $this->assertSame( 'key-1' , $result->keyId ?? null ) ;
        $this->assertSame( $payload , $result->keyfile ?? null , 'keyfile must be the decoded JSON document, not the base64 wrapper' ) ;
    }

    public function testCreateUserKeyReturnsNullWhenKeyDetailsAreInvalidBase64() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'key-1' ,
                    'keyDetails' => '!!!not-base64!!!' ,
                ]
            )
        ) ;

        $this->assertNull( $client->createUserKey( 'mu' ) ) ;
    }

    public function testCreateUserKeyReturnsNullWhenDecodedPayloadIsNotJson() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok
            ( (object)
                [
                    'keyId'      => 'key-1' ,
                    'keyDetails' => base64_encode( 'plain text not json' ) ,
                ]
            )
        ) ;

        $this->assertNull( $client->createUserKey( 'mu' ) ) ;
    }

    public function testCreateUserKeyReturnsNullOnHttpFailure() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 404 , '{"message":"user not found"}' )
        ) ;

        $this->assertNull( $client->createUserKey( 'missing' ) ) ;
        $this->assertSame( 404 , $client->getLastServiceErrorStatus() ) ;
        $this->assertStringContainsString( 'user not found' , (string) $client->getLastServiceErrorBody() ) ;
    }

    public function testCreateUserKeyReturnsNullWhenSuccessBodyLacksKeyIdOrDetails() :void
    {
        // 2xx but the body is missing keyId / keyDetails — Zitadel contract
        // drift or a truncated payload. Must return null *before* attempting
        // to base64-decode a missing field.
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'keyId' => 'k' ] ) // no keyDetails
        ) ;

        $this->assertNull( $client->createUserKey( 'mu' ) ) ;
    }

    // =========================================================================
    // grantUserOnProject()
    // =========================================================================

    public function testGrantUserOnProjectHitsTheResolvedGrantsEndpointWithPost() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'userGrantId' => 'grant-1' ] )
        ) ;

        $client->grantUserOnProject( 'machine-user-77' , 'project-xyz' ) ;

        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'POST' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/users/machine-user-77/grants' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testGrantUserOnProjectAlwaysSendsRoleKeysEvenWhenEmpty() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'userGrantId' => 'grant-1' ] )
        ) ;

        $client->grantUserOnProject( 'mu' , 'project-xyz' ) ;

        // Audience-only invariant : an empty `roleKeys` is the contract for
        // "give me the project audience without materializing any Zitadel
        // role". Stripping the key entirely would change the semantics —
        // Zitadel's gRPC adapter accepts both, but our future smoke tests
        // assert audience-only by inspecting the wire body.
        $body = $calls[ 0 ][ 'body' ] ;
        $this->assertArrayHasKey( 'roleKeys' , $body ) ;
        $this->assertSame( [] , $body[ 'roleKeys' ] ) ;
        $this->assertSame( 'project-xyz' , $body[ 'projectId' ] ?? null ) ;
    }

    public function testGrantUserOnProjectForwardsExplicitRoleKeys() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'userGrantId' => 'grant-1' ] )
        ) ;

        $client->grantUserOnProject( 'mu' , 'project-xyz' , [ 'admin' , 'reader' ] ) ;

        $this->assertSame( [ 'admin' , 'reader' ] , $calls[ 0 ][ 'body' ][ 'roleKeys' ] ?? null ) ;
    }

    public function testGrantUserOnProjectReturnsUserGrantId() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'userGrantId' => 'grant-9' ] )
        ) ;

        $result = $client->grantUserOnProject( 'mu' , 'project-xyz' ) ;

        $this->assertNotNull( $result ) ;
        $this->assertSame( 'grant-9' , $result->userGrantId ?? null ) ;
    }

    public function testGrantUserOnProjectReturnsNullOnHttpFailure() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 403 , '{"message":"permission denied"}' )
        ) ;

        $this->assertNull( $client->grantUserOnProject( 'mu' , 'project-xyz' ) ) ;
        $this->assertSame( 403 , $client->getLastServiceErrorStatus() ) ;
    }

    public function testGrantUserOnProjectReturnsNullWhenSuccessBodyLacksUserGrantId() :void
    {
        // 2xx but the body carries no userGrantId — must not fabricate a
        // grant object from an empty id.
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ok( (object) [ 'details' => (object) [] ] )
        ) ;

        $this->assertNull( $client->grantUserOnProject( 'mu' , 'project-xyz' ) ) ;
    }

    // =========================================================================
    // deleteServiceAccount()
    // =========================================================================

    public function testDeleteServiceAccountHitsTheResolvedUserEndpointWithDelete() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $this->assertTrue( $client->deleteServiceAccount( 'machine-user-77' ) ) ;
        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'DELETE' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/users/machine-user-77' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testDeleteServiceAccountReturnsFalseAndCapturesErrorOnFailure() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 404 , '{"message":"user not found"}' )
        ) ;

        $this->assertFalse( $client->deleteServiceAccount( 'gone' ) ) ;
        $this->assertSame( 404 , $client->getLastServiceErrorStatus() ) ;
        $this->assertStringContainsString( 'user not found' , (string) $client->getLastServiceErrorBody() ) ;
    }

    // =========================================================================
    // deleteUserKey()
    // =========================================================================

    public function testDeleteUserKeyHitsTheResolvedKeyEndpointWithDelete() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $this->assertTrue( $client->deleteUserKey( 'machine-user-77' , 'key-1' ) ) ;
        $this->assertCount( 1 , $calls ) ;
        $this->assertSame( 'DELETE' , $calls[ 0 ][ 'method' ] ) ;
        $this->assertSame( '/management/v1/users/machine-user-77/keys/key-1' , $calls[ 0 ][ 'path' ] ) ;
    }

    public function testDeleteUserKeyReturnsFalseAndCapturesErrorOnFailure() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            fn() => $this->ko( 404 , '{"message":"key not found"}' )
        ) ;

        // Returning false (not an exception) is load-bearing : the keyfile
        // rotation flow needs to *try* to delete the previous key but must
        // not abort if the key was already gone (e.g. a prior partial
        // rotation) — callers inspect the return + lastServiceError* to
        // decide whether to log a warning vs surface a 5xx.
        $this->assertFalse( $client->deleteUserKey( 'mu' , 'gone-key' ) ) ;
        $this->assertSame( 404 , $client->getLastServiceErrorStatus() ) ;
        $this->assertStringContainsString( 'key not found' , (string) $client->getLastServiceErrorBody() ) ;
    }

    // =========================================================================
    // Error capture invariants
    // =========================================================================

    public function testGetLastServiceErrorStartsAtNullAndZero() :void
    {
        $calls  = [] ;
        $client = $this->createCapturingClient( $calls ) ;

        $this->assertNull ( $client->getLastServiceErrorBody()   ) ;
        $this->assertSame ( 0 , $client->getLastServiceErrorStatus() ) ;
    }

    public function testGetLastServiceErrorReflectsTheMostRecentFailure() :void
    {
        $sequence =
        [
            $this->ko( 502 , '{"err":"first"}' )  ,
            $this->ko( 401 , '{"err":"second"}' ) ,
        ] ;
        $cursor = 0 ;

        $calls  = [] ;
        $client = $this->createCapturingClient
        (
            $calls ,
            function() use ( &$sequence , &$cursor )
            {
                return $sequence[ $cursor++ ] ?? $sequence[ array_key_last( $sequence ) ] ;
            }
        ) ;

        // First failure on the orgs/me path of getOrgId() …
        $client->getOrgId() ;
        $this->assertSame( 502 , $client->getLastServiceErrorStatus() ) ;

        // … then a second failure on a delete → captures must be replaced.
        $client->deleteServiceAccount( 'mu' ) ;
        $this->assertSame( 401 , $client->getLastServiceErrorStatus() ) ;
        $this->assertSame( '{"err":"second"}' , $client->getLastServiceErrorBody() ) ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Builds a responder that emulates the two-step flow of
     * createMachineUser : the first call (GET MY_ORG) returns the org id,
     * the second call (POST USERS_NEW_V2) either returns the success body
     * or a failure tuple.
     */
    private function orgThenCreate
    (
        string  $orgId ,
        ?object $createBody    = null ,
        bool    $createSuccess = true ,
        int     $createStatus  = 200  ,
        string  $createRawBody = ''
    ) :callable
    {
        return function( string $method , string $path ) use ( $orgId , $createBody , $createSuccess , $createStatus , $createRawBody )
        {
            if( $path === ZitadelEndpoint::MY_ORG )
            {
                return $this->ok( (object) [ 'org' => (object) [ 'id' => $orgId ] ] ) ;
            }

            return $createSuccess
                ? $this->ok( $createBody )
                : $this->ko( $createStatus , $createRawBody ) ;
        } ;
    }

    /**
     * Encodes a keyfile payload the way Zitadel does (base64 of a JSON
     * document) so the trait's decode path is exercised end-to-end.
     */
    private function encodeKeyfile( array $payload ) :string
    {
        return base64_encode( json_encode( $payload , JSON_THROW_ON_ERROR ) ) ;
    }
}
