<?php

namespace tests\oihana\zitadel\commands;

use DI\Container;

use oihana\commands\enums\ExitCode;
use oihana\zitadel\commands\ZitadelWebhookCommand;
use oihana\zitadel\webhooks\ZitadelWebhookCatalog;
use oihana\zitadel\webhooks\ZitadelWebhookDescriptor;
use oihana\zitadel\ZitadelClient;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end coverage of {@see ZitadelWebhookCommand} action handlers,
 * driven through Symfony's {@see CommandTester}. The Zitadel V2 API is
 * mocked at the {@see ZitadelClient} boundary (listTargets / createTarget
 * / deleteTarget / setEventExecution) so every install / rotate / show /
 * uninstall / list / delete branch — including permission errors, missing
 * descriptors and interactive confirmations — is exercised without a live
 * instance.
 */
#[CoversClass( ZitadelWebhookCommand::class )]
#[AllowMockObjectsWithoutExpectations]
class ZitadelWebhookCommandActionsTest extends TestCase
{
    /** Canonical name for the default descriptor + context. */
    private const string CANONICAL = 'my-api - webhook password - api.example.com' ;

    // =========================================================================
    // Builders
    // =========================================================================

    private function descriptor( string $secret = '' ) :ZitadelWebhookDescriptor
    {
        return new ZitadelWebhookDescriptor
        (
            'password_changed' ,
            'user.human.password.changed' ,
            'webhook password' ,
            '/webhooks/zitadel/password-changed' ,
            $secret
        ) ;
    }

