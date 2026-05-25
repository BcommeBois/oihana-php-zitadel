# Changelog

All notable changes to **oihana/php-zitadel** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
