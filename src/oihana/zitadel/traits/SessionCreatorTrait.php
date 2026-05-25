<?php

namespace oihana\zitadel\traits;

use Throwable;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\enums\Boolean;
use oihana\enums\HashAlgorithm;
use oihana\enums\http\HttpHeader;

use xyz\oihana\schema\auth\Invitation;
use xyz\oihana\schema\auth\Session;
use xyz\oihana\schema\auth\User;
use xyz\oihana\schema\constants\InvitationStatus;
use xyz\oihana\schema\constants\JwtClaim;
use xyz\oihana\schema\constants\UserStatus;

use org\schema\constants\Schema;

use org\iso\Iso8601Format;

use function oihana\auth\jwt\helpers\extractSidFromClaims;
use function oihana\http\helpers\ips\getClientIp;
use function oihana\arango\db\binds\aqlBind;
use function oihana\arango\db\operators\equal;
use function oihana\core\strings\key;

/**
 * Provides reusable ArangoDB session plumbing for Zitadel-backed authentication.
 *
 * The host class is expected to declare the following properties:
 * - Documents|null           $sessionsModel        Sessions Documents model (required for session creation).
 * - Documents|null           $usersModel           Users Documents model (optional — enables user _key resolution and first-login activation).
 * - Documents|null           $invitationsModel     Invitations Documents model (optional — enables pending invitation acceptance on first login).
 * - OAuthClientResolver|null $oauthClientResolver  Resolver used to enrich Session.name with the human-readable Zitadel app label (optional).
 * - int                      $sessionDuration      Session lifetime in seconds.
 *
 * The host class must also inherit from Controller (which exposes $this->logger via LoggerTrait).
 *
 * All methods are best-effort: failures are logged but never propagated. Session
 * creation must not break an otherwise valid authentication flow.
 *
 * @package oihana\zitadel\traits
 * @author  Marc Alcaraz
 */
