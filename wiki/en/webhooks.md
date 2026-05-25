# Zitadel webhooks — full guide

[Version française](../fr/webhooks.md)

This guide covers everything needed to wire a Zitadel webhook into your API: Zitadel V2 concepts (Targets + Executions), local dev configuration with cloudflared, Linux/Nginx server deployment, firewall considerations, and usage of the dedicated CLI command.

## Table of contents

- [Zitadel V2 concepts](#zitadel-v2-concepts)
- [Flow overview](#flow-overview)
- [Webhooks supported by the API](#webhooks-supported-by-the-api)
- [Local setup (Valet + cloudflared)](#local-setup-valet--cloudflared)
- [Linux server setup (Nginx Debian)](#linux-server-setup-nginx-debian)
- [Network security and firewall](#network-security-and-firewall)
- [The `zitadel:webhook` command](#the-authzitadelwebhook-command)
- [Adding a new webhook type](#adding-a-new-webhook-type)
- [Troubleshooting](#troubleshooting)

## Zitadel V2 concepts

In V2 Zitadel replaced its legacy **Actions V1** system (JavaScript scripts running on auth Flows) with a more modern two-concept model:

### Target

A **Target** describes where Zitadel must send data: public HTTPS URL, call type (REST Webhook / Call / Async), payload format (JSON / JWT / JWE), timeout, and — auto-generated at creation — an **HMAC signing key** (`signingKey`) that Zitadel uses to sign every outgoing payload.

A Target is purely *where* to send — it doesn't say *when*.

⚠️ **The signing key is only revealed at Target creation** (in the API response or via the dedicated CLI command). Once created, the Zitadel UI and API never re-expose it. To recover a lost key: use `zitadel:webhook rotate` which deletes + recreates the Target.

### Action (Execution)

An **Execution** binds a *condition* (a Zitadel event, e.g. `user.human.password.changed`) to one or more Targets. When the event happens, Zitadel POSTs the HMAC-signed payload to each Target.

An Execution is purely *when* to fire — it references existing Targets.

### Zitadel console

In the cloud V2 Zitadel console (your instance, `*.zitadel.cloud`), the two concepts are accessible separately under **Default Settings**:

- **Default Settings → Targets**: create / list / edit Targets
- **Default Settings → Actions**: create an Action (= Execution) referencing an existing Target

⚠️ There's also an **Actions V1** page accessible from the project's main nav. It is deprecated (yellow Zitadel banner: "Actions V2 will eventually replace V1"). **Do not use it** for new webhooks.

## Flow overview

```
User changes their password
            ↓
Zitadel persists the new hash
            ↓
Zitadel emits the `user.human.password.changed` event
            ↓
A Zitadel Execution matches the event
            ↓
Zitadel POSTs to the Target URL
   - Header `ZITADEL-Signature: t=<unix>,v1=<hex>`
   - Body: HMAC-SHA256-signed JSON with the shared signing key
            ↓
Your API (`/webhooks/zitadel/password-changed`)
   - Reads the raw body BEFORE BodyParsingMiddleware
   - Verifies the HMAC signature
   - Decodes the JSON
   - Memcached idempotency (TTL 24h)
   - Looks up user via aggregateId
   - Revokes active sessions (with optional exclusion)
   - Audit log + 200 response
```

The API-side processing (signature verification, idempotency, session revocation) is the responsibility of the host application — this page only covers the Zitadel-facing parts.

## Webhooks supported by the API

Each webhook = 1 Target + 1 Execution in Zitadel + 1 entry under `[zitadel.webhooks]` in your application's `config.toml`. Example of a single entry:

| Zitadel event | Target (suggested) | `config.toml` key | API endpoint |
|---|---|---|---|
| `user.human.password.changed` | `my-api password-changed` | `password_changed` | `POST /webhooks/zitadel/password-changed` |

To add a new webhook type, see [Adding a new webhook type](#adding-a-new-webhook-type).

## Local setup (Valet + cloudflared)

Zitadel cloud must be able to POST to your API. In local dev your API runs on `https://myapp.localhost` (Valet) — not reachable from the Internet. Solution: expose an HTTPS tunnel via **cloudflared**.

### 1. Install cloudflared

```shell
brew install cloudflared
cloudflared --version
```

No Cloudflare account needed for "quick tunnel" mode (ephemeral URL, recreated on every launch).

### 2. Launch the tunnel

In a separate terminal (keep it open as long as you want the webhook to work):

```shell
cloudflared tunnel \
    --url https://myapp.localhost \
    --no-tls-verify \
    --http-host-header myapp.localhost
```

Why these two extra flags:

- `--no-tls-verify`: Valet serves HTTPS with a local self-signed certificate. Without this flag, cloudflared rejects the handshake.
- `--http-host-header myapp.localhost`: cloudflared rewrites the `Host:` header sent to nginx. Without it, nginx Valet sees `xxx.trycloudflare.com` instead of `myapp.localhost` and falls through to the default Valet page instead of your app.

cloudflared will print a URL like:

```
+--------------------------------------------------------------------------------------------+
|  Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):  |
|  https://random-words-1234.trycloudflare.com                                               |
+--------------------------------------------------------------------------------------------+
```

### 3. Verify the tunnel reaches the API

```shell
curl -i https://random-words-1234.trycloudflare.com/version
```

You should see a JSON response from your API (not the default Valet page).

### 4. Provision Target + Action on the Zitadel side

```shell
php bin/console zitadel:webhook install password_changed --endpoint https://random-words-1234.trycloudflare.com/webhooks/zitadel/password-changed --inject
```

This command:

1. Creates the Target (the endpoint URL is your tunnel)
2. Binds the Execution to the `user.human.password.changed` event
3. Prints the signing key
4. With `--inject`: also writes it into the host application's `config.toml` under `[zitadel.webhooks].password_changed` (with `.bak` backup)

Then reload your application's config so the controller picks up the new secret.

### 5. End-to-end test

Trigger the target Zitadel event (e.g. change a test user's password) and verify on the application side that the webhook was received: Nginx access log, application log, and the expected business side effect (e.g. revoking that user's active sessions).

### Local dev gotchas

- **The cloudflared URL changes on every launch** (quick tunnel mode). If you stop and restart cloudflared, you'll get a new URL → re-run `install --endpoint <new-url>` to update the Zitadel Target. For a stable dev URL, create a free Cloudflare account and use a named tunnel.
- **If cloudflared is not running**, Zitadel will time out and the webhook will do nothing (sessions won't be revoked). Integration tests (`your password-change integration tests`) still work because they sign payloads locally without going through Zitadel.

## Linux server setup (Nginx Debian)

In staging and prod, the API is reachable directly via public DNS — **no cloudflared tunnel needed**. The Zitadel webhook points directly to the API DNS.

### Prerequisites

| Env | Recommended Target URL | Public DNS? |
|---|---|---|
| Local dev | `https://xxx.trycloudflare.com/webhooks/zitadel/password-changed` | no (tunnel) |
| Staging | `https://staging-api.example.com/webhooks/zitadel/password-changed` | **yes, required** |
| Prod | `https://api.example.com/webhooks/zitadel/password-changed` | **yes, required** |

⚠️ **A bare HTTP URL or a private RFC1918 IP will not work**: Zitadel cloud refuses non-HTTPS Targets and cannot reach `10.x.x.x` / `192.168.x.x` / `172.16.x.x` from the Internet. If your staging is on a private IP, either give it a subdomain + Let's Encrypt cert (Option 1 below) or run a cloudflared tunnel from the staging server (Option 2).

### Option 1 — Public DNS + Nginx + Let's Encrypt (recommended)

1. **DNS**: create an A-record `staging-api.example.com` (and `api.example.com` if not already done) pointing to the reverse-proxy public IP.

2. **Nginx vhost** (`/etc/nginx/sites-available/myapp-prod.conf`):

   ```nginx
   server {
       listen 80;
       listen [::]:80;
       server_name api.example.com;

       # Let's Encrypt validation
       location /.well-known/acme-challenge/ {
           root /var/www/letsencrypt;
       }

       # Everything else → HTTPS
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

       # Separate logs for the webhook (handy for ops)
       location = /webhooks/zitadel/password-changed {
           access_log /var/log/nginx/myapp-webhook.log;
           try_files $uri /main.php?$query_string;
       }
   }
   ```

3. **Enable the vhost + obtain the Let's Encrypt cert**:

   ```shell
   sudo ln -s /etc/nginx/sites-available/myapp-prod.conf /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot certonly --webroot -w /var/www/letsencrypt -d api.example.com
   ```

4. **Provision on the Zitadel side**:

   ```shell
   # Run this from your workstation, not the server (the command talks to Zitadel cloud, not the API)
   php bin/console zitadel:webhook install password_changed --endpoint https://api.example.com/webhooks/zitadel/password-changed --inject
   ```

5. **Deploy the new config to the server**: commit the updated `config.toml` (or if gitignored — pass the secret as an env var / vault), then reload the application config on the server.

### Option 2 — cloudflared tunnel from the staging server (private network fallback)

If your staging is in a network without public exposure:

1. Install cloudflared on the staging machine (`apt install cloudflared` on Debian, or grab the `.deb` from cloudflare).

2. Create a free Cloudflare account + a **named tunnel** (stable URL, not the quick tunnel):

   ```shell
   cloudflared tunnel login
   cloudflared tunnel create myapp-staging
   cloudflared tunnel route dns myapp-staging staging-zitadel.example.com
   ```

3. Configure the tunnel to point at local nginx (`/etc/cloudflared/config.yml`):

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

4. Run the tunnel as a systemd service:

   ```shell
   sudo cloudflared service install
   sudo systemctl enable --now cloudflared
   ```

5. Provision on the Zitadel side with the tunnel URL:

   ```shell
   php bin/console zitadel:webhook install password_changed --endpoint https://staging-zitadel.example.com/webhooks/zitadel/password-changed --inject --name "my-api password-changed (staging)"
   ```

   Note: distinct `--name` so you don't overwrite the prod Target if both run on the same Zitadel instance.

## Network security and firewall

### The webhook URL is public

The endpoint `/webhooks/zitadel/password-changed` is listed under `[auth].passthrough` (no JWT required) because Zitadel does not authenticate to us with a JWT. Security relies entirely on:

| Guard | Effect |
|---|---|
| **HMAC SHA-256 signature** | Any request without a valid `ZITADEL-Signature` header → 401 immediately. Without the signing key, no signature can be forged. |
| **5-min replay window** | The signature includes a Unix timestamp. Older signatures → 401. |
| **Memcached idempotency (TTL 24h)** | The same `eventId` cannot be replayed within the window. |

→ Practically, an attacker who discovers the URL **can do nothing**: every POST returns 401 with no side-effects.

### Should you whitelist Zitadel cloud IPs on your firewall?

**No, and it's actually counter-productive.**

- Zitadel cloud egress IPs may change (elastic cloud infra). A whitelist would silently rot and break the webhook without warning.
- The HMAC signature is **more robust than an IP whitelist**: an attacker can spoof a source IP but cannot forge a signature without the key.
- Industry leaders (Stripe, GitHub, Notion) all rely on HMAC signatures, not IP whitelists.

→ **Reco**: keep port 443 open to the world, let the HMAC signature do the filtering.

### If your network admin insists on origin restriction

Three acceptable paths (in order of complexity):

1. **Generic rate-limit** on `/webhooks/zitadel/*` at the Nginx layer (`limit_req_zone` + `limit_req`) — e.g. 10 req/sec/IP. Protects against a script kiddie flooding the endpoint to generate noise.
2. **Managed WAF** (free Cloudflare, Fail2ban, etc.) that auto-bans IPs producing 100 401 responses in a row.
3. **mTLS Cloudflare → Origin**: require a client certificate signed by Cloudflare, restricting access to Cloudflare-routed requests. Requires a CDN in front of the API; relevant if the API already sits behind Cloudflare.

⚠️ **Do not filter by Zitadel source IP** — false sense of security that breaks at the next Zitadel cloud re-deploy.

### Server logs

For easier debugging + ops auditability, separate the nginx log for the webhook (cf. Nginx example above). You can then count the 200 / 401 / 5xx of the webhook without noise:

```shell
tail -f /var/log/nginx/myapp-webhook.log
```

## The `zitadel:webhook` command

CLI command dedicated to Zitadel V2 Targets + Executions management. It uses the service-account already configured under `[zitadel]` in `config.toml` — no manual Bearer needed.

### Available actions

```shell
php bin/console zitadel:webhook <action> [options]
```

| Action | Description |
|---|---|
| `list` | Lists every Target on the instance with id / name / endpoint / creation date (sorted newest-first) |
| `show` | Displays one Target's details (by `--name` or `--target-id`). Signing key not displayed (Zitadel does not re-expose it) |
| `setup` (default action) | Creates the Target if missing + binds the Execution to the event. If the Target already exists: skips creation, just relinks Execution + warning |
| `rotate` | Deletes the existing Target + recreates it (rotates the signing key). Prints the new key |
| `delete` | Deletes a Target (by `--name`, `--target-id`, or interactively if nothing is supplied) |

### Options

| Option | Default | When |
|---|---|---|
| `--endpoint <url>` | none | Required for `setup` (creation) and `rotate`. Optional for `setup` when the Target already exists |
| `--name <name>` | `my-api password-changed` | Target name on the Zitadel side |
| `--event <event>` | `user.human.password.changed` | Zitadel event to bind via Execution |
| `--config-key <key>` | `password_changed` | TOML key under `[zitadel.webhooks]` to write with `--inject` |
| `--inject` | (off) | Writes the new signing key directly into the root `config.toml` (with `.bak` backup). Reload the application config afterwards |
| `--target-id <id>` | none | Override `--name` when the Zitadel id is already known |

### Common scenarios

**Local setup from scratch**:
```shell
php bin/console zitadel:webhook install password_changed \
    --endpoint https://xxx.trycloudflare.com/webhooks/zitadel/password-changed \
    --inject
```

**I lost the signing key (no longer have the printed snippet)**:
```shell
php bin/console zitadel:webhook rotate password_changed --inject
# The command reuses the current endpoint + generates + writes the new key
```

**My cloudflared tunnel got a new URL**:
```shell
php bin/console zitadel:webhook rotate password_changed \
    --endpoint https://new-url.trycloudflare.com/webhooks/zitadel/password-changed \
    --inject
# Updates the endpoint + rotates the signing key + writes to config.toml
```

**Cleanup time**:
```shell
php bin/console zitadel:webhook list
php bin/console zitadel:webhook delete  # interactive: pick from the list
```

## Adding a new webhook type

To listen to another Zitadel event (e.g. `user.human.email.changed`), the host-side procedure is:

1. **Declare the descriptor**: register a `ZitadelWebhookDescriptor` in your `ZitadelWebhookCatalog` (key `email_changed`, event `user.human.email.changed`, route `/webhooks/zitadel/email-changed`, label as seen on the Zitadel side).

2. **Create the HTTP controller** on the host application side: a PSR-15 handler that reads the raw body before any parsing middleware, verifies the HMAC signature using the `signingKey` stored in config, decodes the JSON, applies the idempotency you want, and runs the business handling (session revocation, audit log, etc.).

3. **Add the secret key under `[zitadel.webhooks]`** in your `config.toml`: `email_changed = ""`. The value will be filled by the `install` command (pass `--inject` to write it back automatically).

4. **Provision on the Zitadel side** with the command shipped by the library:
   ```shell
   php bin/console zitadel:webhook install email_changed \
       --endpoint https://api.example.com/webhooks/zitadel/email-changed
   ```

5. **Reload your application's config** so the controller picks up the new secret.

6. **Update this guide**: add a row to the [Webhooks supported by the API](#webhooks-supported-by-the-api) table.

## Troubleshooting

### `invalid CreateTargetRequest.Name` on creation

→ Name is empty or too long (>1000 chars). Check `--name`.

### `Errors.Target.DeniedURL` on creation

→ Zitadel refuses the supplied URL. Typical causes:
- URL with `http://` (Zitadel requires HTTPS)
- URL pointing to `*.localhost` (Zitadel cloud refuses)
- URL pointing to a private RFC1918 IP

Solutions: cloudflared tunnel (dev) or public DNS + cert (staging/prod).

### The webhook returns 401 on every call

→ The signing key in `config.toml` does not match Zitadel's. Causes:
- The application config was not reloaded after writing
- The Target was rotated but `config.toml` was not updated
- Wrong Target name targeted (the Target referenced by the Action is not the one you have the key for)

Solution: `php bin/console zitadel:webhook rotate password_changed --inject` puis recharger la config de l application.

### Sessions are not revoked after a password change

→ Several possible causes:
1. **Cloudflared down in local**: Zitadel POSTs into the void → check that cloudflared is running
2. **Zitadel Action not created**: only a Target exists with no Execution binding it → `php bin/console zitadel:webhook install password_changed` (no `--endpoint` if the Target exists) re-binds the Execution
3. **Nginx returns 5xx**: check `/var/log/nginx/myapp-webhook.log` — Zitadel will retry several times before giving up

Clean test:
```shell
# trigger the integration test against the host application
```

This command simulates a locally-signed payload (no Zitadel involvement) to verify the API processing chain works.

### `transport: no_token` in the command

→ The Zitadel service-account configured under `[zitadel]` in `config.toml` can no longer authenticate. Check the private key + the JWT expiration of the service account.
