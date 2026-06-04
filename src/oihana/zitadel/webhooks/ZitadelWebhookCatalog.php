<?php

namespace oihana\zitadel\webhooks;

use InvalidArgumentException;

/**
 * In-memory registry of every Zitadel webhook the API consumes.
 *
 * Built at boot from the `[zitadel.webhooks.*]` section of the configuration
 * — one {@see ZitadelWebhookDescriptor} per `[zitadel.webhooks.<key>]`
 * subsection. The catalogue exposes a tiny key-based API used by:
 *
 * - the receiver controllers, which look up the descriptor by key to
 *   read the matching HMAC secret and verify incoming signatures;
 * - the {@see \oihana\zitadel\commands\ZitadelWebhookCommand} CLI
 *   command, which iterates the catalogue to install / rotate /
 *   uninstall webhooks against the Zitadel V2 API.
 *
 * The catalogue is **immutable** — once built from config it cannot be
 * mutated. The CLI command rebuilds it on each invocation against the
 * fresh configuration. Tests can construct a catalogue programmatically
 * via the constructor with a list of descriptors.
 *
 * @package oihana\zitadel\webhooks
 * @author  Marc Alcaraz
 */
final class ZitadelWebhookCatalog
{
    /**
     * Creates a new ZitadelWebhookCatalog instance.
     *
     * @param ZitadelWebhookDescriptor[] $descriptors Descriptors to register. Indexed internally by `descriptor->key` — duplicate keys raise an exception.
     *
     * @throws InvalidArgumentException When two descriptors share the same key.
     */
    public function __construct( array $descriptors = [] )
    {
        foreach( $descriptors as $descriptor )
        {
            if( !$descriptor instanceof ZitadelWebhookDescriptor )
            {
                throw new InvalidArgumentException
                (
                    'ZitadelWebhookCatalog: every entry must be a ZitadelWebhookDescriptor instance.'
                ) ;
            }

            if( isset( $this->descriptors[ $descriptor->key ] ) )
            {
                throw new InvalidArgumentException
                (
                    "ZitadelWebhookCatalog: duplicate descriptor for key '$descriptor->key'."
                ) ;
            }

            $this->descriptors[ $descriptor->key ] = $descriptor ;
        }
    }

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /**
     * Map of `key => descriptor`, populated by the constructor.
     *
     * @var array<string, ZitadelWebhookDescriptor>
     */
    private array $descriptors = [] ;

    // -------------------------------------------------------------------------
    // Public methods
    // -------------------------------------------------------------------------

    /**
     * Returns every descriptor registered in the catalogue, indexed by key.
     *
     * @return array<string, ZitadelWebhookDescriptor>
     */
    public function all() :array
    {
        return $this->descriptors ;
    }

    /**
     * Builds a catalogue from the parsed `[zitadel.webhooks]` TOML
     * section — the value yielded by `parse_toml( ... )[ 'zitadel' ][ 'webhooks' ]`.
     *
     * Accepts (and silently ignores) entries that are not arrays — the
     * legacy flat layout (`password_changed = "<secret>"`) used to live
     * here, so an unconverted file simply yields an empty catalogue
     * rather than crashing the boot.
     *
     * Each subsection that IS an array is forwarded to
     * {@see ZitadelWebhookDescriptor::fromArray()}, which performs its
     * own validation. A malformed section therefore raises a typed
     * exception with the offending key in the message.
     *
     * @param array<string, mixed> $section The parsed `zitadel.webhooks` body.
     *
     * @throws InvalidArgumentException Forwarded from the descriptor.
     */
    public static function fromConfig( array $section ) :self
    {
        $descriptors = [] ;

        foreach( $section as $key => $value )
        {
            if( !is_string( $key ) || !is_array( $value ) )
            {
                continue ;
            }

            $descriptors[] = ZitadelWebhookDescriptor::fromArray( $key , $value ) ;
        }

        return new self( $descriptors ) ;
    }

    /**
     * Returns the descriptor registered under `$key`, or `null` when no
     * such descriptor exists.
     */
    public function get( string $key ) :?ZitadelWebhookDescriptor
    {
        return $this->descriptors[ $key ] ?? null ;
    }

    /**
     * Returns whether a descriptor exists for `$key`.
     */
    public function has( string $key ) :bool
    {
        return isset( $this->descriptors[ $key ] ) ;
    }

    /**
     * Returns the list of every key currently registered.
     *
     * @return list<string>
     */
    public function keys() :array
    {
        return array_keys( $this->descriptors ) ;
    }
}
