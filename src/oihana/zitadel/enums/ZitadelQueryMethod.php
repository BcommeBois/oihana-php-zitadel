<?php

namespace oihana\zitadel\enums;

/**
 * Defines the Zitadel text query methods for search operations.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 */
class ZitadelQueryMethod
{
    public const string EQUALS          = 'TEXT_QUERY_METHOD_EQUALS' ;
    public const string EQUALS_IGNORE   = 'TEXT_QUERY_METHOD_EQUALS_IGNORE_CASE' ;
    public const string STARTS_WITH     = 'TEXT_QUERY_METHOD_STARTS_WITH' ;
    public const string CONTAINS        = 'TEXT_QUERY_METHOD_CONTAINS' ;
    public const string ENDS_WITH       = 'TEXT_QUERY_METHOD_ENDS_WITH' ;
}
