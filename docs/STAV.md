# Work status

Updated: 2026-11-05

## Completed phases

### Documentation in English (branch `dokumentace-anglicky`)

At the owner's request, all project documentation is now in English: `docs/NAVOD.md` (guide, chapter 13 added to the table of contents), `docs/STAV.md`, `docs/ROZHODNUTI.md`, `docs/NAVRH-3D-MAPA.md` and the agent rules in `CLAUDE.md` (now saying documentation and PR descriptions are English, the UI stays bilingual, code comments keep the language of the file). File names stay because `SPEC.md` refers to them; `SPEC.md` itself stays in Czech and unchanged. Code blocks in the guide changed only in comments and sample texts, commands are identical.

### 0.18.0 Sounds, Discord, topology in 3D, README in English (branch `faze-22-notifikace-zvuky`)

From the owner's requests.

- Topology from the main navigation (without parameters) opens in 3D with all objects (filter mode).
- **Discord** channel (migration `0012_discord.sql`): webhook URL encrypted, message as an embed with a color by kind, no user mentions, errors without the token. E-mail with a fallback SMTP, Telegram, generic webhook and test sending have existed since F3.
- **Sound notifications** in the browser: endpoint `/notify/pulse` (new and resolved alerts without suppressed ones, new events; levels info, warning, critical), script `sounds.js` (Web Audio, six sounds, sound choice for each level, mute for an hour cancels itself, on-screen notification, shared time across tabs).
- **README in English** with screenshots (`docs/screenshots/`, English interface, fictitious data from e2e).
- Tests: `PulseTest`, `DiscordChannelTest`, e2e `agent.spec.js` (sounds), `topology.spec.js` and `mobile.spec.js` (default 3D).

### 0.17.0 Events from servers and cube rotation (branch `faze-21-udalosti`, agent 1.4.0)

From the owner's requests: "add new domains with logs and other events on the agent side (new project, support form, order, fail2ban/CrowdSec, message from an AI agent), a layer of events marked with a severity color" and "clicking a cube face rotates it so that it is as readable as possible".

- Migration `0011_dulezitost_udalosti.sql`: severity (`info`, `notice`, `warning`, `critical`) and types `security`, `business`, `message`; existing incidents have `warning`.
- **Events** page (new navigation item): colors by severity, counts, filter by period, minimum severity and type. Severity also in the overview, on the object timeline, in the manual form, in the API `/api/v1/events`, in the inventory export and for the LLM.
- Agent API: `/api/v1/agent/event` (event for a server, optionally for a website, id unique within the server) and `/api/v1/agent/site` (website on a server: object, relation, log paths, optionally suggested checks). Agent 1.4.0: `nordvarr-agent.sh event` and `site`.
- 3D: clicking a cube face turns the camera perpendicular to the face and zooms in.
- Website logs with a `path | text` filter for a shared log (shared hosting with `VirtualDocumentRoot` logs all websites to one file): the agent sends only lines containing the text. Found on the owner's server, where the website's own log stayed empty because traffic from Cloudflare goes to the default vhost on 443.
- NAVOD chapter 13 (agent, fail2ban, CrowdSec, applications via the API).
- Tests: `AgentEventsTest`, `EventSeverityMigrationTest`, `AgentScriptTest` (subcommands), e2e `agent.spec.js` (events, cube rotation), `mobile.spec.js` (six navigation items).

### Fix 0.16.1 (branch `oprava-ukonceni-udrzby`)

- "End now" on a maintenance window that started in the same second (times are in seconds) did nothing: ending required the start to be strictly before "now", while the page showed the window as in progress. It showed up as an occasional failure of the maintenance window e2e test in CI.
- `MaintenanceRepository::end` ends such a window too and moves the start one second earlier (constraint end > start). Test `MaintenanceEndTest`.

### 0.16.0 Website logs (branch `faze-20-logy-webu`, agent 1.3.0)

From the owner's request: "log listing for a website" and "each website can have its log in its own file, there must be a way to set the paths the agent should read data from".

- `Agent\ObjectLogs`: paths in the `logy` attribute (website, service, database, container), path validation, paths for the server's agent (objects within distance 2 in relations), logs for the object card.
- The response to an agent report has `log_paths`. Agent 1.3.0 stores them (`log-paths` in the state directory), sends logs right away when they change and reads them only under `NORDVARR_LOG_ALLOW` (default `/var/log /var/www`) after resolving links.
- Website card: **Logs** section with a path form and the last lines (server, age, "waiting for the agent", warning when there is no related server with an agent).
- Tests: `ObjectLogsTest` (path validation, relations, masking and escaping, deletion), `AgentScriptTest` (allowed directories, symbolic link pointing outside, `..`, forgetting paths), e2e `agent.spec.js` (the whole path with the real agent script).

### 0.15.0 Servers as cubes in 3D and more readable labels (branch `faze-19-kostky-3d`)

From the owner's requests: "a mode where a server can be switched to a cube that has values on its faces" and "the default table has poorly readable text, the contrast must be high and the letters big too".

- 3D: **Servers as cubes** button. Faces as textures from a canvas: summary on opposite faces turned outward and inward of the circle, CPU and RAM with a chart, disks on top, network on the bottom. A selected cube has an edge outline, the label is below the cube, faces are opaque.
- Data: `HostResources::details` (disks with level, load, network, CPU, RAM and network history from `agent_samples` over 30 minutes), `AgentRepository::samplesFor` (one query for all servers in the map).
- Labels in 3D: larger font, solid background, text color instead of gray, darker shades of status colors for text.
- Tests: `HostResourcesTest::testDetailsForCube`, `AgentUiTest` (cube data), e2e `agent.spec.js` (switching, remembering the choice, other objects stay spheres). Appearance checked from screenshots.

### Fix 0.14.1 (branch `oprava-content-type-fpm`)

