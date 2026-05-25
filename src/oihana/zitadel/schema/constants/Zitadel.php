<?php

namespace oihana\zitadel\schema\constants;

use oihana\zitadel\schema\constants\traits\QueryTrait;
use oihana\zitadel\schema\constants\traits\RoleTrait;
use oihana\zitadel\schema\constants\traits\UserTrait;

/**
 * Aggregates all Zitadel schema constants.
 */
class Zitadel
{
    use QueryTrait ,
        RoleTrait  ,
        UserTrait  ;
}
