<?php

namespace oihana\zitadel\webhooks;

use InvalidArgumentException;

/**
 * Immutable value object describing a single Zitadel webhook the API
 * consumes — i.e. one Zitadel *Cible* (Target) bound to one *Action*
 * (Execution) listening to one event.
 *
 * Built either from a parsed TOML section (`[zitadel.webhooks.<key>]`)
 * via {@see fromArray()} or programmatically via the constructor.
 *
 * Layout of the matching TOML section:
 *
 * ```toml
 * [zitadel.webhooks.password_changed]
 * event  = "user.human.password.changed"
 * label  = "webhook password"
 * route  = "/webhooks/zitadel/password-changed"
 * secret = "<HMAC signing key returned by Zitadel at Cible creation>"
 * ```
 *
 * Validation runs in the constructor — anything malformed raises an
 * {@see InvalidArgumentException} so a typo in `config.toml` surfaces
 * at boot rather than silently disabling the webhook.
 *
 * The descriptor is immutable: {@see withSecret()} returns a new
 * instance with the supplied secret swapped in, leaving the original
 * untouched. The CLI command relies on this when rotating a key.
 *
 * @package oihana\zitadel\webhooks
 * @author  Marc Alcaraz
 */
final class ZitadelWebhookDescriptor
{
    /**
     * @param string $key    Local identifier (TOML section suffix), e.g. `password_changed`.
     *                       Must be a non-empty lowercase snake_case slug.
     * @param string $event  Zitadel event name (dot-separated, lowercase),
     *                       e.g. `user.human.password.changed`.
     * @param string $label  Human-readable label embedded in the Zitadel
     *                       Cible name (`{api-id} - {label} - {host}`).
     * @param string $route  HTTP path the API exposes to receive the
     *                       payload, e.g. `/webhooks/zitadel/password-changed`.
     *                       Must start with `/`.
     * @param string $secret HMAC signing key returned by Zitadel at Cible
     *                       creation. Empty until the webhook is installed
     *                       — every signature verification then fails.
     *
     * @throws InvalidArgumentException When any field fails validation.
     */
    public function __construct
    (
        public readonly string $key ,
        public readonly string $event ,
        public readonly string $label ,
        public readonly string $route ,
        public readonly string $secret = ''
    )
    {
        $this->assertKey   ( $key   ) ;
        $this->assertEvent ( $event ) ;
        $this->assertLabel ( $label ) ;
        $this->assertRoute ( $route ) ;
    }

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const string FIELD_EVENT  = 'event' ;
    public const string FIELD_LABEL  = 'label' ;
    public const string FIELD_ROUTE  = 'route' ;
    public const string FIELD_SECRET = 'secret' ;

    /**
     * Pattern enforced on {@see $event}: lowercase letters / digits /
     * underscores split by dots, with at least two segments. Matches
     * every event name documented in the Zitadel V2 Action service
     * (e.g. `user.human.password.changed`, `user.human.email.changed`,
     * `session.added`, …).
     */
    public const string EVENT_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/' ;

    /**
     * Pattern enforced on {@see $key}: lowercase snake_case slug,
     * matches the TOML section suffix `[zitadel.webhooks.<key>]`.
     */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/' ;

    // -------------------------------------------------------------------------
    // Public methods
    // -------------------------------------------------------------------------

    /**
     * Builds a descriptor from a TOML section payload (the array yielded
     * by `parse_toml( ... )[ 'zitadel' ][ 'webhooks' ][ <key> ]`).
     *
     * The supplied key carries the section suffix — it is not read from
     * the array because TOML does not embed the section name inside its
     * value. Missing fields default to an empty string and trigger the
     * same validation as if they had been explicitly set to `''`, so a
     * partial section fails loud rather than silently disabling the
     * webhook.
     *
     * @param string                     $key    The TOML section suffix.
     * @param array<string,string|mixed> $config The parsed section body.
     *
     * @throws InvalidArgumentException Forwarded from the constructor.
     */
    public static function fromArray( string $key , array $config ) :self
    {
        return new self
        (
            key    : $key ,
            event  : self::stringField( $config , self::FIELD_EVENT  ) ,
            label  : self::stringField( $config , self::FIELD_LABEL  ) ,
            route  : self::stringField( $config , self::FIELD_ROUTE  ) ,
            secret : self::stringField( $config , self::FIELD_SECRET )
        ) ;
    }

    /**
     * Whether the descriptor carries a non-empty signing key. Returns
     * `false` immediately after `install` failed or before the webhook
     * has ever been provisioned — the receiver should treat such a
     * descriptor as not-yet-installed and reject every signature.
     */
    public function hasSecret() :bool
    {
        return $this->secret !== '' ;
    }

    /**
     * Returns a new descriptor identical to this one with the supplied
     * secret swapped in. The original instance is untouched (PHP 8.4
     * readonly properties).
     */
    public function withSecret( string $secret ) :self
    {
        return new self
        (
            key    : $this->key ,
            event  : $this->event ,
            label  : $this->label ,
            route  : $this->route ,
            secret : $secret
        ) ;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    private function assertEvent( string $event ) :void
    {
        if( $event === '' || preg_match( self::EVENT_PATTERN , $event ) !== 1 )
        {
            throw new InvalidArgumentException
            (
                "ZitadelWebhookDescriptor: invalid event '$event' for key '$this->key' " .
                '(expected dot-separated lowercase segments, e.g. user.human.password.changed)'
            ) ;
        }
    }

    private function assertKey( string $key ) :void
    {
        if( $key === '' || preg_match( self::KEY_PATTERN , $key ) !== 1 )
        {
            throw new InvalidArgumentException
            (
                "ZitadelWebhookDescriptor: invalid key '$key' " .
                '(expected lowercase snake_case, e.g. password_changed)'
            ) ;
        }
    }

    private function assertLabel( string $label ) :void
    {
        if( trim( $label ) === '' )
        {
            throw new InvalidArgumentException
            (
                "ZitadelWebhookDescriptor: empty label for key '$this->key' " .
                '(expected a non-empty human-readable label, e.g. "webhook password")'
            ) ;
        }
    }

    private function assertRoute( string $route ) :void
    {
        if( $route === '' || $route[ 0 ] !== '/' )
        {
            throw new InvalidArgumentException
            (
                "ZitadelWebhookDescriptor: invalid route '$route' for key '$this->key' " .
                '(expected an absolute path starting with /)'
            ) ;
        }
    }

    /**
     * Reads a string field from the config array, defaulting to '' when
     * absent or non-scalar. Validation is delegated to the matching
     * `assertXxx` method via the constructor.
     */
    private static function stringField( array $config , string $field ) :string
    {
        if( !isset( $config[ $field ] ) || !is_scalar( $config[ $field ] ) )
        {
            return '' ;
        }

        return (string) $config[ $field ] ;
    }
}
