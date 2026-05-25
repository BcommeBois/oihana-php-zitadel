<?php

namespace oihana\zitadel\traits\client;

use oihana\enums\http\HttpMethod;
use oihana\zitadel\schema\constants\Zitadel;

use oihana\zitadel\enums\ZitadelEndpoint;

/**
 * Zitadel Management API role-management surface (v1).
 *
 * Wraps the role-related endpoints of the Zitadel Management API:
 * - {@see self::createRole()}    — `POST   /management/v1/projects/{projectId}/roles`
 * - {@see self::deleteRole()}    — `DELETE /management/v1/projects/{projectId}/roles/{roleKey}`
 * - {@see self::updateRole()}    — `PUT    /management/v1/projects/{projectId}/roles/{roleKey}`
 * - {@see self::listRoles()}     — `POST   /management/v1/projects/{projectId}/roles/_search`
 * - {@see self::grantUserRoles()} — `POST   /management/v1/users/{userId}/grants`
 *
 * **Optional — consuming applications that keep RBAC outside Zitadel
 * do not need to call this trait.**
 *
 * Some integrations follow a "Zitadel = minimal IdP" doctrine and keep
 * RBAC entirely inside their own datastore (e.g. ArangoDB collections
 * + Casbin); their controllers therefore do not exercise this trait at
 * runtime.
 *
 * The trait is preserved on purpose for downstream consumers of the
 * `oihana\zitadel` library that may want to delegate part or all of
 * their RBAC to Zitadel itself. Typical first-party usage is a
 * one-shot cleanup command that uses {@see self::listRoles()} and
 * {@see self::deleteRole()} to garbage-collect role definitions left
 * over in the Zitadel project from a legacy sync.
 *
 * @package oihana\zitadel
 * @author  Marc Alcaraz
 */
trait ZitadelClientRoleTrait
{
    use ZitadelClientTrait ;

    /**
     * Creates a role in the project.
     */
    public function createRole( string $key , string $displayName , string $group = 'app' ) :?object
    {
        return $this->request
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::ROLES , [ Zitadel::PROJECT_ID => $this->projectId ] ) ,
            [
                Zitadel::ROLE_KEY     => $key ,
                Zitadel::DISPLAY_NAME => $displayName ,
                Zitadel::GROUP        => $group ,
            ]
        ) ;
    }

    /**
     * Deletes a role from the project.
     */
    public function deleteRole( string $key ) :?object
    {
        return $this->request
        (
            HttpMethod::DELETE ,
            $this->resolveEndpoint( ZitadelEndpoint::ROLE_BY_KEY , [ Zitadel::PROJECT_ID => $this->projectId , Zitadel::ROLE_KEY => $key ] )
        ) ;
    }

    /**
     * Grants roles to a user on the project.
     */
    public function grantUserRoles( string $userId , array $roleKeys ) :?object
    {
        return $this->request
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::USER_GRANTS , [ 'userId' => $userId ] ) ,
            [
                Zitadel::PROJECT_ID => $this->projectId ,
                Zitadel::ROLE_KEYS  => $roleKeys ,
            ]
        ) ;
    }

    /**
     * Lists all roles in the project.
     */
    public function listRoles() :array
    {
        $response = $this->request
        (
            HttpMethod::POST ,
            $this->resolveEndpoint( ZitadelEndpoint::ROLES_SEARCH , [ Zitadel::PROJECT_ID => $this->projectId ] ) ,
            [
                Zitadel::QUERY =>
                    [
                        Zitadel::OFFSET => '0' ,
                        Zitadel::LIMIT => 100
                    ]
            ]
        ) ;

        return $response->result ?? [] ;
    }

    /**
     * Updates a role's display name and/or group.
     *
     * Zitadel role keys are immutable — only `displayName` and `group` can be
     * updated through this endpoint. When the API renames a role, the caller
     * should pass the *original* Zitadel key (i.e. the previous API name) and
     * the new display name.
     *
     * @param string $key         The Zitadel role key (stable, equals the original API name).
     * @param string $displayName The new display name to store in Zitadel.
     * @param string $group       The role group (default: 'app').
     */
    public function updateRole( string $key , string $displayName , string $group = 'app' ) :?object
    {
        return $this->request
        (
            HttpMethod::PUT ,
            $this->resolveEndpoint( ZitadelEndpoint::ROLE_BY_KEY , [ Zitadel::PROJECT_ID => $this->projectId , Zitadel::ROLE_KEY => $key ] ) ,
            [
                Zitadel::DISPLAY_NAME => $displayName ,
                Zitadel::GROUP        => $group ,
            ]
        ) ;
    }
}