- On a server with Apache and PHP-FPM, the API returned error 415 `unsupported_media_type` for all writes (agent, upsert, events): PHP-FPM passes the Content-Type header only as `CONTENT_TYPE` (without the `HTTP_` prefix) and `Request` did not take it from there. Tests run on the PHP built-in server, which sends both variants, so they did not reveal the bug.
- `Request::headersFromServer` always takes `CONTENT_TYPE` and `CONTENT_LENGTH`. Test `RequestHeadersTest` simulates the variables from PHP-FPM.

### 0.14.0 Resource status in topology (branch `faze-18-prostredky-v-topologii`)

From the owner's request: "visible resource status on objects in the topology, so that you can see in color where the CPU is overloaded or a disk is running out".

- `Agent\HostResources`: from the last report CPU, RAM, the fullest disk (space and inodes) and GPU with level ok, warn, crit according to the agent check thresholds; a flag for a stale report.
- Topology: node data has `resources`. In 2D bars as an SVG image inside the node (colors are regenerated when switching between light and dark mode), in 3D colored values under the name with the disk mount in the tooltip.
- Tests: `HostResourcesTest`, `AgentUiTest` (node data), e2e `agent.spec.js` (image in 2D, colors and tooltip in 3D).

### 0.13.0 Manual domain expiry date (branch `faze-17-whois`)

From the owner's problem: the expiry of a `.eu` domain is permanently "unknown".

- The `.eu` registry (EURid) has no RDAP and does not publish the expiry date even in WHOIS, so a WHOIS fallback would not help (see ROZHODNUTI).
- The domain expiry check has an optional field `expires_on` (Expiry date, manual). It is used when RDAP does not provide a date (TLD without RDAP, response without expiry, RDAP unavailable); the date from RDAP takes precedence. The result detail says "date entered manually".
- Without a manual date for a TLD without RDAP: status unknown, reason `rdap_no_expiry` with instructions to fill in the date.
- Tests: `RdapRunnerTest` (manual date, RDAP precedence, RDAP note), `CheckTypesTest` (date validation), `ChecksUiTest` (form, clearing the date).

### F16 Documentation in categories (branch `faze-16-dokumentace`)

From the owner's request ("for documentation I want to be able to have multiple files split into operations, DR, incidents, runbook, etc.").

- Documentation categories: operations, runbook, disaster recovery (DR), incidents, other. Migration `0010_kategorie_dokumentace.sql` rebuilds `object_docs` (key object + category) and adds the category to revisions; existing documentation is operations.
- Object card: filled categories one below another with their own edit link, empty ones as "Add a document" links. The form has a category switch and revisions of the given category only, a revision shows its category.
- The LLM export contains all categories with headings (only operations documentation stays without a heading as before). Inventory export and import carry categories (`docs_other`, `category` for revisions); an older export without categories can still be imported.
- The `docs_markdown` API and the topology panel keep working with operations documentation.
- Tests: `DocCategoriesTest` (repository, card and form, unknown category, LLM export, migration), `TransferTest` (category transfer, older format, invalid category), e2e `inventory.spec.js` (runbook).

### F15 Services and logs from the agent (branch `faze-15-sluzby-logy`)

From the owner's request ("check that LAMP services, PostgreSQL are running" and "listing of the last 50 lines from the error and access log once every 10 minutes").

- Agent 1.2.0: services via `systemctl` (known services enabled or running, or a list from `NORDVARR_SERVICES`), log tails (`tail -n 50`, at most 20 kB, at most 20 files) once per `NORDVARR_LOG_INTERVAL` (600 s), known log paths or `NORDVARR_LOGS`.
- Server: `Report` validates service names and states, logs only with an absolute path, text without control characters and with masking of secrets (`SecretMasker::maskStrict`). Logs are stored separately (`agent_hosts.logs`, migration `0009_logy_agenta.sql`), so that every report without logs does not overwrite them.
- `HostEvaluator`: `service_down` (unhealthy) for a crashed service or an enabled service that is not running.
- Server card: service table, expandable logs.
- Tests: `AgentScriptTest` (fake systemctl and logs, explicit list, disabling, interval, masking), `AgentServicesTest` (check problem, storing logs, logs stay without a new report, escaping on the card), e2e `agent.spec.js`.

### F14 Providers and external services (branch `faze-14-poskytovatele`)

From the owner's requests after deployment ("a layer of providers who make up a floor of my infrastructure", "availability of things outside my scope and the ability to influence them").

- New object types `provider` (provider: hosting, data center, VPS) and `external` (external service: CDN, public DNS, peering). Migration `0008_poskytovatele.sql` rebuilds the `objects` table.
- **Providers** floor below hardware (number 0, the other floors keep numbers 1 to 7), external services lie on the Internet and external services floor.
- Suggested checks: provider like a service (HTTP on `url`), external service HTTP on `url`, otherwise ping.
- Tests: `FloorsTest`, `SuggestionsTest`, `ProviderTypesMigrationTest`.

### 0.9.2 Click-through to a website and the website's domain (branch `odkazy-na-weby`)

- At the owner's request: a website address is a link opening in a new tab. Under the object name (address of the first HTTP check, otherwise the `url` or `adresa` attribute), at the HTTP check target on the object card and in the check detail, and for attribute values that are an http(s) address.
- `View::webLink`: a link only for http and https with a host, other values stay as text (no `javascript:` or other schemes), `rel="noopener noreferrer"`.
- Suggested checks for a website now include expiry of the registered domain (`Suggestions::registrableDomain`, e.g. `shop.example.com` -> `example.com`, with a list of two-level suffixes such as `co.uk`). The check is created on an object of type domain, which is found by name or created, and the website gets a "depends on" relation to it. The suggestion is dropped when the website already depends on a domain with an expiry check or has the expiry itself.
- Tests: `WebLinkTest`, `SuggestionsTest` (registered domain, covered domain), `SuggestionsUiTest` (link on the card, creating the domain object, sharing between websites, bulk adding).

