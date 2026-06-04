<?php

namespace oihana\zitadel\commands;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\commands\enums\ExitCode;
use oihana\commands\Kernel;
use oihana\enums\http\HttpStatusCode;
use oihana\zitadel\enums\ZitadelOutput;
use oihana\zitadel\traits\ZitadelClientTrait;
use oihana\zitadel\webhooks\ZitadelWebhookCatalog;
use oihana\zitadel\webhooks\ZitadelWebhookDescriptor;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Throwable;

use function oihana\controllers\helpers\resolveDependency;
use function oihana\http\helpers\url\isPublicUrl;

/**
 * Catalog-driven manager for the Zitadel V2 *Targets* (Cibles) and
 * *Executions* (Actions ↔ Target bindings) the API consumes.
 *
 * The command works against the {@see ZitadelWebhookCatalog} built at
 * boot from the `[zitadel.webhooks.*]` sections of the configuration. Each
 * subsection (e.g. `[zitadel.webhooks.password_changed]`) describes one
 * webhook with its event, label, route and HMAC secret. The CLI uses
 * the descriptor to derive a deterministic Cible name —
 * `{apiIdentifier} - {label} - {host}` — so multiple environments can
 * share a single Zitadel instance without collision.
 *
 * Sub-actions (alphabetical, first positional argument):
 *
 * | action      | argument | what it does                                                                          |
 * | ----------- | -------- | ------------------------------------------------------------------------------------- |
 * | `delete`    | —        | Lists every Target on the instance + interactive selection (rescue / housekeeping)    |
 * | `install`   | <key>    | Creates the Cible + binds the Execution + writes the secret to the config file        |
 * | `list`      | —        | Lists every Target on the instance (or `--mine` to filter on the API prefix)          |
 * | `rotate`    | <key>    | Drops + recreates the Cible to obtain a fresh signing key, updates the secret         |
 * | `show`      | <key>    | Prints the descriptor + the matching Target's Zitadel-side metadata (id, dates, …)    |
 * | `uninstall` | <key>    | Removes the Cible (with confirmation), optionally purges the secret from the config file |
 *
 * `<key>` always matches the TOML section suffix
 * (`[zitadel.webhooks.<key>]`) — e.g. `password_changed`. The catalog
 * rejects unknown keys, so the command never operates on a webhook the
 * API does not know about.
 *
 * Why this exists. The Zitadel V2 console creates Targets without
 * surfacing the HMAC signing key on the edit screen — it is only
 * visible in the V2 API responses at creation. This command goes
 * through the V2 API (using the existing service-account configured
 * via {@see ZitadelClient}) so the key is always recoverable on
 * demand, and the configured file stays in sync without manual
 * copy-paste.
 *
 * @package oihana\zitadel\commands
 * @author  Marc Alcaraz
 */
class ZitadelWebhookCommand extends Kernel
{
    use ZitadelClientTrait ;

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     */
    public function __construct( ?string $name , ?Container $container = null , array $init = [] )
    {
        parent::__construct( $name , $container , $init ) ;

        $apiIdentifier = $init[ self::API_IDENTIFIER ] ?? null ;
        $baseUrl       = $init[ self::BASE_URL       ] ?? null ;
        $configFile    = $init[ self::CONFIG_FILE    ] ?? null ;

        $this->apiIdentifier  = is_string( $apiIdentifier ) ? $apiIdentifier : null ;
        $this->baseUrl        = is_string( $baseUrl       ) ? $baseUrl       : null ;
        $this->configFile     = is_string( $configFile    ) ? $configFile    : '' ;

        $this->initializeZitadelClient( $init , $container ) ;

        $this->webhookCatalog = resolveDependency( $init[ self::WEBHOOK_CATALOG ] ?? null , $container ) ;
    }

    // -------------------------------------------------------------------------
    // Constants — actions
    // -------------------------------------------------------------------------

    public const string ACTION_DELETE    = 'delete'    ;
    public const string ACTION_INSTALL   = 'install'   ;
    public const string ACTION_LIST      = 'list'      ;
    public const string ACTION_ROTATE    = 'rotate'    ;
    public const string ACTION_SHOW      = 'show'      ;
    public const string ACTION_UNINSTALL = 'uninstall' ;

    /**
     * Every action this command exposes, alphabetical (matches the help
     * order printed by Symfony Console `--help`).
     */
    public const array ACTIONS_ALL =
    [
        self::ACTION_DELETE ,
        self::ACTION_INSTALL ,
        self::ACTION_LIST ,
        self::ACTION_ROTATE ,
        self::ACTION_SHOW ,
        self::ACTION_UNINSTALL ,
    ] ;

    /**
     * Actions that require a `<key>` second positional argument
     * matching a descriptor in the catalog. `delete` and `list` operate
     * on the full Target collection regardless of the catalog.
     */
    public const array ACTIONS_REQUIRING_KEY =
    [
        self::ACTION_INSTALL ,
        self::ACTION_ROTATE ,
        self::ACTION_SHOW ,
        self::ACTION_UNINSTALL ,
    ] ;

    // -------------------------------------------------------------------------
    // Constants — CLI surface
    // -------------------------------------------------------------------------

