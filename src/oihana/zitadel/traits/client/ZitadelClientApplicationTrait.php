<?php

namespace oihana\zitadel\traits\client;

use oihana\zitadel\enums\ZitadelEndpoint;

/**
 * Zitadel Management API methods for project-side applications.
 *
 * Read-side helpers around the Zitadel "Project Apps" surface
 * (Web Apps PKCE, Native Apps, API Apps) — used to list registered
 * apps, resolve an OAuth2 client ID to a human-readable label, and
 * delete a stale application from the project.
 *
 * Companion to {@see ZitadelClientServiceTrait}, which handles the
 * Service Account (Machine User) M2M flow.
 *
 * Requires {@see ZitadelClientTrait} (provides request(), resolveEndpoint(), $projectId).
 *
 * @package oihana\zitadel\traits\client
 * @author  Marc Alcaraz
 */
trait ZitadelClientApplicationTrait
{
    /**
     * Zitadel "active" state marker for an application.
     */
    public const string APP_STATE_ACTIVE = 'APP_STATE_ACTIVE' ;

    /**
     * Memoized Zitadel project name resolved by {@see getProjectName()}.
     */
    protected ?string $projectName = null ;

    /**
     * Resolves the Zitadel project name from the Management API,
     * memoizing the result on the consuming class.
     *
     * @return string|null The project name, or `null` when the
     *                     Management API call fails or does not
     *                     surface a `name`.
     */
    public function getProjectName() :?string
    {
        if( $this->projectName !== null )
        {
            return $this->projectName ;
        }

        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::PROJECT_BY_ID ,
            [ 'projectId' => $this->projectId ]
        ) ;

        $result = $this->request( 'GET' , $endpoint ) ;

        if( !$result || empty( $result->project->name ) )
        {
            return null ;
        }

        $this->projectName = (string) $result->project->name ;

        return $this->projectName ;
    }

    /**
     * Deletes an application from the Zitadel project.
     *
     * @param string $appId The Zitadel application ID.
     *
     * @return bool True on success, false on failure.
     */
    public function deleteApplication( string $appId ) :bool
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::APP_BY_ID ,
            [ 'projectId' => $this->projectId , 'appId' => $appId ]
        ) ;

        return $this->request( 'DELETE' , $endpoint ) !== null ;
    }

    /**
     * Searches for an application in the Zitadel project by its OIDC/API clientId.
     *
     * The Zitadel apps `_search` endpoint has no native clientId filter, so the
     * full app list is scanned in-memory. Both `oidcConfig.clientId` and
     * `apiConfig.clientId` are inspected since a project can mix OIDC (PKCE/public)
     * and API (M2M) apps.
     *
     * @param string $clientId The OAuth2/OIDC client ID to resolve.
     *
     * @return object|null A normalized object `{appId, clientId, name, description, active}`
     *                     or null on failure / unknown clientId.
     */
    public function findApplicationByClientId( string $clientId ) :?object
    {
        foreach( $this->searchApplications() as $app )
        {
            $oidcClientId = $app->oidcConfig->clientId ?? null ;
            $apiClientId  = $app->apiConfig->clientId  ?? null ;

            if( $oidcClientId === $clientId || $apiClientId === $clientId )
            {
                return (object)
                [
                    'appId'       => $app->id   ?? null ,
                    'clientId'    => $clientId ,
                    'name'        => $app->name ?? null ,
                    'description' => null ,
                    'active'      => ( $app->state ?? null ) === self::APP_STATE_ACTIVE ,
                ] ;
            }
        }

        return null ;
    }

    /**
     * Returns the raw list of applications defined in the Zitadel project.
     *
     * The response is not paginated further — callers are expected to hold the
     * full list since a project typically has a small bounded number of apps.
     *
     * @return array<object> The list of raw app objects as returned by Zitadel,
     *                       or an empty array on failure.
     */
    public function searchApplications() :array
    {
        $endpoint = $this->resolveEndpoint
        (
            ZitadelEndpoint::APPS_SEARCH ,
            [ 'projectId' => $this->projectId ]
        ) ;

        $result = $this->request( 'POST' , $endpoint , (object)[] ) ;

        if( !$result || !isset( $result->result ) || !is_array( $result->result ) )
        {
            return [] ;
        }

        return $result->result ;
    }
}
