# Documentation — `oihana/php-zitadel`

Toolkit PHP composable pour le fournisseur d'identité [Zitadel](https://zitadel.com/). Cette documentation couvre la signature OIDC/OAuth2, le client REST Zitadel, la vérification JWT/JWKS, la gestion des sessions, et les webhooks V2.

## Sommaire

| Page | Contenu | Statut |
|---|---|---|
| [Webhooks Zitadel — guide complet](webhooks.md) | Concepts Zitadel V2 (Targets + Executions), setup local (Valet + cloudflared), déploiement serveur (Nginx + Let's Encrypt), sécurité réseau, commande `zitadel:webhook`, troubleshooting | ✅ disponible |
| Démarrage rapide | Installation, configuration OAuth2, premier appel `ZitadelClient` | 🚧 à venir |
| Authentification OIDC / OAuth2 | Flow PKCE, JWT/JWKS verification, token exchange | 🚧 à venir |
| Sessions | `SessionCreatorTrait`, upsert ArangoDB, sid anchoring, first-login activation | 🚧 à venir |
| Client Management API | Traits `ZitadelClient*` (Users, Roles, Services, Targets, …) | 🚧 à venir |

## Vocabulaire

- **Application hôte** — l'application PHP qui consomme `oihana/php-zitadel`. Elle fournit le conteneur PSR-11 (typiquement PHP-DI), la configuration `[zitadel]` (issuer, clientId, clientSecret, scopes, etc.), et — pour les webhooks — les contrôleurs HTTP qui reçoivent les payloads signés.
- **Zitadel V2 Targets + Executions** — le système moderne d'événements Zitadel : une *Cible* décrit où POSTer le payload signé, une *Execution* lie un événement à une ou plusieurs Cibles. Voir [webhooks](webhooks.md).
- **signingKey** — clé HMAC partagée entre Zitadel et votre application, générée à la création d'une Cible et utilisée pour signer chaque payload sortant.

## Code source

Le code du package vit sous [`src/oihana/zitadel/`](../../src/oihana/zitadel/).

## Voir aussi

- [Documentation officielle Zitadel](https://zitadel.com/docs) — référence canonique côté fournisseur.
- [Spec Zitadel V2 Actions / Targets](https://zitadel.com/docs/apis/actions/v2) — détail technique des webhooks.
