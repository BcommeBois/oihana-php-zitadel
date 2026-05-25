<?php

namespace oihana\zitadel\traits;

use DI\Container;

use oihana\zitadel\ZitadelClient;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\zitadel\helpers\getZitadelClient;

/**
 * Standalone trait for the {@see ZitadelClient} dependency.
 *
 * Used by commands / controllers / traits that need to call the Zitadel
 * REST API (user creation, password reset, role grant, session revocation,
 * webhook helpers, etc.). Composable on its own.
 *
 * @package oihana\zitadel\traits
 * @author  Marc Alcaraz
 */
trait ZitadelClientTrait
{
    /**
     * Initialization key for the Zitadel client dependency.
     */
    public const string ZITADEL_CLIENT = 'zitadelClient' ;

    /**
     * The Zitadel client.
     */
    protected ?ZitadelClient $zitadelClient = null ;

    /**
     * Initializes the Zitadel client dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeZitadelClient( array $init , ?Container $container ) :static
    {
        $this->zitadelClient = getZitadelClient( $init , $container , self::ZITADEL_CLIENT ) ;
        return $this ;
    }
}