    public const string ARGUMENT_ACTION = 'action' ;
    public const string ARGUMENT_KEY    = 'key'    ;

    public const string OPTION_ENDPOINT = 'endpoint'     ;
    public const string OPTION_MINE     = 'mine'         ;
    public const string OPTION_PURGE    = 'purge-config' ;
    public const string OPTION_YES      = 'yes'          ;

    // -------------------------------------------------------------------------
    // Constants — Init keys (DI container)
    // -------------------------------------------------------------------------

    public const string API_IDENTIFIER  = 'apiIdentifier'  ;
    public const string BASE_URL        = 'baseUrl'        ;
    public const string CONFIG_FILE     = 'configFile'     ;
    public const string WEBHOOK_CATALOG = 'webhookCatalog' ;

    // -------------------------------------------------------------------------
    // Constants — Internal
    // -------------------------------------------------------------------------

    /**
     * Separator used between the three segments of the canonical Cible
     * name (`{apiIdentifier} - {label} - {host}`). Whitespace + dash +
     * whitespace is loose enough to keep the human label readable but
     * unique enough to prevent accidental collisions with descriptor
     * labels that happen to contain a dash.
     */
    public const string NAME_SEPARATOR = ' - ' ;

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /**
     * Local API identifier read from `[auth.api].identifier` — used as
     * the first segment of the canonical Cible name.
     */
    protected ?string $apiIdentifier = null ;

    /**
     * Application base URL read from `[app].baseUrl` — used to derive
     * both the host segment of the Cible name and the default
     * receiver endpoint when the deployment is publicly reachable.
     */
    protected ?string $baseUrl = null ;

    /**
     * Absolute path to the config file the secret is written into, injected
     * via {@see self::CONFIG_FILE}. Typically a per-environment file the host
     * application reads (directly or after a build step). The file is created
     * if missing. When empty, the command prints the snippet for the operator
     * to paste manually instead of writing anything.
     */
    protected string $configFile = '' ;

    /**
     * Catalog of every webhook the API consumes, built from
     * `[zitadel.webhooks.*]` at boot.
     */
    protected ?ZitadelWebhookCatalog $webhookCatalog = null ;

    // -------------------------------------------------------------------------
    // Public — pure helpers (testable in isolation)
    // -------------------------------------------------------------------------

    /**
     * Builds the canonical Zitadel Cible name for the supplied
     * descriptor — the deterministic identifier the command uses on
     * `install`, `rotate`, `uninstall` and `show`.
     *
     * Format: `{apiIdentifier} - {label} - {host}` where `host` is the
     * authority of `$baseUrl` (no scheme, no port, no path). When
     * `$baseUrl` does not contain a parsable authority the raw value
     * is used instead — in practice this only happens when a developer
     * mistypes the config.
     *
     * Example (dev):
     *   `my-api - webhook password - myapp.localhost`
     * Example (prod):
     *   `my-api - webhook password - api.example.com`
     */
    public static function buildCanonicalName( string $apiIdentifier , string $label , string $baseUrl ) :string
    {
        $host = parse_url( $baseUrl , PHP_URL_HOST ) ;

        if( !is_string( $host ) || $host === '' )
        {
            $host = $baseUrl ;
        }

        return $apiIdentifier . self::NAME_SEPARATOR . $label . self::NAME_SEPARATOR . $host ;
    }

    /**
     * Pure TOML rewrite — replaces the `secret = "..."` line inside
     * the `[zitadel.webhooks.<key>]` subsection with a fresh value.
     * When the section exists but lacks a `secret` line, one is
     * appended; when the section is missing entirely, it is created
     * at the end of the file with the supplied secret only — the
     * caller is expected to fill in `event` / `label` / `route`
     * separately (in practice this branch never fires because
     * `install` is the only writer and the descriptor must already
     * exist in the catalog before `install` runs).
     *
     * Conservative regex — only matches the exact `secret = "..."`
     * pattern; commented or multi-line values are ignored. Always
     * idempotent: calling with the same secret leaves the file
     * byte-identical apart from a possible trailing newline.
     */
    public static function replaceSecretInToml( string $toml , string $key , string $secret ) :string
    {
        $sectionPattern = '/^\[zitadel\.webhooks\.' . preg_quote( $key , '/' ) . '][^\[]*?(?=^\[|\z)/ms' ;
        $secretPattern  = '/^secret\s*=\s*"[^"]*"/m' ;
        $newSecretLine  = 'secret = "' . $secret . '"' ;

        if( preg_match( $sectionPattern , $toml , $matches , PREG_OFFSET_CAPTURE ) === 1 )
        {
            $section       = $matches[ 0 ][ 0 ] ;
            $sectionOffset = $matches[ 0 ][ 1 ] ;

            if( preg_match( $secretPattern , $section ) === 1 )
            {
                $newSection = preg_replace( $secretPattern , $newSecretLine , $section , 1 ) ;
            }
            else
            {
                $newSection = rtrim( $section , "\n" ) . "\n" . $newSecretLine . "\n\n" ;
            }

            return substr_replace( $toml , $newSection , $sectionOffset , strlen( $section ) ) ;
        }

        return rtrim( $toml , "\n" ) . "\n\n[zitadel.webhooks." . $key . "]\n" . $newSecretLine . "\n" ;
    }

