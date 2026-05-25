<?php

namespace oihana\zitadel\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Zitadel-standardised field names accepted by the
 * `sortingColumn` parameter of the Sessions V2 search endpoint.
 *
 * Mirrors the pattern of {@see ZitadelQueryMethod} for the
 * `TEXT_QUERY_METHOD_*` literals — these constants carry the
 * verbatim string values Zitadel expects on the wire.
 *
 * Extend this enum as new sortable columns are needed (USER_ID,
 * USER_AGENT, ID, …) — Zitadel exposes a fixed enum on its side.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 *
 * @see https://zitadel.com/docs/apis/resources/session_service_v2
 */
class ZitadelSessionField
{
    use ConstantsTrait ;

    /**
     * The session creation date. Used as a `sortingColumn` value
     * to order search results chronologically.
     */
    public const string CREATION_DATE = 'SESSION_FIELD_NAME_CREATION_DATE' ;
}
