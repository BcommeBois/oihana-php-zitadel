<?php

namespace oihana\zitadel\helpers;

use oihana\zitadel\ZitadelClient;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Resolves a {@see ZitadelClient} instance from various types of input definitions.
 *
 * This helper function returns a {@see ZitadelClient} object from:
 * a direct instance, an array definition (looked up at the given key),
 * a service name within a PSR-11 container, or falls back to a provided default value.
 *
 * ### Behavior:
 * - If `$definition` is a {@see ZitadelClient} instance, it is returned as-is.
 * - If `$definition` is an array, the function looks for the `$key` (default: `zitadelClient`).
 * - If `$definition` is a non-empty string and `$container` contains a service with that name,
 *   the corresponding service is fetched.
 * - If none of the above conditions are met, the `$default` value is returned.
 *
 * @param array|string|ZitadelClient|null $definition Input definition that may represent a `ZitadelClient`
 *                                                    instance, an associative array containing one, or a
 *                                                    container service name.
 * @param ContainerInterface|null         $container  Optional PSR-11 container used to resolve string service names.
 * @param string                          $key        Array key to look for when `$definition` is an array.
 * @param ZitadelClient|null              $default    Default `ZitadelClient` instance to return if resolution fails.
 *
 * @return ZitadelClient|null Returns the resolved {@see ZitadelClient} instance or the default value if not found.
 *
 * @throws ContainerExceptionInterface If an error occurs while retrieving the service from the container.
 * @throws NotFoundExceptionInterface  If a string definition is provided but not found in the container.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\zitadel\helpers
 * @version 1.0.0
 */
function getZitadelClient
(
    array|string|null|ZitadelClient $definition = null ,
    ?ContainerInterface             $container  = null ,
    string                          $key        = 'zitadelClient' ,
    ?ZitadelClient                  $default    = null ,
)
:?ZitadelClient
{
    if( $definition instanceof ZitadelClient )
    {
        return $definition ;
    }

    if( is_array( $definition ) )
    {
        $definition = $definition[ $key ] ?? null ;
    }

    if( is_string( $definition ) && !empty( $definition ) && $container?->has( $definition ) )
    {
        $definition = $container->get( $definition ) ;
    }

    return $definition instanceof ZitadelClient ? $definition : $default ;
}
