<?php

namespace tests\oihana\zitadel\commands;

use oihana\zitadel\commands\ZitadelWebhookCommand;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure helpers exposed by
 * {@see ZitadelWebhookCommand}. The helpers drive the canonical Cible
 * naming, the localhost/private-IP detection that gates `--endpoint`
 * requirement, and the in-place TOML secret rewrite.
 *
 * The action handlers themselves (install / rotate / uninstall / …)
 * are exercised end-to-end via the manual playbook in
 * `docs/fr/zitadel/webhooks.md` since they touch the live Zitadel V2
 * API.
 */
#[CoversClass( ZitadelWebhookCommand::class )]
class ZitadelWebhookCommandTest extends TestCase
{
    // =========================================================================
    // buildCanonicalName
    // =========================================================================

    public function testBuildCanonicalNameWithLocalhostBaseUrl() :void
    {
        $name = ZitadelWebhookCommand::buildCanonicalName
        (
            'my-api' ,
            'webhook password' ,
            'https://myapp.localhost'
        ) ;

        $this->assertSame( 'my-api - webhook password - myapp.localhost' , $name ) ;
    }

    public function testBuildCanonicalNameWithProductionBaseUrl() :void
    {
        $name = ZitadelWebhookCommand::buildCanonicalName
        (
            'my-api' ,
            'webhook password' ,
            'https://api.example.com'
        ) ;

        $this->assertSame( 'my-api - webhook password - api.example.com' , $name ) ;
    }

    public function testBuildCanonicalNameStripsPathAndQuery() :void
    {
        $name = ZitadelWebhookCommand::buildCanonicalName
        (
            'my-api' ,
            'webhook password' ,
            'https://api.staging.example.com/some/path?ignored=1'
        ) ;

        $this->assertSame( 'my-api - webhook password - api.staging.example.com' , $name ) ;
    }

    public function testBuildCanonicalNameFallsBackToRawWhenHostIsUnparsable() :void
    {
        // Edge case — a malformed baseUrl with no scheme + no authority
        // yields a null host; the helper falls back to the raw value
        // rather than producing a name with an empty middle segment.
        $name = ZitadelWebhookCommand::buildCanonicalName
        (
            'my-api' ,
            'webhook password' ,
            'not-a-url'
        ) ;

        $this->assertSame( 'my-api - webhook password - not-a-url' , $name ) ;
    }

    // =========================================================================
    // isPublicBaseUrl
    // =========================================================================