    // -------------------------------------------------------------------------
    // Protected — Symfony Console hooks
    // -------------------------------------------------------------------------

    protected function configure() :void
    {
        $this->addArgument( self::ARGUMENT_ACTION , InputArgument::OPTIONAL , 'Action: ' . implode( ' | ' , self::ACTIONS_ALL ) , self::ACTION_LIST ) ;
        $this->addArgument( self::ARGUMENT_KEY    , InputArgument::OPTIONAL , 'Webhook key from [zitadel.webhooks.<key>] (required for install / rotate / show / uninstall)' ) ;

        $this->addOption( self::OPTION_ENDPOINT , null , InputOption::VALUE_OPTIONAL , 'Public HTTPS URL Zitadel will POST to (required on install when baseUrl is private)' ) ;
        $this->addOption( self::OPTION_MINE     , null , InputOption::VALUE_NONE     , 'On `list`, restrict the output to Targets owned by this API (matching prefix)' ) ;
        $this->addOption( self::OPTION_PURGE    , null , InputOption::VALUE_NONE     , 'On `uninstall`, blank the secret in the config file (event/label/route preserved)' ) ;
        $this->addOption( self::OPTION_YES      , 'y'  , InputOption::VALUE_NONE     , 'Skip the interactive confirmation (for cron / scripted runs)' ) ;
    }

    protected function execute( InputInterface $input , OutputInterface $output ) :int
    {
        [ $io , $timestamp ] = $this->startCommand( $input , $output ) ;

        if( !$this->zitadelClient )
        {
            $io->error( 'No ZitadelClient injected — check the DI definition.' ) ;
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }

        if( !$this->webhookCatalog )
        {
            $io->error( 'No ZitadelWebhookCatalog injected — check the DI definition.' ) ;
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }

        $action = (string) $input->getArgument( self::ARGUMENT_ACTION ) ;

        if( !in_array( $action , self::ACTIONS_ALL , true ) )
        {
            $io->error( "Unknown action '$action'. Allowed: " . implode( ' | ' , self::ACTIONS_ALL ) ) ;
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }

        $io->title( "Zitadel webhook — action: $action" ) ;

        try
        {
            $exit = match( $action )
            {
                self::ACTION_DELETE    => $this->runDelete   ( $io , $input ) ,
                self::ACTION_INSTALL   => $this->runInstall  ( $io , $input ) ,
                self::ACTION_LIST      => $this->runList     ( $io , $input ) ,
                self::ACTION_ROTATE    => $this->runRotate   ( $io , $input ) ,
                self::ACTION_SHOW      => $this->runShow     ( $io , $input ) ,
                self::ACTION_UNINSTALL => $this->runUninstall( $io , $input ) ,
            } ;

            return $this->endCommand( $input , $output , $exit , $timestamp ) ;
        }
        catch( Throwable $e )
        {
            $io->error( 'Unexpected error: ' . $e->getMessage() ) ;
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }
    }

    // -------------------------------------------------------------------------
    // Private — action handlers
    // -------------------------------------------------------------------------

    /**
     * When a management call failed for lack of permission, prints a concrete remediation hint.
     * Managing Actions and Targets is an instance-level capability, so the service account
     * must hold a manager role that grants it (typically *IAM Owner* on the instance). No-op for any other failure.
     *
     * @param SymfonyStyle $io     The console style.
     * @param array        $result A structured result from {@see ZitadelClientTrait::requestRaw()}.
     */
    private function hintMissingPermission( SymfonyStyle $io , array $result ) :void
    {
        if( !self::isPermissionDenied( $result ) )
        {
            return ;
        }

        $io->warning
        ([
            'Zitadel refused the call (HTTP 403). The service account is authenticated' ,
            'but lacks the rights to manage Actions and Targets — an instance-level' ,
            'capability that requires a manager role such as IAM Owner on the instance.' ,
            '' ,
            'Grant it in the Zitadel console, then run this command again:' ,
            '  Users → <service account> → Administrator roles → add the role.' ,
        ]) ;
    }

    /**
     * Least-privilege reminder, printed after a Target was successfully
     * provisioned. The elevated, instance-level role the service account
     * needed for this operation is NOT required for normal API traffic, so
     * the operator can revoke it again once the provisioning is done.
     *
     * @param SymfonyStyle $io The console style.
     */
    private function hintRevokeElevatedRole( SymfonyStyle $io ) :void
    {
        $io->warning
        ([
            'Least privilege: the instance-level role the service account needed here' ,
            'is NOT required for day-to-day API traffic. If you granted it just for' ,
            'this command, you can revoke it now:' ,
            '  Users → <service account> → Administrator roles → remove the role.' ,
        ]) ;
    }

