<?php

namespace oihana\zitadel;

use oihana\zitadel\traits\client\ZitadelClientApplicationTrait;
use oihana\zitadel\traits\client\ZitadelClientPasswordTrait;
use oihana\zitadel\traits\client\ZitadelClientRoleTrait;
use oihana\zitadel\traits\client\ZitadelClientServiceTrait;
use oihana\zitadel\traits\client\ZitadelClientSessionTrait;
use oihana\zitadel\traits\client\ZitadelClientTargetTrait;
use oihana\zitadel\traits\client\ZitadelClientUserTrait;

/**
 * Client for the Zitadel Management API.
 *
 * Uses a service account (JWT Bearer grant) to authenticate and manage
 * users, roles, sessions, and projects in Zitadel.
 *
 * @package oihana\zitadel
 * @author  Marc Alcaraz
 */
class ZitadelClient
{
    use ZitadelClientApplicationTrait ,
        ZitadelClientUserTrait ,
        ZitadelClientPasswordTrait ,
        ZitadelClientRoleTrait ,
        ZitadelClientServiceTrait ,
        ZitadelClientSessionTrait ,
        ZitadelClientTargetTrait ;
}