### Fix 0.9.1 (branch `oprava-curl-starsi-libcurl`)

- On a server with AlmaLinux 9 (PHP 8.5 from Remi, system libcurl 7.76), the worker crashed on every run on the undefined constant `CURLOPT_PROTOCOLS_STR` (it exists only since libcurl 7.85). Checks thus never got a result and stayed in the unknown state; cron had its output in `/dev/null`, so the error was not visible.
- `Http\Client\CurlProtocols`: on older libcurl the numeric variant `CURLOPT_PROTOCOLS` is used (the same restriction to http and https). Used in `CurlRunner` and `CurlHttpClient` (RDAP, notifications).
- A worker error is now written to `nordvarr-data/logs/worker-error.log` and the worker exits with code 1.
- Tests: `CurlProtocolsTest` (both variants, libcurl accepts them), `CliTest` (worker error in the log).

### F13 Container's own status (branch `faze-13-stav-kontejneru`)

Candidate from F12 (owner: "merge and continue").

- Check type `container` (migration `0007_kontrola_kontejneru.sql` rebuilds the `checks` table, just like 0005). The check is created by the agent for each container object, it cannot be created manually (`CheckTypes::MANUAL_TYPES`), tolerance, threshold and notifications can be edited.
- `Agent\ContainerEvaluator`: container status -> check status (table in `docs/NAVOD.md`). Each server report writes a result to all its containers, a vanished container gets `container_missing` once. The worker watches whether the server is reporting (`agent_missing`, status unknown).
- The server's Agent check no longer reports container problems (moved to the containers). The relation "server contains container" provides alert suppression and the probable cause.
- Tests: `ContainerEvaluatorTest`, `AgentContainersTest` (status of each container after two reports, `container_missing` once, manual creation rejected, worker on server outage), `ContainerCheckMigrationTest` (migration over data from 0001 to 0006), e2e `agent.spec.js` (check on the container card, server alert next to container alerts).

### F12 Docker containers from the agent (branch `faze-12-docker`)

Next step after the 3D map design (owner: "merge and continue" after F11, candidate from F11).

- Agent 1.1.0: when `docker` is on the server and the agent has access to it, it sends the output of `docker ps -a --format {{json .}}` (at most 200 containers, time limit 10 s). Without access it sends `containers_error`. `NORDVARR_DOCKER` changes the path, `0` disables the report.
- Server: `Report` turns the docker output into name, image, state, healthcheck, exit code and compose project. Invalid names and duplicates are discarded.
- `AgentService` maintains containers as objects of type container under the server (relation contains, attributes with source `agent`, external id `docker:<server external id>/<name>`). A vanished container is archived, a returning one is restored. A report without a list (older agent) changes nothing.
- `HostEvaluator`: problem `container_down` (degraded) for restarting, dead, error-exited containers and a failed healthcheck.
- Server card: container table, warning about missing access to docker.
- Tests: `AgentScriptTest` (collection from a fake docker, disabling, docker without access, docker missing, parsing on the server), `AgentContainersTest` (objects, attributes, archiving and restoring, older agent, check problems, card, invalid inputs), e2e `agent.spec.js` (table and relation on the server card).

### F11 Containers and clusters, metrics in 3D (branch `faze-11-kontejnery`)

Step D from `docs/NAVRH-3D-MAPA.md` (owner: "merge and continue" after F10). With this the 3D map design is fully done.

- New object types `cluster` and `container` (UI, API, import, filters). Migration `0006_kontejnery.sql` rebuilds the `objects` table (new CHECK constraint), data and foreign keys remain.
- Both types lie on the containers floor. Suggested checks as for a service (HTTP on `url`, otherwise TCP on `host` + `port`).
- Demo data: cluster `k3s na mon1` (in VM mon1) with containers prometheus and grafana (43 objects instead of 40).
- 3D: a server with an agent shows CPU, RAM and GPU utilization from the last report under its name, refreshed with the data.
- Tests: `ContainerTypesMigrationTest` (migration over data from 0001 to 0005: objects, relations, attributes and checks remain, new types pass, cascade delete works, `foreign_key_check` is empty), `FloorsTest`, `SuggestionsTest`, adjusted demo data counts (`DemoDataTest`, `TransferTest`, `InventoryUiTest`), e2e `agent.spec.js` (server metrics in 3D).

### F10 Search, floors and problem mode in the map (branch `faze-10-hledani-problem`)

Step C from `docs/NAVRH-3D-MAPA.md` (owner: "merge and continue" after F9).

- Search over the object list in the topology: filter ignoring diacritics and letter case, Enter selects the first match and shows it (in 3D a camera fly-over), Esc cancels.
- Floors in the map: floor checkboxes from the data, a disabled floor disappears in the automatic layout, in floors and in 3D (with its band, labels and edges). The floor list refreshes with the data.
- Problem mode (To the cause button): the shortest path from an object to the probable cause (Cytoscape aStar, ignoring the direction of relations), dimming the rest, selecting the cause, in 2D zooming to the path, in 3D turning the camera and highlighting the path. It is turned off by a second click, by selecting another object or by a graph change.
- Tests: `TopologyTest` (new controls), e2e in `topology.spec.js` (search with diacritics and an empty result, problem mode in 2D and 3D, disabling a floor in 2D and 3D, leaving the mode by selection).

### F9 3D view of floors (branch `faze-9-3d-mapa`)

Step B from `docs/NAVRH-3D-MAPA.md` (owner: "merge and continue", November 2, 2026): a read-only 3D prototype over the data from F8.

