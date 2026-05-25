# Oihana PHP Zitadel

![Oihana PHP Zitadel](https://raw.githubusercontent.com/BcommeBois/oihana-php-zitadel/main/assets/images/oihana-php-zitadel-logo-inline-512x160.png)

Composable PHP toolkit for the [Zitadel](https://zitadel.com/) identity provider. Part of the **Oihana PHP** ecosystem, this package bundles an OIDC/OAuth2 API client, JWT/JWKS verification, ArangoDB-backed session lifecycle helpers, OAuth client metadata resolution, V2 Action webhook catalog and descriptor, and Symfony Console commands.

[![Latest Version](https://img.shields.io/packagist/v/oihana/php-zitadel.svg?style=flat-square)](https://packagist.org/packages/oihana/php-zitadel)
[![Total Downloads](https://img.shields.io/packagist/dt/oihana/php-zitadel.svg?style=flat-square)](https://packagist.org/packages/oihana/php-zitadel)
[![License](https://img.shields.io/packagist/l/oihana/php-zitadel.svg?style=flat-square)](LICENSE)

## 📚 Documentation

Full API reference (generated with phpDocumentor): `https://bcommebois.github.io/oihana-php-zitadel`

User guides (FR + EN) live under [`wiki/`](wiki/).

## 📦 Installation

Requires [PHP 8.4+](https://php.net/releases/) and a [Zitadel](https://zitadel.com/) instance reachable over HTTPS. Install via [Composer](https://getcomposer.org/):

```bash
composer require oihana/php-zitadel
```

## ✨ What you can do

- **Talk to Zitadel over the Management + Auth APIs** through `ZitadelClient` — a Guzzle-based HTTP client composed of focused traits (`ZitadelClientApplicationTrait`, `ZitadelClientPasswordTrait`, `ZitadelClientRoleTrait`, `ZitadelClientServiceTrait`, `ZitadelClientSessionTrait`, `ZitadelClientTargetTrait`, `ZitadelClientUserTrait`), with typed enums for endpoints, scopes, grants, query methods, error ids and outcomes.
- **Resolve OAuth clients to human-readable names** via `OAuthClientResolver` — in-process TTL cache + ArangoDB `oauth_clients` mirror + fallback to the Zitadel Management API for auto-seeding.
- **Mirror Zitadel sessions in ArangoDB** via `SessionCreatorTrait` — upsert on `[identifier, clientId, userAgent, active]`, sid anchoring from the id-token claims, IP + User-Agent capture, first-login activation + pending invitation acceptance.
- **Build V2 Action webhook handlers** via `ZitadelWebhookDescriptor` + `ZitadelWebhookCatalog` — typed event keys, route declaration, secret rotation, validation.
- **Plug into a CLI** through the included `ZitadelWebhookCommand` — declarative webhook synchronization between Zitadel and the application.

### Under the hood

- A consistent set of typed enums and constants — `ZitadelEndpoint`, `ZitadelEndpointPlaceholder`, `ZitadelScope`, `ZitadelGrant`, `ZitadelQueryMethod`, `ZitadelError`, `ZitadelErrorId`, `ZitadelOutcome`, `ZitadelSessionField`, `ZitadelSessionSearchParam`, `ZitadelMessageKeyword`, `ZitadelOutput`, `ZitadelAppAuthMethod`, `ZitadelCookie` — no magic strings.
- Pure-PHP HTTP transport based on [GuzzleHttp](https://github.com/guzzle/guzzle) v7.
- JWT/JWKS verification through [firebase/php-jwt](https://github.com/firebase/php-jwt) v7.
- Persistence delegated to [`oihana/php-arango`](https://github.com/BcommeBois/oihana-php-arango) for OAuth client mirror + session storage.

## ✅ Running tests

Run all tests:

```bash
composer test
```

Run a specific test file:

```bash
composer test ./tests/oihana/zitadel/webhooks/ZitadelWebhookDescriptorTest.php
```

The unit tests cover the OAuth client resolver, the session creator trait (with a PSR-7 mocked request), the webhook catalog and descriptor, the webhook command, error ids and selected client traits — they run without a live Zitadel instance.

## 🛠️ Generate the documentation

We use [phpDocumentor](https://phpdoc.org/) to generate documentation into the `./docs` folder.

```bash
composer doc
```

## 🧾 License

Licensed under the [Mozilla Public License 2.0 (MPL‑2.0)](https://www.mozilla.org/en-US/MPL/2.0/).

## 👤 About the author

- Author: Marc ALCARAZ (aka eKameleon)
- Email: `marc@ooop.fr`
- Website: `https://www.ooop.fr`

## 🔗 Related packages

| Package | Description |
| --- | --- |
| [oihana/php-arango](https://github.com/BcommeBois/oihana-php-arango) | Composable toolkit for ArangoDB — document/edge models, AQL helpers, controllers. |
| [oihana/php-auth](https://github.com/BcommeBois/oihana-php-auth) | Casbin RBAC + JWT/OIDC authorization toolkit. |
| [oihana/php-commands](https://github.com/BcommeBois/oihana-php-commands) | Symfony Console kernel and reusable command traits. |
| [oihana/php-core](https://github.com/BcommeBois/oihana-php-core) | Core helpers and utilities shared across the ecosystem. |
| [oihana/php-enums](https://github.com/BcommeBois/oihana-php-enums) | Typed constants and enums — no more magic strings. |
| [oihana/php-files](https://github.com/BcommeBois/oihana-php-files) | File system helpers (paths, readers, writers). |
| [oihana/php-http](https://github.com/BcommeBois/oihana-php-http) | HTTP helpers — client IP, cookies, route patterns. |
| [oihana/php-reflect](https://github.com/BcommeBois/oihana-php-reflect) | Reflection and object hydration utilities. |
| [oihana/php-schema](https://github.com/BcommeBois/oihana-php-schema) | Schema.org constants and vocabulary. |
| [oihana/php-system](https://github.com/BcommeBois/oihana-php-system) | Framework helpers — controllers, models, request handling. |
