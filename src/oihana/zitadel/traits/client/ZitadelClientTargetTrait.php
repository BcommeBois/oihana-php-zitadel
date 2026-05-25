<?php

namespace oihana\zitadel\traits\client;

use oihana\enums\http\HttpMethod;

/**
 * Client for the Zitadel V2 Actions API — Targets (webhook destinations
 * created via *Paramètres par défaut → Cibles* in the console) and
 * Executions (event → target bindings created via *…→ Actions*).
 *
 * The cloud V2 console generates a per-Target HMAC signing key at
 * creation but does not surface it on the edit screen — and Zitadel
 * does not expose it on subsequent reads either. Use these methods from
 * a one-shot Symfony command to provision a Target + Execution from
 * scratch (the `createTarget` response is the only opportunity to read
 * the signing key in cleartext; for an existing Target the only way to
 * obtain a fresh key is to delete + recreate via the `rotate` action).
 *
 * REST contract reference (audited 2026-05-02):
 *
 * - {@link https://zitadel.com/docs/reference/api/action/zitadel.action.v2.ActionService.CreateTarget CreateTarget}
 * - {@link https://zitadel.com/docs/reference/api/action/zitadel.action.v2.ActionService.ListTargets  ListTargets}
 * - {@link https://zitadel.com/docs/reference/api/action/zitadel.action.v2.ActionService.SetExecution SetExecution}
 *
 * @package oihana\zitadel\traits\client
 * @author  Marc Alcaraz
 */
trait ZitadelClientTargetTrait
{
    use ZitadelClientTrait ;

    /**
     * Creates a Target. The response body carries `id`, `creationDate`
     * and `signingKey` — the latter is the HMAC secret used by Zitadel
     * to sign every outgoing webhook payload.
     *
     * @param string $name             Human-readable name (visible in the UI).
     * @param string $endpoint         Public HTTPS URL Zitadel will POST to.
     * @param int    $timeoutSeconds   Maximum response time before Zitadel gives up.
     */
    public function createTarget
    (
        string $name ,
        string $endpoint ,
        int    $timeoutSeconds = 10
    )
    :array
    {
        return $this->requestRaw
        (
            HttpMethod::POST ,
            '/v2/actions/targets' ,
            [
                'name'        => $name ,
                'endpoint'    => $endpoint ,
                'timeout'     => sprintf( '%ds' , max( 1 , $timeoutSeconds ) ) ,
                'payloadType' => 'PAYLOAD_TYPE_JSON' ,
                'restWebhook' => (object) [] ,
            ]
        ) ;
    }

    /**
     * Deletes a Target by id.
     */
    public function deleteTarget( string $targetId ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::DELETE ,
            sprintf( '/v2/actions/targets/%s' , $targetId ) ,
            null
        ) ;
    }

    /**
     * Lists every Target configured on the current instance.
     *
     * @return array Raw API result. The decoded `body->targets` is the
     *               array of Targets, each with `id`, `name`, `endpoint`,
     *               `creationDate`, etc. The `signingKey` field is NOT
     *               populated on read — use `createTarget()` to obtain
     *               one (only at creation time).
     */
    public function listTargets() :array
    {
        return $this->requestRaw( HttpMethod::POST , '/v2/actions/targets/search' , (object) [] ) ;
    }

    /**
     * Sets (creates or replaces) an event-condition Execution that fires
     * the supplied Target id(s) each time the event is emitted by
     * Zitadel. Idempotent — calling again with the same condition
     * replaces the previous execution.
     *
     * @param string $event    Zitadel event name (e.g. `user.human.password.changed`).
     * @param string $targetId The Target id returned by {@see createTarget()}.
     */
    public function setEventExecution( string $event , string $targetId ) :array
    {
        return $this->requestRaw
        (
            HttpMethod::PUT ,
            '/v2/actions/executions' ,
            [
                'condition' =>
                [
                    'event' => [ 'event' => $event ] ,
                ] ,
                'targets' => [ $targetId ] ,
            ]
        ) ;
    }
}