- `public/assets/js/topology3d.js` on three.js 0.170.0 (`assets/vendor/three-0.170.0`, MIT, entry in `VERSIONS.md`). The module is loaded only on the first switch to 3D.
- Floors as transparent discs above each other with an axis, objects as spheres on the circumference (angle from the horizontal position of F8, wrapped floors on a smaller radius), color by status, the neighborhood center larger, the selected object with an outline. Edges: "depends on" solid line, "contains" dashed, path to the cause orange.
- Object and floor labels as HTML over the canvas (sharp text). Clicking a label selects the object.
- Controls without another library: dragging rotates, wheel and two fingers zoom, click selects (card on the right), double click opens the card, hovering dims everything except neighbors. Clicking in the list turns the map to the object. Buttons Top view, Spread floors, Fit graph.
- Data refresh (ETag) updates 3D too: colors without a scene change, when objects or relations change the scene is rebuilt.
- Without WebGL the map stays in floors with a warning.
- Tests: `TopologyTest` (3D switch, module with version in the URL, hidden controls), `ReleaseBuildTest` (three.js and module files in the zip), e2e `topology.spec.js` (3D scene, clicking a sphere opens the card, selection from the list, buttons, reload, return to floors, browser without WebGL).

### F8 Floors in the map (branch `faze-8-patra`)

Step A from `docs/NAVRH-3D-MAPA.md` (the owner agreed with the design on November 2, 2026): verify the layer model in 2D before starting with 3D.

- `Topology\Floors`: floor by object type, override with the `vrstva` attribute (floor key or number 1 to 7), deterministic layout into rows and columns (average of neighbor positions on other floors, several passes), wrapping of a floor with more than 10 objects.
- Topology data (the page and `/topology/data`) have the floor and position for a node and a list of floors with labels.
- Map: Automatic / Floors switch, floor bands in the background with labels, rows stretch to the map height. The choice is in the URL (`layout=floors`) and the form preserves it.
- `AttributeRepository::validValues` loads the valid attribute value for multiple objects with one query.
- Tests: `FloorsTest` (floors, override, columns, spacing, determinism, wrapping), `TopologyTest` (floors in data, attribute override with a source conflict, switch on the page), e2e in `topology.spec.js` (bands, floor order, click on a node, preserving the choice).

### F7 Quick monitoring setup (branch `faze-7-rychle-nastaveni`)

A phase beyond SPEC at the owner's request after deploying 0.2.1 ("how do I add real monitoring there"). Goal: get from an inventory without checks to monitoring in a few clicks.

- `Checks\Suggestions`: suggested checks by object type, name and attributes (rules in ROZHODNUTI F7). Suggestions are validated the same way as manual checks, and what an existing check already covers is not offered.
- Object card: "Suggested checks" block with checkboxes and a button to add the selected ones; for a server and VM without an agent a link to Settings -> Server agent.
- Inventory: bulk button "Add suggested checks" for selected objects (result in a flash message: how many checks, for how many objects, how many objects without a suggestion).
- Overview: number of objects without checks with a link to the inventory filter.
- Each added check is written to the audit log (`check.create`, flag `suggested`).
- `docs/NAVOD.md`: section "How to turn on monitoring" in chapter 4.
- Tests: `SuggestionsTest` (rules, duplicates, invalid targets), `SuggestionsUiTest` (card, bulk adding, tip in the overview), e2e `suggestions.spec.js`. Result: `composer test` and `npm run e2e` green.

### Fix 0.2.1 (branch `oprava-datovy-adresar`)

- Installation on a server where the web server may write only to the domain directory (application in the domain directory, webroot `public/`) failed: the installer suggested a data directory one level up, where the web server may not write, and forbade it inside the application. It now suggests the first writable location (next to the application, otherwise in the application directory next to `public/`), respects `open_basedir` and verifies writability already in the form. See ROZHODNUTI of 2026-10-23.
- Tests: `InstallerTest` (fallback suggestion, avoiding the webroot, `open_basedir`, forbidden folders with code, installation into an application subdirectory).

### F6 Host agent (branch `faze-6-agent`, follows `faze-5-export-bezpecnost`)

A phase beyond SPEC at the owner's request (see ROZHODNUTI F5 item 11 and F6). Scope as assigned: Linux only, NVIDIA GPU, temperatures, disk wear and reporting of their problems, disk usage.

- **Agent** `agent/nordvarr-agent.sh` (bash + curl, no other dependencies), systemd service and timer, configuration template:
  - CPU and network as an average since the last run (per minute), load, RAM, swap, disk and inode usage, temperatures of all hwmon sensors (with max and crit limits), NVIDIA GPU via `nvidia-smi`,
  - SMART once every 15 minutes: the agent sends the output of `smartctl -j`, the server does the parsing,
  - the configuration is only read (not executed), the token goes to curl via a file with permissions 600, the last error in `last-error`,
  - modes `--test`, `--dry-run`, `--version`.
- **Server:**
  - API `POST /api/v1/agent/report` (scope `agent:write`): validation and normalization of the report, the first report creates an object of type Server (or attaches to the object from `NORDVARR_OBJECT_ID`) and a check of type **Agent**,
  - SMART parsing for ATA/SATA and NVMe: health, wear (attributes 177, 202, 231, 233, NVMe `percentage_used`), temperature, power-on hours, reallocated, pending and uncorrectable sectors, media errors, NVMe critical warnings, errors in the disk log,
  - Agent check with configurable thresholds and new reason codes `agent_missing`, `cpu_high`, `memory_high`, `disk_full`, `temperature_high`, `disk_failing`, `disk_wear`, `disk_errors`; it is evaluated right on report, missing reports are watched by the worker, alerts, suppression and maintenance work,
  - migration `0005_agent.sql` (rebuild of the `checks` table for the new type, tables `agent_hosts`, `agent_samples` 48 h, `agent_hourly` 400 days), the Migrator now runs migrations with foreign keys disabled and verifies them after the migration.
- **UI:**
  - on the object card a Server (agent) section: gauges for CPU, RAM, swap, the fullest disk, the highest temperature and each GPU, a 24 h chart (CPU, RAM, temperature, GPU), disk usage, disk status from SMART, all sensors, network,
  - in the topology a node label with CPU, RAM and GPU and value bars in the node panel,
  - a section in the LLM export,
  - Settings -> Server agent with instructions and download URLs; the agent files are public at `/agent/...` (they contain nothing secret),
  - threshold fields in the check form.
