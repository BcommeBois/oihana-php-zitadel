<?php

namespace oihana\zitadel;

use org\iso\Iso8601Format;
use Throwable;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use xyz\oihana\schema\auth\OAuthClient;

use org\schema\constants\Schema;

use Psr\Log\LoggerInterface;

/**
 * Resolves a Zitadel OAuth2/OIDC `clientId` to a human-readable application name.
 *
 * Strategy (hybrid):
 * 1. Check the in-process memory cache (TTL-bound) — avoids repeated Arango hits.
 * 2. Look up the `oauth_clients` collection — authoritative local mirror.
 * 3. On miss, fetch the application metadata from the Zitadel Management API,
 *    upsert the local `oauth_clients` row (auto-seeding) and return the name.
 * 4. On fetch failure or unknown clientId, a negative cache entry is stored
 *    (still TTL-bound) so repeated calls for the same unknown id do not
 *    hammer Zitadel. The caller receives null and keeps session creation
 *    unblocked.
 *
 * The resolver is stateful (memory cache) so it must be wired as a singleton
 * in the DI container.
 *
 * @package oihana\zitadel
 * @author  Marc Alcaraz
 */
class OAuthClientResolver
{
    /**
     * Creates a new OAuthClientResolver instance.
     *
     * @param Documents            $model    Documents model bound to the `oauth_clients` collection.
     * @param ZitadelClient|null   $zitadel  Zitadel Management API client (optional — falls back to null names when missing).
     * @param LoggerInterface|null $logger   Optional PSR logger.
     * @param int                  $cacheTtl In-process cache TTL in seconds.
     */
    public function __construct
    (
        protected Documents          $model ,
        protected ?ZitadelClient     $zitadel  = null ,
        protected ?LoggerInterface   $logger   = null ,
        protected int                $cacheTtl = self::DEFAULT_CACHE_TTL
    ) {}

    /**
     * Default in-process cache TTL in seconds (10 minutes).
     *
     * Long enough to amortize Arango hits over a request storm, short enough
     * that a freshly added Zitadel application is picked up within 10 minutes
     * even without calling `command:auth:zitadel:sync:oauth-clients`.
     */
    public const int DEFAULT_CACHE_TTL = 600 ;

    public const string EXPIRES = 'expires' ;
    public const string NAME    = 'name' ;

    /**
     * Process-local cache of resolved clientId → name pairs.
     *
     * Shape: `[ clientId => [ 'name' => ?string , 'expires' => int ] ]`.
     * A null `name` is a negative-cache marker (unknown clientId or fetch error).
     *
     * @var array
     */
    protected array $cache = [] ;

    /**
     * Clears the in-process cache.
     *
     * @return void
     */
    public function flush() :void
    {
        $this->cache = [] ;
    }

    /**
     * Removes a single entry from the in-process cache.
     *
     * @param string $clientId The OAuth2/OIDC client ID to forget.
     *
     * @return void
     */
    public function forget( string $clientId ) :void
    {
        unset( $this->cache[ $clientId ] ) ;
    }

    /**
     * Resolves an OAuth2/OIDC clientId to its human-readable application name.
     *
     * Returns null when the clientId is unknown, when the Zitadel fetch fails,
     * or when no Zitadel client is configured. Callers must treat a null return
     * as "no label available" and keep the flow non-blocking.
     *
     * @param string|null $clientId The OAuth2/OIDC client ID to resolve.
     *
     * @return string|null The resolved name, or null when unavailable.
     */
    public function resolve( ?string $clientId ) :?string
    {
        if( !$clientId )
        {
            return null ;
        }

        $now    = time() ;
        $cached = $this->cache[ $clientId ] ?? null ;

        if( $cached !== null && $cached[ self::EXPIRES ] > $now )
        {
            return $cached[ self::NAME ] ;
        }

        $name = $this->lookupLocal( $clientId ) ;

        if( $name !== null )
        {
            $this->remember( $clientId , $name ) ;
            return $name ;
        }

        $app = $this->fetchRemote( $clientId ) ;

        if( $app !== null )
        {
            $this->upsertLocal( $app ) ;
            $this->remember( $clientId , $app->name ?? null ) ;
            return $app->name ?? null ;
        }

        $this->remember( $clientId , null ) ;

        return null ;
    }

