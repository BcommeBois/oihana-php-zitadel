<?php

namespace tests\oihana\zitadel\enums;

use oihana\zitadel\enums\ZitadelErrorId;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelErrorId} — the Zitadel error identifier
 * catalogue and its helper methods.
 *
 * The helpers are reused by any controller that proxies a Zitadel
 * call and needs to map a failure mode to an API-side outcome on the
 * consuming application side.
 */
#[CoversClass( ZitadelErrorId::class )]
class ZitadelErrorIdTest extends TestCase
{
    // =========================================================================
    // extractErrorId
    // =========================================================================

    public function testExtractErrorIdFromExpiredCodeMessage() :void
    {
        $rawBody = '{"code":9,"message":"Code is expired (CODE-QvUQ4P)","details":[]}' ;

        $this->assertSame( 'CODE-QvUQ4P' , ZitadelErrorId::extractErrorId( $rawBody ) ) ;
    }

    public function testExtractErrorIdFromInvalidCodeMessage() :void
    {
        $rawBody = '{"message":"Code is invalid (CODE-woT0xc)"}' ;

        $this->assertSame( 'CODE-woT0xc' , ZitadelErrorId::extractErrorId( $rawBody ) ) ;
    }

    public function testExtractErrorIdFromCommandPrefix() :void
    {
        $rawBody = '{"message":"Code not found (COMMAND-2M9fs)"}' ;

        $this->assertSame( 'COMMAND-2M9fs' , ZitadelErrorId::extractErrorId( $rawBody ) ) ;
    }

    public function testExtractErrorIdFromPasswordPolicyMessage() :void
    {
        // Password complexity errors use COMMA-/DOMAIN- prefixes.
        $rawBody = '{"message":"Password is too short (COMMA-HuJf6)"}' ;

        $this->assertSame( 'COMMA-HuJf6' , ZitadelErrorId::extractErrorId( $rawBody ) ) ;
    }

    public function testExtractErrorIdReturnsNullOnEmptyBody() :void
    {
        $this->assertNull( ZitadelErrorId::extractErrorId( '' ) ) ;
    }

    public function testExtractErrorIdReturnsNullWhenNoIdPresent() :void
    {
        // Older releases or non-Zitadel responses without the trailing tag.
        $this->assertNull( ZitadelErrorId::extractErrorId( '{"message":"Some plain error"}' ) ) ;
    }

    public function testExtractErrorIdReturnsFirstMatchOnly() :void
    {
        // Defensive — if for some reason a body carries two tags, we
        // surface the first one (consistent ordering).
        $rawBody = '(CODE-QvUQ4P) (COMMAND-2M9fs)' ;

        $this->assertSame( 'CODE-QvUQ4P' , ZitadelErrorId::extractErrorId( $rawBody ) ) ;
    }

    // =========================================================================
    // bodyMatchesAny
    // =========================================================================

    public function testBodyMatchesAnyReturnsTrueWhenIdInList() :void
    {
        $rawBody = '{"message":"Code is expired (CODE-QvUQ4P)"}' ;

        $this->assertTrue( ZitadelErrorId::bodyMatchesAny( $rawBody , ZitadelErrorId::VERIFICATION_CODE_FAILURE_IDS ) ) ;
    }

    public function testBodyMatchesAnyReturnsFalseWhenIdNotInList() :void
    {
        $rawBody = '{"message":"Password is too short (COMMA-HuJf6)"}' ;

        $this->assertFalse( ZitadelErrorId::bodyMatchesAny( $rawBody , ZitadelErrorId::VERIFICATION_CODE_FAILURE_IDS ) ) ;
    }

    public function testBodyMatchesAnyReturnsFalseWhenNoIdPresent() :void
    {
        $this->assertFalse( ZitadelErrorId::bodyMatchesAny( '{"message":"plain"}' , ZitadelErrorId::VERIFICATION_CODE_FAILURE_IDS ) ) ;
    }

    public function testBodyMatchesAnyReturnsFalseOnEmptyBody() :void
    {
        $this->assertFalse( ZitadelErrorId::bodyMatchesAny( '' , ZitadelErrorId::VERIFICATION_CODE_FAILURE_IDS ) ) ;
    }

    // =========================================================================
    // WRONG_CURRENT_PASSWORD_IDS aggregate
    // =========================================================================

    public function testBodyMatchesWrongCurrentPasswordOnInvalidId() :void
    {
        $rawBody = '{"code":3,"message":"Password is invalid (COMMAND-3M0fs)"}' ;

        $this->assertTrue( ZitadelErrorId::bodyMatchesAny( $rawBody , ZitadelErrorId::WRONG_CURRENT_PASSWORD_IDS ) ) ;
    }

    public function testBodyMatchesWrongCurrentPasswordOnNotChangedId() :void
    {
        $rawBody = '{"code":9,"message":"Password not changed (COMMAND-Aesh5)"}' ;

        $this->assertTrue( ZitadelErrorId::bodyMatchesAny( $rawBody , ZitadelErrorId::WRONG_CURRENT_PASSWORD_IDS ) ) ;
    }

    public function testBodyMatchesWrongCurrentPasswordReturnsFalseOnVerificationCodeId() :void
    {
        // Sanity — verification-code IDs must NOT be matched by the
        // wrong-current-password aggregate (they belong to a different
        // semantic family).
        $rawBody = '{"message":"Code is expired (CODE-QvUQ4P)"}' ;

        $this->assertFalse( ZitadelErrorId::bodyMatchesAny( $rawBody , ZitadelErrorId::WRONG_CURRENT_PASSWORD_IDS ) ) ;
    }
}
