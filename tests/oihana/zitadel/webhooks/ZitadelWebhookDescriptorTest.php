<?php

namespace tests\oihana\zitadel\webhooks;

use InvalidArgumentException;

use oihana\zitadel\webhooks\ZitadelWebhookDescriptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelWebhookDescriptor} — the immutable value object
 * describing a single Zitadel Cible + Action pair the API consumes.
 *
 * Coverage focuses on the validation rules, the `fromArray` builder,
 * and the `withSecret` immutability contract used by the rotation flow.
 */
#[CoversClass( ZitadelWebhookDescriptor::class )]
class ZitadelWebhookDescriptorTest extends TestCase
{
    // =========================================================================
    // Construction — happy path
    // =========================================================================

    public function testConstructorAcceptsValidFields() :void
    {
        $descriptor = new ZitadelWebhookDescriptor
        (
            key    : 'password_changed' ,
            event  : 'user.human.password.changed' ,
            label  : 'webhook password' ,
            route  : '/webhooks/zitadel/password-changed' ,
            secret : 'hmac-secret'
        ) ;

        $this->assertSame( 'password_changed'                   , $descriptor->key    ) ;
        $this->assertSame( 'user.human.password.changed'        , $descriptor->event  ) ;
        $this->assertSame( 'webhook password'                   , $descriptor->label  ) ;
        $this->assertSame( '/webhooks/zitadel/password-changed' , $descriptor->route  ) ;
        $this->assertSame( 'hmac-secret'                        , $descriptor->secret ) ;
    }

    public function testConstructorAcceptsEmptySecret() :void
    {
        $descriptor = new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;

        $this->assertSame( '' , $descriptor->secret ) ;
        $this->assertFalse( $descriptor->hasSecret() ) ;
    }

    public function testConstructorAcceptsTwoSegmentEvent() :void
    {
        // session.added is a real Zitadel event with only two segments.
        $descriptor = new ZitadelWebhookDescriptor
        (
            key   : 'session_added' ,
            event : 'session.added' ,
            label : 'webhook session' ,
            route : '/webhooks/zitadel/session-added'
        ) ;

        $this->assertSame( 'session.added' , $descriptor->event ) ;
    }

    // =========================================================================
    // Construction — validation failures
    // =========================================================================

    public function testConstructorRejectsEmptyKey() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'invalid key' ) ;

        new ZitadelWebhookDescriptor
        (
            key   : '' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsKeyWithUppercase() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'PasswordChanged' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsKeyStartingWithDigit() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new ZitadelWebhookDescriptor
        (
            key   : '1password' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsEventWithoutDot() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'invalid event' ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'userhumanpasswordchanged' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsEventWithUppercase() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'User.Human.Password.Changed' ,
            label : 'webhook password' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsEmptyLabel() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'empty label' ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'user.human.password.changed' ,
            label : '   ' ,
            route : '/webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsRouteWithoutLeadingSlash() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'invalid route' ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : 'webhooks/zitadel/password-changed'
        ) ;
    }

    public function testConstructorRejectsEmptyRoute() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new ZitadelWebhookDescriptor
        (
            key   : 'password_changed' ,
            event : 'user.human.password.changed' ,
            label : 'webhook password' ,
            route : ''
        ) ;
    }

    // =========================================================================
    // fromArray
    // =========================================================================

    public function testFromArrayBuildsFromCompleteSection() :void
    {
        $descriptor = ZitadelWebhookDescriptor::fromArray
        (
            'password_changed' ,
            [
                'event'  => 'user.human.password.changed' ,
                'label'  => 'webhook password' ,
                'route'  => '/webhooks/zitadel/password-changed' ,
                'secret' => 'hmac-secret' ,
            ]
        ) ;

        $this->assertSame( 'hmac-secret' , $descriptor->secret ) ;
        $this->assertTrue( $descriptor->hasSecret() ) ;
    }

    public function testFromArrayDefaultsSecretToEmpty() :void
    {
        $descriptor = ZitadelWebhookDescriptor::fromArray
        (
            'password_changed' ,
            [
                'event' => 'user.human.password.changed' ,
                'label' => 'webhook password' ,
                'route' => '/webhooks/zitadel/password-changed' ,
            ]
        ) ;

        $this->assertSame( '' , $descriptor->secret ) ;
        $this->assertFalse( $descriptor->hasSecret() ) ;
    }

    public function testFromArrayRejectsMissingEvent() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'invalid event' ) ;

        ZitadelWebhookDescriptor::fromArray
        (
            'password_changed' ,
            [
                'label' => 'webhook password' ,
                'route' => '/webhooks/zitadel/password-changed' ,
            ]
        ) ;
    }

    public function testFromArrayCarriesKeyIntoErrorMessage() :void
    {
        try
        {
            ZitadelWebhookDescriptor::fromArray
            (
                'broken_key' ,
                [
                    'event' => 'malformed' ,
                    'label' => 'webhook broken' ,
                    'route' => '/webhooks/zitadel/broken' ,
                ]
            ) ;

            $this->fail( 'Expected InvalidArgumentException to be thrown.' ) ;
        }
        catch( InvalidArgumentException $e )
        {
            $this->assertStringContainsString( "key 'broken_key'" , $e->getMessage() ) ;
        }
    }

    // =========================================================================
    // hasSecret
    // =========================================================================

    public function testHasSecretReturnsFalseForEmptyString() :void
    {
        $descriptor = $this->validDescriptor( '' ) ;

        $this->assertFalse( $descriptor->hasSecret() ) ;
    }

    public function testHasSecretReturnsTrueForNonEmptyString() :void
    {
        $descriptor = $this->validDescriptor( 'hmac' ) ;

        $this->assertTrue( $descriptor->hasSecret() ) ;
    }

    // =========================================================================
    // withSecret — immutability contract
    // =========================================================================

    public function testWithSecretReturnsNewInstance() :void
    {
        $original = $this->validDescriptor( 'old-secret' ) ;
        $rotated  = $original->withSecret( 'new-secret' ) ;

        $this->assertNotSame( $original , $rotated ) ;
        $this->assertSame   ( 'old-secret' , $original->secret ) ;
        $this->assertSame   ( 'new-secret' , $rotated->secret  ) ;
    }

    public function testWithSecretPreservesOtherFields() :void
    {
        $original = $this->validDescriptor( 'old-secret' ) ;
        $rotated  = $original->withSecret( 'new-secret' ) ;

        $this->assertSame( $original->key   , $rotated->key   ) ;
        $this->assertSame( $original->event , $rotated->event ) ;
        $this->assertSame( $original->label , $rotated->label ) ;
        $this->assertSame( $original->route , $rotated->route ) ;
    }

    public function testWithSecretAcceptsEmptyToReset() :void
    {
        $original = $this->validDescriptor( 'old-secret' ) ;
        $blanked  = $original->withSecret( '' ) ;

        $this->assertSame( '' , $blanked->secret ) ;
        $this->assertFalse( $blanked->hasSecret() ) ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validDescriptor( string $secret = '' ) :ZitadelWebhookDescriptor
    {
        return new ZitadelWebhookDescriptor
        (
            key    : 'password_changed' ,
            event  : 'user.human.password.changed' ,
            label  : 'webhook password' ,
            route  : '/webhooks/zitadel/password-changed' ,
            secret : $secret
        ) ;
    }
}
