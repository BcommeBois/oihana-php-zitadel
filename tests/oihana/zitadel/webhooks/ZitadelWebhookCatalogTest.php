<?php

namespace tests\oihana\zitadel\webhooks;

use InvalidArgumentException;

use oihana\zitadel\webhooks\ZitadelWebhookCatalog;
use oihana\zitadel\webhooks\ZitadelWebhookDescriptor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelWebhookCatalog} — the immutable registry that
 * groups every {@see ZitadelWebhookDescriptor} the API consumes.
 *
 * Coverage focuses on the `fromConfig` builder (boot-time loading from
 * `config.toml`), the lookup contract, and the duplicate-key rejection
 * guard.
 */
#[CoversClass( ZitadelWebhookCatalog::class )]
class ZitadelWebhookCatalogTest extends TestCase
{
    // =========================================================================
    // Construction
    // =========================================================================

    public function testEmptyCatalogReturnsEmptyArrays() :void
    {
        $catalog = new ZitadelWebhookCatalog() ;

        $this->assertSame( [] , $catalog->all()  ) ;
        $this->assertSame( [] , $catalog->keys() ) ;
        $this->assertFalse( $catalog->has( 'password_changed' ) ) ;
        $this->assertNull ( $catalog->get( 'password_changed' ) ) ;
    }

    public function testConstructorIndexesDescriptorsByKey() :void
    {
        $passwordChanged = $this->descriptor( 'password_changed' , 'user.human.password.changed' ) ;
        $emailChanged    = $this->descriptor( 'email_changed'    , 'user.human.email.changed'    ) ;

        $catalog = new ZitadelWebhookCatalog([ $passwordChanged , $emailChanged ]) ;

        $this->assertTrue( $catalog->has( 'password_changed' ) ) ;
        $this->assertTrue( $catalog->has( 'email_changed'    ) ) ;
        $this->assertSame( $passwordChanged , $catalog->get( 'password_changed' ) ) ;
        $this->assertSame( $emailChanged    , $catalog->get( 'email_changed'    ) ) ;
    }

    public function testConstructorRejectsDuplicateKeys() :void
    {
        $first  = $this->descriptor( 'password_changed' , 'user.human.password.changed' ) ;
        $second = $this->descriptor( 'password_changed' , 'user.human.password.changed' ) ;

        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'duplicate descriptor' ) ;

        new ZitadelWebhookCatalog([ $first , $second ]) ;
    }

    public function testConstructorRejectsNonDescriptorEntries() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'ZitadelWebhookDescriptor' ) ;

        /** @phpstan-ignore-next-line — deliberate misuse */
        new ZitadelWebhookCatalog([ 'not-a-descriptor' ]) ;
    }

    // =========================================================================
    // fromConfig
    // =========================================================================

    public function testFromConfigBuildsFromValidSection() :void
    {
        $catalog = ZitadelWebhookCatalog::fromConfig
        ([
            'password_changed' =>
            [
                'event'  => 'user.human.password.changed' ,
                'label'  => 'webhook password' ,
                'route'  => '/webhooks/zitadel/password-changed' ,
                'secret' => 'hmac-1' ,
            ] ,
            'email_changed' =>
            [
                'event'  => 'user.human.email.changed' ,
                'label'  => 'webhook email' ,
                'route'  => '/webhooks/zitadel/email-changed' ,
                'secret' => 'hmac-2' ,
            ] ,
        ]) ;

        $this->assertSame( [ 'password_changed' , 'email_changed' ] , $catalog->keys() ) ;
        $this->assertSame( 'hmac-1' , $catalog->get( 'password_changed' )?->secret ) ;
        $this->assertSame( 'hmac-2' , $catalog->get( 'email_changed'    )?->secret ) ;
    }

    public function testFromConfigIgnoresLegacyFlatEntries() :void
    {
        // Legacy config used a flat string under [zitadel.webhooks].
        // The catalogue must skip those without crashing so an
        // unconverted file boots into an empty catalogue.
        $catalog = ZitadelWebhookCatalog::fromConfig
        ([
            'password_changed' => 'legacy-flat-secret' ,
            'email_changed'    =>
            [
                'event'  => 'user.human.email.changed' ,
                'label'  => 'webhook email' ,
                'route'  => '/webhooks/zitadel/email-changed' ,
                'secret' => 'hmac' ,
            ] ,
        ]) ;

        $this->assertFalse( $catalog->has( 'password_changed' ) ) ;
        $this->assertTrue ( $catalog->has( 'email_changed'    ) ) ;
    }

    public function testFromConfigPropagatesDescriptorValidationErrors() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( "key 'password_changed'" ) ;

        ZitadelWebhookCatalog::fromConfig
        ([
            'password_changed' =>
            [
                'event' => 'malformed' ,
                'label' => 'webhook password' ,
                'route' => '/webhooks/zitadel/password-changed' ,
            ] ,
        ]) ;
    }

    public function testFromConfigAcceptsEmptySection() :void
    {
        $catalog = ZitadelWebhookCatalog::fromConfig( [] ) ;

        $this->assertSame( [] , $catalog->keys() ) ;
    }

    // =========================================================================
    // Lookup
    // =========================================================================

    public function testGetReturnsNullForUnknownKey() :void
    {
        $catalog = new ZitadelWebhookCatalog([ $this->descriptor( 'password_changed' , 'user.human.password.changed' ) ]) ;

        $this->assertNull( $catalog->get( 'nope' ) ) ;
        $this->assertFalse( $catalog->has( 'nope' ) ) ;
    }

    public function testKeysPreservesInsertionOrder() :void
    {
        $catalog = new ZitadelWebhookCatalog
        ([
            $this->descriptor( 'session_added'    , 'session.added'                ) ,
            $this->descriptor( 'password_changed' , 'user.human.password.changed'  ) ,
            $this->descriptor( 'email_changed'    , 'user.human.email.changed'     ) ,
        ]) ;

        $this->assertSame
        (
            [ 'session_added' , 'password_changed' , 'email_changed' ] ,
            $catalog->keys()
        ) ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function descriptor( string $key , string $event ) :ZitadelWebhookDescriptor
    {
        return new ZitadelWebhookDescriptor
        (
            key   : $key ,
            event : $event ,
            label : 'webhook ' . $key ,
            route : '/webhooks/zitadel/' . str_replace( '_' , '-' , $key )
        ) ;
    }
}