    /**
     * Tells whether a structured client result denotes a missing-permission outcome —
     * the service account authenticated correctly (it obtained a token) but Zitadel refused the call
     * because the account lacks the manager role required to manage Actions and Targets.
     *
     * @param array $result A structured result from {@see ZitadelClientTrait::requestRaw()}.
     *
     * @return bool `true` when Zitadel answered HTTP 403 (Forbidden), `false` otherwise —
     *              including transport failures and missing-token outcomes (status `0`),
     *              which are not permission problems.
     */
    public static function isPermissionDenied( array $result ) :bool
    {
        return ( int ) ( $result[ ZitadelOutput::STATUS ] ?? 0 ) === HttpStatusCode::FORBIDDEN ;
    }

    /**
     * `delete` (no key) — interactive picker over every Target on the
     * instance, used as a rescue / housekeeping path (e.g. removing a
     * legacy Cible whose name does not follow the canonical convention).
     */
    private function runDelete( SymfonyStyle $io , InputInterface $input ) :int
    {
        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        if( empty( $targets ) )
        {
            $io->writeln( '  <comment>·</comment> no Target on this instance' ) ;
            return ExitCode::SUCCESS ;
        }

        usort( $targets , fn( array $a , array $b ) => strcmp( $b[ 'creationDate' ] , $a[ 'creationDate' ] ) ) ;

        $picked = $this->pickTargetInteractively( $io , $targets ) ;

        if( $picked === null )
        {
            $io->writeln( '  <comment>·</comment> aborted' ) ;
            return ExitCode::SUCCESS ;
        }

        if( !( bool ) $input->getOption( self::OPTION_YES ) )
        {
            if( !$io->confirm( 'Delete Target "' . $picked[ 'name' ] . '" (id: ' . $picked[ 'id' ] . ')?' , false ) )
            {
                $io->writeln( '  <comment>·</comment> aborted' ) ;
                return ExitCode::SUCCESS ;
            }
        }

        $result = $this->zitadelClient->deleteTarget( $picked[ 'id' ] ) ;

        if( !( $result[ ZitadelOutput::SUCCESS ] ?? false ) )
        {
            $io->error( 'Delete failed: ' . $this->describeFailure( $result ) ) ;
            $this->hintMissingPermission( $io , $result ) ;
            return ExitCode::FAILURE ;
        }

        $io->success( 'Target deleted.' ) ;
        $io->writeln( '<comment>Note: any Execution that referenced this Target now points to a missing Target.</comment>' ) ;
        return ExitCode::SUCCESS ;
    }