    public function testIsPublicBaseUrlAcceptsFqdn() :void
    {
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'https://api.example.com'        ) ) ;
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'https://api.staging.example.com' ) ) ;
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'http://example.org'              ) ) ;
    }

    public function testIsPublicBaseUrlAcceptsPublicIPv4() :void
    {
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'https://8.8.8.8'   ) ) ;
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'https://1.1.1.1'   ) ) ;
        $this->assertTrue( ZitadelWebhookCommand::isPublicBaseUrl( 'https://203.0.113.1' ) ) ;
    }

    public function testIsPublicBaseUrlRejectsLocalhost() :void
    {
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://localhost'                ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'https://myapp.localhost' ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://anything.localhost:8080'  ) ) ;
    }

    public function testIsPublicBaseUrlRejectsLoopback() :void
    {
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://127.0.0.1'   ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://127.10.0.1'  ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://[::1]'       ) ) ;
    }

    public function testIsPublicBaseUrlRejectsRfc1918Ranges() :void
    {
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://10.0.0.1'       ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://10.1.2.3'       ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://172.16.0.1'     ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://172.31.255.255' ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'http://192.168.1.10'   ) ) ;
    }

    public function testIsPublicBaseUrlAcceptsIPv4ButRejectsBoundaryHostsCorrectly() :void
    {
        // 172.32.x is just outside the private range — public.
        $this->assertTrue ( ZitadelWebhookCommand::isPublicBaseUrl( 'http://172.32.0.1' ) ) ;
        // 172.15.x is just below the private range — public.
        $this->assertTrue ( ZitadelWebhookCommand::isPublicBaseUrl( 'http://172.15.0.1' ) ) ;
    }

    public function testIsPublicBaseUrlRejectsEmptyOrMalformedInput() :void
    {
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( ''            ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'not-a-url'   ) ) ;
        $this->assertFalse( ZitadelWebhookCommand::isPublicBaseUrl( 'no-scheme.com' ) ) ;
    }

    // =========================================================================
    // replaceSecretInToml
    // =========================================================================

    public function testReplaceSecretInTomlUpdatesExistingSecret() :void
    {
        $toml     = $this->fixtureToml( 'old-secret' ) ;
        $rewrite  = ZitadelWebhookCommand::replaceSecretInToml( $toml , 'password_changed' , 'new-secret' ) ;

        $this->assertStringContainsString( 'secret = "new-secret"' , $rewrite ) ;
        $this->assertStringNotContainsString( 'old-secret' , $rewrite ) ;
        // Other descriptor fields must be preserved verbatim.
        $this->assertStringContainsString( 'event  = "user.human.password.changed"' , $rewrite ) ;
        $this->assertStringContainsString( 'label  = "webhook password"'            , $rewrite ) ;
        $this->assertStringContainsString( 'route  = "/webhooks/zitadel/password-changed"' , $rewrite ) ;
        // Sibling sections must remain intact.
        $this->assertStringContainsString( '[zitadel.apps]'  , $rewrite ) ;
    }

    public function testReplaceSecretInTomlBlanksSecret() :void
    {
        $toml    = $this->fixtureToml( 'old-secret' ) ;
        $rewrite = ZitadelWebhookCommand::replaceSecretInToml( $toml , 'password_changed' , '' ) ;

        $this->assertStringContainsString   ( 'secret = ""' , $rewrite ) ;
        $this->assertStringNotContainsString( 'old-secret'  , $rewrite ) ;
    }

    public function testReplaceSecretInTomlIsIdempotent() :void
    {
        $toml    = $this->fixtureToml( 'stable' ) ;
        $first   = ZitadelWebhookCommand::replaceSecretInToml( $toml  , 'password_changed' , 'stable' ) ;
        $second  = ZitadelWebhookCommand::replaceSecretInToml( $first , 'password_changed' , 'stable' ) ;

        $this->assertSame( $first , $second ) ;
    }

    public function testReplaceSecretInTomlAppendsSecretWhenMissingFromExistingSection() :void
    {
        $toml = <<<TOML
[zitadel]
issuer = "https://test.zitadel.cloud"

[zitadel.webhooks.password_changed]
event  = "user.human.password.changed"
label  = "webhook password"
route  = "/webhooks/zitadel/password-changed"

[zitadel.apps]
pkce_client_id = "x"
TOML ;

        $rewrite = ZitadelWebhookCommand::replaceSecretInToml( $toml , 'password_changed' , 'fresh' ) ;

        $this->assertStringContainsString( 'secret = "fresh"' , $rewrite ) ;
        $this->assertStringContainsString( '[zitadel.apps]'   , $rewrite ) ;
    }

    public function testReplaceSecretInTomlCreatesSectionWhenAbsent() :void
    {
        $toml = <<<TOML
[zitadel]
issuer = "https://test.zitadel.cloud"

[zitadel.apps]
pkce_client_id = "x"
TOML ;

        $rewrite = ZitadelWebhookCommand::replaceSecretInToml( $toml , 'password_changed' , 'fresh' ) ;

        $this->assertStringContainsString( '[zitadel.webhooks.password_changed]' , $rewrite ) ;
        $this->assertStringContainsString( 'secret = "fresh"'                    , $rewrite ) ;
    }

    public function testReplaceSecretInTomlOnlyTouchesTargetedKey() :void
    {
        $toml = <<<TOML
[zitadel.webhooks.password_changed]
event  = "user.human.password.changed"
label  = "webhook password"
route  = "/webhooks/zitadel/password-changed"
secret = "keep-this"

[zitadel.webhooks.email_changed]
event  = "user.human.email.changed"
label  = "webhook email"
route  = "/webhooks/zitadel/email-changed"
secret = "old-email"
TOML ;

        $rewrite = ZitadelWebhookCommand::replaceSecretInToml( $toml , 'email_changed' , 'new-email' ) ;

        $this->assertStringContainsString   ( 'secret = "keep-this"' , $rewrite ) ;
        $this->assertStringContainsString   ( 'secret = "new-email"' , $rewrite ) ;
        $this->assertStringNotContainsString( 'old-email'            , $rewrite ) ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fixtureToml( string $secret ) :string
    {
        return <<<TOML
[zitadel]
issuer = "https://test.zitadel.cloud"

[zitadel.webhooks.password_changed]
event  = "user.human.password.changed"
label  = "webhook password"
route  = "/webhooks/zitadel/password-changed"
secret = "$secret"

[zitadel.apps]
pkce_client_id = "x"
TOML ;
    }
}
