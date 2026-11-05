# Decisions

Decisions that differ from SPEC.md or that SPEC leaves open.

## 2026-10-20 (F0 Skeleton)

1. **Migrations per phase.** The migration `0001_zaklad.sql` creates only the tables F0 needs (`users`, `login_attempts`, `audit_log`, `settings`, `schema_migrations`). The other tables of the data model are added by migrations of the phases that use them. Reason: the schema is verified by the code that uses it, and upgrading via migrations is tested for real at the same time.
2. **Pretty URLs switch on by themselves.** With mod_rewrite active, `public/.htaccess` sets the variable `NORDVARR_REWRITE=1` and the application then generates URLs like `/login`. Without mod_rewrite it generates `index.php?r=/login`. The router always accepts both forms. Nothing is configured by hand.
3. **Login attempt limiting.** Failed attempts since the last success within the last hour are counted, separately per e-mail and per IP. From the 5th failure, 1, 2, 4, 8, 16 s must pass between attempts (instead of `sleep()`, which would block the PHP process); from the 10th failure there is a lockout of 15 minutes from the last failure. A rejected attempt during the lockout is not counted, so the lockout does not extend forever. The response is HTTP 429 with a generic message. A non-existent account is verified against a dummy hash so that it cannot be detected by response time.
4. **Session invalidation after a password change** via the column `users.session_version`: a password change increments it and every session with a different value is discarded.
5. **Session files** are in `data_dir/sessions` (outside the webroot, independent of hosting settings). The installer, which does not yet know the data directory, uses the default PHP session storage.
6. **Language choice** is stored in the cookie `nordvarr_lang` (form with CSRF). A per-user language setting in the DB is not needed yet.
7. **Minimum password length** for the admin is 10 characters.
8. **The data directory must not be inside the application directory** (an upgrade by uploading files could overwrite it). Besides `.htaccess`, an empty `index.html` is also put into the data directory. The application root additionally has a `.htaccess` denying access (a safeguard for when the whole `nordvarr/` directory is uploaded to the webroot, not just `public/`).
9. **Checking whether the data directory is reachable from the web.** If it lies outside DOCUMENT_ROOT, it is fine. If it lies inside, the installer always warns and additionally tries to download the database file over HTTP; if that succeeds, it shows a stronger warning.
10. **`config.php` is created as the last step of installation** and is written in mode `x` (fails if it has been created in the meantime). Its existence blocks the installer.
11. **The worker in F0** only holds a lock against concurrent runs and writes the time of the last run to `settings.worker_last_run`, so that the cron line from the installer works from the start. Checks are added in F2.
12. **Navigation items** (Inventory, Topology, Alerts, Settings) lead in F0 to placeholder pages saying in which phase they will be added. This makes it possible to verify the layout and the mobile bottom bar.
13. **`bin/dev-router.php`** replaces `.htaccess` for the PHP built-in server (development and e2e tests). It is not included in the release zip, just like `bin/build-release.sh`.
14. **The cron line** uses `php` from PATH, because under PHP-FPM `PHP_BINARY` is the path to FPM, not to the CLI. The script path is quoted only when it contains unusual characters.
15. **JS libraries** will be in `public/assets/vendor/` (CLAUDE.md says `assets/vendor/`, SPEC has the webroot `public/`). The version list will be in `public/assets/vendor/VERSIONS.md`. There is no library in F0.
16. **Test tools:** PHPUnit 12, Playwright `@playwright/test` 1.56.1 (matching the Chromium preinstalled in the cloud VM). CI runs PHPUnit on PHP 8.4 and 8.5, e2e on 8.4. E2E tests build the zip, extract it into an empty temporary directory and install from it.
17. **The version** in the file `VERSION` is `0.1.0-dev` during development; release 0.1.0 comes only in F5.

## 2026-10-20 (F1 Inventory)