- `docs/NAVOD.md` chapter 12 (installation, configuration, thresholds, API). The agent is part of the release zip (`nordvarr/agent/`). Released as release 0.2.0.

Verification:

- report normalization, SMART parsing over samples of `smartctl -j` output (healthy SSD, failing HDD, worn NVMe with errors), thresholds, reason priority: `AgentEvaluationTest`,
- API (creating the server and check, attaching to an object, conflict, permissions, validation), threshold of 3 reports and alert, missing agent via the worker, aggregation and retention, migration 0005 preserving results and alerts and with a working cascade: `AgentApiTest`,
- agent script over a fake `/proc` and `/sys` and fake `df`, `nvidia-smi` and `smartctl`: values, CPU and network calculation from two runs, a space in a disk path, SMART and its interval, the configuration is not executed, sending with the token in a header to a local mock, sending error: `AgentScriptTest`,
- card, topology, export, threshold form, instructions, public agent files, no inline styles: `AgentUiTest`,
- e2e `agent.spec.js`: token with scope `agent:write` from the UI, the real script from the zip over a fake `/proc` sends a report three times to the installed application -> card with metrics, SMART "failing", status unhealthy (`disk_failing`), alert after a worker run, panel in the topology; mobile without a horizontal scrollbar,
- appearance on screenshots with demo data (dark, light, mobile),
- tests green: `composer test` (468 tests), `npm run e2e` (30 tests).

### F5 Export, security, documentation (branch `faze-5-export-bezpecnost`, follows `faze-4-topologie`)

- **LLM export** (SPEC ch. 10), `Nordvarr\Export\LlmContext`:
  - the "Copy for LLM" button on the object card leads to a page with Markdown and a "Copy" button (`navigator.clipboard`, without HTTPS the text is selected for manual copying),
  - content: identification, type, tags, external id, status and reason, open alerts with the probable cause, a 24 h check summary from hourly aggregates, neighborhood up to 2 steps in both directions (max. 30, sorted unhealthy -> recently changed -> closer), events from the neighborhood over 7 days (max. 30), attributes including conflicts, documentation (max. 4000 characters),
  - size at most 24 kB for both Markdown and JSON (documentation, events, neighborhood, attributes are shortened in turn),
  - API `GET /api/v1/objects/{id}/context` (JSON, or Markdown with `?format=markdown`), new scope `context:read`.
- **Strict masking** (`SecretMasker::maskStrict`): attribute keys with `pass`, `pwd`, `secret`, `token`, `key`, `private`; credentials in URLs; passwords in DSNs; `key: value` pairs with a secret key in text (also `heslo:`); `Bearer` tokens; PEM private key blocks; high-entropy strings (at least 20 characters, lowercase and uppercase letters and digits, entropy at least 3.5 bits per character) and hex strings longer than 40 characters. UUIDs, commit hashes, paths and host names stay.
- **Account:** password change in Settings -> Account (verification of the current password with attempt limiting, other logins are invalidated, audit) and the command `php bin/nordvarr.php reset-password <e-mail>` for a forgotten password.
- **Security pass** according to ch. 11 (see below) with fixes and regression tests.
- **`docs/NAVOD.md`:** requirements, installation (including Apache), cron and worker, first steps, updates, backups and restore, forgotten password, API with curl (upsert, events, context, heartbeat, error codes), configuration, security, troubleshooting. The curl examples are verified against a running instance.
- **Release 0.1.0:** `VERSION` is `0.1.0`. The release zip is built by CI after pushing the tag `v0.1.0` (the tag should be created only on `main` after the phases are merged).

Verification:

- canary tests: 14 planted secret values (attribute, URL, DSN, random token, hex secret, `password:` and `Bearer` in documentation, PEM key, `aws_secret_access_key`, event, description, check URL, reason detail, neighbor description) do not appear in Markdown, JSON, on the export page or in the API; the test also checks that ordinary text (commit, path, host name) is not masked. Verified in reverse too: after disabling entropy-based masking the test fails. `LlmExportTest`, `SecretMaskerTest`,
- export content, neighborhood sorting, 24 kB limit, empty object, API with scope (200, 401, 403, 404, Markdown): `LlmExportTest`,
- password change, invalidation of other sessions, attempt limiting, CLI reset: `AccountTest`,
- CSRF on all 34 UI POST routes (the list is read from the source code, a new route is tested automatically): `AppTest`,
- fixes from the security pass: `SecurityPassTest`, `MarkdownTest`,
- e2e `export.spec.js`: button on the card, Copy to clipboard (clipboard content = export text), secret attribute password masked,
- tests green: `composer test` (419 tests), `npm run e2e` (27 tests).

#### Security pass (SPEC ch. 11)

The code went through an independent review (a separate read-only agent) against every item of chapter 11 and common web application bugs.

| Item of ch. 11 | Status |
|---|---|
| Argon2id passwords (otherwise bcrypt) | OK; a password longer than 4096 bytes is now not verified (CPU protection) |
| Session: HttpOnly, SameSite=Lax, Secure on HTTPS, new id after login, invalidation after password change | OK; the option to change the password was missing -> **added** (Settings -> Account, CLI `reset-password`) |
| CSRF on every POST | OK (check in `App` before routing, `hash_equals`); new test of all POST routes |
| Login limiting | OK; old attempt records are now deleted by the worker (after 2 days) |
| API tokens as prefix + HMAC-SHA256 | OK |
| Data directory outside the webroot, `.htaccess` | OK; **fixed**: files were created with default permissions (often world-readable), now `umask 0027`, `config.php` gets permissions 0640 even before the secret is written |
| CSP, X-Frame-Options, Referrer-Policy, nosniff headers | OK on all responses (HTML, JSON, API, heartbeat, installer, errors, redirects) |
| Audit | **added**: tag removal, object archiving and restoring, password change and reset |

