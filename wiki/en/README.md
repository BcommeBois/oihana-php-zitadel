# Documentation — `oihana/php-zitadel`

Composable PHP toolkit for the [Zitadel](https://zitadel.com/) identity provider. This documentation covers OIDC/OAuth2 signing, the Zitadel REST client, JWT/JWKS verification, session lifecycle, and V2 webhooks.

## Contents

| Page | Content | Status |
|---|---|---|
| [Zitadel webhooks — full guide](webhooks.md) | Zitadel V2 concepts (Targets + Executions), local setup (Valet + cloudflared), server deployment (Nginx + Let's Encrypt), network security, the `zitadel:webhook` command, troubleshooting | ✅ available |
| Getting started | Installation, OAuth2 configuration, first `ZitadelClient` call | 🚧 coming soon |
| OIDC / OAuth2 authentication | PKCE flow, JWT/JWKS verification, token exchange | 🚧 coming soon |
| Sessions | `SessionCreatorTrait`, ArangoDB upsert, sid anchoring, first-login activation | 🚧 coming soon |
| Management API client | `ZitadelClient*` traits (Users, Roles, Services, Targets, …) | 🚧 coming soon |

## Vocabulary

- **Host application** — the PHP application consuming `oihana/php-zitadel`. It provides the PSR-11 container (typically PHP-DI), the `[zitadel]` configuration (issuer, clientId, clientSecret, scopes, etc.), and — for webhooks — the HTTP controllers that receive the signed payloads.
- **Zitadel V2 Targets + Executions** — Zitadel's modern event system: a *Target* describes where to POST the signed payload, an *Execution* binds an event to one or more Targets. See [webhooks](webhooks.md).
- **signingKey** — HMAC key shared between Zitadel and your application, generated at Target creation and used to sign each outgoing payload.

## Source code

The package code lives under [`src/oihana/zitadel/`](../../src/oihana/zitadel/).

## See also

- [Official Zitadel documentation](https://zitadel.com/docs) — canonical reference on the provider side.
- [Zitadel V2 Actions / Targets spec](https://zitadel.com/docs/apis/actions/v2) — technical details on webhooks.
