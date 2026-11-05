# Guide: installation, operation and API

Nordvarr is a single PHP application with an SQLite database. It installs like WordPress: upload the files and open them in a browser. The server does not need Composer, Node or any build step.

Contents:

1. [Requirements](#1-requirements)
2. [Installation](#2-installation)
3. [Cron and worker](#3-cron-and-worker)
4. [First steps](#4-first-steps)
5. [Updating](#5-updating)
6. [Backups and restore](#6-backups-and-restore)
7. [Forgotten password](#7-forgotten-password)
8. [API with curl](#8-api-with-curl)
9. [config.php configuration](#9-configphp-configuration)
10. [Security](#10-security)
11. [Troubleshooting](#11-troubleshooting)
12. [Server agent (Linux)](#12-server-agent-linux)
13. [Events from servers and applications](#13-events-from-servers-and-applications)

## 1. Requirements

- PHP 8.4 or 8.5 with the extensions `pdo_sqlite`, `curl`, `openssl`, `json`, `mbstring`. The installer verifies them.
- A web server; Apache 2.4 with `mod_rewrite` (clean URLs) is recommended. Without `mod_rewrite` the application works with `index.php?r=...` addresses.
- Cron that runs PHP from the command line every minute.
- For the `ping` check, the `ping` command (package `iputils-ping`). Without it the check has the status "unknown" with the reason `tool_missing`.
- Outbound connections from the server to the monitored targets, to the SMTP server, Telegram, Discord or webhooks, and for domain expiry to RDAP (`data.iana.org` and registry servers).

## 2. Installation

1. Download `nordvarr-<version>.zip` from the release and unpack it. This creates the `nordvarr/` directory.
2. Upload the directory to the server, for example to `/var/www/nordvarr`.
3. Set the web root (DocumentRoot) to `/var/www/nordvarr/public`. Nothing outside `public/` may be reachable from the web.
4. Open `https://nordvarr.example.com/install.php` in a browser and fill in the form:
   - **Data directory**: database, logs, locks and backups. The installer suggests `nordvarr-data` next to the application directory (`/var/www/nordvarr-data`), and when the web server may not write there (typically a server where each domain has its own user that may only write to the domain directory), it suggests `nordvarr-data` inside the application directory next to `public/` (for example `/var/www/vhosts/example.com/app.example.com/nordvarr-data`). Both locations are outside the web root. The data directory must not be in the code folders (`public/`, `src/` and others). The installer verifies that the directory can be created and that it is not reachable from the web, and adds a `.htaccess` file that denies access.
   - **Administrator email and password** (password at least 10 characters).
   - **Time zone** (default Europe/Prague).
5. The installer creates `config.php` with a random application secret, the database and the account. At the end it shows the exact cron line. Then it locks itself: as long as `config.php` exists, `install.php` refuses to run.

The web server must have write access to the application directory (for `config.php`) and to the data directory. After installation, write access to the data directory is enough.

### Sample Apache configuration

```apache
<VirtualHost *:443>
    ServerName nordvarr.example.com
    DocumentRoot /var/www/nordvarr/public

    <Directory /var/www/nordvarr/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/nordvarr.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/nordvarr.example.com.key
</VirtualHost>
```

`public/.htaccess` enables clean URLs and passes the `Authorization` header to PHP (without it API tokens do not work under PHP-FPM). If `AllowOverride` cannot be enabled, move its content into the virtual host configuration.

Run the application over HTTPS. The session cookie has the `Secure` flag on HTTPS, and the "Copy" button in the LLM export requires a secure connection.

## 3. Cron and worker

The worker runs checks, evaluates alerts and sends notifications. Cron starts it every minute, one run takes at most 50 s and a lock prevents concurrent runs. The exact line is shown at the end of the installation and in Settings, for example:

```
* * * * * php /var/www/nordvarr/bin/nordvarr.php worker >/dev/null 2>&1
```

Run cron as the same user the web server runs as (for example `www-data`), so both can write to the data directory:

```
crontab -u www-data -e
```

When the worker has not run for more than 3 minutes, the overview shows a warning.

Other commands:

```
php bin/nordvarr.php version                  # version
php bin/nordvarr.php migrate                  # run migrations manually (with a backup)
php bin/nordvarr.php demo-data                # fictional sample data into an empty inventory
php bin/nordvarr.php reset-password <e-mail>  # new password for a forgotten account
```

## 4. First steps

1. **Settings -> General:** time zone and the cron line.
2. **Settings -> Notifications:** application address for links in notifications (filled in automatically at the first sign-in), notification language and reminder interval.
3. **Settings -> Notification channels:** where alerts go. After saving a channel, use "Send test".
   - **Email:** sender, recipients, primary SMTP server and an optional **fallback SMTP server** that is used when the primary one fails (the attempt history is in the channel detail).
   - **Telegram:** bot token from @BotFather and chat ID.
   - **Discord:** in Discord go to Server settings -> Integrations -> Webhooks -> New webhook -> Copy webhook URL and paste the URL into the channel. The message has a colored bar by kind (red for an outage, green for a recovery). The URL contains a token and is stored encrypted.
   - **Webhook:** generic JSON signed with HMAC for custom integrations.
   - **Sound in the browser:** the **Sound** button in the top bar. A sound plays on a new or resolved alert and on a new event while Nordvarr is open in a tab. Each level has its own sound (info, orange, red), and the sound can be chosen (Chime, Bell, Double beep, Gong, Siren, No sound) and played. "Mute for 1 hour" ends by itself after an hour, "Mute" lasts until sound is turned on again. The settings apply to the given browser. The browser allows sound only after the first click on the page. The notification is also shown as text in the top right corner.
4. **Inventory:** objects manually, by JSON import or via the API. The relationships "depends on" and "contains" determine the neighborhood in the topology and alert suppression.
5. **Checks** on the object detail: HTTP, TCP, ping, TLS certificate, domain expiry, heartbeat. A faster way is described below in "How to enable monitoring".
6. **Topology:** a graph of an object's neighborhood or of objects matching a filter. From the main navigation it opens in 3D with all objects. The switch above the map selects the layout:
   - **Automatic:** layout based on relationships.
   - **Floors:** objects in horizontal bands by infrastructure layer, from the bottom: providers (hosting, data centres), hardware and network, virtual machines and VPS, containers, services and databases, websites and APIs, domains, internet and external services. Objects connected by a relationship sit above each other in a column (a VM above its server, a website above its service). The floor is determined by the object type: containers and clusters (Kubernetes, Docker Swarm) have their own types `container` and `cluster` and sit on the containers floor. A cluster contains its containers (the "contains" relationship) and can itself sit in a VM or on a server. A **Provider** (type `provider`, for example the company you rent a VPS or a server from) sits on the lowest floor and "contains" its servers and VPS. An **External service** (type `external`: Cloudflare, public DNS servers, peering such as NIX.CZ, Google) sits on the highest floor: things outside your control that your websites and servers "depend on". You set a different floor with the attribute `vrstva` with the value `providers`, `hardware`, `virtual`, `containers`, `services`, `web`, `domains`, `external` or a number 0 to 7 (0 = providers, 1 = hardware). The layout choice is kept in the page address.
   - **3D:** floors as discs stacked on top of each other (hardware at the bottom), objects on the edge of the disc. Objects connected by a relationship sit above each other in the same direction. Drag with the mouse or a finger to rotate, use the scroll wheel or two fingers to zoom, click to select an object (card on the right), double-click to open its card. A click in the list on the left turns the map to the object. Buttons: "Top view", "Spread floors" and "Fit graph" (the default view). Hovering over an object dims everything except its neighbors. A server with the agent shows under its name the CPU, RAM, fullest disk (and GPU) usage from the last report, colored by state (see below). With more than 60 objects, labels are shown only for objects with a problem, for the selected object and its neighbors. 3D needs WebGL. Without it the map stays in floors with a notice.
   - **Resource state of servers with the agent:** in 2D the server node has bars for CPU, RAM and the fullest disk inside (small nodes show only bars without text), in 3D the values are under the name. Green = healthy, orange = approaching the limit, red = the agent check limit is exceeded. For CPU and RAM, orange starts 15 points below the check limit (default 80 %, red from 95 %); for disks, the check limits for degraded and unhealthy apply (default 85 % and 95 %, inode usage counts as well; in 3D hovering shows which disk it is). The limits are changed in the Agent check on the server card. Grey bars mean that the last report is older than the allowed delay. GPU is not color-rated.
   - **Servers as cubes** (button in 3D, the choice is remembered in the browser): a server with the agent is shown as a cube with values on its faces instead of a sphere. Two opposite faces (facing out of and into the ring) show a summary: name, CPU, RAM, fullest disk and network traffic. The side faces show CPU (with load) and RAM with a chart of the last 30 minutes, the top face shows disks (at most four, fullest first) and the bottom face shows the network with a chart of incoming and outgoing traffic (with cubes enabled the camera can also be rotated below the floor). The colors are the same as for the resource bars, the face border has the color of the object status.
   - **Search the map** (above the object list): filters the list by name, type or reason, ignoring diacritics. Enter selects the first match and shows it on the map (in 3D it turns the camera to it), Esc cancels the search.
   - **Floors on the map:** checkboxes hide whole floors, in every layout.
   - **To the cause:** the button appears when some object on the map has a probable cause of an outage. It highlights the path from the object to the cause (the selected object, otherwise the worst one in the list), dims the others and selects the cause. In 3D it turns the camera to the cause. A second click or selecting another object ends the mode.
7. **Documentation** on the object card is divided into categories: **Operations** (what the object does, where it runs, who looks after it), **Runbook** (step-by-step procedures: restart, deployment, certificate renewal), **Disaster recovery (DR)** (backups, restore order, RTO and RPO), **Incidents** (records of past outages and lessons learned) and **Other**. The card shows the filled-in categories; you add empty ones with the link below them ("Add a document"). Each category has its own revision history. The text is in Markdown. The LLM export contains all categories; the API (`docs_markdown`) and the topology panel work with the operations documentation.
8. During an incident: the object card shows the state and reason, the probable cause, the last change in the neighborhood and the state of dependencies. The **"Copy for LLM"** button prepares context to paste into any LLM (secrets are masked, Nordvarr itself never calls an LLM).

### How to enable monitoring

Nordvarr monitors in two ways:

- **Checks from the Nordvarr server.** The worker (cron every minute) connects from your server to the targets: website (HTTP), port (TCP), ping, TLS certificate, domain expiry. Nothing needs to be installed on the monitored machines.
- **Agent on the monitored server.** A script on Linux sends CPU, RAM, disks, temperatures, GPU and disk health from SMART every minute (chapter 12). It is also useful for the server that runs Nordvarr itself.

Procedure:

1. **Suggested checks.** On the object card (Checks section) Nordvarr suggests checks based on the object type and attributes, and they are added with one button. Suggestions:
   - website: HTTP on the `url` attribute, otherwise `https://<name>/`; for https also a TLS certificate and the expiry of the website domain (for `shop.example.com` the domain `example.com`). The expiry check is not created on the website but on an object of type **Domain**: when it is not in the inventory yet, it is created, gets an expiry check and the website "depends on" it. Another website with the same domain uses the same object. For existing websites you add it in bulk with a button in the inventory (see step 2),
   - domain: domain expiry by name. Registries that do not publish the expiry date (e.g. `.eu`) need **Expiry date (manual, optional)** filled in the check, taken from your registrar account. The check then reports an approaching expiry the same way as with RDAP; after renewing the domain, update the date. When RDAP returns a date, it takes precedence,
   - server, VM, network device: ping on the `ip` attribute (or `ipv6`, `host`, `hostname`), otherwise on the name if it is an IP address or a host name,
   - service, database, container, cluster, provider, other: HTTP on the `url` attribute, otherwise TCP on `host` (or `ip`, `server`) and `port`,
   - external service: HTTP on the `url` attribute, otherwise ping on `ip`/`host` or on the name.

   A suggestion already covered by an existing check is not offered. If nothing is suggested, add an attribute to the object (e.g. `url` or `ip`) or add a check manually with the "Add check" button.
2. **In bulk from the inventory.** In the inventory select objects and use "Add suggested checks". The overview shows the number of objects without checks with a link to their list.
3. **Ping on shared hosting.** If the ping check ends with the reason `tool_missing` (the Nordvarr server has no `ping` program or PHP may not run it), use a TCP check on an open port instead (e.g. 22 or 443).
4. **Agent** for server metrics: install it according to chapter 12. The card of a server without the agent has a link to the guide in Settings -> Server agent.
5. **Notifications.** Without a channel you learn about an outage only in the application: set up a channel and use "Send test" (steps 2 and 3 above).

## 5. Updating

1. Download the new zip and upload the content of the `nordvarr/` directory over the existing files. `config.php` is not in the package, so it is not overwritten; the data directory stays unchanged.
2. Sign in as admin. On the first request, pending database migrations run; before that, a copy of the database is created in `nordvarr-data/backups/`. The application shows a message about it.

Until the admin signs in, the API may return error 500 (the new tables do not exist yet). Migrations can also be run manually: `php bin/nordvarr.php migrate`.

No other upgrade scripts or manual steps are needed. If the `nordvarr-data` data directory is inside the application directory, do not delete it during the update (uploading files over the old ones leaves it unchanged). Files the new version no longer uses do no harm; it is cleaner, though, to upload the new version into an empty directory and carry over only `config.php`.

## 6. Backups and restore

All data is in the data directory:

- `nordvarr.sqlite` (+ `-wal`, `-shm`): the database,
- `backups/`: automatic copies before migrations and before an inventory import,
- `sessions/`, locks and logs.

A consistent backup while running:

```
sqlite3 /var/www/nordvarr-data/nordvarr.sqlite ".backup '/zalohy/nordvarr-$(date +%F).sqlite'"
```

To restore, stop cron, replace `nordvarr.sqlite` with the backup (and delete `-wal` and `-shm`) and enable cron again. Back up `config.php` as well: it contains the application secret, without which API tokens cannot be verified and notification channel passwords cannot be decrypted.

The inventory (objects, attributes, relationships, tags, documentation, events, checks) can also be downloaded as JSON in **Settings -> Inventory export and import**. The export does not contain secrets (attributes with a sensitive key and passwords in URLs are masked), check states, alerts or channels.

## 7. Forgotten password

A signed-in admin changes the password in **Settings -> Account**; all other sessions are signed out. For a forgotten password, a command on the server generates a new one:

```
php /var/www/nordvarr/bin/nordvarr.php reset-password admin@example.com
```

The new password is printed only once. Change it after signing in.

## 8. API with curl

The API is at `/api/v1/...` addresses and accepts only a token in the `Authorization: Bearer <token>` header; it does not use cookies or sessions. You create a token in **Settings -> API tokens**; it is shown only once. The token defines the source name (for example `ansible`, `ci`) and the permissions:

| Scope | What it allows |
|---|---|
| `inventory:write` | `POST /api/v1/objects/upsert` |
| `events:write` | `POST /api/v1/events` |
| `context:read` | `GET /api/v1/objects/{id}/context` (LLM export) |
| `agent:write` | `POST /api/v1/agent/report` (host agent report, see chapter 12) |

The limit is 120 requests per minute per token (then `429` with the `Retry-After` header). The request body must be JSON (`Content-Type: application/json`), at most 1 MB.

In the examples:

```
NV=https://nordvarr.example.com
TOKEN=nv_0123456789ab_...
```

### Objects: upsert

Idempotent by the pair (token source, `external_id`). Sending the same input again does not duplicate anything. When the request contains `attributes` or `relationships`, attributes and relationships that this source sent earlier and that are now missing from the list are removed; data from other sources and manual edits stay. Tags are only added.

```
curl -sS -X POST "$NV/api/v1/objects/upsert" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"external_id":"vm:app1","type":"vm","name":"app1.example.com",
       "attributes":{"ip":"192.0.2.33","os":"Debian 13"},"tags":["eshop"]}'

curl -sS -X POST "$NV/api/v1/objects/upsert" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"external_id":"web:shop.example.com","type":"website","name":"shop.example.com",
       "attributes":{"document_root":"/srv/www/shop"},"tags":["eshop"],
       "relationships":[{"kind":"depends_on","to_external_id":"vm:app1"}],
       "docs_markdown":"# E-shop\n\nOperated by **app1**."}'
```

Response (`201` new object, `200` existing):

```json
{"id":"01a1...","created":true,"changed":true,"changes":{"object":["created"],"attributes":{"added":["document_root"],"updated":[],"removed":[]},"tags":{"added":["eshop"]},"relationships":{"added":["depends_on:vm:app1"],"removed":[]},"docs":true}}
```

Object types: `server`, `vm`, `cluster`, `container`, `service`, `website`, `database`, `domain`, `network_device`, `provider`, `external`, `other`. Relationships: `depends_on`, `contains`. A reference to a non-existent object returns `422` with a `missing_external_ids` list, invalid input returns `422` with a `fields` item.

The `docs_markdown` field writes the operations documentation of the object; the other categories (runbook, DR, incidents) are edited on the object card.

Secrets (passwords, tokens, private keys, DSNs with a password) do not belong in attributes or documentation.

### Events

Idempotent by (token source, `event_id`). Types: `deploy`, `config`, `maintenance`, `restart`, `incident`, `note`, `other`, `security` (security), `business` (business and customers), `message` (message). Optional `severity`: `info` (default), `notice` (good to know), `warning` (warning), `critical` (critical). Objects are identified by `external_id` (from the same source) or `id`.

```
curl -sS -X POST "$NV/api/v1/events" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"event_id":"deploy-2026-10-20-1","type":"deploy","occurred_at":"2026-10-20T13:58:00Z",
       "summary":"release 42","details":"Changes: new shipping calculation",
       "objects":[{"external_id":"web:shop.example.com"}]}'
```

The event appears on the timeline of the object and of its direct neighbors, and in the diagnostic header as the "last change".

### Context for LLM

The same content as the "Copy for LLM" button, as JSON (or Markdown):

```
curl -sS "$NV/api/v1/objects/01a1.../context" -H "Authorization: Bearer $TOKEN"
curl -sS "$NV/api/v1/objects/01a1.../context?format=markdown&lang=en" -H "Authorization: Bearer $TOKEN"
```

The object id is in the address of its card (`/objects/<id>`) and in the upsert response. The export is at most 24 kB and masks secrets.

### Heartbeat

A heartbeat check waits for a regular signal, for example from a backup script. The address with the token is on the check detail; it needs no other authentication.

```
# at the end of the backup script, only on success
/usr/local/bin/zaloha.sh && curl -fsS -m 10 "$NV/hb/0123456789abcdef0123456789abcdef" >/dev/null
```

Both GET and POST work. When the signal does not arrive within the interval plus the grace period, the check changes to the "unhealthy" state (`heartbeat_missed`).

### Error responses

| Code | `error` | Meaning |
|---|---|---|
| 400 | `invalid_json` | the body is not a valid JSON object |
| 401 | `unauthorized` | the token is missing or invalid |
| 403 | `insufficient_scope` | the token lacks the required permission |
| 404 | `not_found` | unknown address or object |
| 413 | `payload_too_large` | body over 1 MB |
| 415 | `unsupported_media_type` | `Content-Type: application/json` is missing |
| 422 | `validation_failed`, `missing_objects` | invalid input, reference to a non-existent object |
| 429 | `rate_limited` | request limit exceeded |

## 9. config.php configuration

`config.php` is created by the installer; a new version does not overwrite it. The template is in `config.example.php`.

| Key | Meaning |
|---|---|
| `app_secret` | random secret (HMAC of API tokens, encryption of channel passwords). Changing it invalidates tokens and channel passwords. |
| `data_dir` | absolute path to the data directory outside the web root |
| `debug` | detailed errors; always `false` in production |
| `ping_binary` | path to the ping command (default `ping` from PATH) |
| `rdap_bootstrap_url` | RDAP bootstrap address (default `https://data.iana.org/rdap/dns.json`) |
| `telegram_api_url` | Telegram API address (default `https://api.telegram.org`) |

## 10. Security

- Passwords: Argon2id (otherwise bcrypt). Sign-in has a generic error message, a progressive delay from 5 failures and a 15-minute lockout from 10 failures, separately for the account and the IP address.
- Every form has a CSRF token. The session cookie is `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS; it gets a new id after sign-in, and after a password change the other sessions are invalidated.
- API tokens are stored only as a prefix and HMAC-SHA256; a lost token cannot be shown, only revoked and replaced with a new one.
- Notification channel passwords are encrypted in the database (AES-256-GCM) and are not sent back to forms.
- Headers: CSP without inline scripts, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`.
- The audit log (sign-ins, tokens, settings and channel changes, deletions, password changes) is in the database in the `audit_log` table.
- Checks and webhooks can also target internal addresses in your network. This is intentional (monitoring of internal infrastructure), so protect the admin account with a strong password.
- The LLM export and the inventory export mask secrets. Masking is a safeguard, not a reason to store secrets in the inventory.
- Configure HTTPS and the `Strict-Transport-Security` header in the web server (the application does not know whether it is reachable only over HTTPS).
- Behind a reverse proxy, the application sees the proxy address as the client IP address: the sign-in limit by IP then applies to all clients together, and the session cookie may not get the `Secure` flag. Direct operation on Apache with HTTPS is recommended.

## 11. Troubleshooting

| Symptom | What to check |
|---|---|
| The overview reports that the worker is not running | the cron line, the cron user, `php -v` in cron (same version as the web server), permissions on the data directory |
| The API returns `401` even with a correct token | passing of the `Authorization` header to PHP (see `public/.htaccess`; for PHP-FPM `CGIPassAuth On` or `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`) |
| Addresses lead to `index.php?r=...` | `mod_rewrite` is not enabled or `AllowOverride` does not allow `.htaccess`; the application keeps working this way |
| Checks stay in the unknown state even though cron runs the worker | the worker error is in `nordvarr-data/logs/worker-error.log` (cron output usually goes to `/dev/null`); a manual run `php bin/nordvarr.php worker` prints it |
| The ping check has `tool_missing` | the `ping` command is missing or lacks permission (`setcap cap_net_raw+ep /usr/bin/ping`), or set `ping_binary` |
| Domain expiry is "unknown" | the server cannot reach RDAP (`data.iana.org` and the registry server) or the registry does not publish the expiry date (e.g. `.eu`, which has no RDAP and WHOIS does not show the date either): fill in **Expiry date (manual, optional)** in the check from your registrar account |
| Email is not sent | "Send test" on the channel, attempt history in the channel detail, port 465 (SSL) or 587 (STARTTLS) |
| The agent or the API reports HTTP 415 `unsupported_media_type` | versions older than 0.14.1 did not read the Content-Type header under PHP-FPM; update |
| After an update the API returns 500 | sign in as admin or run `php bin/nordvarr.php migrate` |
| Forgotten password | `php bin/nordvarr.php reset-password <e-mail>` |
| A server with the agent reports `agent_missing` | `/usr/local/bin/nordvarr-agent.sh --test` on the server, `systemctl status nordvarr-agent.timer`, the file `/var/lib/nordvarr-agent/last-error` |
| Disk health is missing for the agent | the `smartmontools` package; the agent must run as root; virtual disks (VMs) often have no SMART |

## 12. Server agent (Linux)

The agent is a single bash script (`agent/nordvarr-agent.sh`) that sends the server state to Nordvarr every minute. It needs nothing except `bash` 4.4+, `curl` and `coreutils`; for disk health the `smartmontools` package, for GPUs the NVIDIA driver with `nvidia-smi`.

### What it measures

| Metric | Source |
|---|---|
| CPU (average over the last minute), load, number of cores | `/proc/stat`, `/proc/loadavg`, `/proc/cpuinfo` |
| RAM and swap | `/proc/meminfo` |
| Disk and inode usage (without tmpfs, overlay and similar) | `df` |
| Temperatures of all sensors (CPU, board, NVMe) including sensor limits | `/sys/class/hwmon`, otherwise `/sys/class/thermal` |
| NVIDIA GPU: utilization, VRAM, temperature, power draw, fan | `nvidia-smi` |
| Network traffic per interface (average per minute) | `/proc/net/dev` |
| Disk health from SMART: health, SSD and NVMe wear, temperature, power-on hours, reallocated and pending sectors, media errors and errors in the disk log | `smartctl -j` once every 15 minutes |
| Docker containers: name, image, state, healthcheck, exit code, docker compose project | `docker ps -a` (when docker is installed) |
| Services: Apache/httpd, nginx, MariaDB/MySQL, PostgreSQL, PHP-FPM, Redis, Docker, SSH, cron, mail and other known services (state, restart count) | `systemctl` |
| The last 50 lines of service logs (Apache, nginx, MariaDB/MySQL, PHP-FPM, Redis, PostgreSQL), once every 10 minutes | `tail` |

The agent sends the output of `smartctl` and `docker ps` in its original JSON form; the server does the parsing (ATA/SATA and NVMe, container state).

### Installation

1. In **Settings -> API tokens** create a token with the scope `agent:write` (one is enough for all servers, source for example `agent`).
2. On the server as root (the addresses are also in **Settings -> Server agent**, already with the address of your installation):

```
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.sh -o /usr/local/bin/nordvarr-agent.sh
chmod 755 /usr/local/bin/nordvarr-agent.sh
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.service -o /etc/systemd/system/nordvarr-agent.service
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.timer -o /etc/systemd/system/nordvarr-agent.timer
apt install smartmontools

install -m 600 /dev/null /etc/nordvarr-agent.conf
cat > /etc/nordvarr-agent.conf <<'KONEC'
NORDVARR_URL=https://nordvarr.example.com
NORDVARR_TOKEN=nv_...
KONEC

/usr/local/bin/nordvarr-agent.sh --test
systemctl daemon-reload
systemctl enable --now nordvarr-agent.timer
```

The agent files are also in the release zip in the `nordvarr/agent/` directory. Instead of systemd you can use cron: `* * * * * /usr/local/bin/nordvarr-agent.sh >/dev/null 2>&1`.

Without `mod_rewrite` set `NORDVARR_URL=https://nordvarr.example.com/index.php?r=`.

### Configuration `/etc/nordvarr-agent.conf`

| Key | Meaning |
|---|---|
| `NORDVARR_URL` | Nordvarr address |
| `NORDVARR_TOKEN` | API token with the scope `agent:write` |
| `NORDVARR_EXTERNAL_ID` | server identification, default `host:<hostname>` |
| `NORDVARR_OBJECT_ID` | attaches the agent to an existing object (id from the card address `/objects/<id>`) |
| `NORDVARR_HOSTNAME` | server name, default `hostname -f` |
| `NORDVARR_SMART_INTERVAL` | seconds between SMART reads (default 900, 0 = do not read) |
| `NORDVARR_SMARTCTL`, `NORDVARR_NVIDIA_SMI` | a different path to the commands |
| `NORDVARR_DOCKER` | a different path to the `docker` command, `0` = do not report containers |
| `NORDVARR_SERVICES` | monitored services separated by spaces (e.g. `httpd mariadb php-fpm`); the default is all known enabled or running services, `0` = do not report |
| `NORDVARR_LOGS` | logs to read separated by spaces (paths); the default is the known service logs, `0` = do not send |
| `NORDVARR_LOG_INTERVAL` | seconds between log sends (default 600) |
| `NORDVARR_LOG_ALLOW` | directories separated by spaces from which the agent may read website logs entered in Nordvarr (default `/var/log /var/www`) |

The file is only read as `KEY=value` pairs; nothing in it is executed. The token is passed to curl through a file with permissions 600, so it is not visible in the process list. The last send error is in `/var/lib/nordvarr-agent/last-error`.

### What happens after the first report

- The server appears in the inventory as an object of type **Server** (or attaches to the object from `NORDVARR_OBJECT_ID`) and gets a check of type **Agent (server)**.
- The object card shows the **Server (agent)** section: gauges for CPU, RAM, swap, the fullest disk, the highest temperature and each GPU, a 24 h chart, disk usage, disk health from SMART and all temperature sensors.
- In the topology the server node shows CPU, RAM and GPU in its label, and the node panel shows value bars.
- The LLM export contains a section with the server state.

### Services and logs

Since version 1.2.0 the agent monitors systemd services and sends the tails of their logs:

- The server card has a **Services** table with the state and restart count. A crashed service (`failed`) or a service that is enabled but not running is a problem of the server Agent check (`service_down`, unhealthy).
- Without configuration the agent monitors known services that are enabled or running. You set your own list in `NORDVARR_SERVICES`.
- Once every 10 minutes the agent sends the last 50 lines (at most 20 kB) of the service logs. They are on the server card in the **Service logs** section; each log expands on click. The server masks passwords, tokens and similar values on receipt, but still treat logs as sensitive: everyone signed in to Nordvarr can see them.

### Website and service logs

Each website can have its logs in its own file (for example `CustomLog /var/www/vhosts/example_com/access.log combined` in the Apache vhost). The paths to them are entered in Nordvarr; agent 1.3.0 and newer reads them:

1. On the website card (likewise for a service, database and container), in the **Logs** section, enter the paths to the files on the server, one per line, and save.
2. The website must be related to the server the agent runs on: the website "depends on" the server, the server "contains" the website, or through one object between them (the website depends on a service, the server contains the service). Otherwise the card warns that nobody will send the logs.
3. The server sends the paths to the agent in the response to its report. When the paths change, the agent sends the logs right on the next run, then once every 10 minutes (the last 50 lines, at most 20 kB).
4. The logs are on the website card; each file shows the server and its age. A file the agent has not sent yet is marked "waiting for the agent".

**A shared log for several websites** (typically mass hosting with `VirtualDocumentRoot`, where all websites log to one file): enter the path and, after a vertical bar, the text that identifies the website's lines, for example `/var/log/httpd/weby_access_log | shop.example.com`. The agent sends the last 50 lines that contain the text (it searches the last 20,000 lines of the file). The default Apache log format does not contain the website name; add it to the configuration (on a server behind Cloudflare also the real visitor address):

```
LogFormat "%V %{CF-Connecting-IP}i %h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" weby
CustomLog /var/log/httpd/weby_access_log weby
```

The filter looks for the text anywhere in the line, so `example.com` also matches `shop.example.com`; enter the most specific name possible.

The agent reads only files under the allowed directories `NORDVARR_LOG_ALLOW` (default `/var/log` and `/var/www`), and it checks the path after resolving symbolic links. Without this, anyone with access to Nordvarr could make the agent (which runs as root) send any file from the server, for example `/etc/shadow`. You allow logs elsewhere in `/etc/nordvarr-agent.conf`, for example `NORDVARR_LOG_ALLOW=/var/log /var/www /srv/app/logs`.

### Docker containers

When `docker` is on the server and the agent has access to it (it runs as root), it reports the list of containers every minute (at most 200):

- Each container is an object of type **Container** in the inventory, which the server **contains**. Attributes with the source `agent`: `image`, `docker_state`, `health` (healthcheck result) and `compose_project`. On the map it sits on the containers floor in the column of its server.
- A container missing from the report (removed) is archived. When it returns with the same name, the archiving is undone and the object (documentation, relationships, history) stays the same.
- The server card has a **Docker containers** table with the state of each container.
- Each container has its own **Container (agent)** check, which the agent creates and evaluates on every report (it cannot be created manually). A problem is reported after 2 consecutive reports:

  | Container state | Check status | Reason code |
  |---|---|---|
  | running (healthcheck healthy, starting or none) | healthy | - |
  | running, healthcheck reports `unhealthy` | unhealthy | `container_unhealthy` |
  | restarting repeatedly, in the `dead` state, exited with a non-zero code | unhealthy | `container_down` |
  | exited with code 0 (one-off job, migration) | healthy | - |
  | paused (`docker pause`), created and not started | degraded | `container_paused`, `container_down` |
  | missing from the report (removed) | unknown | `container_missing` |
  | the server stopped reporting (interval + grace 180 s) | unknown | `agent_missing` |

  Because the server contains the container, when the server is down the container alerts are suppressed and the server is shown as the probable cause.
- When the agent cannot reach docker (it does not run as root), the server card says so. Adding the agent to the `docker` group also works, but the group grants root privileges, so there is no security difference.
- You turn off container reporting in the configuration: `NORDVARR_DOCKER=0`.

Until Nordvarr 0.9.0 the container state was reported as a problem of the server Agent check; since 0.9.0 each container has it on its own. The agent reports containers since version 1.1.0. On servers with an older agent, download the script again (the first line of the installation instructions).

### Agent check limits

They are set on the check detail (Edit). A problem is reported after 3 consecutive reports (the check failure threshold), so that a one-off spike does not trigger an alert.

| Problem | Reason code | Status | Default limit |
|---|---|---|---|
| report did not arrive | `agent_missing` | unhealthy | interval 60 s + grace 180 s |
| CPU usage | `cpu_high` | degraded | 95 % |
| RAM usage | `memory_high` | degraded | 95 % |
| disk or inode usage | `disk_full` | degraded / unhealthy | 85 % / 95 % |
| sensor or GPU temperature | `temperature_high` | degraded / unhealthy | 85 / 95 °C (the sensor's critical limit applies when it is lower) |
| SSD/NVMe wear | `disk_wear` | degraded (unhealthy at 100 %) | 80 % |
| SMART reports a failure, an attribute below the vendor threshold, an NVMe critical warning | `disk_failing` | unhealthy | - |
| reallocated, pending or uncorrectable sectors, media errors, errors in the disk log | `disk_errors` | degraded | - |
| a service crashed (`failed`) or is enabled but not running | `service_down` | unhealthy | - |

The check sends notifications also in the degraded state (can be turned off). Values are stored per minute for 48 h and as hourly summaries for 400 days.

### Report API

`POST /api/v1/agent/report` with an `agent:write` token. The script builds the body itself; a custom agent can send the same JSON:

```json
{"agent_version":"1.0.0","hostname":"srv1.example.com","collected_at":"2026-10-21T10:00:00Z",
 "os":"Debian GNU/Linux 13","kernel":"6.12.0","uptime_s":864000,
 "cpu":{"usage_pct":37.5,"cores":16,"model":"Example CPU","load":[2.5,2.1,1.9]},
 "memory":{"total_b":68719476736,"available_b":25769803776,"swap_total_b":8589934592,"swap_free_b":8589934592},
 "disks":[{"mount":"/","device":"/dev/sda1","fs":"ext4","size_b":500000000000,"used_b":200000000000,"avail_b":275000000000,"inodes_total":30000000,"inodes_used":900000}],
 "temperatures":[{"chip":"coretemp","label":"Package id 0","c":61,"max_c":84,"crit_c":100}],
 "gpus":[{"index":0,"name":"NVIDIA ...","util_pct":92,"mem_used_mb":18000,"mem_total_mb":24576,"temp_c":71,"power_w":280.5,"fan_pct":55}],
 "network":[{"iface":"eth0","rx_bps":1250000,"tx_bps":250000}],
 "smart":[{ ...smartctl -j output... }]}
```

Response: `{"object_id":"...","created":false,"check_id":"...","status":"healthy","problems":0}`. Unknown keys are ignored; values outside a reasonable range are stored as empty.

## 13. Events from servers and applications

The **Events** page (in the main navigation) shows everything that happened: deployments, maintenance, interventions, security events, orders, support forms, messages. Each event has a severity and a matching color:

| Severity | Color | Example |
|---|---|---|
| `info` (info) | grey | an AI agent changed something on the server, a routine change |
| `notice` (good to know) | blue | a new order, a new support form, a new website |
| `warning` (warning) | orange | fail2ban or CrowdSec banned addresses, repeated errors |
| `critical` (critical) | red | an attack, a payment outage, something that needs immediate attention |

The filter at the top selects the period, the minimum severity and the type. The overview shows events for the last 24 hours, the object card shows its events and those of its neighbors. Older events (from versions before 0.17.0) have `info`, incidents have `warning`.

### From a server: agent

On a server with agent 1.4.0 or newer (the agent token is read from `/etc/nordvarr-agent.conf`, so run as root):

```
# Message (default type message, severity info), for example from an AI agent on the server:
nordvarr-agent.sh event "AI agent: I renewed the certificate for shop.example.com"

# Security with severity and details:
nordvarr-agent.sh event -s warning -t security "fail2ban banned 203.0.113.7" "jail sshd, 12 attempts"

# Event for a website (the website must exist in Nordvarr), details from standard input:
echo "customer: Example Ltd." | nordvarr-agent.sh event -s notice -t business -w shop.example.com "New order no. 1042" -

# New website on this server: creates a Website object, a "depends on" relationship to the server, log paths, with --checks also the suggested checks:
nordvarr-agent.sh site shop.example.com /var/www/vhosts/shop_example_com/access.log --checks
```

The option `-i <id>` gives the event its own id; sending again with the same id does not create a second event.

**fail2ban**: the file `/etc/fail2ban/action.d/nordvarr.conf`

```
[Definition]
actionban = /usr/local/bin/nordvarr-agent.sh event -s warning -t security -i "f2b-<name>-<ip>-<time>" "fail2ban (<name>) banned <ip>" "attempts: <failures>"
actionunban =
```

and in `/etc/fail2ban/jail.local`, for the jail, `action = %(action_)s` and `nordvarr` on the next line. With many bans choose `-s info` so they do not flood the overview.

**CrowdSec**: the http notification plugin (`/etc/crowdsec/notifications/http.yaml`); verify it against your CrowdSec version:

```
type: http
name: nordvarr
log_level: info
format: |
  {"hostname":"srv1.example.com","type":"security","severity":"warning","summary":"CrowdSec: {{len .}} new decisions","details":"{{range .}}{{range .Decisions}}{{.Value}} {{.Scenario}}\n{{end}}{{end}}"}
url: https://nordvarr.example.com/api/v1/agent/event
method: POST
headers:
  Authorization: Bearer nv_...
  Content-Type: application/json
```

(`hostname` must match the server with the agent; the token is the agent token.)

### From an application: API

A web application (orders, support form) should not read the agent configuration. Create its own token with the scope `events:write` in **Settings -> API tokens** and send events to `/api/v1/events` with the id of the website object (from the address of its card):

```
curl -sS -X POST "$NV/api/v1/events" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"event_id":"order-1042","type":"business","severity":"notice","occurred_at":"2026-11-05T10:00:00Z",
       "summary":"New order no. 1042","objects":[{"id":"<website object id>"}]}'
```

Do not put customers' personal data into events (everyone signed in can see them and they go into the LLM export); the order or request number is enough.