Other findings and fixes:

- **Redirect to a foreign site via the language switcher** (medium to low severity): the path `/\evil.example` passed as a local address and the browser reads it as `//evil.example`. Fixed: paths with a backslash or control characters are replaced with `/`.
- **A Markdown link `/\host`** was considered relative. Fixed.
- **`install.php` without exception handling:** an unexpected error could show a PHP dump. Fixed (`display_errors` off in both `index.php` and `install.php`, error page).
- **Check import** stored the configuration from the file without normalization. Fixed (the validated configuration is stored).
- **Import:** backups before import are now rotated (the last 10) and a file that is too large (over `post_max_size`) reports a clear 413 error instead of a CSRF error.
- Verified with no findings: SQL only via parameters, escaping of output and Markdown, viewer role without POST, heartbeat token (128 bits), channel secrets encrypted and never printed, API without session and cookies, UI rejects bearer tokens.

Left unchanged (described in `docs/NAVOD.md`, ch. 10): HSTS belongs to the web server configuration; behind a reverse proxy the application sees the proxy IP and does not recognize HTTPS; the audit table has no retention; checks and webhooks may target internal addresses (intentional, only the admin sets them).

### F4 Topology and diagnostic card (branch `faze-4-topologie`, follows `faze-3-alerty`)

- Topology page (`/topology`) with a graph in Cytoscape.js and dagre layout (libraries with a pinned version in `public/assets/vendor/`, entry in `VERSIONS.md`):
  - "object neighborhood" mode (1 or 2 steps, both directions, both kinds of relations); the default center is the object with the oldest unsuppressed `unhealthy` alert, then `degraded`, otherwise the object with the most relations,
  - "by filter" mode (type, tag, status), max. 300 nodes, above the limit a prompt to narrow the filter,
  - node: a circle with a ring of check status shares, the name and a second line (latency, status or type), glow for `unhealthy`/`degraded`, dashed for paused objects and objects in maintenance, highlighted center,
  - edges: `depends on` with an arrow, `contains` dashed, path to the probable cause orange and thicker,
  - left panel: view form and a list of nodes sorted from the worst status; right panel: card of the selected node (status and reason, probable cause, tags, checks with latency, incidents over 90 days, recent changes including neighbors, documentation),
  - clicking a node opens the card in the panel, a double click or "Open card" leads to the object detail, hovering highlights the node's neighborhood,
  - graph data refresh via `/topology/data` with ETag (15 s / 60 s / slowdown on errors), status change without rebuilding the graph, structure change with a new layout,
  - light and dark appearance, map across the full window width, stacked on mobile.
- Diagnostic header of the object card (SPEC ch. 9 item 3): status and reason, probable cause, the last change on the object and direct neighbors over 24 h, dependency status, number of alerts over 90 days. It refreshes via `/objects/{id}/diagnosis` with ETag. Below the card a new section "Incident history (90 days)" and a "Show in topology" button.
- ETag refresh is shared (`Nordvarr\Ui\JsonFragment`, in `app.js` for every element with `data-refresh-url`).
- Fix: the detail of a missed heartbeat showed the time of the last signal in UTC in ISO format, now it is in the time zone from the settings.

Verification:

- neighborhood to depth 1 and 2, trimming a large neighborhood with a warning, cause edge, node data, default center, filter by status, 300 node limit, page, JSON endpoints with ETag and 304, node card with documentation and incidents, diagnostic header: `TopologyTest`,
- release zip content including the graph libraries: `ReleaseBuildTest`,
- e2e acceptance `topology.spec.js`: "the database goes down -> the website is unhealthy, the website notification is suppressed (the local webhook receiver gets only the database), the website card shows the database as the probable cause and the last deploy in the neighborhood"; further the neighborhood graph with the cause edge, clicking a node in the graph and in the list, filter mode, ETag, automatic map refresh, no console errors (CSP); `mobile.spec.js`: topology on mobile without a horizontal scrollbar,
- appearance checked on screenshots with demo data (light and dark mode),
- tests green: `composer test` (391 tests), `npm run e2e` (26 tests).

### F3 Alerts and notifications (branch `faze-3-alerty`, follows `faze-2-kontroly`)

- Migration `0004_alerty.sql`: alerts (one open per check), channels and their rules, a sending queue with attempt history, maintenance windows.
- Alerts: opening on `unhealthy` (on `degraded` only with `notify_on_degraded`), closing and a recovery notification on return to `healthy`, reminders (default 24 h, 0 = off).
- Suppression by one step (`depends_on` before `contains`, then the earlier opened alert, then the smaller id; cycles do not matter). For a suppressed alert the probable cause is visible: alert list, alert detail, object header, overview.
- Maintenance windows (object or tag, optionally with checks paused). Nothing is sent during the window, after it ends at most one notification goes out for each alert that is still open.
- Channels with a shared interface `Nordvarr\Notify\Channel`:
  - e-mail: own SMTP client with SSL/STARTTLS, AUTH LOGIN/PLAIN, primary and fallback server,
  - Telegram,
  - webhook with `X-Nordvarr-Signature` (HMAC-SHA256),
  - rules by tags, secrets encrypted, "Send test" button.
- `deliveries` queue with retries after 1, 5, 15 and 60 minutes, then `failed`; attempt history in the UI.
- UI:
  - Alerts page (filter, detail with deliveries, maintenance windows, sent notifications),
  - channel and notification settings (reminders, application URL, notification language).
- After the checks the worker evaluates alerts and sends the queue (within the 50 s limit).

Verification:

