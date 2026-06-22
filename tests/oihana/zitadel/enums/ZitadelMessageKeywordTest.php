<?php

namespace tests\oihana\zitadel\enums;

use oihana\zitadel\enums\ZitadelMessageKeyword;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ZitadelMessageKeyword} — the lowercased-substring
 * fallback used to discriminate Zitadel error bodies when the stable
 * error-id catalogue did not match.
 *
 * The caller is contractually responsible for lowercasing the body once
 * before calling {@see ZitadelMessageKeyword::bodyMatchesAny()}; these
 * tests pin that contract.
 */
#[CoversClass( ZitadelMessageKeyword::class )]
class ZitadelMessageKeywordTest extends TestCase
{
    public function testBodyMatchesAnyReturnsTrueWhenANeedleIsPresent() :void
    {
        $body = 'code is expired (code-qvuq4p)' ;

        $this->assertTrue
        (
            ZitadelMessageKeyword::bodyMatchesAny( $body , ZitadelMessageKeyword::VERIFICATION_CODE_FAILURE )
        ) ;
    }

    public function testBodyMatchesAnyReturnsFalseWhenNoNeedleMatches() :void
    {
        $body = 'something completely unrelated' ;

        $this->assertFalse
        (
            ZitadelMessageKeyword::bodyMatchesAny( $body , ZitadelMessageKeyword::VERIFICATION_CODE_FAILURE )
        ) ;
    }

    public function testBodyMatchesAnyMatchesWrongCurrentPasswordFamily() :void
    {
        $body = 'password is invalid (command-3m0fs)' ;

        $this->assertTrue
        (
            ZitadelMessageKeyword::bodyMatchesAny( $body , ZitadelMessageKeyword::WRONG_CURRENT_PASSWORD )
        ) ;
    }

    public function testBodyMatchesAnyIsCaseSensitiveOnTheBody() :void
    {
        // The needles are lowercase; an unlowered body must NOT match —
        // this is the contract that forces callers to lowercase first.
        $body = 'CODE IS EXPIRED' ;

        $this->assertFalse
        (
            ZitadelMessageKeyword::bodyMatchesAny( $body , ZitadelMessageKeyword::VERIFICATION_CODE_FAILURE )
        ) ;
    }

    public function testBodyMatchesAnyReturnsFalseOnEmptyKeywordList() :void
    {
        $this->assertFalse( ZitadelMessageKeyword::bodyMatchesAny( 'code is expired' , [] ) ) ;
    }

    public function testBodyMatchesAnyReturnsFalseOnEmptyBody() :void
    {
        $this->assertFalse
        (
            ZitadelMessageKeyword::bodyMatchesAny( '' , ZitadelMessageKeyword::VERIFICATION_CODE_FAILURE )
        ) ;
    }

    public function testVerificationCodeFamilyExcludesPasswordNeedles() :void
    {
        $body = 'password mismatch' ;

        $this->assertFalse
        (
            ZitadelMessageKeyword::bodyMatchesAny( $body , ZitadelMessageKeyword::VERIFICATION_CODE_FAILURE )
        ) ;
    }
}