1. **Upsert as the complete state of a source.** When the upsert input contains the key `attributes`, it is taken as the complete set of attributes from that source: attributes from this source that are missing from the input are deleted. The same applies to `relationships` (the object's outgoing relationships from this source). When the key is missing from the input, nothing is deleted. Tags are only added, because they are shared with manual edits. Manual attributes and relationships (source `manual`) are never deleted by an upsert.
2. **Relationship targets in an upsert** are looked up by `to_external_id` only within the token's source. A reference to a non-existent object returns 422 with a list `missing_external_ids` and nothing is written (the whole upsert runs in a transaction).
3. **A relationship is unique by (from, to, kind)** regardless of source. When the same relationship already exists from another source, the upsert neither duplicates nor deletes it.
4. **Events** must belong to at least one object. Objects are referenced via `external_id` (within the token's source) or via the internal `id`. The same `event_id` from another source is a different event. Sending again returns 200 and the existing id; the content does not change.
5. **The object timeline** shows its events and the events of its direct neighbors (both directions, both kinds of relationships), the newest 50. For a neighbor's event it is visible which neighbor it belongs to.
6. **Manual events from the UI** (source `manual`): SPEC does not mention them explicitly, but without them the timeline could only be filled via the API.
7. **Export** contains objects, external ids, attributes, tags, relationships, documentation with revisions and events. It does not contain users, tokens, audit or settings. Secrets are masked (`***`) by attribute key and in URLs/DSNs; after importing such an export they stay masked. Masking of high-entropy strings and canary tests for the LLM export are in F5.
8. **Import replaces the whole inventory** (no merging), requires confirmation, and a database backup to `backups/` is made beforehand. An invalid file changes nothing (transaction). Limit 20 MB; on hosting it may be further limited by `upload_max_filesize`.
9. **API tokens** have the form `nv_<12 hex>_<48 hex>`; the prefix and an HMAC-SHA256 with the application secret are stored (changing `app_secret` invalidates tokens). The sources `manual` and `import` are reserved and cannot be used by tokens.
10. **The limit of 120 requests per minute** is counted in a fixed one-minute window (table `api_rate_limits`). Exceeding it returns 429 with `Retry-After`.
11. **The API accepts only JSON** (`Content-Type: application/json`, max. 1 MB). No session is started at all for `/api/`; cookies are ignored.
12. **Attributes** store text, or JSON (arrays, numbers, booleans) with the flag `is_json`. In case of a conflict the manual value wins; without a manual value none is marked as effective.
13. **The status filter** in the inventory will be added in F2 together with checks (status does not exist yet). Instead there is an active/archived filter.
14. **User management** in settings is not there yet (F1 does not mention it, the MVP has one admin from the installer). The role `viewer` is prepared: it must not send any POST except login, logout and language.
15. **Deleting an object** is physical (cascading to attributes, relationships, documentation, event links), requires confirmation and is written to the audit log. Archiving is used for ordinary retirement.
16. **Demo data** is loaded by `php bin/nordvarr.php demo-data` via upsert with the source `demo` (40 objects, 8 events, a sample attribute conflict). Into an inventory with other objects only with `--force`.
17. **Markdown in documentation** shifts heading levels by one (`#` -> `h2`), because the page's `h1` is the object name.

## 2026-10-20 (F2 Checks)

1. **Check results** (`check_results`) have an integer id instead of UUIDv7 because of the volume (hundreds of checks every minute, retention 14 days). Other tables still use UUIDv7.
2. **The default number of failures before a status change** is 2 for `http`, `tcp` and `ping`, but 1 for `tls_expiry`, `domain_expiry` and `heartbeat`. These checks run rarely (6 h, 24 h) or already include a tolerance, so double confirmation would mean a delay of a day. The value is configurable per check (1 to 10).
3. **State machine:** to a worse state (also from the initial `unknown`) only after N consecutive failures; then the state follows the latest result, so even an improvement from `unhealthy` to `degraded` shows immediately. Back to `healthy` after the first success. A result that started earlier than the last counted one goes only into history.
4. **The `paused` state is not part of the stored state** but is computed on display: a disabled check or an archived object. An object without checks has a special state "no checks" (`none`). Pausing by a maintenance window is added in F3.
5. **`http`:** TLS verification is always on (no option), proxies from the environment are not used, a disallowed 1xx to 3xx code and too many redirects give `unknown_error`. Searching for text requires the GET method.
6. **`tcp` and `tls_expiry` run via curl with `CONNECT_ONLY`** in the same `curl_multi` as `http` (up to 20 in parallel). `tls_expiry` reads the certificate without trust verification (it only watches validity in time) and supports only direct TLS, not STARTTLS. When the target is unreachable, the state is `unknown` (the expiry cannot be determined), not `unhealthy`; an outage of the service is reported by its `http`/`tcp` check.
7. **`ping`** calls `ping -n -c 1 -W <timeout>`, up to 20 processes at once. No reply -> `unhealthy` with `check_timeout` (SPEC has no dedicated code for ping), unknown name -> `dns_failure`, missing command or permission -> `unknown` with `tool_missing`. The path to the command can be changed in `config.php` (`ping_binary`).
8. **RDAP:** the IANA bootstrap is kept in the table `http_cache` for 7 days. An unavailable RDAP, 404 or a missing server for the TLD give `unknown` (with `unknown_error`, on a DNS error `dns_failure`), because that says nothing about the domain expiry. The bootstrap URL can be changed in `config.php` (`rdap_bootstrap_url`). The `domain_expiry` interval is fixed at 24 h.
9. **Heartbeat:** the token (32 hex) is stored in readable form, because it is part of the URL the admin needs to display repeatedly. It is not included in the export; a new one is created after import. The tolerance (`grace_s`) defaults to 300 s. After a miss the check is evaluated again after the interval; an incoming signal returns it to `healthy` immediately.
10. **The worker** moves the next run time forward before running the checks (after a crash the check is not repeated every minute). When the database is waiting for migrations, the worker runs nothing. Aggregates are recomputed from one hour before the last maintenance (this catches late results and worker outages). The 24 h summary is taken from the aggregates, so the current hour shows in it only after the hourly maintenance.
11. **The overview refreshes via `/overview/data`**, which returns ready-made HTML of the sections in JSON (the server renders, the browser only replaces the content). The ETag is the SHA-1 of this HTML; on an `If-None-Match` match it returns 304. Interval 15 s (active tab) / 60 s (in the background), doubling on error up to 5 minutes. "Not working now" includes checks in the `unhealthy` and `degraded` states.
12. **The object header** already shows in F2 the status with the reason (e.g. "TCP: tcp_refused, 3 min") and the status of direct dependencies (objects it depends on or is contained in). The rest of the diagnostic card is in F4.
13. **Checks are part of the inventory export and import** (configuration without state and history, URLs with credentials masked). The format still has version 1 and the key `checks` is optional, so an export from F1 can be imported.
14. **Demo data** got only a nightly backup heartbeat. Other checks would target documentation addresses and would only wait for a timeout.
15. **Check tests** run against local fixture servers (`php -S` with multiple workers, a TLS server with certificates generated in the test), a fake `ping` command and RDAP from fixture JSON via a replaceable HTTP client. A curl DNS error is not tested, because it would require a real DNS query.

## 2026-10-21 (F3 Alerts and notifications)

1. **Alerts are derived from the current state of checks** in a single pass after each worker run, not directly when a result is written. This way worker results, heartbeats and changes from the UI are processed the same way, and suppression is decided only over all results of one run (a website and a database that went down in the same minute are evaluated correctly). A heartbeat that arrives between runs is reflected in alerts at the latest in the next run (within a minute).
2. **When an alert closes:** only on return to `healthy`. A transition to `unknown`, or to `degraded` without `notify_on_degraded`, leaves the alert open. Disabling the check or archiving the object closes the alert silently (`paused`), and so does deleting the check (`check_deleted`). The alert keeps a snapshot of the check's type, name and reason, so the history remains even after the check is deleted.
3. **One-step suppression** is evaluated in the order of opening. When a new alert suppressed another new alert and is itself suppressed in the end, the decision is made again, so only a non-suppressed alert may suppress (chain hypervisor -> VM -> service: the VM is suppressed, the service is reported). With a mutual dependency between two objects, one of them is reported.
4. **When the cause is resolved and the suppressed alert persists,** one notification is sent about it ("down even after the cause was resolved") and from then on it behaves as an ordinary alert. The probable cause stays visible. SPEC does not cover this case; without it an ongoing outage would go unnotified.
5. **A recovery notification** is sent only when some notification about the alert was sent earlier (so not for a suppressed alert).
6. **Maintenance window:** nothing is sent during the window and open alerts of objects in scope are marked; after the window ends, one notification is sent for each alert still open ("still down after maintenance"). Recovery during the window is silent. With `pause_checks` the object's checks do not run, they have the state `paused` and their alerts stay unchanged; after the window ends the checks are due immediately.
7. **The `deliveries` queue:** 5 attempts in total (retries after 1, 5, 15 and 60 minutes), then `failed`. Every attempt is in the table `delivery_attempts` and in the UI (alert detail, channel detail, the "Sent notifications" page). A test send happens immediately from the UI and is not retried.
8. **Channel secrets** (SMTP passwords, Telegram token, webhook secret) are encrypted in the database with AES-256-GCM using a key derived from `app_secret` (the openssl extension, which SPEC already requires). They are never returned to the form; an empty field when editing keeps the existing value, and when the server or user changes, the old password is discarded. Changing `app_secret` breaks the channels (the secrets have to be entered again).
9. **SMTP client:** `ssl` (implicit TLS, default port 465), `starttls` (default 587, the server must offer STARTTLS) and `none`. The certificate is verified by default (can be turned off per channel). Login over an unencrypted connection is allowed only on localhost. AUTH PLAIN is preferred, otherwise AUTH LOGIN. The fallback server is tried on any error of the primary (connection, TLS, login, rejection).
10. **Webhook:** signature `X-Nordvarr-Signature: sha256=<hex HMAC-SHA256 of the body>`, plus the header `X-Nordvarr-Event`. The POST does not follow redirects. Success is any 2xx response.
11. **Telegram:** an error must not reveal the token (it is part of the URL), so it is replaced with `***` in the message. The API URL can be changed in `config.php` (`telegram_api_url`), so tests run against a local mock.
12. **Channel rules** (`channel_rules`): without tags a channel gets everything, otherwise alerts of objects with any of the tags.
13. **Links in notifications** need the application URL, which the worker running from cron does not know. It is saved automatically on the first admin request (`base_url` in settings, without mod_rewrite also with `index.php?r=`) and can be changed in settings. The notification language is configurable (Czech by default); times are in the time zone from settings.
14. **Reminders** are configurable in settings (default 24 h, 0 = off) and are counted from the last notification for the alert. Suppressed alerts and alerts in maintenance do not get reminders.
15. **Delivery history** is deleted after 400 days together with the hourly aggregates.
16. **Tests** run against a local fake SMTP server (STARTTLS, implicit TLS, AUTH PLAIN/LOGIN, outage, rejected recipient) and a local mock endpoint for Telegram and webhooks; in e2e, webhooks are received by a small HTTP server in Node. The check parallelism test uses its own slow server handling all connections at once, because `php -S` with workers handles only some of them and the test was flaky.

## 2026-10-21 (F4 Topology and diagnostic card)

1. **Clicking a node opens a card in the map's side panel** (status, cause, checks, incidents in 90 days, recent changes, documentation) with a button to the full object card; a double click goes straight to the object card. SPEC says "clicking a node opens the card"; the panel allows browsing the graph without leaving the map.
2. **Default neighborhood center** (SPEC does not specify it): the object with the oldest non-suppressed open alert of level `unhealthy`, then `degraded`, otherwise the active object with the most relationships. The default depth is 2 steps.
3. **Layout:** dagre top to bottom, or left to right if the graph fits into the map noticeably larger that way. In filter mode with more than 24 nodes, the force-directed layout `cose` (part of Cytoscape) is used, because dagre would stretch objects in few layers into a very wide row with unreadable labels. Nodes are then smaller with the label below them, and edge labels are shown only for the highlighted node.
4. **Node color** is a ring with the shares of its checks' states (green, yellow, red, gray), with a glow for `unhealthy` and `degraded` objects; an object without checks has a neutral outline, a paused one and one in maintenance a dashed one. The probable cause is drawn in the graph as an orange edge from the suppressed object to the cause (along `depends on`, or along `contains` from the host).
5. **CPU, RAM, GPU and similar metrics are not shown in the map:** they would require a host agent, which is outside the MVP scope. A node shows the latency of the first `http`/`tcp`/`ping` check, the status, the reason and the number of alerts.
6. **The neighborhood** also includes archived objects that are in a relationship with the center (the relationship still exists); filter mode only active ones. The limit of 300 nodes also applies to the neighborhood (it is then trimmed and a notice with the total count is shown below the node list).
7. **Cytoscape and CSP:** on startup the library inserts a `<style>` with one rule, which the CSP `style-src 'self'` blocks. Instead of allowing inline styles, the rule is in `app.css` and the page has an empty element with the id `__________cytoscape_stylesheet`, by which the library recognizes that the style already exists.
8. **Libraries:** Cytoscape.js 3.34.2, dagre 0.8.5 and cytoscape-dagre 2.5.0 (MIT) as minified UMD files from npm, loaded with ordinary `<script>` tags without a build; checksums are in `VERSIONS.md`.
9. **The diagnostic header** is a separate template `partials/diagnosis.php` with the endpoint `/objects/{id}/diagnosis` (JSON with HTML, ETag) and refreshes the same way as the overview. "Last change" takes the newest event within 24 h from the timeline of the object and its direct neighbors and shows at which neighbor it happened. The number of alerts in 90 days includes suppressed alerts; the link leads to a new section with the incident history (the last 20).
10. **The placeholder page** from F0 (`templates/placeholder.php`) has been removed; all navigation items now have their own page.


## 2026-10-21 (F5 Export, security, documentation)

1. **The LLM export is a separate page** (`/objects/{id}/llm`) with Markdown in a read-only field and a "Copy" button. The "Copy for LLM" button on the card leads to it, so it is visible what is being copied. `navigator.clipboard` works only on HTTPS (and localhost); elsewhere the text is selected and a prompt to copy it with the keyboard is shown.
2. **A new scope `context:read`** for `GET /api/v1/objects/{id}/context`. SPEC names only two write scopes; reading the context hands out inventory data, so write tokens (Ansible, CI) do not have it. A new token has only the write scopes preselected in the form. The API returns JSON, with `?format=markdown` the same Markdown as the UI; the Markdown language is the notification language, or `?lang=`.
3. **Strict masking only in the LLM export.** The inventory export (backup) still masks only by key, URL and DSN, so that it can be imported back without loss; entropy-based masking would damage legitimate values in a backup. Entropy rules: at least 20 characters from `[A-Za-z0-9+/=_-]`, lowercase and uppercase letters and digits, and a Shannon entropy of at least 3.5 bits per character; pure hex strings are masked from 41 characters (a commit hash up to 40 and UUIDs stay).
4. **The 24 kB limit applies to both Markdown and JSON.** When they do not fit, documentation (by halving), events, neighborhood, attributes and checks are reduced step by step (each to at least 5 items); the export then carries the flag `truncated` and a note. As a last resort the Markdown is cut at a UTF-8 character boundary.
5. **Neighborhood in the export:** up to 2 steps in both directions along both kinds of relationships. In the 2nd step the relationship is described relative to the middle object ("depends on nginx on web1"), because a transitive relationship to the exported object could be misleading. Ordering: unhealthy ones (`unhealthy`, `degraded`, `unknown` from the worst), then by the last event within 7 days, then closer, then by name. Events are taken from the object and the neighbors included in the export; event details and reason details are shortened to 300 characters.
6. **Documentation headings** are shifted by two levels in the export (`#` -> `###`), so they do not break the structure of the export.
7. **Password change and recovery from the CLI.** SPEC lists "users" in settings and in chap. 11 session invalidation after a password change, but the password could not be changed yet. Added the section Settings -> Account (own password only; there is no management of other users, the MVP has one admin) and the command `reset-password`, which generates a random password and prints it once. A failed verification of the current password counts toward login limiting.
8. **Fixes from the security pass:** `umask 0027` in `index.php`, `install.php` and the CLI; `config.php` gets permissions 0640 before writing; paths with a backslash or control characters are normalized to `/`; Markdown links with a backslash are dropped; at most 4096 bytes of a password are verified; the worker deletes login attempts older than 2 days; the last 10 pre-import backups are kept; a POST body discarded because of `post_max_size` returns 413 with an understandable message.
9. **Deliberately not handled in the application:** HSTS (belongs in the web server configuration), reverse proxies (the application does not trust `X-Forwarded-*` headers, because they could be spoofed), audit retention. Described in `docs/NAVOD.md`.
10. **Release 0.1.0:** `VERSION` is `0.1.0`. The agent does not create the tag `v0.1.0`: CI builds and publishes the release from the tag, and that should happen only from `main` after all phases are merged.
11. **The host agent** is outside the MVP scope according to SPEC chap. 14. The owner explicitly asked for it on 21. 10. 2026. So that release 0.1.0 matches SPEC, the agent will be a separate phase F6 after the release; `SPEC.md` does not change, the proposal is in `docs/STAV.md` (Next step).

## 2026-10-21 (F6 Host agent)

The owner requested the phase explicitly ("linux only, nvidia yes, temperatures yes, disk wear status and problem reporting, disk usage"), even though SPEC chap. 14 places the host agent outside the MVP. `SPEC.md` does not change; the scope is described here and in `docs/STAV.md`.

1. **The agent is a script for bash + curl**, not a binary nor PHP: nothing else needs to be installed on the servers and the code can be read. It is run by a systemd timer or cron every minute; a daemon would need its own supervision.
2. **CPU and network are computed from the difference of counters since the previous run** (stored in the state directory), so the value is an average over a minute and the script does not have to wait. The first run does not have these values.
3. **The agent sends SMART as raw `smartctl -j` and the server does the parsing** (PHP is testable and fixes do not require updating the agents). It is read once every 15 minutes; a report without SMART leaves the last state on the server. If sending fails, SMART is sent again next time.
4. **The first report creates an object of type Server** (by the token's source and `external_id`, default `host:<hostname>`) and an Agent check with default thresholds. This is not automatic network discovery: an object is created only by an agent installed by the admin with the admin's token. Attaching to an existing object via `NORDVARR_OBJECT_ID`; when the `external_id` already belongs to another object, the API returns 422.
5. **The Agent check:** interval 60 s, tolerance 180 s, threshold 3 consecutive reports, notifications also in the degraded state. Thresholds: see `docs/NAVOD.md`. For temperatures the lower of the check threshold and the sensor's critical limit applies; the sensor's `max` limit means degraded. At equal severity the reason code determines the order: failing disk, temperature, usage, wear, disk errors, RAM, CPU. The result detail contains all problems (max. 300 characters).
6. **Wear:** NVMe `percentage_used`, for ATA the normalized value of attributes 177, 202, 231 or 233 (100 - value). Errors (reallocated, pending, uncorrectable sectors, media errors, errors in the log) are degraded; failure according to SMART, an attribute below the manufacturer threshold and an NVMe critical warning are unhealthy.
7. **The report time** is the agent's time only when it differs from the server time by at most 5 minutes; otherwise the time of receipt (a server with a wrong clock must not break the graph or the monitoring).
8. **Storage:** the last report and SMART in `agent_hosts`, summary values of each report in `agent_samples` (48 h, one row per report), hourly summaries in `agent_hourly` (400 days) computed by the worker's hourly maintenance. Detailed values (individual disks, sensors) are kept only from the last report.
9. **Rebuilding the `checks` table** in migration 0005 (SQLite cannot change a CHECK constraint). The migrator therefore now disables foreign keys outside the transaction, runs `PRAGMA foreign_key_check` after the migration and enables the keys again (the procedure from the SQLite documentation); otherwise DROP TABLE would cascade-delete results and alerts.
10. **Bars and the graph are SVG generated on the server** (widths as attributes), because CSP forbids inline styles. The 24 h graph is in 5-minute segments; a gap in the data breaks the line.
11. **The agent files are publicly downloadable** at `/agent/<file>` (only a fixed list of files), so that the agent can be installed with a curl command directly on the server without logging in. They contain nothing secret.
12. **Version:** the agent is a new feature on top of the MVP and is released as 0.2.0. Release 0.1.0 was not released separately: the owner decided to release 0.2.0 straight away with all phases F0 to F6.
13. **The test of attempt limiting on password change** (F5) depended on the speed of the machine and on whether the attempt fell into the same second as the login; rewritten deterministically, the fix is also on the F5 branch.

## 2026-10-23 (fix after deployment, 0.2.1)

1. **The data directory may be inside the application directory** (outside the code folders `public`, `src`, `templates`, `lang`, `migrations`, `bin`, `agent`, `vendor`). Replaces F0 item 8. Reason: when installing on a server where the web server can write only to the domain directory (the owner does not use Plesk, the similarity of the path `/var/www/vhosts/...` is a coincidence), a directory next to the application could not be created and the installer forbade one inside the application; installation was impossible. The risk of overwriting during an upgrade is small (uploading files over old ones deletes nothing), the directory is protected by the `.htaccess` in the application root as well as its own, and if it lay in the webroot, the installer still warns and tries to download it over HTTP.
2. **The suggested data directory** takes the first usable option: `nordvarr-data` next to the application, then `nordvarr-data` in the application directory. An option is usable when the web server may create it or write to it and `open_basedir` allows it; options outside the webroot take precedence.
3. **The installer verifies writability** of the data directory already when validating the form and explains clearly why the directory cannot be used (previously only the technical error "Directory cannot be created" was shown).

## 2026-10-23 (F7 Quick monitoring setup)

After deployment the owner wrote that they have objects without checks and do not know how to turn on monitoring, and asked to continue with further milestones. SPEC prescribes nothing like this; `SPEC.md` does not change.

1. **Nothing is created automatically.** Suggestions are only shown and the admin confirms them (on the object card by selection, in the inventory by selecting objects). Otherwise editing a name or attribute would silently add checks and alerts.
2. **Suggestion rules:** website -> HTTP to the attribute `url`/`adresa`, otherwise `https://<name>/` (only when the name is a host name with a dot, not an IP), for https a TLS certificate; domain -> domain expiry from the name; server, VM, network device -> ping to `ip`/`ipv4`/`ipv6`/`host`/`hostname`, otherwise to the name (IP or a name with a dot); service, database, other -> HTTP to `url` (+ TLS), without `url` TCP to `host`/`ip`/`server` and `port`. For a service nothing is guessed from the name. Attribute keys are compared case-insensitively; the effective value is used (in a conflict the manual one).
3. **Ping instead of TCP for servers:** it does not require knowing the port. Where ping does not work on the Nordvarr server (hosting), the guide recommends TCP.
4. **New checks have the type's default settings** (interval, threshold, notifications), the same as the manual form without changes. A duplicate is recognized by the check type and target.
5. **The bulk button in the inventory** uses the same form as bulk editing (`formaction`), so that selecting objects works without JavaScript.
6. **Version 0.3.0:** a new feature on top of 0.2.1.

## 2026-11-02 (F8 Floors in the map)

The owner proposed a 3D view of infrastructure layers. The analysis and proposal are in `docs/NAVRH-3D-MAPA.md`. The owner agreed with the recommendations: floors stacked on top of each other (not nested shells), projects as tags, logs out of scope, 3D as a second view next to 2D, start with step A (floors in 2D). `SPEC.md` does not change.

1. **Floors first in 2D** in the existing Cytoscape map. This verifies the layer model on real data without a new library. The layout is computed on the server and a possible 3D view will use it too.
2. **Seven floors from the bottom:** hardware and network (`server`, `network_device`), virtual machines and VPS (`vm`), containers, services and databases (`service`, `database`), websites and APIs (`website`), domains (`domain`), internet and external services (`other`).
3. **New object types (container, cluster) are not added yet.** They would require rebuilding the `objects` table (CHECK constraint) and changes everywhere the types are enumerated. Instead the floor can be overridden with the attribute `vrstva` (also `patro` or `layer`), so a service in Docker can lie on the containers floor. An invalid value is ignored. In a conflict between sources the manual value wins, as with other attributes.
4. **The layout is deterministic** (row = floor, horizontal position = average of the positions of neighbors on other floors, six passes alternately from the bottom and from the top, minimum spacing 1, ties by name). Links within a floor do not determine the position. The same data always give the same map; manual positions are still not stored (SPEC chap. 14).
5. **A floor with more than 10 objects wraps** into additional rows; otherwise, with a filter over the whole inventory, the band would be too wide and the text unreadable.
6. **Empty floors are not drawn.** Rows are stretched in the browser according to the aspect ratio of the map (at most to double), so that a wide graph does not stay flat.
7. **The layout choice** is the URL parameter `layout=floors` (nothing is stored in a profile). The default remains the automatic layout.
8. **Version 0.4.0.**

## 2026-11-02 (F9 3D view of the floors)

Step B from `docs/NAVRH-3D-MAPA.md`. After F8 was merged the owner wrote "merge and continue", so 3D was built without trying the floors on the owner's data (which the proposal recommended). `SPEC.md` does not change.

1. **three.js 0.170.0** as a single file `three.module.min.js` (from version 0.171 the library is split into multiple files). Included unchanged, with an entry in `VERSIONS.md`.
2. **No add-ons from `examples/` (OrbitControls, CSS2DRenderer).** They import the module `three` by a bare name, which without a build requires an import map, and that has to be an inline script, which CSP forbids. Camera controls and labels are therefore custom, directly in `topology3d.js`.
3. **The module is loaded dynamically** (`import()`) only when switching to 3D, so other views do not download an extra 700 kB. The module URL comes from the template with a version parameter, so that an old version is not loaded from the cache after an upgrade.
4. **Shape: floors stacked on top of each other** (variant C of the proposal). Angle on the circumference = horizontal position from F8, height = floor. A wrapped floor (more than 10 objects) gives additional rows on a smaller radius. The radius grows with the number of columns, the floor spacing with the radius.
5. **Labels as HTML above the canvas**, the position is set by the script via CSSOM (`style.transform`). CSP (`style-src 'self'`) allows this; only inline styles in HTML are forbidden. With more than 60 objects only the labels of objects with a problem, the selected object and its neighbors are shown.
6. **Rendering only on change** (movement, animation, new data), not on every frame. A map at rest does not load the CPU.
7. **Without WebGL** 3D does not turn on: the map switches to floors and shows a notice. The option `layout=3d` in the URL is rewritten to `layout=floors` in such a browser.
8. **Version 0.5.0.**

## 2026-11-02 (F10 Search, floors and problem mode)

Step C from `docs/NAVRH-3D-MAPA.md`. `SPEC.md` does not change.

1. **Search filters the object list in the left panel**, not the graph. The list is the same in all layouts and also works on mobile. It searches the name, type and status reason, ignoring diacritics and case.
2. **The path to the cause is found in the browser** as the shortest path in the displayed graph regardless of the direction of relationships. The server knows only the cause (F3/F4), not the path to it. When the cause is not in the displayed graph (neighborhood with depth 1), the button is not shown.
3. **Source of problem mode:** the selected object with a cause, otherwise the first such object in the list (sorted from the worst state). The mode does not turn on by itself on load: otherwise the map would jump after every outage.
4. **Disabled floors are not remembered** (neither in the URL nor in the browser). It is an aid for investigation; the default state should show everything.
5. **A smooth transition between 3D and 2D (from the proposal) is not done.** The Top view button in 3D gives a similar result, and animating a transition between two libraries would require custom rendering of the 2D graph.
6. **Version 0.6.0.**

## 2026-11-02 (F11 Containers and clusters, metrics in 3D)

Step D from `docs/NAVRH-3D-MAPA.md`. `SPEC.md` does not change. SPEC chap. 5 gives a fixed list of object types; `cluster` and `container` are an extension of it at the owner's request (the list stays fixed, just longer).

1. **The types `container` and `cluster`** replace F8 item 3 (the containers floor only via the attribute `vrstva`). The attribute `vrstva` remains for exceptions. Container and cluster have the same floor; a cluster contains containers (relationship "contains"), and therefore they lie in the same column.
2. **Migration 0006 rebuilds the `objects` table** the same way 0005 rebuilt the `checks` table. The migrator disables foreign keys outside the transaction and verifies `PRAGMA foreign_key_check` after the migration, so DROP TABLE does not delete attributes, relationships, checks or history. Verified by a test on data from previous migrations.
3. **Suggested checks** for container and cluster are the same as for a service. Ping is not suggested; a container usually does not have its own address reachable from the Nordvarr server.
4. **Metrics in 3D** are text below the server name (the same as the second line of the label in 2D), not bars. Bars on hundreds of spheres would be unreadable, and the text is refreshed with the same data.
5. **Demo data** has a cluster and two containers, so that the containers floor is not empty in the demo. Tests with the number of demo objects changed from 40 to 43.
6. **Version 0.7.0.**

## 2026-11-03 (F12 Docker containers from the agent)

The owner wrote "merge and continue" after F11; work continues with the candidate named in F11. SPEC does not have the host agent (F6 is beyond its scope); `SPEC.md` does not change.

1. **The agent sends the raw output of `docker ps -a --format {{json .}}`**, the server does the parsing, as with SMART (F6 item 3). The script stays without further dependencies (no jq). Lines that do not start with `{`, or longer than 20 kB, are dropped; the server validates the rest.
2. **Without access to docker** (the agent does not run as root and is not in the docker group) the agent sends `containers_error` and the server card says so. When docker is not installed, the agent sends nothing about containers.
3. **Containers are objects of type container** contained in the server. Identity is by the container name on the given server (external id `docker:<external id of the server>/<name>` with the source of the agent's token), because the container id changes with every recreation (`docker compose up` after an image update). Documentation and relationships on the container object thus survive updates.
4. **A disappeared container is archived, not deleted.** Deleting would also take manually added documentation and history. When a container with the same name returns, the archiving is undone.
5. **Attributes only with stable values** (`image`, `docker_state`, `health`, `compose_project`), not the text `Up 2 hours`, which changes every minute. An attribute is written only on change; an empty value is removed.
6. **Container problems are reported by the server's Agent check** (`container_down`, degraded), not by a check of the container itself: such a check would need a new check type (rebuilding the `checks` table), and we learn the container state only from the server's report anyway. A container that exited with code 0 is not considered a problem (one-off jobs, migrations).
7. **Version 0.8.0, agent 1.1.0.**

## 2026-11-03 (F13 Container's own status)

A candidate from F12. `SPEC.md` does not change.

1. **A new check type `container`** replaces F12 item 6 (container problems on the server check). A container thus has its own status in the map, history, alerts and notifications, and alert suppression and the probable cause work for it (the server contains the container). The `checks` table was rebuilt by migration 0007 because of the CHECK constraint.
2. **The check is created only by the agent** (just like the Agent check on a server): a manual container check would have no source of data. The type is not in the form for a new check; a POST with it ends with an error.
3. **States:** healthcheck `unhealthy`, restarting, `dead` and exit with a non-zero code are "unhealthy"; a paused and a never-started container are "degraded"; exit with code 0 is "healthy" (one-off jobs). A healthcheck in the `starting` state counts as healthy, so that starting a container does not trigger an alert.
4. **Threshold of 2 consecutive reports, notifications only in the unhealthy state** (default, can be changed per check). A short restart during deployment is thus not reported.
5. **A deleted container** gets the result `container_missing` (unknown) once when archived, then no more. When the server stops reporting, the worker gives the checks of its containers the state unknown (`agent_missing`), not unhealthy: at that moment we know nothing about the container, and the outage is reported by the server check.
6. **Version 0.9.0.**

## 2026-11-04 (release by running CI manually)

1. **A release can be published by manually running the CI workflow on `main`** (workflow_dispatch). The tag is taken from the file `VERSION` and is created together with the release on the commit just tested, so the mistake from the `v0.3.0` release (tag on an old commit) cannot happen again. Reason: a tag cannot be pushed from the agent's environment (proxy) and the owner wanted the agent to do the release. Releasing via the GitHub web UI and pushing a tag still work.
2. **Safeguard:** when a tag with the version already exists and points to a different commit, the manual run ends with an error (`VERSION` has to be increased or the tag deleted). The release is created only after successful tests (`needs: [phpunit, e2e]`).

## 2026-11-04 (fix 0.9.1, older libcurl)

1. **Support for libcurl older than 7.85** (AlmaLinux/RHEL 9 has 7.76): instead of `CURLOPT_PROTOCOLS_STR`, `CURLOPT_PROTOCOLS` with the same restriction to http and https is used there. Newer systems still use the text variant (the numeric one is marked as deprecated in libcurl).
2. **Worker errors go to a log in the data directory** (`logs/worker-error.log`): the cron line sends output to `/dev/null`, so a worker crash was not visible anywhere. The log is not cleaned automatically; it is written only on error.

## 2026-11-04 (0.9.2 Website link and the website's domain)

1. **Links only for http and https**, always into a new tab with `rel="noopener noreferrer"`. Other values (for example `javascript:`) stay as text.
2. **The website's domain expiry on an object of type domain**, not on the website: the domain is a separate thing (it can have multiple websites, e-mail, DNS) and in the map floors it lies on the domains floor. The website "depends on" it, so a domain outage shows as the probable cause for the website. The domain object is looked up by name case-insensitively, so it is not created twice.
3. **Registered domain without the full public suffix list** (the Public Suffix List would be a big extra file): the last two levels, three for known two-level suffixes (`co.uk`, `com.au` and similar). For unusual suffixes the suggestion may come out wrong; the suggestion can be unchecked.

## 2026-11-04 (F14 Providers and external services)

1. **Two new object types** instead of one generic: a provider is "below" the infrastructure (contains servers and VPS), an external service is "above" it (websites and servers depend on it). The distinction gives relationships and floors meaning, and a provider outage shows as the probable cause for its servers.
2. **The Providers floor has number 0**, so that the meaning of numbers 1 to 7 for the attribute `vrstva` from F8 does not change.
3. **External services share a floor with the type `other`** (Internet and external services). The type `other` remains for things that do not belong elsewhere.
4. **Version 0.10.0.**

## 2026-11-05 (F15 Services and logs from the agent)

1. **Services as a problem of the server check, not as objects** (unlike containers in F12/F13): systemd services are part of the server, and the inventory often already has manually created services (e.g. "nginx on web1"), so automatic objects would be created as duplicates. The status is in a table on the server card.
2. **A failed service, or an enabled service that is not running = unhealthy.** A disabled service is not reported at all without an explicit list (it is merely installed).
3. **Logs once every 10 minutes, 50 lines, at most 20 kB per file and 20 files**, as the owner wanted. The agent sends them raw, the server masks them immediately on receipt (the same masking as the LLM export) and stores only the latest state (no log history, Nordvarr is not a log collector).
4. **Logs are not included in the LLM export** (they may contain personal data of visitors).
5. **Version 0.11.0, agent 1.2.0.**

## 2026-11-05 (F16 Documentation in categories)

1. **A fixed list of categories** (operations, runbook, DR, incidents, other) instead of arbitrary named files: it matches what the owner listed, does not require managing names and order, and is always in the same order on the card (the runbook right after the operations documentation, because it is what people look for first during an outage). The "Other" category covers the rest.
2. **The existing documentation is operations documentation**, and the API (`docs_markdown`) and the panel in the topology still work only with it, so that the behavior of existing integrations does not change.
3. **The inventory export stays at version 1**: operations documentation is still in `docs`, the other categories in the new field `docs_other`, and revisions have the field `category` (if missing, it is operations). An older export can be imported; an older version of Nordvarr ignores the new fields (it loses only the other categories).
4. **The LLM export contains all categories** under category headings within the same limit of 4000 characters; the runbook and DR are useful when handling an incident.
5. **Version 0.12.0.**

## 2026-11-05 (0.13.0 Manual domain expiry date)

1. **A manual expiry date instead of a WHOIS fallback.** The `.eu` registry (EURid) has no RDAP and does not publish the expiry date (nor the creation date) in WHOIS, so WHOIS would bring nothing for `.eu`. The same applies to other registries that hide the date. WHOIS would also need outbound port 43, which hosting providers often block, and parsing of text that differs from registry to registry.
2. **The date from RDAP takes precedence** over the manual one: after a renewal it changes in RDAP by itself, while the manual date could be outdated. The manual date is also used when RDAP is unavailable (with a note in the detail), because it is the owner's information.
3. **The manual date is midnight UTC of the given day.** Registries do not give an exact time, and with margins of 30 and 7 days it does not matter.
4. **Version 0.13.0.**

## 2026-11-05 (0.14.0 Resource status in the topology)

1. **Colors according to the agent check thresholds**, so that the map and the check status say the same thing: red is where the check reports a problem. The orange "approaching" for CPU and RAM starts 15 points below the threshold (the check itself has only one threshold for CPU and RAM); for disk it is the degraded threshold.
2. **Only the fullest disk** (including inodes), not all of them: the node has room for three bars. The other disks are on the server card and in the panel.
3. **In 2D an SVG image in the node** (Cytoscape cannot do a multicolored label). CSP allows `data:` images; the text in the image is escaped.
4. **GPU without color**: full GPU utilization is a normal state for compute servers, not a problem.
5. **Version 0.14.0.**

## 2026-11-05 (0.15.0 Servers as cubes in 3D)

1. **Face layout:** the summary is on two opposite faces turned outward and inward of the floor circle, so it is visible from the usual view and from the other side of the cylinder. The camera normally does not go below a floor, so the network is on the bottom face (its values are also in the summary), and with cubes switched on the camera can also move below the horizontal plane.
2. **Graph of the last 30 minutes** from the agent samples (one sample per minute); the data come with the same map refresh as the rest of the graph (ETag), so the cubes update themselves.
3. **The cubes option in `localStorage`** (only a convenience for one browser), not in the URL nor on the server.
4. **Version 0.15.0.**

## 2026-11-05 (0.16.0 Website logs)

1. **The owner enters the paths in Nordvarr and the agent gets them in the response to its report** (remote configuration), not only in the agent's file: the owner wanted to set paths on the website, and websites are added more often than servers.
2. **Security: the agent reads only under allowed directories** from its own configuration (`NORDVARR_LOG_ALLOW`, default `/var/log /var/www`) and verifies the path after resolving symbolic links (`realpath`). The agent runs as root; without a restriction, access to Nordvarr would mean reading any file on all servers. Both the server and the agent also reject paths with `..`, spaces and characters other than safe ones.
3. **The link between a website and a server via relationships up to distance 2** (the same as the neighborhood in the topology): a website on a server, a website depending on a service on a server. When a website is related to several servers, all of them get the paths and the agent skips a missing file.
4. **Paths in the attribute `logy`**, not in a new table: they can also be set via the API (upsert) and are carried over by the inventory export.
5. **Version 0.16.0, agent 1.3.0.**

## 2026-11-05 (0.17.0 Events from servers)

1. **Four severity levels** (`info`, `notice`, `warning`, `critical`) independent of the type: an order and a ban are different types, but the severity is decided by the sender according to the situation. Existing incidents got `warning`, others `info`.
2. **Three new types** instead of a generic "other": `security` (fail2ban, CrowdSec), `business` (orders, forms), `message` (a message, the default for the agent). The type is in a CHECK constraint; the table was rebuilt.
3. **The agent sends events via its own endpoint** `/api/v1/agent/event` with its token (scope `agent:write`); the event is tied to its server, and the event id is unique within the server. For applications we recommend their own token `events:write` and the generic `/api/v1/events`, so that they do not hold the agent's token.
4. **A website from the server** (`nordvarr-agent.sh site`) is looked up by name (the same as the domain for suggested checks); the relationship and log paths have the source `agent`. Suggested checks only on an explicit `--checks`: a website that is not public yet would immediately report an outage.
5. **Notifications of critical events to channels are not there yet**: events are an overview, alerts stay with checks. A candidate for the next version.
6. **Log filter as `path | text`** (fixed text, `grep -F`), not a regular expression: safe to pass to the agent (only characters without spaces) and sufficient for a website name in a shared log. It searches the last 20 000 lines, so that reading a large log does not load the server.
7. **Version 0.17.0, agent 1.4.0.**

## 2026-11-05 (0.18.0 Sounds, Discord, README)

1. **README in English** at the owner's request ("use only English in the project description"). The deviation from CLAUDE.md (documentation in Czech) concerns the project description (README); the detailed guide `docs/NAVOD.md`, the status and the decisions stay in Czech until the owner decides otherwise.
2. **Discord as its own channel type**, not as a generic webhook: Discord requires its own message format and the webhook URL is secret (it contains a token), so it is stored encrypted and the host is restricted to Discord domains.
3. **Sounds in the browser via Web Audio** (generated tones), no sound files: nothing more in `assets/vendor`, no licenses, works with CSP. Sound and mute settings are in `localStorage` (a convenience for one browser). A sound is triggered only by what would send a notification: suppressed alerts (cause elsewhere, maintenance) stay silent.
4. **Topology from the navigation in 3D with everything**; links with parameters (object neighborhood from the card, filter) still work the same way.
5. **Version 0.18.0.**

## 2026-11-05 (Documentation in English)

1. **All project documentation is in English** at the owner's request: README, the administrator guide `docs/NAVOD.md`, the work status `docs/STAV.md`, the decision log and design notes. File names stay the same because `SPEC.md` (which must not change and stays in Czech) refers to them. The user interface stays bilingual (Czech and English).