- alert logic (opening, recovery, degraded, suppression via dependency and containment, only by one step, candidate order, cycles, maintenance, reminders, channel rules, delivery retries, deleted and disabled check): `AlertEngineTest`,
- e-mail against a local fake SMTP server (STARTTLS + AUTH PLAIN, implicit TLS + AUTH LOGIN, certificate verification, fallback server, both servers unavailable, rejected recipient, refusal to log in without encryption), Telegram and webhook against a local mock endpoint: `ChannelsTest`,
- channel UI, test sending, maintenance windows, alerts page and settings: `AlertsUiTest`; the whole chain worker -> alert -> e-mail via CLI: `CliTest`,
- e2e `alerts.spec.js`: webhook channel with a test and signature verification, database outage (notification only about the database, website with the probable cause), maintenance window,
- tests green: `composer test` (375 tests), `npm run e2e` (20 tests).

### F2 Checks (branch `faze-2-kontroly`, follows `faze-1-inventar`)

- Migration `0003_kontroly.sql`: checks, raw results (retention 14 days), hourly aggregates (retention 400 days), current status, HTTP response cache.
- All 6 check types:
  - `http`: GET/HEAD, expected codes, searched text, latency limit, max. 5 redirects, max. 1 MB, TLS verification,
  - `tcp`, `tls_expiry` (thresholds 21/7 days),
  - `ping` via the system command (`tool_missing` when it is missing or lacks permissions),
  - `domain_expiry` via RDAP with the IANA bootstrap cached for 7 days,
  - `heartbeat` with the URL `/hb/<token>` (both GET and POST).
- Statuses, reason codes and detail (max. 300 characters). Transition to a worse status only after the set number of failures, return after the first success, a late result only into history. The object status is the worst status of its checks, an archived object and a disabled check are `paused`.
- Worker (`php bin/nordvarr.php worker`): lock, 50 s limit, `http`/`tcp`/`tls_expiry` in parallel via `curl_multi` (max. 20), ping in parallel, RDAP sequentially. Once an hour aggregation and retention, recording the time of the last run.
- UI:
  - overview: status summary, not working now, changes over 24 h, expiring soon, warning about a worker that is not running; refresh via JSON with ETag (15 s / 60 s / slowdown on errors),
  - checks on the object detail: adding, editing, pausing, running on the next run, deleting with audit,
  - check detail: last 50 results, hourly summaries over 48 h, 24 h summary, heartbeat URL,
  - status and reason in the object header, dependency status, status column and filter in the inventory.
- Checks are part of the inventory export and import.

Verification (SPEC F2: tests against local fixture servers, RDAP from fixture JSON, no network calls):

- HTTP 200, 500, 404, slow response (latency and timeout), redirects (3 OK, 7 too many), closed port, missing text, response over 1 MB, untrusted certificate: `CurlRunnerTest`,
- TLS certificate with near expiry (90, 10 and 3 days) and unavailable TLS port: `CurlRunnerTest`,
- RDAP from fixture JSON (valid, expiring soon, expired, without expiry date, 404, bootstrap cache, longest suffix): `RdapRunnerTest`,
- ping via a fake command (reply, no reply, DNS, permissions, hung process, missing command): `PingRunnerTest`,
- worker, thresholds, pausing, heartbeat, aggregation and retention: `WorkerTest`, `StateMachineTest`, CLI `CliTest`,
- UI and overview with ETag: `ChecksUiTest`, e2e `checks.spec.js` (worker run via CLI over the installed zip),
- tests green: `composer test` (306 tests), `npm run e2e` (16 tests).

### F1 Inventory (branch `faze-1-inventar`, follows `faze-0-kostra`)

- Migration `0002_inventar.sql`: objects, external ids, attributes (with source and JSON flag), relations (`depends_on`, `contains`), tags, documentation with immutable revisions, events, API tokens, API limits.
- UI:
  - inventory with a filter (text, type, tag, active/archived) and bulk tag adding,
  - object detail: attributes with a marked conflict (the manual value wins), tags, external ids, relations in both directions with a cycle check for `contains`, documentation in safe Markdown with revisions, timeline of the object and its direct neighbors, manual events, archiving and deletion,
  - settings: API tokens (shown only once, revocation), inventory export and import in JSON, time zone, cron line.
- API: `POST /api/v1/objects/upsert` (idempotent, 422 with a list of missing external_id, response with a summary of changes), `POST /api/v1/events` (idempotent), bearer tokens with scope, limit of 120 requests per minute, without session and cookies.
- Own safe Markdown renderer (`src/View/Markdown.php`), masking of secrets in the export (`src/Security/SecretMasker.php`).
- Demo data: `php bin/nordvarr.php demo-data` (40 fictitious objects, 8 events).
- Audit: tokens, deletion of objects, attributes and relations, time zone change, export and import.

F1 acceptance verified by tests:

- repeated upsert of the same input duplicates nothing: `UpsertTest`, `ApiTest`, e2e `inventory.spec.js` (over real HTTP),
- an event shows on the timeline of the object and of its neighbor: `EventIngestTest`, `InventoryUiTest`, e2e,
- inventory export and import gives the same result: `TransferTest` (demo data + canary secrets), `InventoryUiTest`, e2e via file download and upload,
- tests green: `composer test` (222 tests), `npm run e2e` (13 tests).

### F0 Skeleton (branch `faze-0-kostra`)