    /**
     * `install <key>` — full provisioning round trip:
     *   1. Look up the descriptor in the catalog.
     *   2. Refuse if a Cible with the canonical name already exists
     *      (suggest `rotate` to refresh the secret instead).
     *   3. Resolve the endpoint: explicit `--endpoint`, else
     *      `baseUrl + descriptor.route` if the base URL is publicly
     *      reachable, else fail loudly.
     *   4. Create the Cible, bind the Execution, write the secret to
     *      the config file.
     */
    private function runInstall( SymfonyStyle $io , InputInterface $input ) :int
    {
        $descriptor = $this->resolveDescriptorOrFail( $io , $input ) ;

        if( $descriptor === null )
        {
            return ExitCode::FAILURE ;
        }

        if( !$this->assertContext( $io ) )
        {
            return ExitCode::FAILURE ;
        }

        $canonical = self::buildCanonicalName( $this->apiIdentifier , $descriptor->label , $this->baseUrl ) ;

        $io->writeln( "  <comment>Key:</comment>      $descriptor->key" ) ;
        $io->writeln( "  <comment>Event:</comment>    $descriptor->event" ) ;
        $io->writeln( "  <comment>Name:</comment>     $canonical" ) ;

        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        $existing = $this->findTargetByName( $targets , $canonical ) ;

        if( $existing !== null )
        {
            $io->error
            (
                'A Target named "' . $canonical . '" already exists (id: ' . $existing[ 'id' ] . ').' .
                "\nRun the `rotate $descriptor->key` action to refresh the secret in place," .
                "\nor the `uninstall $descriptor->key` action to remove it first."
            ) ;
            return ExitCode::FAILURE ;
        }

        $endpoint = $this->resolveEndpoint( $io , $input , $descriptor ) ;

        if( $endpoint === null )
        {
            return ExitCode::FAILURE ;
        }

        $io->writeln( "  <comment>Endpoint:</comment> $endpoint" ) ;
        $io->writeln( '' ) ;

        $created = $this->createTargetOrFail( $io , $canonical , $endpoint ) ;

        if( $created === null )
        {
            return ExitCode::FAILURE ;
        }

        [ $targetId , $signingKey ] = $created ;

        $this->bindOrWarn( $io , $descriptor->event , $targetId ) ;
        $this->writeSecretOrWarn( $io , $descriptor->key , $signingKey ) ;

        $this->hintRevokeElevatedRole( $io ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * `list` — diagnostic listing, sorted creation date desc. The
     * `--mine` flag restricts the output to Targets whose name starts
     * with the canonical `{apiIdentifier} - ` prefix, which is useful
     * when the instance hosts unrelated Targets (other apps, legacy
     * experiments, …).
     */
    private function runList( SymfonyStyle $io , InputInterface $input ) :int
    {
        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        if( ( bool ) $input->getOption( self::OPTION_MINE ) )
        {
            if( !is_string( $this->apiIdentifier ) || $this->apiIdentifier === '' )
            {
                $io->error( '--mine requires [auth.api].identifier to be set in the configuration.' ) ;
                return ExitCode::FAILURE ;
            }

            $prefix  = $this->apiIdentifier . self::NAME_SEPARATOR ;
            $targets = array_values( array_filter
            (
                $targets ,
                fn( array $target ) :bool => str_starts_with( $target[ 'name' ] , $prefix )
            )) ;
        }

        if( empty( $targets ) )
        {
            $io->writeln( '  <comment>·</comment> no Target on this instance' ) ;
            return ExitCode::SUCCESS ;
        }

        usort( $targets , fn( array $a , array $b ) => strcmp( $b[ 'creationDate' ] , $a[ 'creationDate' ] ) ) ;

        $rows = [] ;

        foreach( $targets as $target )
        {
            $rows[] =
            [
                $target[ 'name' ] ,
                $target[ 'id' ] ,
                $target[ 'endpoint' ] ,
                $this->formatDate( $target[ 'creationDate' ] ) ,
            ] ;
        }

        $io->table( [ 'Name' , 'Id' , 'Endpoint' , 'Created' ] , $rows ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * `rotate <key>` — drop the existing Cible (named with the
     * canonical convention) and recreate it with the same endpoint to
     * obtain a fresh signing key. The new secret is written to the
     * config file automatically; the user is reminded to rebuild their
     * configuration afterwards.
     */
    private function runRotate( SymfonyStyle $io , InputInterface $input ) :int
    {
        $descriptor = $this->resolveDescriptorOrFail( $io , $input ) ;

        if( $descriptor === null )
        {
            return ExitCode::FAILURE ;
        }

        if( !$this->assertContext( $io ) )
        {
            return ExitCode::FAILURE ;
        }

        $canonical = self::buildCanonicalName( $this->apiIdentifier , $descriptor->label , $this->baseUrl ) ;

        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        $existing = $this->findTargetByName( $targets , $canonical ) ;

        if( $existing === null )
        {
            $io->error
            (
                'No Target named "' . $canonical . '" on this instance.' .
                "\nRun the `install $descriptor->key` action first."
            ) ;
            return ExitCode::FAILURE ;
        }

        $endpoint = $existing[ 'endpoint' ] ;

        $io->writeln( "  <comment>Key:</comment>      $descriptor->key" ) ;
        $io->writeln( "  <comment>Event:</comment>    $descriptor->event" ) ;
        $io->writeln( "  <comment>Name:</comment>     $canonical" ) ;
        $io->writeln( "  <comment>Endpoint:</comment> $endpoint" ) ;
        $io->writeln( '  <comment>·</comment> deleting existing Target id ' . $existing[ 'id' ] ) ;

        $this->zitadelClient->deleteTarget( $existing[ 'id' ] ) ;

        $created = $this->createTargetOrFail( $io , $canonical , $endpoint ) ;

        if( $created === null )
        {
            return ExitCode::FAILURE ;
        }

        [ $targetId , $signingKey ] = $created ;

        $this->bindOrWarn( $io , $descriptor->event , $targetId ) ;
        $this->writeSecretOrWarn( $io , $descriptor->key , $signingKey ) ;

        $this->hintRevokeElevatedRole( $io ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * `show <key>` — prints the descriptor + the matching Target's
     * Zitadel-side metadata (id, endpoint, creation date) when one
     * exists. Reminds the operator that the signing key is not
     * recoverable for an existing Target.
     */
    private function runShow( SymfonyStyle $io , InputInterface $input ) :int
    {
        $descriptor = $this->resolveDescriptorOrFail( $io , $input ) ;

        if( $descriptor === null )
        {
            return ExitCode::FAILURE ;
        }

        if( !$this->assertContext( $io ) )
        {
            return ExitCode::FAILURE ;
        }

        $canonical = self::buildCanonicalName( $this->apiIdentifier , $descriptor->label , $this->baseUrl ) ;

        $io->writeln( "  <comment>Key:</comment>     $descriptor->key" ) ;
        $io->writeln( "  <comment>Event:</comment>   $descriptor->event" ) ;
        $io->writeln( "  <comment>Label:</comment>   $descriptor->label" ) ;
        $io->writeln( "  <comment>Route:</comment>   $descriptor->route" ) ;
        $io->writeln( "  <comment>Name:</comment>    $canonical" ) ;
        $io->writeln( '' ) ;
        $io->writeln( '  <comment>Secret in config:</comment> ' . ( $descriptor->hasSecret() ? '<info>set</info>' : '<comment>blank</comment>' ) ) ;

        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        $existing = $this->findTargetByName( $targets , $canonical ) ;

        if( $existing === null )
        {
            $io->writeln( '  <comment>Zitadel Cible:</comment>     <comment>not installed</comment>' ) ;
            return ExitCode::SUCCESS ;
        }

        $io->writeln( '  <comment>Zitadel Cible:</comment>     <info>installed</info>' ) ;
        $io->writeln( '    id:           ' . $existing[ 'id' ] ) ;
        $io->writeln( '    endpoint:     ' . $existing[ 'endpoint' ] ) ;
        $io->writeln( '    creationDate: ' . $this->formatDate( $existing[ 'creationDate' ] ) ) ;
        $io->writeln( '' ) ;
        $io->writeln( '<comment>The signing key is only readable at Cible creation. Run `rotate` to generate a fresh one.</comment>' ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * `uninstall <key>` — removes the Cible (with confirmation), and
     * optionally blanks the secret in the config file when
     * `--purge-config` is supplied. The descriptor body
     * (event / label / route) is always preserved so a future
     * `install` can restore the binding without re-typing the contract.
     */
    private function runUninstall( SymfonyStyle $io , InputInterface $input ) :int
    {
        $descriptor = $this->resolveDescriptorOrFail( $io , $input ) ;

        if( $descriptor === null )
        {
            return ExitCode::FAILURE ;
        }

        if( !$this->assertContext( $io ) )
        {
            return ExitCode::FAILURE ;
        }

        $canonical = self::buildCanonicalName( $this->apiIdentifier , $descriptor->label , $this->baseUrl ) ;

        $io->writeln( "  <comment>Key:</comment>  $descriptor->key" ) ;
        $io->writeln( "  <comment>Name:</comment> $canonical" ) ;

        $targets = $this->loadTargets( $io ) ;

        if( $targets === null )
        {
            return ExitCode::FAILURE ;
        }

        $existing = $this->findTargetByName( $targets , $canonical ) ;

        if( $existing !== null )
        {
            if( !( bool ) $input->getOption( self::OPTION_YES ) )
            {
                if( !$io->confirm( 'Delete Cible "' . $canonical . '" (id: ' . $existing[ 'id' ] . ')?' , false ) )
                {
                    $io->writeln( '  <comment>·</comment> aborted' ) ;
                    return ExitCode::SUCCESS ;
                }
            }

            $result = $this->zitadelClient->deleteTarget( $existing[ 'id' ] ) ;

            if( !( $result[ ZitadelOutput::SUCCESS ] ?? false ) )
            {
                $io->error( 'Delete failed: ' . $this->describeFailure( $result ) ) ;
                $this->hintMissingPermission( $io , $result ) ;
                return ExitCode::FAILURE ;
            }

            $io->writeln( '  <info>✓</info> Cible deleted on Zitadel.' ) ;
        }
        else
        {
            $io->writeln( '  <comment>·</comment> no Cible matching the canonical name on Zitadel — nothing to delete.' ) ;
        }

        if( ( bool ) $input->getOption( self::OPTION_PURGE ) )
        {
            $this->writeSecretOrWarn( $io , $descriptor->key , '' , 'Secret blanked in the config file.' ) ;
        }
        else
        {
            $io->writeln( '<comment>The secret in the config file is preserved. Pass --purge-config to blank it.</comment>' ) ;
        }

        return ExitCode::SUCCESS ;
    }

    // -------------------------------------------------------------------------
    // Private — helpers
    // -------------------------------------------------------------------------

    /**
     * Verifies that `apiIdentifier` and `baseUrl` were injected
     * — both are required by every action that builds a canonical
     * Cible name. Reports a typed error and returns false otherwise.
     */
    private function assertContext( SymfonyStyle $io ) :bool
    {
        if( !is_string( $this->apiIdentifier ) || $this->apiIdentifier === '' )
        {
            $io->error( 'No apiIdentifier injected — set [auth.api].identifier in the configuration.' ) ;
            return false ;
        }

        if( !is_string( $this->baseUrl ) || $this->baseUrl === '' )
        {
            $io->error( 'No baseUrl injected — set [app].baseUrl in the configuration.' ) ;
            return false ;
        }

        return true ;
    }

    private function bindOrWarn( SymfonyStyle $io , string $event , string $targetId ) :void
    {
        $result = $this->zitadelClient->setEventExecution( $event , $targetId ) ;

        if( !( $result[ ZitadelOutput::SUCCESS ] ?? false ) )
        {
            $io->warning( 'Execution binding failed: ' . $this->describeFailure( $result ) ) ;
            $io->writeln( "  → fix manually in console (event $event → Target $targetId)" ) ;
            $this->hintMissingPermission( $io , $result ) ;
        }
        else
        {
            $io->writeln( "  <info>✓</info> Execution set (event $event → Target $targetId)" ) ;
        }
    }

    /**
     * @return array{0: string, 1: string}|null `[targetId, signingKey]` on success.
     */
    private function createTargetOrFail( SymfonyStyle $io , string $name , string $endpoint ) :?array
    {
        $result = $this->zitadelClient->createTarget( $name , $endpoint ) ;

        if( !( $result[ ZitadelOutput::SUCCESS ] ?? false ) )
        {
            $io->error( 'Cible creation failed: ' . $this->describeFailure( $result ) ) ;
            $this->hintMissingPermission( $io , $result ) ;
            return null ;
        }

        $body = $result[ ZitadelOutput::BODY ] ?? null ;

        $targetId   = is_object( $body ) ? ( $body->id         ?? null ) : null ;
        $signingKey = is_object( $body ) ? ( $body->signingKey ?? null ) : null ;

        if( !is_string( $targetId ) || $targetId === '' )
        {
            $io->error( 'Cible created but Zitadel did not return an id (raw body: ' . ( $result[ ZitadelOutput::RAW_BODY ] ?? '' ) . ')' ) ;
            return null ;
        }

        $io->writeln( "  <info>✓</info> Cible created (id: $targetId)" ) ;

        return [ $targetId , is_string( $signingKey ) ? $signingKey : '' ] ;
    }

    private function describeFailure( array $result ) :string
    {
        $status = ( int    ) ( $result[ ZitadelOutput::STATUS   ] ?? 0  ) ;
        $error  = ( string ) ( $result[ ZitadelOutput::ERROR    ] ?? '' ) ;
        $body   = ( string ) ( $result[ ZitadelOutput::RAW_BODY ] ?? '' ) ;

        return $status > 0 ? "HTTP $status — $body" : "transport: $error" ;
    }

    /**
     * Searches a pre-loaded, normalised Target list for an exact name match.
     *
     * Pure lookup over the result of {@see loadTargets()} — kept separate from
     * the listing call so the API request (and its permission-error handling)
     * happens once in the caller, and a genuine "not found" is never confused
     * with a listing failure such as HTTP 403.
     *
     * @param list<array{ id: string , name: string , endpoint: string , creationDate: string }> $targets Normalised Targets.
     * @param string                                                                              $name    The canonical name to match.
     *
     * @return array{ id: string , name: string , endpoint: string , creationDate: string }|null The matching Target, or null when none matches.
     */
    private function findTargetByName( array $targets , string $name ) :?array
    {
        return array_find( $targets , fn( $target ) => ( $target['name'] ?? null ) === $name );
    }

    /**
     * Formats a Zitadel ISO-8601 creationDate into a short readable
     * `YYYY-MM-DD HH:MM`. Returns the raw value on unparsable inputs.
     */
    private function formatDate( string $iso8601 ) :string
    {
        if( $iso8601 === '' )
        {
            return '?' ;
        }

        $timestamp = strtotime( $iso8601 ) ;

        return $timestamp === false ? $iso8601 : gmdate( 'Y-m-d H:i' , $timestamp ) ;
    }

    /**
     * Loads + normalises every Target on the current instance, or
     * returns `null` on transport failure (the caller decides how to
     * surface the error).
     *
     * @return list<array{ id: string , name: string , endpoint: string , creationDate: string }>|null
     */
    private function loadTargets( SymfonyStyle $io ) :?array
    {
        $list = $this->zitadelClient->listTargets() ;

        if( !( $list[ ZitadelOutput::SUCCESS ] ?? false ) )
        {
            $io->error( 'Listing failed: ' . $this->describeFailure( $list ) ) ;
            $this->hintMissingPermission( $io , $list ) ;
            return null ;
        }

        $body = $list[ ZitadelOutput::BODY ] ?? null ;
        $raw  = is_object( $body ) && isset( $body->targets ) && is_array( $body->targets ) ? $body->targets : [] ;

        $targets = [] ;

        foreach( $raw as $target )
        {
            if( is_object( $target ) )
            {
                $targets[] = $this->normaliseTarget( $target ) ;
            }
        }

        return $targets ;
    }

    /**
     * @return array{ id: string , name: string , endpoint: string , creationDate: string }
     */
    private function normaliseTarget( object $target ) :array
    {
        return
        [
            'id'           => is_string( $target->id           ?? null ) ? $target->id           : '' ,
            'name'         => is_string( $target->name         ?? null ) ? $target->name         : '' ,
            'endpoint'     => is_string( $target->endpoint     ?? null ) ? $target->endpoint     : '' ,
            'creationDate' => is_string( $target->creationDate ?? null ) ? $target->creationDate : '' ,
        ] ;
    }

    /**
     * Interactive picker over a pre-sorted list of Targets. Returns the
     * picked entry, or `null` when the user cancels.
     *
     * Uses a numerically indexed `$choices` array so `SymfonyStyle::choice()`
     * returns the picked label (its value) — an associative array would
     * make `choice()` return the key instead, which trips the cancel
     * detection and breaks the lookup back into the targets list.
     *
     * @param list<array{ id: string , name: string , endpoint: string , creationDate: string }> $targets
     *
     * @return array{ id: string , name: string , endpoint: string , creationDate: string }|null
     */
    private function pickTargetInteractively( SymfonyStyle $io , array $targets ) :?array
    {
        $cancelLabel = '(cancel)' ;
        $labels      = [] ;
        $targetByLabel = [] ;

        foreach( $targets as $target )
        {
            $label                  = $target[ 'name' ] . '  —  ' . $this->formatDate( $target[ 'creationDate' ] ) . '  —  ' . $target[ 'endpoint' ] ;
            $labels[]               = $label ;
            $targetByLabel[ $label ] = $target ;
        }

        $labels[] = $cancelLabel ;

        $pickedLabel = $io->choice( 'Pick a Target' , $labels , $cancelLabel ) ;

        if( $pickedLabel === $cancelLabel )
        {
            return null ;
        }

        return $targetByLabel[ $pickedLabel ] ?? null ;
    }

    /**
     * Looks up the descriptor under the supplied `<key>` argument.
     * Reports a typed error when the argument is missing or the key
     * is unknown, and returns `null` to short-circuit the action.
     */
    private function resolveDescriptorOrFail( SymfonyStyle $io , InputInterface $input ) :?ZitadelWebhookDescriptor
    {
        $key = $input->getArgument( self::ARGUMENT_KEY ) ;

        if( !is_string( $key ) || $key === '' )
        {
            $action = (string) $input->getArgument( self::ARGUMENT_ACTION ) ;
            $io->error( "$action requires a <key> argument matching a section under [zitadel.webhooks.*]." ) ;
            $io->writeln( '  Available keys: ' . ( empty( $this->webhookCatalog->keys() ) ? '<none — check your configuration>' : implode( ' , ' , $this->webhookCatalog->keys() ) ) ) ;
            return null ;
        }

        $descriptor = $this->webhookCatalog->get( $key ) ;

        if( $descriptor === null )
        {
            $io->error( "Unknown webhook key '$key' — no [zitadel.webhooks.$key] section in the configuration." ) ;
            $io->writeln( '  Available keys: ' . ( empty( $this->webhookCatalog->keys() ) ? '<none>' : implode( ' , ' , $this->webhookCatalog->keys() ) ) ) ;
            return null ;
        }

        return $descriptor ;
    }

    /**
     * Resolves the public HTTPS URL Zitadel will POST to:
     *
     *   1. `--endpoint <url>` always wins when supplied.
     *   2. Otherwise the URL is built from `baseUrl + descriptor.route`,
     *      but only when `baseUrl` resolves to a publicly reachable
     *      host (FQDN or non-private IPv4).
     *   3. A private `baseUrl` (localhost / RFC1918 / loopback)
     *      forces the operator to supply `--endpoint` explicitly,
     *      typically the URL of a tunnel like cloudflared.
     */
    private function resolveEndpoint( SymfonyStyle $io , InputInterface $input , ZitadelWebhookDescriptor $descriptor ) :?string
    {
        $explicit = $input->getOption( self::OPTION_ENDPOINT ) ;

        if( is_string( $explicit ) && $explicit !== '' )
        {
            return $explicit ;
        }

        if( !isPublicUrl( ( string ) $this->baseUrl ) )
        {
            $io->error
            (
                'baseUrl "' . $this->baseUrl . '" is not publicly reachable. ' .
                "\nPass --endpoint <https://...> with the URL of a tunnel (cloudflared, ngrok, …)" .
                "\nso Zitadel cloud can deliver the payload."
            ) ;
            return null ;
        }

        return rtrim( ( string ) $this->baseUrl , '/' ) . $descriptor->route ;
    }

    /**
     * Writes (or blanks) the descriptor secret in the injected target file
     * ({@see self::CONFIG_FILE}). An existing file is backed up to
     * `<file>.bak` before the in-place write; a missing target is created.
     * When no target is configured, the snippet is printed for the operator
     * to paste manually instead.
     *
     * Failures are surfaced as warnings rather than fatal errors —
     * the previous Zitadel-side action (Cible creation, deletion, …)
     * has already succeeded by the time we get here, so a config
     * write hiccup should not look like a complete rollback.
     */
    private function writeSecretOrWarn( SymfonyStyle $io , string $key , string $secret , ?string $successMessage = null ) :void
    {
        $path = $this->configFile ;

        if( $path === '' )
        {
            $io->warning( 'No config file configured — paste the snippet manually.' ) ;
            $io->writeln( '  [zitadel.webhooks.' . $key . ']' ) ;
            $io->writeln( '  secret = "' . $secret . '"' ) ;
            return ;
        }

        // A missing target is created from scratch (replaceSecretInToml
        // appends the section); an existing one is read and backed up.
        $exists  = is_file( $path ) ;
        $content = $exists ? file_get_contents( $path ) : '' ;

        if( $content === false )
        {
            $io->warning( 'Could not read ' . $path . ' — paste the snippet manually.' ) ;
            return ;
        }

        $backup = null ;

        if( $exists )
        {
            $backup = $path . '.bak' ;

            if( file_put_contents( $backup , $content ) === false )
            {
                $io->warning( 'Could not write backup ' . $backup . ' — aborting injection.' ) ;
                return ;
            }
        }

        $updated = self::replaceSecretInToml( $content , $key , $secret ) ;

        if( file_put_contents( $path , $updated ) === false )
        {
            $io->warning( 'Could not write ' . $path . ( $backup !== null ? ' — restore from ' . $backup : '' ) ) ;
            return ;
        }

        $io->writeln( '' ) ;

        if( $successMessage !== null )
        {
            $io->success( $successMessage ) ;
        }
        else
        {
            $io->success( 'Secret written to [zitadel.webhooks.' . $key . '] in ' . basename( $path ) ) ;
        }

        if( $backup !== null )
        {
            $io->writeln( '  Backup saved to <comment>' . $backup . '</comment>' ) ;
        }

        $io->writeln( '  Rebuild your configuration so the change takes effect.' ) ;
    }
}