    private function zitadelMock() :ZitadelClient&MockObject
    {
        return $this->getMockBuilder( ZitadelClient::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'listTargets' , 'createTarget' , 'deleteTarget' , 'setEventExecution' ])
            ->getMock() ;
    }

    /**
     * Builds a fully wired command + the mocked ZitadelClient behind it.
     *
     * @param array<string,mixed> $o Overrides: apiIdentifier, baseUrl,
     *                               configFile, descriptors, catalogId,
     *                               withClient.
     *
     * @return array{0: ZitadelWebhookCommand, 1: ZitadelClient&MockObject}
     */
    private function makeCommand( array $o = [] ) :array
    {
        $descriptors = $o[ 'descriptors' ] ?? [ $this->descriptor() ] ;
        $catalog     = new ZitadelWebhookCatalog( $descriptors ) ;

        $container = new Container() ;
        $container->set( 'webhookCatalog' , $catalog ) ;

        $zitadel = $this->zitadelMock() ;

        $init =
        [
            'apiIdentifier'  => array_key_exists( 'apiIdentifier' , $o ) ? $o[ 'apiIdentifier' ] : 'my-api' ,
            'baseUrl'        => array_key_exists( 'baseUrl'       , $o ) ? $o[ 'baseUrl'       ] : 'https://api.example.com' ,
            'configFile'     => $o[ 'configFile' ] ?? '' ,
            'webhookCatalog' => $o[ 'catalogId'  ] ?? 'webhookCatalog' ,
        ] ;

        if( $o[ 'withClient' ] ?? true )
        {
            // A PHPUnit mock of ZitadelClient is `instanceof ZitadelClient`,
            // so getZitadelClient() accepts it directly.
            $init[ 'zitadelClient' ] = $zitadel ;
        }

        $command = new ZitadelWebhookCommand( 'zitadel:webhook' , $container , $init ) ;

        return [ $command , $zitadel ] ;
    }

    private function ok( ?object $body , int $status = 200 ) :array
    {
        return [ 'success' => true , 'status' => $status , 'body' => $body , 'rawBody' => '' , 'error' => null ] ;
    }

    private function ko( int $status , string $rawBody = '' , ?string $error = null ) :array
    {
        return [ 'success' => false , 'status' => $status , 'body' => null , 'rawBody' => $rawBody , 'error' => $error ] ;
    }

    private function target( string $name , string $id = 't-1' , string $endpoint = 'https://api.example.com/webhooks/zitadel/password-changed' , string $created = '2026-06-01T10:00:00Z' ) :object
    {
        return (object) [ 'id' => $id , 'name' => $name , 'endpoint' => $endpoint , 'creationDate' => $created ] ;
    }

    /** Wraps a list of target objects into the listTargets() success envelope. */
    private function targetsBody( array $targets ) :object
    {
        return (object) [ 'targets' => $targets ] ;
    }

    // =========================================================================
    // execute() — guards & dispatch
    // =========================================================================

    public function testExecuteFailsWhenNoClientInjected() :void
    {
        [ $command ] = $this->makeCommand( [ 'withClient' => false ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No ZitadelClient injected' , $tester->getDisplay() ) ;
    }

    public function testExecuteFailsWhenNoCatalogInjected() :void
    {
        [ $command ] = $this->makeCommand( [ 'catalogId' => 'missing-service' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No ZitadelWebhookCatalog injected' , $tester->getDisplay() ) ;
    }

    public function testExecuteFailsOnUnknownAction() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'bogus' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( "Unknown action 'bogus'" , $tester->getDisplay() ) ;
    }

    public function testExecuteCatchesUnexpectedException() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willThrowException( new \RuntimeException( 'boom' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Unexpected error: boom' , $tester->getDisplay() ) ;
    }

    public function testExecuteDefaultsToListAction() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [] , [ 'interactive' => false ] ) ; // no action → default 'list'

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'action: list' , $tester->getDisplay() ) ;
    }

    // =========================================================================
    // list
    // =========================================================================

    public function testListRendersTableSortedByCreationDate() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'older' , 'a' , 'https://x' , '2026-01-01T00:00:00Z' ) ,
            $this->target( 'newer' , 'b' , 'https://y' , '2026-06-01T00:00:00Z' ) ,
            // Unparsable + empty dates exercise formatDate's fallbacks.
            $this->target( 'weird' , 'c' , 'https://z' , 'not-a-date' ) ,
            $this->target( 'undated' , 'd' , 'https://w' , '' ) ,
        ] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'newer' , $display ) ;
        $this->assertStringContainsString( 'older' , $display ) ;
        $this->assertStringContainsString( 'not-a-date' , $display , 'unparsable date falls back to the raw value' ) ;
    }

    public function testListReturnsFailureWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 403 , '{"message":"denied"}' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Listing failed' , $display ) ;
        $this->assertStringContainsString( 'IAM Owner' , $display , '403 must surface the permission hint' ) ;
    }

    public function testListEmptyPrintsNoTargetMessage() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'no Target on this instance' , $tester->getDisplay() ) ;
    }

    public function testListMineFiltersOnApiPrefix() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'my-api - webhook password - api.example.com' , 'mine' ) ,
            $this->target( 'someone-else - thing - host' , 'other' ) ,
        ] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' , '--mine' => true ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertStringContainsString( 'my-api - webhook password' , $display ) ;
        $this->assertStringNotContainsString( 'someone-else' , $display ) ;
    }

    public function testListMineFailsWhenNoApiIdentifier() :void
    {
        [ $command , $zitadel ] = $this->makeCommand( [ 'apiIdentifier' => '' ] ) ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' , '--mine' => true ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( '--mine requires' , $tester->getDisplay() ) ;
    }

    public function testListMineEmptyAfterFilterPrintsNoTarget() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'someone-else - thing - host' , 'other' ) ,
        ] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'list' , '--mine' => true ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'no Target on this instance' , $tester->getDisplay() ) ;
    }

    // =========================================================================
    // delete
    // =========================================================================

    public function testDeleteFailsWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 0 , '' , 'transport_error' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'delete' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'transport: transport_error' , $display , 'status 0 → transport failure description' ) ;
    }

    public function testDeleteEmptyPrintsNoTarget() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'delete' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'no Target on this instance' , $tester->getDisplay() ) ;
    }

    public function testDeleteCancelledByPicker() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'some target' , 't-1' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->never() )->method( 'deleteTarget' ) ;

        // Non-interactive → choice() returns its default ('(cancel)').
        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'delete' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'aborted' , $tester->getDisplay() ) ;
    }

    public function testDeleteConfirmDeclined() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'some target' , 't-1' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->never() )->method( 'deleteTarget' ) ;

        $tester = new CommandTester( $command ) ;
        $tester->setInputs( [ '0' , 'no' ] ) ; // pick first, decline confirmation
        $tester->execute( [ 'action' => 'delete' ] , [ 'interactive' => true ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'aborted' , $tester->getDisplay() ) ;
    }

    public function testDeleteSuccessWithYesFlag() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'some target' , 't-1' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->once() )->method( 'deleteTarget' )->with( 't-1' )->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->setInputs( [ '0' ] ) ; // pick first; --yes skips confirm
        $tester->execute( [ 'action' => 'delete' , '--yes' => true ] , [ 'interactive' => true ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Target deleted' , $tester->getDisplay() ) ;
    }

    public function testDeleteFailureSurfacesError() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( 'some target' , 't-1' ) ,
        ] ) ) ) ;
        $zitadel->method( 'deleteTarget' )->willReturn( $this->ko( 403 , '{"message":"denied"}' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->setInputs( [ '0' ] ) ;
        $tester->execute( [ 'action' => 'delete' , '--yes' => true ] , [ 'interactive' => true ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Delete failed' , $display ) ;
        $this->assertStringContainsString( 'IAM Owner' , $display ) ;
    }

    // =========================================================================
    // install
    // =========================================================================

    public function testInstallMissingKeyFails() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'requires a <key>' , $display ) ;
        $this->assertStringContainsString( 'password_changed' , $display , 'available keys are listed' ) ;
    }

    public function testInstallUnknownKeyFails() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'ghost' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( "Unknown webhook key 'ghost'" , $tester->getDisplay() ) ;
    }

    public function testInstallFailsWhenContextMissing() :void
    {
        [ $command ] = $this->makeCommand( [ 'apiIdentifier' => '' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No apiIdentifier injected' , $tester->getDisplay() ) ;
    }

    public function testInstallFailsWhenBaseUrlMissing() :void
    {
        [ $command ] = $this->makeCommand( [ 'baseUrl' => '' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No baseUrl injected' , $tester->getDisplay() ) ;
    }

    public function testInstallFailsWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 500 , 'oops' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Listing failed' , $tester->getDisplay() ) ;
    }

    public function testInstallFailsWhenTargetAlreadyExists() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'existing-id' ) ,
        ] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        // Symfony's error block word-wraps, so normalise whitespace before
        // matching the phrase.
        $display = preg_replace( '/\s+/' , ' ' , $tester->getDisplay() ) ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'already exists' , $display ) ;
        $this->assertStringContainsString( 'existing-id' , $display ) ;
    }

    public function testInstallFailsWhenBaseUrlPrivateAndNoEndpoint() :void
    {
        [ $command , $zitadel ] = $this->makeCommand( [ 'baseUrl' => 'https://myapp.localhost' ] ) ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'not publicly reachable' , $tester->getDisplay() ) ;
    }

    public function testInstallSucceedsWithPublicBaseUrl() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->expects( $this->once() )->method( 'createTarget' )
                ->with( self::CANONICAL , 'https://api.example.com/webhooks/zitadel/password-changed' )
                ->willReturn( $this->ok( (object) [ 'id' => 'new-id' , 'signingKey' => 'sk-123' ] ) ) ;
        $zitadel->expects( $this->once() )->method( 'setEventExecution' )
                ->with( 'user.human.password.changed' , 'new-id' )
                ->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Cible created' , $display ) ;
        $this->assertStringContainsString( 'Execution set' , $display ) ;
        // No config file → the snippet is printed for manual paste.
        $this->assertStringContainsString( 'secret = "sk-123"' , $display ) ;
        $this->assertStringContainsString( 'Least privilege' , $display ) ;
    }

    public function testInstallUsesExplicitEndpointOption() :void
    {
        [ $command , $zitadel ] = $this->makeCommand( [ 'baseUrl' => 'https://myapp.localhost' ] ) ;
        // A localhost baseUrl changes the canonical host segment.
        $canonicalLocal = 'my-api - webhook password - myapp.localhost' ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->expects( $this->once() )->method( 'createTarget' )
                ->with( $canonicalLocal , 'https://tunnel.example.com/hook' )
                ->willReturn( $this->ok( (object) [ 'id' => 'new-id' , 'signingKey' => 'sk' ] ) ) ;
        $zitadel->method( 'setEventExecution' )->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute
        (
            [ 'action' => 'install' , 'key' => 'password_changed' , '--endpoint' => 'https://tunnel.example.com/hook' ] ,
            [ 'interactive' => false ]
        ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
    }

    public function testInstallFailsWhenCreateTargetFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->method( 'createTarget' )->willReturn( $this->ko( 403 , '{"message":"denied"}' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Cible creation failed' , $display ) ;
        $this->assertStringContainsString( 'IAM Owner' , $display ) ;
    }

    public function testInstallFailsWhenCreatedTargetHasNoId() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->method( 'createTarget' )->willReturn( $this->ok( (object) [ 'creationDate' => 'x' ] , 201 ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'did not return an id' , $tester->getDisplay() ) ;
    }

    public function testInstallWarnsWhenExecutionBindingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->method( 'createTarget' )->willReturn( $this->ok( (object) [ 'id' => 'new-id' , 'signingKey' => 'sk' ] ) ) ;
        $zitadel->method( 'setEventExecution' )->willReturn( $this->ko( 403 , '{"message":"denied"}' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        // The Zitadel-side Target succeeded, so the action still ends OK, but
        // the binding failure is surfaced as a warning + manual-fix hint.
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Execution binding failed' , $display ) ;
        $this->assertStringContainsString( 'fix manually' , $display ) ;
    }

    public function testInstallWritesSecretToConfigFile() :void
    {
        $path = sys_get_temp_dir() . '/zitadel_install_' . uniqid() . '.toml' ;

        [ $command , $zitadel ] = $this->makeCommand( [ 'configFile' => $path ] ) ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->method( 'createTarget' )->willReturn( $this->ok( (object) [ 'id' => 'new-id' , 'signingKey' => 'sk-file' ] ) ) ;
        $zitadel->method( 'setEventExecution' )->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'install' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertFileExists( $path ) ;
        $this->assertStringContainsString( 'secret = "sk-file"' , (string) file_get_contents( $path ) ) ;
        $this->assertStringContainsString( 'Secret written' , $tester->getDisplay() ) ;

        @unlink( $path ) ;
        @unlink( $path . '.bak' ) ;
    }

    // =========================================================================
    // rotate
    // =========================================================================

    public function testRotateMissingKeyFails() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'requires a <key>' , $tester->getDisplay() ) ;
    }

    public function testRotateFailsWhenContextMissing() :void
    {
        [ $command ] = $this->makeCommand( [ 'apiIdentifier' => '' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No apiIdentifier injected' , $tester->getDisplay() ) ;
    }

    public function testRotateFailsWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 500 , 'boom' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Listing failed' , $tester->getDisplay() ) ;
    }

    public function testRotateFailsWhenTargetDoesNotExist() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No Target named' , $display ) ;
        $this->assertStringContainsString( 'install' , $display ) ;
    }

    public function testRotateRecreatesTargetWithFreshKey() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'old-id' , 'https://api.example.com/webhooks/zitadel/password-changed' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->once() )->method( 'deleteTarget' )->with( 'old-id' )->willReturn( $this->ok( null ) ) ;
        $zitadel->expects( $this->once() )->method( 'createTarget' )
                ->with( self::CANONICAL , 'https://api.example.com/webhooks/zitadel/password-changed' )
                ->willReturn( $this->ok( (object) [ 'id' => 'fresh-id' , 'signingKey' => 'sk-fresh' ] ) ) ;
        $zitadel->method( 'setEventExecution' )->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'deleting existing Target id old-id' , $display ) ;
        $this->assertStringContainsString( 'secret = "sk-fresh"' , $display ) ;
    }

    public function testRotateFailsWhenRecreateFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'old-id' ) ,
        ] ) ) ) ;
        $zitadel->method( 'deleteTarget' )->willReturn( $this->ok( null ) ) ;
        $zitadel->method( 'createTarget' )->willReturn( $this->ko( 500 , 'boom' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'rotate' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Cible creation failed' , $tester->getDisplay() ) ;
    }

    // =========================================================================
    // show
    // =========================================================================

    public function testShowMissingKeyFails() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'show' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'requires a <key>' , $tester->getDisplay() ) ;
    }

    public function testShowFailsWhenContextMissing() :void
    {
        [ $command ] = $this->makeCommand( [ 'baseUrl' => '' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'show' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No baseUrl injected' , $tester->getDisplay() ) ;
    }

    public function testShowNotInstalled() :void
    {
        [ $command , $zitadel ] = $this->makeCommand( [ 'descriptors' => [ $this->descriptor( 'a-secret' ) ] ] ) ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'show' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'not installed' , $display ) ;
        $this->assertStringContainsString( 'set' , $display , 'a non-empty secret shows as "set"' ) ;
    }

    public function testShowInstalledPrintsMetadata() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ; // default descriptor: blank secret
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'shown-id' , 'https://api.example.com/hook' , '2026-06-01T10:00:00Z' ) ,
        ] ) ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'show' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'installed' , $display ) ;
        $this->assertStringContainsString( 'shown-id' , $display ) ;
        $this->assertStringContainsString( 'blank' , $display , 'an empty secret shows as "blank"' ) ;
    }

    public function testShowFailsWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 500 , 'boom' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'show' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Listing failed' , $tester->getDisplay() ) ;
    }

    // =========================================================================
    // uninstall
    // =========================================================================

    public function testUninstallMissingKeyFails() :void
    {
        [ $command ] = $this->makeCommand() ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'uninstall' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'requires a <key>' , $tester->getDisplay() ) ;
    }

    public function testUninstallFailsWhenContextMissing() :void
    {
        [ $command ] = $this->makeCommand( [ 'apiIdentifier' => '' ] ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'uninstall' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'No apiIdentifier injected' , $tester->getDisplay() ) ;
    }

    public function testUninstallNothingToDeleteKeepsSecret() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody( [] ) ) ) ;
        $zitadel->expects( $this->never() )->method( 'deleteTarget' ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'uninstall' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'nothing to delete' , $display ) ;
        $this->assertStringContainsString( 'preserved' , $display ) ;
    }

    public function testUninstallConfirmDeclined() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'kill-id' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->never() )->method( 'deleteTarget' ) ;

        $tester = new CommandTester( $command ) ;
        $tester->setInputs( [ 'no' ] ) ;
        $tester->execute( [ 'action' => 'uninstall' , 'key' => 'password_changed' ] , [ 'interactive' => true ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'aborted' , $tester->getDisplay() ) ;
    }

    public function testUninstallDeletesWithYesAndPurgesSecret() :void
    {
        $path = sys_get_temp_dir() . '/zitadel_uninstall_' . uniqid() . '.toml' ;
        file_put_contents( $path , "[zitadel.webhooks.password_changed]\nsecret = \"to-purge\"\n" ) ;

        [ $command , $zitadel ] = $this->makeCommand( [ 'configFile' => $path ] ) ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'kill-id' ) ,
        ] ) ) ) ;
        $zitadel->expects( $this->once() )->method( 'deleteTarget' )->with( 'kill-id' )->willReturn( $this->ok( null ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute
        (
            [ 'action' => 'uninstall' , 'key' => 'password_changed' , '--yes' => true , '--purge-config' => true ] ,
            [ 'interactive' => false ]
        ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::SUCCESS , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Cible deleted on Zitadel' , $display ) ;
        $this->assertStringContainsString( 'Secret blanked' , $display ) ;
        $this->assertStringContainsString( 'secret = ""' , (string) file_get_contents( $path ) ) ;

        @unlink( $path ) ;
        @unlink( $path . '.bak' ) ;
    }

    public function testUninstallDeleteFailureSurfacesError() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ok( $this->targetsBody(
        [
            $this->target( self::CANONICAL , 'kill-id' ) ,
        ] ) ) ) ;
        $zitadel->method( 'deleteTarget' )->willReturn( $this->ko( 403 , '{"message":"denied"}' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute
        (
            [ 'action' => 'uninstall' , 'key' => 'password_changed' , '--yes' => true ] ,
            [ 'interactive' => false ]
        ) ;

        $display = $tester->getDisplay() ;
        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Delete failed' , $display ) ;
        $this->assertStringContainsString( 'IAM Owner' , $display ) ;
    }

    public function testUninstallFailsWhenListingFails() :void
    {
        [ $command , $zitadel ] = $this->makeCommand() ;
        $zitadel->method( 'listTargets' )->willReturn( $this->ko( 500 , 'boom' ) ) ;

        $tester = new CommandTester( $command ) ;
        $tester->execute( [ 'action' => 'uninstall' , 'key' => 'password_changed' ] , [ 'interactive' => false ] ) ;

        $this->assertSame( ExitCode::FAILURE , $tester->getStatusCode() ) ;
        $this->assertStringContainsString( 'Listing failed' , $tester->getDisplay() ) ;
    }
}