- Repository structure according to SPEC ch. 4, own PSR-4 autoloader (`src/autoload.php`), no runtime dependencies.
- Front controller `public/index.php`, router with parameters, pretty URLs via `public/.htaccess` and fallback `index.php?r=...`.
- PHP templates with mandatory escaping (`View::e()`, `View::t()`), CZ/EN layout, bottom navigation bar on mobile.
- Translations `lang/cs.php` and `lang/en.php`, a test checks completeness and matching parameters.
- SQLite in WAL via PDO, repository layer, numbered migrations with a backup (`VACUUM INTO`) before running. Migrations run on the first request of a logged-in admin or with the command `php bin/nordvarr.php migrate`.
- Installer `public/install.php`: PHP and extension check, data directory (default `nordvarr-data` next to the application), `.htaccess` in the data directory, database, admin, `config.php` with a random secret, cron line, check of data accessibility from the web, locking after installation.
- Login and logout: Argon2id, new session id after login, CSRF on every POST, attempt limiting (delay from 5 failures, 15 min lock from 10 failures, per account and IP), generic error message, session invalidation after password change, login and logout audit.
- Security headers (CSP without inline scripts, X-Frame-Options, Referrer-Policy, nosniff), UI rejects bearer tokens.
- CLI `bin/nordvarr.php` (`worker` as a placeholder with a lock, `migrate`, `version`).
- `bin/build-release.sh` -> `dist/nordvarr-<version>.zip` with a `nordvarr/` directory, without `config.php`, data and development files.
- Tests: PHPUnit (unit + integration, `composer test`), Playwright (`npm run e2e`), CI in `.github/workflows/ci.yml` (tests on push, release zip on tag `v*`).
- `scripts/setup-cloud.sh` for preparing the cloud VM.

F0 acceptance verified by tests:

- clean installation into an empty directory via `install.php`: e2e `install.spec.js` (installs from the unpacked zip),
- login and logout: e2e `app.spec.js`, integration `AppTest`,
- the built zip can be installed again after unpacking into another directory: e2e builds the zip and installs from it into a new temporary directory, `ReleaseBuildTest` checks the zip content,
- tests green (state at the end of F0): `composer test` (128 tests), `npm run e2e` (8 tests).

## In progress

Nothing.

## Known issues and notes

- The cloud VM has no access to PPA ondrej/php (the proxy returns 403 for `ppa.launchpadcontent.net`). `scripts/setup-cloud.sh` therefore falls back to PHP 8.4 from the official Docker image (`mirror.gcr.io/library/php:8.4-cli`) and runs it natively via patchelf. Docker Hub (`registry-1.docker.io`) returned 429, the mirrors work. If access to `keyserver.ubuntu.com` and `ppa.launchpadcontent.net` were allowed, the script uses the standard packages.
- Composer in the cloud VM downloads packages via git (the GitHub API `api.github.com` and `codeload.github.com` return 403), it works, just more slowly.
- The check of data directory accessibility from the web makes an HTTP request from the server to itself; on hosting where the server cannot see itself, only a generic warning remains.
- The tested run is on the PHP built-in server; on a real Apache the `.htaccess` (mod_rewrite, the `NORDVARR_REWRITE` variable, passing the Authorization header for the API) has not been verified yet.
- After uploading a new version, migrations run only on the first request of a logged-in admin (SPEC ch. 12). Until then the API returns 500 because the new tables do not exist yet. Solution: after an update log in or run `php bin/nordvarr.php migrate` (described in `docs/NAVOD.md`).
- The object list in the relation target selection on the object detail contains all objects; for the target size (low hundreds) this is enough, for more objects a search would be needed.
- Management of additional users in the settings is missing, the MVP has one admin; the password can be changed in Settings -> Account (see ROZHODNUTI F1 item 14 and F5).
- The error detail of network checks takes the English text from the curl library (e.g. "Failed to connect ..."); the reason code is always from a fixed list.
- The 24 h summary in the check detail is computed from hourly aggregates, the current hour appears in it only after the worker's hourly maintenance.
- The `tls_expiry` check supports only direct TLS (e.g. 443, 465, 993), not STARTTLS.
- A DNS error in curl-based checks is not covered by an automated test (it would require a real DNS query).
- Alerts are evaluated when the worker runs, so a heartbeat signal or a manual change is reflected in alerts and notifications within a minute at the latest.
- Real sending via public SMTP servers, Telegram and foreign webhooks has not been tested (only local fake servers); in production I recommend using "Send test" after setting up a channel.
- Changing `app_secret` in `config.php` makes it impossible to decrypt the channel secrets, they then need to be entered again.
- The topology map shows status, check latency, alerts and causes. Metrics such as CPU, RAM or GPU would need a host agent, which is outside the MVP scope (SPEC ch. 14).
- The graph layout is recomputed on every load (manual positions are not stored according to SPEC). For large graphs in filter mode (over 24 nodes) a force-directed layout is used instead of dagre, see ROZHODNUTI F4 item 3. With hundreds of nodes the labels are small, zooming in is needed.
- Entropy-based masking is a heuristic: it does not recognize short or dictionary secret values in text without a key (e.g. "the password is garden"). Attributes with a sensitive key and `key: value` pairs are always masked.
- Audit records (including failed logins) are not deleted; with long-term password guessing the `audit_log` table grows. The login attempts table is cleaned by the worker.
- Agent: Linux only with bash 4.4+. Without root privileges and without `smartmontools` the disk status is missing (the card says so). Virtual disks in a VM usually have no SMART. Disks behind a RAID controller require a special device type for `smartctl` (`-d megaraid,N` etc.), which `smartctl --scan` sometimes does not offer.
- Agent: archived containers are still shown in the object neighborhood in the map (as archived they have the status paused, the last check result is `container_missing`), just like other archived objects with relations.
- Agent: problem descriptions (e.g. "remapped sectors: 1872") are stored in Czech even for the English UI, just like the details of other checks.
- 3D view: labels overlap when there are many objects on one floor (zooming in or Spread floors helps). Lines in WebGL always have a width of 1 px, so the path to the cause differs only by color. Performance is verified on demo data (40 objects), not on hundreds.
- Cytoscape inserts its own `<style>` into the page, which CSP blocks. This is worked around with an empty element with the id the library looks for and a rule in `app.css` (ROZHODNUTI F4 item 7). When updating the library this needs to be verified (an e2e test watches for console errors).

## Next step

- Release 0.18.0. Further candidates: notifications of critical events to channels, events in the topology map, NAVOD in English.
- Further work based on the owner's experience with real data. Candidates: audit retention, verification of `.htaccess` on a real Apache, agent for other platforms.
