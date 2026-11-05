# Nordvarr - rules for the agent

## At the start of every session

1. Read `SPEC.md` (the source of truth, in Czech) and `docs/STAV.md` (work status). If `docs/STAV.md` does not exist, you are in phase F0.
2. Find the next unfinished phase or request and work only on it. Do not start the next phase without being asked.

## Environment (cloud VM)

- You need PHP 8.4 with the extensions pdo_sqlite, curl, openssl, mbstring, plus Composer, Node.js LTS and Playwright with Chromium.
- Install whatever is missing (on Ubuntu, PHP 8.4 from the ondrej/php PPA). Write every command you needed for the installation into `scripts/setup-cloud.sh`, so the script can be used as the environment setup for the next session. The script must be idempotent.
- If the installation is not possible because of network restrictions, stop and state in the final summary exactly which network access has to be allowed.
- The code must not use anything newer than PHP 8.4.

## Git and delivery

- Work on a new branch `faze-<N>-<short-description>` (for example `faze-0-kostra`), never directly on `main`.
- Commit continuously after logical steps, with short commit messages.
- At the end of the session push the branch. Open a pull request to `main` if the environment allows it; if not, say so in the summary.
- The PR description is in English: what is done, how it was tested, what was decided differently from SPEC, known issues, what comes next. The final summary for the owner is in Czech.
- Merge a PR only when the owner says so ("slouč").

## Tests

- Before delivery run `composer test` and `npm run e2e`. Both must pass.
- Never delete, skip or weaken tests just to make them pass. If a test fails because of the environment, say so in the summary.
- No calls to real external services in tests (SMTP, Telegram, Discord, RDAP, third-party websites). Use local fixture servers and mocks.
- Tests work in temporary directories and clean up after themselves.

## Code rules

- PHP with `declare(strict_types=1);`, PSR-12 style, namespace `Nordvarr\`.
- No framework and no runtime dependencies from Composer. Composer is only for development tools.
- Database access only through the repository layer and prepared statements. No SQL built from user input.
- All HTML output goes through the escaping function. No inline scripts and no inline event handlers (CSP).
- No CDN. JavaScript libraries only with a pinned version in `assets/vendor/` and an entry in `assets/vendor/VERSIONS.md` (version, source, licence).
- When in doubt, choose the simpler solution. Add abstractions only where SPEC explicitly asks for them (repository layer, notification channel interface).
- `config.php` and the data directory are in `.gitignore`. Only `config.example.php` is in the repository.

## Data and privacy

- Never put secrets, tokens or passwords into the repository.
- Sample and test data only with fictitious names (`example.com`, `example.net`) and documentation IP ranges (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`). Never real server or domain names of the owner.

## Texts and typography

- Project documentation (README, `docs/`) and PR descriptions are in English (owner's decision, 2026-11-05). `SPEC.md` stays in Czech.
- The user interface is bilingual: Czech in `lang/cs.php`, English in `lang/en.php`; every new text needs both.
- Code comments: keep the language of the surrounding file (most existing comments are in Czech).
- Use only keyboard characters: no em dash or en dash (use a hyphen with spaces, a comma or a colon instead), straight quotes, a straight apostrophe, three dots as three dots, an arrow as `->`. Czech texts with diacritics. `TypographyTest` checks this.

## Progress documentation

- `docs/STAV.md`: update at the end of every session: completed phases, work in progress, known issues, next step.
- `docs/ROZHODNUTI.md`: every decision that differs from SPEC or that SPEC leaves open (date, what, why).
- Do not change `SPEC.md`. The only exception is fixing an obvious mistake, which you mention in the summary. The file names `docs/STAV.md`, `docs/ROZHODNUTI.md` and `docs/NAVOD.md` stay as they are because SPEC refers to them.

## What not to do

- No deployments to servers, no paid services, no calls to LLM APIs.
- Do not add features from the chapter "Mimo rozsah MVP" (out of scope for the MVP) in SPEC.
