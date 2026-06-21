# Changelog

All notable changes to **oihana/php-zitadel** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-21

### Changed

- `ZitadelWebhookCommand`: the public-reachability check now delegates to the
  new `oihana\http\helpers\url\isPublicUrl()` helper from oihana/php-http
  (dev-main ≥ `22df5e7`) instead of the in-class `isPublicBaseUrl()`. Behaviour
  is a strict superset of the old method: IP literals are handed to
  `isPublicIp()`, so private / reserved IPv6 ranges (RFC 4193 `fd00::/8`, …) are
  now rejected too, and bracketed IPv6 hosts (`[::1]`) are normalised before the
  test. All previously covered cases (FQDN, public IPv4, `localhost` /
  `*.localhost`, loopback, RFC 1918, `172.x` boundaries, empty / malformed
  input) behave identically.
- `ZitadelWebhookCommand`: the descriptor secret is now written to a
  caller-injected config file (new `CONFIG_FILE` init key) instead of a
  guessed project-root `config.toml`. A missing target file is created; an
  existing one is backed up to `<file>.bak` before the in-place rewrite. When
  no target is injected the snippet is printed for manual paste. Removed the
  `findRootConfigPath()` filesystem walk (and `CONFIG_LOOKUP_DEPTH`) and the
  project-specific `config.toml` / `bun refresh` wording from messages and
  docblocks — the command is now path-agnostic.
- docs(`wiki/{fr,en}/webhooks.md`): synced the webhook guide with the
  path-agnostic command. Dropped the stale `--inject` flag (the secret is
  written automatically on `install` / `rotate`), rewrote the actions/options
  tables to the real CLI surface (`--endpoint`, `--mine`, `--purge-config`,
  `--yes`; positional `<key>`; default action `list`), and genericised the
  project-specific wording (hard-coded `config.toml`, `bun refresh`) into
  neutral "your configuration" / "rebuild your configuration" language.
- Dependencies: dropped the now-unused `oihana/php-system` requirement. `php-zitadel` consumes no `php-system` namespace directly; the focused split packages it needs (e.g. `oihana/php-logging`, `oihana/php-traits`) are pulled transitively through `oihana/php-arango`, `oihana/php-auth` and `oihana/php-commands`. Removes the heavy Slim/Twig/Symfony stack from the dependency tree. No code or public-API change.

### Added

- Initial scaffold: Composer manifest, PHPUnit 12 + phpDocumentor 3 configuration, MPL-2.0 license, README, CHANGELOG, sibling-aligned folder layout (`src/`, `tests/`, `wiki/`, `assets/`), one `autoload.files` entry (`getZitadelClient.php`).
- Source code under `src/oihana/zitadel/` (35 PHP files):
  - `ZitadelClient.php` — entry-point Guzzle client composed of 8 trait families.
  - `OAuthClientResolver.php` — TTL-bound in-process cache + ArangoDB `oauth_clients` mirror + Zitadel Management API fallback.
  - `commands/ZitadelWebhookCommand.php` — Symfony Console command for declarative webhook sync.
  - `enums/` (14 files): `ZitadelAppAuthMethod`, `ZitadelCookie`, `ZitadelEndpoint`, `ZitadelEndpointPlaceholder`, `ZitadelError`, `ZitadelErrorId`, `ZitadelGrant`, `ZitadelMessageKeyword`, `ZitadelOutcome`, `ZitadelOutput`, `ZitadelQueryMethod`, `ZitadelScope`, `ZitadelSessionField`, `ZitadelSessionSearchParam`.
  - `helpers/getZitadelClient.php` — DI helper that resolves the Zitadel client from a PSR-11 container.
  - `schema/constants/Zitadel.php` + `schema/constants/traits/` (3 traits): `QueryTrait`, `RoleTrait`, `UserTrait`.
  - `traits/SessionCreatorTrait.php` — ArangoDB session lifecycle (upsert, sid anchoring, first-login activation, invitation acceptance).
  - `traits/ZitadelClientTrait.php` + `traits/ZitadelOutcomeTrait.php` — base client composition + outcome enum routing.
  - `traits/client/` (8 traits): `ZitadelClientApplicationTrait`, `ZitadelClientPasswordTrait`, `ZitadelClientRoleTrait`, `ZitadelClientServiceTrait`, `ZitadelClientSessionTrait`, `ZitadelClientTargetTrait`, `ZitadelClientTrait`, `ZitadelClientUserTrait`.
  - `webhooks/` (2 files): `ZitadelWebhookCatalog`, `ZitadelWebhookDescriptor`.
- Test suite under `tests/oihana/zitadel/` (8 PHP files): `OAuthClientResolverTest`, `commands/ZitadelWebhookCommandTest`, `enums/ZitadelErrorIdTest`, `traits/SessionCreatorTraitTest`, `traits/client/ZitadelClientServiceTraitTest`, `traits/client/ZitadelClientUserTraitTest`, `webhooks/ZitadelWebhookCatalogTest`, `webhooks/ZitadelWebhookDescriptorTest`. Unit-only — no live Zitadel instance required.
- Bilingual user guides under `wiki/{fr,en}/`: README index + webhooks page (existing). Further pages (getting-started, OIDC flow, sessions) to be added in subsequent commits.
- `ZitadelWebhookCommand` — permission-aware feedback: when a management call is refused with HTTP 403, the command prints an actionable remediation hint (grant the service account a manager role allowed to manage Actions/Targets — an instance-level capability, typically *IAM Owner*). Wired on Target creation, Execution binding, Target listing and Target deletion.
- `ZitadelWebhookCommand` — least-privilege reminder printed after a successful `install` / `rotate`, inviting the operator to revoke the elevated, instance-level role that day-to-day API traffic does not need.
- `ZitadelWebhookCommand::isPermissionDenied()` — public static predicate that detects an HTTP 403 (Forbidden) outcome in a structured client result.

### Removed

- `ZitadelWebhookCommand::isPublicBaseUrl()` — superseded by oihana/php-http's `oihana\http\helpers\url\isPublicUrl()`. Its dedicated unit tests were dropped as well, the helper being covered by php-http's own suite.

### Changed

- `ZitadelWebhookCommand::findTargetByName()` is now a pure search over a pre-loaded Target list (`(array $targets, string $name)`); callers fetch the instance Targets through `loadTargets()` first. A listing failure (e.g. HTTP 403) now surfaces the permission hint and aborts, instead of being silently treated as "Target not found" — fixing the misleading "run install first" message on `rotate` when the service account lacks rights. Removes the duplicated listing path.
- `SessionCreatorTrait::extractClaimsFromAccessToken()` decodes the JWT payload through `oihana\core\encoding\base64UrlDecode()` (strict base64url alphabet validation) and returns `null` when decoding fails, rather than passing non-validated bytes to `json_decode()`.
- `ZitadelWebhookCommand` housekeeping: removed an unused import and migrated structured-result key access to `ZitadelOutput::*` constants.

### Tests

- `ZitadelWebhookCommandTest` — added coverage for `isPermissionDenied()`, the permission and least-privilege hints, and the pure `findTargetByName()` lookup.
- `SessionCreatorTraitTest` — added coverage for `extractClaimsFromAccessToken()` (valid JWT, wrong segment count, malformed base64url payload, non-object payload).
