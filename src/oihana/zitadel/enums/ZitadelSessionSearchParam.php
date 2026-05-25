<?php

namespace oihana\zitadel\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Body parameter keys of the Zitadel Sessions V2 search endpoint
 * (`POST /v2/sessions/search`).
 *
 * Keeps the literal string keys out of the call sites that build
 * the search payload, in line with the library-wide
 * no-magic-strings convention.
 *
 * @package oihana\zitadel\enums
 * @author  Marc Alcaraz
 *
 * @see https://zitadel.com/docs/apis/resources/session_service_v2
 */
class ZitadelSessionSearchParam
{
    use ConstantsTrait ;

    /**
     * Top-level `queries` array of the search body. Each entry is a
     * filter typed by its key (e.g. `userIdQuery`, `creationDateQuery`).
     */
    public const string QUERIES = 'queries' ;

    /**
     * The `sortingColumn` parameter. Value must come from
     * {@see ZitadelSessionField}.
     */
    public const string SORTING_COLUMN = 'sortingColumn' ;

    /**
     * The `userId` key carried inside a `userIdQuery` filter — the
     * Zitadel user identifier whose sessions are being searched.
     */
    public const string USER_ID = 'userId' ;

    /**
     * The `userIdQuery` filter type — narrows the search to the
     * sessions owned by a specific user.
     */
    public const string USER_ID_QUERY = 'userIdQuery' ;
}
