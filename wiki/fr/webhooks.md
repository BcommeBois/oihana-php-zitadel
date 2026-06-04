# Webhooks Zitadel — guide complet

[English version](../en/webhooks.md)

Ce guide couvre tout ce qu'il faut pour câbler un webhook Zitadel vers votre API : concepts Zitadel V2 (Targets + Executions), configuration en dev local avec cloudflared, déploiement serveur Linux/Nginx, considérations firewall, et utilisation de la commande CLI dédiée.

## Sommaire

- [Concepts Zitadel V2](#concepts-zitadel-v2)
- [Vue d'ensemble du flow](#vue-densemble-du-flow)
- [Webhooks supportés par l'API](#webhooks-supportés-par-lapi)
- [Setup en local (Valet + cloudflared)](#setup-en-local-valet--cloudflared)
- [Setup serveur Linux (Nginx Debian)](#setup-serveur-linux-nginx-debian)
- [Sécurité réseau et firewall](#sécurité-réseau-et-firewall)
- [La commande `zitadel:webhook`](#la-commande-authzitadelwebhook)
- [Ajouter un nouveau type de webhook](#ajouter-un-nouveau-type-de-webhook)
- [Troubleshooting](#troubleshooting)

## Concepts Zitadel V2

Depuis sa V2, Zitadel a remplacé son système d'**Actions V1** (basé sur des scripts JavaScript exécutés sur des Flows d'authentification) par un système plus moderne en deux concepts :

### Cible (Target)

Une **Cible** décrit où Zitadel doit envoyer des données : URL HTTPS publique, type d'appel (REST Webhook / Call / Async), format de payload (JSON / JWT / JWE), timeout, et — automatiquement généré à la création — une **clé de signature HMAC** (`signingKey`) que Zitadel utilise pour signer chaque payload sortant.

Une Cible est purement *où* envoyer — elle ne dit pas *quand*.

⚠️ **La signing key n'est révélée qu'à la création** de la Cible (dans la réponse de l'API ou via la commande CLI dédiée). Une fois créée, l'UI Zitadel et l'API ne la ré-exposent plus. Pour récupérer une clé perdue : utiliser `zitadel:webhook rotate` qui supprime + recrée la Cible.

### Action (Execution)

Une **Execution** lie une *condition* (un événement Zitadel, ex. `user.human.password.changed`) à une ou plusieurs Cibles. Quand l'événement se produit, Zitadel POST le payload signé HMAC vers chaque Cible.

Une Execution est purement *quand* déclencher — elle référence des Cibles existantes.

### Console Zitadel

Dans la console cloud Zitadel V2 (votre instance, `*.zitadel.cloud`), les deux concepts sont accessibles séparément sous **Paramètres par défaut** :

- **Paramètres par défaut → Cibles** : créer / lister / éditer les Cibles
- **Paramètres par défaut → Actions** : créer une Action (= Execution) qui réfère une Cible existante

⚠️ Il existe aussi une page **Actions V1** accessible depuis la nav principale du projet. Elle est obsolète (bandeau jaune Zitadel : « Actions V2 finira par remplacer V1 »). **Ne pas l'utiliser** pour de nouveaux webhooks.

## Vue d'ensemble du flow

```
Utilisateur change son mot de passe
            ↓
Zitadel persiste le nouveau hash
            ↓
Zitadel émet l'événement `user.human.password.changed`
            ↓
Execution Zitadel matche l'événement
            ↓
Zitadel POST sur l'URL de la Cible
   - Header `ZITADEL-Signature: t=<unix>,v1=<hex>`
   - Body JSON signé HMAC-SHA256 avec la signing key partagée
            ↓
Votre API (`/webhooks/zitadel/password-changed`)
   - Lit le raw body AVANT BodyParsingMiddleware
   - Vérifie la signature HMAC
   - Decode le JSON
   - Idempotency Memcached (TTL 24h)
   - Lookup user via aggregateId
   - Révoque les sessions actives (avec exclusion optionnelle)
   - Audit log + retour 200
```

Le traitement côté API (vérification de signature, idempotence, révocation des sessions) est à la charge de l'application hôte — cette page ne couvre que la partie qui touche Zitadel.

## Webhooks supportés par l'API

Chaque webhook = 1 Cible + 1 Execution dans Zitadel + 1 entrée dans la section `[zitadel.webhooks]` de votre configuration. Exemple d'une seule entrée :

| Événement Zitadel             | Cible (suggérée)          | Clé `[zitadel.webhooks]` | Endpoint API                              |
|-------------------------------|---------------------------|--------------------------|-------------------------------------------|
| `user.human.password.changed` | `my-api password-changed` | `password_changed`       | `POST /webhooks/zitadel/password-changed` |

Pour ajouter un nouveau type de webhook : voir [Ajouter un nouveau type de webhook](#ajouter-un-nouveau-type-de-webhook).

## Configuration en local (Valet + cloudflared)

Zitadel cloud doit pouvoir POST sur ton API. En local, ton API tourne sur `https://myapp.localhost` (Valet) — pas joignable depuis Internet. 

Solution : exposer un tunnel HTTPS via **cloudflared**.

### 1. Installer cloudflared

```shell
brew install cloudflared
cloudflared --version
```

Pas besoin de compte Cloudflare pour le mode "quick tunnel" (URL éphémère, recréée à chaque lancement).

### 2. Lancer le tunnel

Dans un terminal séparé (à laisser ouvert tant que tu veux que le webhook fonctionne) :

```shell
cloudflared tunnel \
    --url https://myapp.localhost \
    --no-tls-verify \
    --http-host-header myapp.localhost
```

Pourquoi ces deux flags supplémentaires :

- `--no-tls-verify` : Valet sert en HTTPS avec un certificat auto-signé local. Sans ce flag, cloudflared rejette le handshake.
- `--http-host-header myapp.localhost` : cloudflared réécrit le `Host:` envoyé à nginx. Sans ça, nginx Valet voit `xxx.trycloudflare.com` au lieu de `myapp.localhost` et tombe sur la page Valet par défaut au lieu de votre app.

cloudflared affichera une URL du genre :

```
+--------------------------------------------------------------------------------------------+
|  Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):  |
|  https://random-words-1234.trycloudflare.com                                               |
+--------------------------------------------------------------------------------------------+
```

### 3. Vérifier que le tunnel transite bien jusqu'à l'API

```shell
curl -i https://random-words-1234.trycloudflare.com/version
```

Tu dois voir une réponse JSON de votre API (pas la page Valet par défaut).

### 4. Provisionner Cible + Action côté Zitadel

```shell
php bin/console zitadel:webhook install password_changed --endpoint https://random-words-1234.trycloudflare.com/webhooks/zitadel/password-changed
```

Cette commande :

1. Crée la Cible (l'URL endpoint est ton tunnel)
2. Lie l'Execution à l'événement `user.human.password.changed`
3. Écrit automatiquement la signing key dans le fichier de configuration injecté (clé d'initialisation `CONFIG_FILE`), sous `[zitadel.webhooks.password_changed]`. Le fichier est créé s'il est absent, et une sauvegarde `<fichier>.bak` est faite avant la réécriture en place. Si aucun fichier n'est injecté, la commande imprime le snippet à coller manuellement.

Reconstruire ensuite votre configuration pour que le changement prenne effet et que le contrôleur utilise le nouveau secret.

### 5. Tester de bout en bout

Déclencher l'événement Zitadel cible (par exemple changer le mot de passe d'un utilisateur de test) puis vérifier côté application que le webhook a bien été reçu : log d'accès Nginx, log applicatif, et effet métier attendu (par exemple révocation des sessions actives de cet utilisateur).

### Pièges du mode dev local

- **L'URL cloudflared change à chaque lancement** (mode quick tunnel). Si tu coupes cloudflared puis relances, tu auras une nouvelle URL → re-faire un `install --endpoint <nouvelle-url>` pour mettre à jour la Cible Zitadel. Pour une URL stable en dev, créer un compte Cloudflare gratuit et utiliser un tunnel nommé.
- **Si cloudflared n'est pas lancé**, la requête de Zitadel arrivera à expiration (timeout) et le webhook ne fera rien (les sessions ne seront pas révoquées). Les tests d'intégration `your password-change integration tests` continuent de marcher car ils signent les payloads localement, sans passer par Zitadel.

## Configuration serveur Linux (Nginx Debian)

En staging et en prod, l'API est joignable directement via DNS public — **pas besoin de tunnel cloudflared**. Le webhook Zitadel pointe directement vers le DNS de l'API.

### Pré-requis

| Env       | URL Cible recommandée                                                 | DNS public ?   |
|-----------|-----------------------------------------------------------------------|----------------|
| Local dev | `https://xxx.trycloudflare.com/webhooks/zitadel/password-changed`     | non (tunnel)   |
| Staging   | `https://staging-api.example.com/webhooks/zitadel/password-changed` | **oui requis** |
| Prod      | `https://api.example.com/webhooks/zitadel/password-changed`         | **oui requis** |

⚠️ **Une URL HTTP nue ou une IP privée RFC1918 ne marche pas** : Zitadel cloud refuse les Cibles non-HTTPS et ne peut pas atteindre `10.x.x.x` / `192.168.x.x` / `172.16.x.x` depuis Internet. Si ton staging est sur une IP privée, soit tu lui donnes un sous-domaine + certificat Let's Encrypt (Option 1 ci-dessous), soit tu lances un tunnel cloudflared depuis le serveur staging (Option 2).

### Option 1 — DNS public + Nginx + Let's Encrypt (recommandée)

1. **DNS** : créer un A-record `staging-api.example.com` (et `api.example.com` si pas déjà fait) pointant vers l'IP publique du reverse-proxy.

2. **Nginx vhost** (`/etc/nginx/sites-available/myapp-prod.conf`) :

   ```nginx
   server {
       listen 80;
       listen [::]:80;
       server_name api.example.com;

       # Let's Encrypt validation
       location /.well-known/acme-challenge/ {
           root /var/www/letsencrypt;
       }

       # Tout le reste → HTTPS
       location / {
           return 301 https://$host$request_uri;
       }
   }

   server {
       listen 443 ssl http2;
       listen [::]:443 ssl http2;
       server_name api.example.com;

       ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
       ssl_protocols       TLSv1.2 TLSv1.3;
       ssl_ciphers         HIGH:!aNULL:!MD5;

       root  /var/www/myapp/api;
       index main.php;

       client_max_body_size 16m;

       location / {
           try_files $uri $uri/ /main.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }

       # Journaux séparés pour le webhook (utile pour l'exploitation)
       location = /webhooks/zitadel/password-changed {
           access_log /var/log/nginx/myapp-webhook.log;
           try_files $uri /main.php?$query_string;
       }
   }
   ```

3. **Activer le vhost + obtenir le certificat Let's Encrypt** :

   ```shell
   sudo ln -s /etc/nginx/sites-available/myapp-prod.conf /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot certonly --webroot -w /var/www/letsencrypt -d api.example.com
   ```

4. **Provisionner côté Zitadel** :

   ```shell
   # Sur ton poste, pas sur le serveur (la commande tape sur Zitadel cloud, pas sur l'API)
   php bin/console zitadel:webhook install password_changed --endpoint https://api.example.com/webhooks/zitadel/password-changed
   ```

5. **Déployer la nouvelle config sur le serveur** : commit le fichier de configuration mis à jour (ou s'il est gitignored — passer le secret en variable d'env / vault), puis reconstruire la configuration sur le serveur.

### Option 2 — Tunnel cloudflared depuis le serveur staging (repli pour réseau privé)

Si ton staging est dans un réseau sans expo publique :

1. Installer cloudflared sur la machine staging (`apt install cloudflared` sur Debian, ou récupérer le `.deb` depuis cloudflare).

2. Créer un compte Cloudflare gratuit + un **tunnel nommé** (URL stable, pas le quick tunnel) :

   ```shell
   cloudflared tunnel login
   cloudflared tunnel create myapp-staging
   cloudflared tunnel route dns myapp-staging staging-zitadel.example.com
   ```

3. Configurer le tunnel pour pointer vers nginx local (`/etc/cloudflared/config.yml`) :

   ```yaml
   tunnel: myapp-staging
   credentials-file: /root/.cloudflared/<tunnel-id>.json

   ingress:
     - hostname: staging-zitadel.example.com
       service: https://localhost:443
       originRequest:
         noTLSVerify: true
         httpHostHeader: staging-api.localhost
     - service: http_status:404
   ```

4. Lancer le tunnel comme service systemd :

   ```shell
   sudo cloudflared service install
   sudo systemctl enable --now cloudflared
   ```

5. Provisionner côté Zitadel avec l'URL du tunnel :

   ```shell
   php bin/console zitadel:webhook install password_changed --endpoint https://staging-zitadel.example.com/webhooks/zitadel/password-changed
   ```

   Note : pas de risque d'écraser la Cible de prod si elle partage la même instance Zitadel. Le nom de la Cible est construit automatiquement sous la forme `{apiIdentifier} - {label} - {host}` ; il suffit que chaque environnement ait son propre `apiIdentifier` (premier segment, lu dans `[auth.api].identifier`) et/ou son propre `baseUrl` pour que les noms ne se télescopent pas.

## Sécurité réseau et firewall

### L'URL du webhook est publique

Le "endpoint" `/webhooks/zitadel/password-changed` est listé dans `[auth].passthrough` (pas de JWT requis) car Zitadel ne sait pas s'authentifier avec un JWT vers nous. La sécurité repose entièrement sur :

| Garde-fou                           | Effet                                                                                                                                  |
|-------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------|
| **Signature HMAC SHA-256**          | Toute requête sans header `ZITADEL-Signature` valide → 401 immédiat. Sans la signing key, impossible de forger une signature acceptée. |
| **Replay window 5 min**             | La signature inclut un timestamp Unix. Une signature plus vieille → 401.                                                               |
| **Idempotency Memcached (TTL 24h)** | Un même `eventId` ne peut pas être rejoué dans la fenêtre.                                                                             |

→ Concrètement, un attaquant qui découvre l'URL ne peut **rien faire** : tous ses POST renvoient 401 sans aucun effet de bord.

### Faut-il white lister les IPs Zitadel cloud sur ton firewall ?

**Non, et c'est même contre-productif.**

- Les IPs sortantes de Zitadel cloud peuvent changer (infra cloud évolutive). Une whitelist deviendrait silencieusement obsolète et casserait le webhook sans rien dire.
- La signature HMAC est **plus robuste qu'une whitelist IP** : un attaquant peut spoofer une IP source mais pas forger une signature sans la clé.
- Les benchmarks (Stripe, GitHub, Notion) reposent tous sur la signature HMAC, pas sur une whitelist IP.

→ **Reco** : garder le port 443 ouvert au monde, laisser la signature HMAC faire le filtrage.

### Si ton admin réseau insiste pour restreindre l'origine

Trois pistes acceptables (par ordre de complexité) :

1. **Rate-limit générique** sur `/webhooks/zitadel/*` côté Nginx (`limit_req_zone` + `limit_req`) — ex. 10 req/sec/IP. Protège contre un script kiddie qui voudrait flood l'endpoint pour générer du bruit.
2. **WAF managé** (Cloudflare gratuit, Fail2ban, etc.) qui banit automatiquement les IPs qui font 100 requêtes 401 d'affilée.
3. **mTLS Cloudflare → Origin** : exiger un certificat client signé par Cloudflare, ce qui restreint l'accès aux requêtes passant par Cloudflare. Demande une config CDN devant ton API ; intéressant si l'API est déjà fronted par Cloudflare.

⚠️ **Ne pas filtrer par IP source Zitadel** — c'est un faux sentiment de sécurité qui casse au prochain re-deploy de Zitadel cloud.

### Logs serveur

Pour faciliter le débogage et l'audit côté exploitation, séparer le journal nginx du webhook (cf. exemple Nginx ci-dessus). Tu peux ainsi compter les 200 / 401 / 5xx du webhook sans bruit :

```shell
tail -f /var/log/nginx/myapp-webhook.log
```

## La commande `zitadel:webhook`

Commande CLI dédiée à la gestion des Cibles + Executions Zitadel V2. Elle utilise le service-account déjà configuré dans la section `[zitadel]` de votre configuration — pas besoin de Bearer manuel.

La commande est *catalog-driven* : chaque webhook est décrit par un descripteur (clé, événement, route, label) dans le `ZitadelWebhookCatalog`. Les actions qui ciblent un webhook précis prennent une `<key>` correspondant à la section `[zitadel.webhooks.<key>]`. Le nom de la Cible côté Zitadel est dérivé automatiquement sous la forme `{apiIdentifier} - {label} - {host}` — il n'y a donc pas d'option `--name` / `--event`.

### Actions disponibles

```shell
php bin/console zitadel:webhook <action> [<key>] [options]
```

| Action               | Argument | Description                                                                                                                              |
|----------------------|----------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `list` (par défaut)  | —        | Liste toutes les Cibles de l'instance (id / name / endpoint / date de création, triées du plus récent au plus vieux). `--mine` filtre sur le préfixe de l'API |
| `install`            | `<key>`  | Crée la Cible + lie l'Execution à l'événement + **écrit automatiquement** le secret dans le fichier de configuration                     |
| `show`               | `<key>`  | Affiche le descripteur + les métadonnées Zitadel de la Cible correspondante. La signing key n'est pas affichée (Zitadel ne la ré-expose pas) |
| `rotate`             | `<key>`  | Supprime + recrée la Cible (même endpoint) pour obtenir une nouvelle signing key, puis **réécrit** le secret dans la configuration       |
| `uninstall`          | `<key>`  | Supprime la Cible (avec confirmation). `--purge-config` vide en plus le secret dans la configuration (event / label / route préservés)   |
| `delete`             | —        | Picker interactif sur **toutes** les Cibles de l'instance (sauvetage / ménage, ex. une Cible legacy hors convention de nommage)          |

`<key>` correspond toujours au suffixe de section `[zitadel.webhooks.<key>]` (ex. `password_changed`). Le catalogue rejette les clés inconnues, donc la commande n'opère jamais sur un webhook que l'API ne connaît pas.

### Options

| Option            | Défaut  | Quand                                                                                                                          |
|-------------------|---------|-------------------------------------------------------------------------------------------------------------------------------|
| `--endpoint <url>`| aucun   | URL HTTPS publique que Zitadel POST. Requis sur `install` quand `baseUrl` est privé (sinon dérivé de `baseUrl + route`)        |
| `--mine`          | (off)   | Sur `list`, restreint la sortie aux Cibles possédées par cette API (nom préfixé par `{apiIdentifier} - `)                      |
| `--purge-config`  | (off)   | Sur `uninstall`, vide le secret dans la configuration (la section event / label / route est préservée)                         |
| `--yes` / `-y`    | (off)   | Saute la confirmation interactive (pour les runs cron / scriptés)                                                              |

Il n'y a **pas** de flag d'écriture du secret : `install` et `rotate` écrivent le secret automatiquement dans le fichier de configuration injecté (clé d'initialisation `CONFIG_FILE`). Le fichier est créé s'il est absent, et sauvegardé en `<fichier>.bak` avant la réécriture en place. Si aucun fichier n'est injecté, le snippet est imprimé pour collage manuel.

### Exemples de scénarios

**Setup local from scratch** :
```shell
php bin/console zitadel:webhook install password_changed \
    --endpoint https://xxx.trycloudflare.com/webhooks/zitadel/password-changed
```

**J'ai perdu la signing key (je n'ai plus le snippet imprimé)** :
```shell
php bin/console zitadel:webhook rotate password_changed
# La commande reprend le endpoint actuel + génère + écrit la nouvelle clé
```

**Mon tunnel cloudflared a une nouvelle URL** :
```shell
# rotate reprend l'endpoint existant de la Cible ; pour changer l'endpoint,
# faire un uninstall puis un install avec la nouvelle URL
php bin/console zitadel:webhook uninstall password_changed --yes
php bin/console zitadel:webhook install password_changed \
    --endpoint https://nouvelle-url.trycloudflare.com/webhooks/zitadel/password-changed
```

**Je veux faire le ménage** :
```shell
php bin/console zitadel:webhook list
php bin/console zitadel:webhook delete  # interactif : choix dans la liste
```

## Ajouter un nouveau type de webhook

Pour écouter un autre événement Zitadel (par exemple `user.human.email.changed`), la procédure côté application hôte est :

1. **Déclarer le descripteur** : enregistrer un `ZitadelWebhookDescriptor` dans votre `ZitadelWebhookCatalog` (clé `email_changed`, événement `user.human.email.changed`, route `/webhooks/zitadel/email-changed`, label visible côté Zitadel).

2. **Créer le contrôleur HTTP** côté application : un handler PSR-15 qui lit le raw body avant tout middleware de parsing, vérifie la signature HMAC avec le `signingKey` stocké dans la config, décode le JSON, applique l'idempotence souhaitée et fait le traitement métier (révocation de sessions, audit log, etc.).

3. **Ajouter la clé secret dans `[zitadel.webhooks]`** de votre configuration : `email_changed = ""`. La valeur sera renseignée automatiquement par la commande `install`.

4. **Provisionner côté Zitadel** via la commande livrée par la lib :
   ```shell
   php bin/console zitadel:webhook install email_changed \
       --endpoint https://api.example.com/webhooks/zitadel/email-changed
   ```

5. **Reconstruire la configuration** de votre application pour que le changement prenne effet et que le contrôleur utilise le nouveau secret.

6. **Mettre à jour ce guide** : ajouter une ligne dans la table [Webhooks supportés par l'API](#webhooks-supportés-par-lapi).

## Troubleshooting

### `invalid CreateTargetRequest.Name` à la création

→ Le nom dérivé est vide ou trop long (>1000 chars). Le nom est construit sous la forme `{apiIdentifier} - {label} - {host}` : vérifier `[auth.api].identifier`, le `label` du descripteur et `[app].baseUrl` dans votre configuration.

### `Errors.Target.DeniedURL` à la création

→ Zitadel refuse l'URL fournie. Causes typiques :
- URL en `http://` (Zitadel exige HTTPS)
- URL pointant vers `*.localhost` (Zitadel cloud n'accepte pas)
- URL pointant vers une IP privée RFC1918

Solutions : tunnel cloudflared (dev) ou DNS public + certificat (staging/prod).

### Le webhook reçoit 401 à chaque appel

→ La signing key dans la configuration ne matche pas celle de Zitadel. Causes :
- La configuration n'a pas été reconstruite après écriture
- La Cible a été renouvelée mais la configuration n'a pas été mise à jour
- Mauvais nom de Cible visé (la Cible référencée par l'Action n'est pas celle dont on a la clé)

Solution : `php bin/console zitadel:webhook rotate password_changed` puis reconstruire la configuration.

### Les sessions ne sont pas révoquées après changement de mot de passe

→ Plusieurs causes possibles :
1. **Cloudflared coupé en local** : Zitadel POST dans le vide → vérifier que cloudflared tourne
2. **Action Zitadel pas créée** : seule une Cible existe, sans Execution pour la lier → `php bin/console zitadel:webhook rotate password_changed` recrée la Cible et relie l'Execution (`install` refuse si la Cible existe déjà)
3. **Nginx renvoie 5xx** : check `/var/log/nginx/myapp-webhook.log` — Zitadel retentera plusieurs fois avant d'abandonner

Test propre :
```shell
# trigger the integration test against the host application
```

Cette commande simule un payload signé localement (sans passer par Zitadel) pour vérifier que la chaîne de traitement côté API fonctionne.

### `transport: no_token` dans la commande

→ Le service-account Zitadel configuré dans la section `[zitadel]` de votre configuration ne peut plus s'authentifier. Vérifier la clé privée + l'expiration du JWT du service account.