trait SessionCreatorTrait
{
    /**
     * Creates (or upserts) a session document in ArangoDB after a successful authentication.
     *
     * The upsert key is `[identifier, clientId, userAgent, active]` so the same Zitadel
     * user keeps distinct sessions per OIDC client (e.g. API /login vs external NextJS app)
     * and per device (different browsers / mobile / tablet). When a matching row already
     * exists, only its token/expiration/IP/metadata fields are mutated in place — this
     * preserves stability across the silent refresh flow used by external PKCE clients
     * (PHP never observes the refresh so each new token would otherwise stack a fresh row).
     *
     * When `$identifier` or `$clientId` is null, the `sub` / `azp` claims are extracted by
     * manually decoding the JWT payload. No signature verification happens here — callers
     * must have vouched for the token upstream (Zitadel token exchange in /callback, or
     * JWT middleware for Bearer requests). When the token has already been decoded upstream,
     * the caller should pass the pre-decoded values to avoid re-parsing.
     *
     * When `$idTokenClaims` is provided, the `sid` claim is captured into
     * `session.id` (Schema.org `@id`). This anchors the Arango row to the
     * exact Zitadel session it mirrors and unlocks downstream propagation
     * (e.g. `DELETE /v2/sessions/{sid}` at revoke time). At insert time only
     * — the upsert/update path is handled by the auto-repair step in
     * `CheckJwtAuthentication` (separate concern).
     *
     * @param Request|null      $request       Incoming request (for client IP + User-Agent).
     * @param string|null       $accessToken   The access token whose SHA-256 hash indexes the session.
     * @param string|null       $identifier    Optional pre-decoded Zitadel `sub` claim.
     * @param string|null       $clientId      Optional pre-decoded Zitadel `azp` claim.
     * @param object|array|null $idTokenClaims Optional decoded id_token claims (object from
     *                                         firebase/php-jwt or array from a manual decode).
     *                                         When present, the `sid` claim is persisted into
     *                                         `session.id`.
     *
     * @return string|null The resolved ArangoDB user `_key`, or null on failure / unknown user.
     */
    protected function createSession
    (
        ?Request           $request                ,
        ?string            $accessToken            ,
        ?string            $identifier     = null  ,
        ?string            $clientId       = null  ,
        object|array|null  $idTokenClaims  = null
    )
    :?string
    {
        if( !$this->sessionsModel || !$accessToken )
        {
            return null ;
        }

        try
        {
            if( !$identifier || !$clientId )
            {
                $claims     = $this->extractClaimsFromAccessToken( $accessToken ) ?? [] ;
                $clientId   = $clientId   ?? ( $claims[ JwtClaim::CLIENT_ID ] ?? $claims[ JwtClaim::AZP ] ?? null ) ;
                $identifier = $identifier ?? ( $claims[ JwtClaim::SUBJECT ] ?? null ) ;
                // Zitadel access tokens carry the OIDC `client_id` claim; `azp`
                // is only populated on ID tokens, so it's the fallback here.
            }

            if( !$identifier )
            {
                return null ;
            }

            $userKey = null ;
            $user    = null ;

            if( $this->usersModel )
            {
                $user    = $this->usersModel->get([ Arango::KEY => Schema::IDENTIFIER , Arango::VALUE => $identifier ]) ;
                $userKey = $user->_key ?? null ;
            }

            // Lifecycle gate: refuse session creation when the user is known
            // and its status is not active (disabled, suspended, archived, …).
            // A null status from a never-backfilled legacy user also blocks —
            // operators must run `auth:users:backfill-lifecycle` once before
            // this gate ships, see docs/fr/auth/auth.md.
            // Unknown users (no Arango mirror yet) are intentionally left
            // through so first-login provisioning paths still work.
            if( $user !== null && ( $user->status ?? null ) !== UserStatus::ACTIVE )
            {
                $observed = $user->status ?? 'null' ;
                $this->logger?->warning( "Session refused for user $userKey: status='$observed' (required '" . UserStatus::ACTIVE . "')" ) ;
                return null ;
            }

            $now       = gmdate( Iso8601Format::DATE_TIME_ZULU ) ;
            $expiresAt = gmdate( Iso8601Format::DATE_TIME_ZULU  , time() + $this->sessionDuration ) ;
            $tokenHash = hash( HashAlgorithm::SHA256 , $accessToken ) ;
            $ip        = getClientIp( $request ) ;
            $userAgent = $request?->getHeaderLine( HttpHeader::USER_AGENT ) ?: null ;
            $clientName = $this->oauthClientResolver?->resolve( $clientId ) ;

            // Internal upsert lookup — raw AQL conditions + binds instead of
            // AQL::FILTER (whitelist-based). These columns are server-private
            // for this call-site, we don't want to couple a backend flow to
            // the URL-filter whitelist surface.
            $binds    = [] ;
            $existing = $this->sessionsModel->list
            ([
                AQL::CONDITIONS =>
                [
                    equal( key( Schema::IDENTIFIER  , AQL::DOC ) , aqlBind( $identifier , $binds , 'sessionIdentifier' ) ) ,
                    equal( key( Session::CLIENT_ID  , AQL::DOC ) , aqlBind( $clientId   , $binds , 'sessionClientId'   ) ) ,
                    equal( key( Session::USER_AGENT , AQL::DOC ) , aqlBind( $userAgent  , $binds , 'sessionUserAgent'  ) ) ,
                    equal( key( Schema::ACTIVE      , AQL::DOC ) , Boolean::TRUE                                    ) ,
                ] ,
                AQL::BINDS => $binds ,
                AQL::LIMIT => 1 ,
            ]) ?? [] ;

            $session = $existing[ 0 ] ?? null ;

            if( $session && !empty( $session->_key ) )
            {
                $updateDoc =
                [
                    Session::TOKEN_HASH => $tokenHash ,
                    Session::EXPIRES_AT => $expiresAt ,
                    Session::IP         => $ip ,
                    Schema::MODIFIED    => $now ,
                ] ;

                // Refresh the app label on every update so a rename on the
                // Zitadel side propagates without needing a full resync,
                // and so rows created before the field existed get backfilled.
                $currentName = $session->name ?? null ;

                if( $clientName !== null && $clientName !== $currentName )
                {
                    $updateDoc[ Schema::NAME ] = $clientName ;
                }

                $this->sessionsModel->update
                ([
                    Arango::KEY   => Schema::_KEY ,
                    Arango::VALUE => $session->_key ,
                    Arango::DOC   => $updateDoc ,
                ]) ;

                $this->logger?->info( "Session refreshed for user $identifier (client $clientId)" ) ;

                return $session->userId ?? $userKey ;
            }

            $this->sessionsModel->insert
            ([
                Arango::DOC =>
                [
                    Schema::ACTIVE      => true ,
                    Schema::ID          => extractSidFromClaims( $idTokenClaims ) ,
                    Schema::NAME        => $clientName ,
                    Session::CLIENT_ID  => $clientId ,
                    Schema::IDENTIFIER  => $identifier ,
                    Session::IP         => $ip ,
                    Session::USER_AGENT => $userAgent ,
                    Session::USER_ID    => $userKey ,
                    Session::TOKEN_HASH => $tokenHash ,
                    Session::REVOKED_AT => null ,
                    Session::EXPIRES_AT => $expiresAt ,
                ] ,
            ]) ;

            $this->logger?->info( "Session created for user $identifier (client $clientId)" ) ;

            return $userKey ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "Failed to create session: " . $e->getMessage() ) ;
            return null ;
        }
    }

    /**
     * Extracts the JWT payload claims (`sub`, `azp`, `sid`, ...) from a raw JWT.
     *
     * Originally named after the access token use-case but generic enough to
     * decode any JWT-shaped string (ID Token, access token, refresh-as-JWT…).
     * No signature verification is performed — this is meant to be called only
     * after upstream validation (Zitadel token exchange or JWT middleware) has
     * already vouched for the token's authenticity.
     *
     * Visibility is `protected` so consuming controllers can reuse the helper
     * to peek at ID Token claims when the access token is unsuitable.
     *
     * @param string $accessToken The raw JWT.
     *
     * @return array<string, mixed>|null The decoded payload or null when it can't be parsed.
     */
    protected function extractClaimsFromAccessToken( string $accessToken ) :?array
    {
        $parts = explode( '.' , $accessToken ) ;

        if( count( $parts ) !== 3 )
        {
            return null ;
        }

        $payload = json_decode( base64_decode( strtr( $parts[ 1 ] , '-_' , '+/' ) ) , true ) ;

        return is_array( $payload ) ? $payload : null ;
    }

    /**
     * Reports whether a `lazyCreateSession` bootstrap attempt must be
     * refused because the incoming id_token carries a `sid` claim
     * that matches an already-revoked session row.
     *
     * The OIDC `sid` claim is sticky across silent refreshes of the
     * same Zitadel session — it changes only when the user performs
     * a fresh authentication (password / MFA). When admin or self-
     * service revocation has flipped a row to `active = false`, any
     * subsequent silent refresh forwarded by the SPA still carries
     * the same `sid` and MUST be refused here ; otherwise a fresh
     * active row would be inserted behind the revocation's back,
     * silently resurrecting the killed session.
     *
     * Fail-open in every "we don't know" scenario :
     *
     * - no id_token claims attached (M2M, legacy callers),
     * - no `sid` claim or non-string value,
     * - no row in the sessions collection carries this `sid` (the
     *   user is re-logging from a fresh Zitadel session whose sid
     *   the API has never observed before — legitimate bootstrap).
     *
     * Defense-in-depth net complementing the Zitadel revoke
     * propagation `DELETE /v2/sessions/{sid}` : the propagation
     * cuts the refresh chain at the source, but this guard catches
     * the residual case where the propagation timed out, errored,
     * or Zitadel kept the refresh token usable past the session
     * termination.
     *
     * @param object|array|null $idTokenClaims The validated id_token claims.
     *
     * @return bool True when the bootstrap must be refused.
     */
    protected function isSidRevoked( object|array|null $idTokenClaims ) :bool
    {
        if( $idTokenClaims === null || !$this->sessionsModel )
        {
            return false ;
        }

        $sid = extractSidFromClaims( $idTokenClaims ) ;

        if( $sid === null )
        {
            return false ;
        }

        try
        {
            $row = $this->sessionsModel->get
            ([
                Arango::KEY   => Schema::ID ,
                Arango::VALUE => $sid ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "isSidRevoked lookup failed: {$e->getMessage()}" ) ;
            return false ;
        }

        if( $row === null )
        {
            return false ;
        }

        return ( $row->active ?? false ) === false ;
    }

    /**
     * Records a successful authentication on the user document.
     *
     * Always bumps `lastLogin` to now and increments `loginsCount`.
     *
     * On the first successful login (when `activated !== true`) it also flips
     * `activated` to true, stamps `firstLoginAt`, materializes
     * `invitationStatus = accepted` and marks any pending invitation row as
     * accepted. Subsequent calls only refresh the counters.
     *
     * Failures are logged but never propagated: a race here must not block the
     * caller's flow (login redirect or authenticated API request).
     *
     * @param string $userKey The ArangoDB user `_key`.
     *
     * @return void
     */
    protected function recordSuccessfulLogin( string $userKey ) :void
    {
        if( !$this->usersModel )
        {
            return ;
        }

        try
        {
            $user = $this->usersModel->get([ Arango::VALUE => $userKey ]) ;

            if( !$user )
            {
                return ;
            }

            $now     = gmdate( Iso8601Format::DATE_TIME_ZULU  ) ;
            $isFirst = ( $user->activated ?? false ) !== true ;

            $doc =
            [
                User::LAST_LOGIN   => $now ,
                User::LOGINS_COUNT => ( (int) ( $user->loginsCount ?? 0 ) ) + 1 ,
                Schema::MODIFIED   => $now ,
            ] ;

            if( $isFirst )
            {
                $doc[ User::ACTIVATED         ] = true ;
                $doc[ User::FIRST_LOGIN_AT    ] = $now ;
                $doc[ User::INVITATION_STATUS ] = InvitationStatus::ACCEPTED ;
            }

            $this->usersModel->update
            ([
                Arango::KEY   => Schema::_KEY ,
                Arango::VALUE => $userKey ,
                Arango::DOC   => $doc ,
            ]) ;

            if( $isFirst )
            {
                $this->markInvitationAccepted( $userKey , $now ) ;
                $this->logger?->info( "User $userKey activated on first login" ) ;
            }
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "Failed to record login for user $userKey: " . $e->getMessage() ) ;
        }
    }

    /**
     * Transitions a pending invitation for the given user (if any) to `accepted`.
     *
     * A user may have multiple historical invitations (e.g. resent then expired) —
     * only the one still in `pending` status is flipped.
     *
     * All exceptions are swallowed: invitation bookkeeping must not break the login.
     *
     * @param string $userKey The ArangoDB user `_key`.
     * @param string $now     Current ISO 8601 UTC timestamp.
     *
     * @return void
     */
    private function markInvitationAccepted( string $userKey , string $now ) :void
    {
        if( !$this->invitationsModel )
        {
            return ;
        }

        try
        {
            $binds   = [] ;
            $results = $this->invitationsModel->list
            ([
                AQL::CONDITIONS =>
                [
                    equal( key( Schema::OBJECT        , AQL::DOC ) , aqlBind( $userKey                                , $binds , 'invitationUserKey' ) ) ,
                    equal( key( Schema::ACTION_STATUS , AQL::DOC ) , aqlBind( Invitation::ACTION_STATUS_PENDING , $binds , 'invitationStatus'  ) ) ,
                ] ,
                AQL::BINDS => $binds ,
                AQL::LIMIT => 1 ,
            ]) ?? [] ;

            $invitation = $results[ 0 ] ?? null ;

            if( !$invitation || empty( $invitation->_key ) )
            {
                return ;
            }

            $this->invitationsModel->update
            ([
                Arango::KEY   => Schema::_KEY ,
                Arango::VALUE => $invitation->_key ,
                Arango::DOC   =>
                [
                    Schema::ACTION_STATUS => Invitation::ACTION_STATUS_ACCEPTED ,
                    Schema::END_TIME      => $now ,
                    Schema::MODIFIED      => $now ,
                ] ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "Failed to mark invitation accepted: " . $e->getMessage() ) ;
        }
    }
}
