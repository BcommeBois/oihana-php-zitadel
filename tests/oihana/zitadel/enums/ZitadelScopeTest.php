<?php

namespace tests\oihana\zitadel\enums;

use oihana\zitadel\enums\ZitadelScope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelScope} — OAuth2 / OIDC scope tokens and the two
 * composition helpers (`compose()`, `projectAudience()`).
 */
#[CoversClass( ZitadelScope::class )]
class ZitadelScopeTest extends TestCase
{
    // =========================================================================
    // compose()
    // =========================================================================

    public function testComposeJoinsScopesWithASingleSpace() :void
    {
        $this->assertSame
        (
            'openid profile email' ,
            ZitadelScope::compose( ZitadelScope::OPENID , ZitadelScope::PROFILE , ZitadelScope::EMAIL )
        ) ;
    }

    public function testComposeWithASingleScopeReturnsItVerbatim() :void
    {
        $this->assertSame( 'openid' , ZitadelScope::compose( ZitadelScope::OPENID ) ) ;
    }

    public function testComposeWithNoArgumentsReturnsEmptyString() :void
    {
        $this->assertSame( '' , ZitadelScope::compose() ) ;
    }

    public function testComposeIntegratesAProjectAudience() :void
    {
        $scope = ZitadelScope::compose
        (
            ZitadelScope::OPENID ,
            ZitadelScope::projectAudience( '123456789' )
        ) ;

        $this->assertSame( 'openid urn:zitadel:iam:org:project:id:123456789:aud' , $scope ) ;
    }

    // =========================================================================
    // projectAudience()
    // =========================================================================

    public function testProjectAudienceFormatsTheUrn() :void
    {
        $this->assertSame
        (
            'urn:zitadel:iam:org:project:id:proj-42:aud' ,
            ZitadelScope::projectAudience( 'proj-42' )
        ) ;
    }

    // =========================================================================
    // Scope constants
    // =========================================================================

    public function testScopeConstants() :void
    {
        $this->assertSame( 'email'          , ZitadelScope::EMAIL ) ;
        $this->assertSame( 'openid'         , ZitadelScope::OPENID ) ;
        $this->assertSame( 'profile'        , ZitadelScope::PROFILE ) ;
        $this->assertSame( 'offline_access' , ZitadelScope::OFFLINE_ACCESS ) ;
        $this->assertSame( 'urn:zitadel:iam:org:project:id:zitadel:aud' , ZitadelScope::ZITADEL_MANAGEMENT ) ;
    }
}