    /**
     * Inserts or updates a local `oauth_clients` document from a Zitadel app payload.
     *
     * Safe to call repeatedly; the underlying Documents model is expected to
     * handle upsert semantics (matches on `clientId`, full mutation otherwise).
     *
     * @param object $app Normalized app object `{appId, clientId, name, description, active}`.
     *
     * @return void
     */
    public function upsertLocal( object $app ) :void
    {
        $clientId = $app->clientId ?? null ;

        if( !$clientId )
        {
            return ;
        }

        try
        {
            $now      = gmdate( Iso8601Format::DATE_TIME_ZULU ) ;
            $existing = $this->lookupDocument( $clientId ) ;

            $payload =
            [
                Schema::ACTIVE          => $app->active ?? true ,
                Schema::NAME            => $app->name ?? null ,
                Schema::DESCRIPTION     => $app->description ?? null ,
                OAuthClient::APP_ID     => $app->appId ?? null ,
                OAuthClient::CLIENT_ID  => $clientId ,
            ] ;

            if( $existing && !empty( $existing->_key ) )
            {
                $payload[ Schema::MODIFIED ] = $now ;

                $this->model->update
                ([
                    Arango::KEY   => Schema::_KEY ,
                    Arango::VALUE => $existing->_key ,
                    Arango::DOC   => $payload ,
                ]) ;

                $this->logger?->info( "OAuthClientResolver: updated oauth_clients row for $clientId" ) ;

                return ;
            }

            $this->model->insert([ Arango::DOC => $payload ]) ;

            $this->logger?->info( "OAuthClientResolver: inserted oauth_clients row for $clientId" ) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "OAuthClientResolver: upsert failed for $clientId: " . $e->getMessage() ) ;
        }
    }

    // =========================================================================
    // Protected
    // =========================================================================

    /**
     * Fetches an application from the Zitadel Management API by its clientId.
     *
     * @param string $clientId The OAuth2/OIDC client ID.
     *
     * @return object|null The normalized app object, or null on failure / unknown.
     */
    protected function fetchRemote( string $clientId ) :?object
    {
        if( !$this->zitadel )
        {
            return null ;
        }

        try
        {
            return $this->zitadel->findApplicationByClientId( $clientId ) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "OAuthClientResolver: Zitadel fetch failed for $clientId: " . $e->getMessage() ) ;
            return null ;
        }
    }

    /**
     * Returns the raw local `oauth_clients` document for a given clientId.
     *
     * @param string $clientId The OAuth2/OIDC client ID.
     *
     * @return object|null The Arango document, or null when absent / on error.
     */
    protected function lookupDocument( string $clientId ) :?object
    {
        try
        {
            return $this->model->get
            ([
                Arango::KEY   => OAuthClient::CLIENT_ID ,
                Arango::VALUE => $clientId ,
            ]) ?: null ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "OAuthClientResolver: local lookup failed for $clientId: " . $e->getMessage() ) ;
            return null ;
        }
    }

    /**
     * Looks up a clientId in the local `oauth_clients` collection and returns its name.
     *
     * @param string $clientId The OAuth2/OIDC client ID.
     *
     * @return string|null The resolved name, or null when the clientId is unknown locally.
     */
    protected function lookupLocal( string $clientId ) :?string
    {
        $document = $this->lookupDocument( $clientId ) ;

        return $document->name ?? null ;
    }

    /**
     * Stores a (possibly null) name in the in-process cache with the configured TTL.
     *
     * @param string      $clientId The OAuth2/OIDC client ID.
     * @param string|null $name     The resolved name (null marks a negative-cache entry).
     *
     * @return void
     */
    protected function remember( string $clientId , ?string $name ) :void
    {
        $this->cache[ $clientId ] =
        [
            self::NAME    => $name ,
            self::EXPIRES => time() + $this->cacheTtl ,
        ] ;
    }
}
