<?php

namespace oihana\zitadel\schema\constants\traits;

/**
 * Constants for Zitadel User properties.
 */
trait UserTrait
{
    public const string USER_NAME                = 'userName' ;            // v1
    public const string USERNAME                 = 'username' ;            // v2 (lowercase 'name')
    public const string HUMAN                    = 'human' ;               // v2 — discriminator on UpdateUser body (vs `machine`)
    public const string PROFILE                  = 'profile' ;
    public const string FIRST_NAME               = 'firstName' ;           // v1 — profile.firstName
    public const string LAST_NAME                = 'lastName' ;            // v1 — profile.lastName
    public const string GIVEN_NAME               = 'givenName' ;           // v2 — profile.givenName (Schema.org)
    public const string FAMILY_NAME              = 'familyName' ;          // v2 — profile.familyName (Schema.org)
    public const string DISPLAY_NAME             = 'displayName' ;
    public const string EMAIL                    = 'email' ;                // outer key in both v1 and v2 ; ALSO the inner key in v2 (email.email)
    public const string IS_EMAIL_VERIFIED        = 'isEmailVerified' ;     // v1 — email.isEmailVerified
    public const string IS_VERIFIED              = 'isVerified' ;          // v2 — email.isVerified
    public const string PASSWORD                 = 'password' ;
    public const string PASSWORD_CHANGE_REQUIRED = 'passwordChangeRequired' ;
    public const string CHANGE_REQUIRED          = 'changeRequired' ;

    // ------- Email verification (V2) -- discriminated `verification` oneof on
    // the email update payload : pick `returnCode` to receive the code in the
    // response (we send our own MJML mail), `sendCode` to let Zitadel send
    // its own templated mail, or set `isVerified=true` to skip verification
    // entirely (admin-trusted scenarios). The dedicated verify endpoint
    // expects a top-level `verificationCode` field (no nesting).

    public const string VERIFICATION             = 'verification' ;          // v2 — email.verification (oneof)
    public const string RETURN_CODE              = 'returnCode' ;            // v2 — email.verification.returnCode
    public const string SEND_CODE                = 'sendCode' ;              // v2 — email.verification.sendCode
    public const string VERIFICATION_CODE        = 'verificationCode' ;      // v2 — body of POST /v2/users/{id}/email/verify
}
