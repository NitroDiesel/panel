# PLAN — NitroDiesel/panel improvements

Base: `pterodactyl/panel` v1.15.1 (Laravel 12, PHP 8.2/8.3, React 16 + Tailwind 3, xterm 4, Yarn 1).
Fork branch: `nitro/main`. Upstream target branch: `1.0-develop`.

**Goals:** efficiency, reliability, security, and a UI that's intuitive for new users without slowing down advanced ones, delivered as small changes that can merge upstream.

**Status:** spec only. Nothing below is implemented yet. Last checked against the code at `e98678c5a` (2026-10-15).

---

## Ground rules

- **One PR = one concern.** Every item has an ID (e.g. `1.2`) and must rebase onto `1.0-develop` by itself, unless its `Depends` line says otherwise.
- **Upstream defaults don't change unless it's a clear security fix.** Behavior changes go behind an env/config flag that defaults to upstream behavior. Fork-only defaults live in fork config (`deploy/`), not in code.
- **No new runtime dependencies** unless an item explicitly says so.
- **Tests ship with the change.** PHP: `tests/Unit` or `tests/Integration` (PHPUnit). TS: Jest. Every PR must pass `php-cs-fixer`, `phpstan`, `yarn tsc`, `yarn lint`, `yarn test` and `yarn build:production`. *Today CI does not run `phpstan` or `yarn test`; item 2.6 adds them.*
- **Fork-only material** (`branding/`, `deploy/`) lives in paths upstream never touches, so merges stay conflict-free.
- **Do the cheap work first:** P0 items are small, low-risk fixes for real problems and ship before anything else.

**Priority key:** **P0** = security or correctness problem, ship first · **P1** = core goal of this fork · **P2** = valuable polish · **Backlog** = needs design and upstream discussion first.
**Size key:** XS < 50 LOC · S < 200 · M < 600 · L = split it further.

---

## Verified findings

Each claim was checked against the code at `e98678c5a`.

| # | Area | Current state | File |
|---|---|---|---|
| F1 | Secrets in logs | Entrypoint `echo`s the generated `APP_KEY` and `HASHIDS_SALT` to stdout | `.github/docker/entrypoint.sh` |
| F2 | Release workflow | Last commit added a step that dispatches to `pterodactyl/pterodactyl-cdn` using `secrets.CDN_DISPATCH_TOKEN`. There's no repository guard, so **every fork release job fails at that step** (the secret is empty) | `.github/workflows/release.yaml` |
| F3 | Unthrottled endpoint | `POST /auth/password/reset` sits outside the `throttle:authentication` group | `routes/auth.php` |
| F4 | Container runs as root | supervisord (`user=root`), php-fpm master, nginx master and crond all run as root; `chmod 777 -R bootstrap storage`; there's no `.dockerignore` | `Dockerfile`, `.github/docker/supervisord.conf` |
| F5 | Root filesystem writes at boot | Entrypoint writes `/app/.env` (symlink), `/var/log/*` and `/etc/nginx/http.d/panel.conf`, which blocks `read_only` | `.github/docker/entrypoint.sh` |
| F6 | Compose example | Inline DB passwords, `links:`, publishes 80/443, unpinned `mariadb:11` / `redis:alpine`, Redis has no auth, passes a MySQL-only `--default-authentication-plugin` flag | `docker-compose.example.yml` |
| F7 | No health endpoint | Probes have to hit `/`, which starts a session (a Redis write per probe) | `routes/`, `RouteServiceProvider` |
| F8 | Security headers | XFO, nosniff, deprecated `X-XSS-Protection: 1; mode=block`, `Referrer-Policy: no-referrer-when-downgrade`; no CSP, HSTS or Permissions-Policy | `app/Http/Middleware/SetSecurityHeaders.php` |
| F9 | Rate limits | Login **and** 2FA checkpoint share a 10/min-per-IP bucket. There's no per-account bucket and no per-user bucket for account-security actions. Per-server `ResourceLimit` already covers backups, databases, schedules, subusers, allocations, websocket and file pull | `RouteServiceProvider`, `app/Enum/ResourceLimit.php` |
| F10 | Session | `secure` cookie unset by default, 720-minute lifetime, `lax`. Password change already calls `logoutOtherDevices`; 2FA enable/disable does **not** regenerate the session | `config/session.php`, `AccountController`, `TwoFactorController` |
| F11 | API keys | `expires_at` column already exists (2023 migration) and the model is Sanctum's token model, but the client UI can't set it or show it. `last_used_at` is already displayed | `app/Models/ApiKey.php`, `AccountApiContainer.tsx` |
| F12 | Supply chain | No Dependabot/Renovate. Most actions are pinned by tag (only `softprops/action-gh-release` uses a SHA). No dependency audit, SBOM or image scan | `.github/` |
| F13 | Search hotkey | Only `metaKey + /` opens search, so Windows/Linux users have no shortcut | `components/dashboard/search/SearchContainer.tsx:13` |
| F14 | CSP blockers | Admin layout loads CSS/fonts from `cdnjs.cloudflare.com` and IE-only shims from `oss.maxcdn.com`. 28 Blade views contain inline `<script>` | `resources/views/layouts/admin.blade.php` |

**Already done upstream, so dropped from the previous draft:** logging failed logins and 2FA failures (`auth:fail` via `AuthenticationListener`); persisting console command history (`usePersistedState`); the console search bar (`Ctrl/⌘+F`, `xterm-addon-search-bar`); confirming Kill (it's only offered while `stopping`, behind a modal); "log out other sessions" on password change; showing API key `last_used_at`; adding a `Retry-After` header on 429 (Laravel's `ThrottleRequests` sends it).

---

## Phase 0 — Quick wins (P0)

Each is an XS PR with no behavior change for correctly configured installs. Ship all four before anything else.

### 0.1 Guard the CDN dispatch step to the upstream repo · P0 · XS · Upstream: yes
**Why:** F2. Fork releases fail, and a misconfigured fork could hand its tags to upstream's manifest.
**Change:** add `github.repository == 'pterodactyl/panel'` to the step's `if:`. Replace the `contains(ref_name, 'rc'|'beta'|'alpha')` prerelease check with an anchored match on a `-` suffix (e.g. `!contains(github.ref_name, '-')`). Today's substring check would treat a tag like `v1.12.0-arc` as a prerelease.
**Acceptance:**
- [ ] A tag push on the fork completes the release job green and skips the dispatch step.
- [ ] The workflow logic for `pterodactyl/panel` is unchanged for `v1.x.y` tags and still skips `v1.x.y-rc.1`.

### 0.2 Stop logging generated secrets · P0 · XS · Upstream: yes
**Why:** F1. Container logs often ship to third-party aggregators.
**Change:** remove the `echo` lines that print `APP_KEY` and `HASHIDS_SALT`, and print `Generated app key.` instead. Generate the key as `base64:` + `base64_encode(random_bytes(32))` via `php -r` (the format `key:generate` uses) instead of 32 alphanumerics from `tr -dc` over urandom. `artisan key:generate --show` can't be used: `SettingsServiceProvider` needs the encrypter, so artisan won't boot without a key. Only affects first boot (no existing `var/.env`).
**Status:** done in `9d7645eb2` (shipped with Phase 1, which depends on it).
**Acceptance:**
- [x] First boot with an empty `var/` volume: `docker logs` contains neither value (grep for the values in `var/.env`). *Checked in both root and non-root mode.*
- [x] The generated `APP_KEY` starts with `base64:` and the panel encrypts and decrypts (log in, enable 2FA). *Login and 2FA enable over HTTP on the compose stack; `totp_secret` stored encrypted.*
- [x] Booting with an existing `var/.env` leaves it byte-for-byte unchanged. *sha256 identical across a legacy-mode restart.*

### 0.3 Throttle password reset submission · P0 · XS · Upstream: yes
**Why:** F3. Lets an attacker hammer reset tokens without limit.
**Change:** move `POST /auth/password/reset` into the `throttle:authentication` group in `routes/auth.php`.
**Acceptance:**
- [ ] Integration test: the 11th request in a minute from one IP returns `429` with `Retry-After`.
- [ ] The existing reset flow test still passes.

### 0.4 Security header defaults (no CSP) · P0 · XS · Upstream: yes
**Why:** F8. These changes are safe for every install.
**Change** in `SetSecurityHeaders::$headers`: set `X-XSS-Protection: 0`; `Referrer-Policy: strict-origin-when-cross-origin`; add `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`. Keep XFO and nosniff. COOP, HSTS and CSP stay out of this PR (see 2.1).
**Status:** done in `03d448cd6` (shipped with Phase 2 as the prerequisite of 2.1).
**Acceptance:**
- [x] Unit test asserts the exact header set on a normal response. *Fails on the pre-change code.*
- [x] A header that's already set on the response is still not overwritten (existing behavior).

---

## Phase 1 — Operable, hardened deployment (P1)

Order: **1.1 → 1.2 → 1.3.** The compose stack's acceptance criteria (read-only rootfs, non-root, `/up` healthcheck) depend on the first two. The previous draft had this order backwards.

### 1.1 `/up` health endpoint · P1 · S · Upstream: yes
**Change:**
- `GET /up`, registered in `RouteServiceProvider` **outside** the `web`/`api` groups: no session, no CSRF, no auth, no throttle. It must be registered before any SPA catch-all.
- Returns `200 {"status":"ok"}` when the DB (`select 1`) and cache (`get` of a fixed key) both respond, otherwise `503 {"status":"fail"}`. No versions, hostnames or error text in the body.
- Opt-in `DB_CONNECT_TIMEOUT` (sets `PDO::ATTR_TIMEOUT`; unset = upstream behavior). Needed because `SettingsServiceProvider` queries the DB on **every** request before any controller runs, so without it an unreachable DB holds each request, health probes included, for `default_socket_timeout` (60 s) and can exhaust php-fpm workers. A timeout private to the health check doesn't help for the same reason.

**Status:** done in `4961fc551` (endpoint) and `df0df98d6` (`DB_CONNECT_TIMEOUT`).
**Known limitation:** when Redis is down, `AppServiceProvider::boot()` (`Cache::remember('git-version')`) throws before the controller runs, so `/up` returns a generic **500**, not 503, after predis's 5 s connect timeout. Probes still read it as unhealthy. Making boot tolerate a dead cache is out of scope here.
**Acceptance:**
- [x] Integration tests: 200 when healthy; 503 when the DB connection throws (mocked); 503 when the cache throws. *Plus a POST → 405 routing test. Mutation check: removing the checks fails 2 tests.*
- [x] The response has no `Set-Cookie` header, and `redis-cli dbsize` doesn't grow across 100 probes. *dbsize 2 → 2; for contrast, `/auth/login` sets 2 cookies.*
- [x] With the DB container stopped, the 503 arrives within 5 s (the healthcheck timeout). *4.0–4.1 s with `DB_CONNECT_TIMEOUT=2` (two attempts: settings load, then the check). Without it: no response within 15 s.*

### 1.2 Non-root, read-only-capable image · P1 · M · Upstream: yes (open a Discussion first) · Depends: 0.2, 1.1
**Key design decision:** non-root is **opt-in at runtime**, not forced with a Dockerfile `USER` line. The entrypoint branches on `id -u`:
- **uid 0 (legacy mode):** today's behavior, unchanged. Ports 80/443, certbot when `LE_EMAIL` is set, and crond for certbot renew. This keeps `docker-compose.example.yml` working as is.
- **non-root mode:** nginx on `:8080`, no certbot, no crond. supervisord, nginx, php-fpm and the queue run as the `nginx` user, and nothing is written outside `/tmp`, `/app/var` and `/app/storage`.

**Change:**
1. Add a `.dockerignore`: `node_modules`, `vendor`, `.git`, `.env*`, runtime files under `storage/`, `tests`, built `public/assets`. *Not `*.md`: that would drop `LICENSE.md`, which MIT requires shipping with copies. `.gitignore` used to ignore `.dockerignore` itself; that line is removed.*
2. Add a supervisord `[program:scheduler]` that runs `php artisan schedule:work` in both modes, replacing the root crontab's `schedule:run` line.
3. Build time: create the `/app/.env -> /app/var/.env` symlink and an `nginx-unprivileged.conf` (listen 8080, pid and temp paths under `/tmp`). Replace `chmod 777` with `chown nginx:nginx` on `storage`, `bootstrap/cache` and `var` only.
4. supervisord: socket, pid and logs under `/tmp`, with child logs going to stdout/stderr. *php-fpm keeps `user`/`group` (one config for both modes; non-root only logs a NOTICE) and now logs to stderr via a `[global]` section.*
5. Entrypoint in non-root mode: skip the `mkdir /var/log/*`, the certbot/nginx config copy and crond. Fail fast with a clear message if `/app/var` or `/app/storage` isn't writable. *The `.env` link is only recreated if it isn't already the baked symlink.*
6. `HEALTHCHECK` that runs `.github/docker/healthcheck.sh`: port 8080 when non-root, 80 when root, `https://127.0.0.1` (no cert check) when `LE_EMAIL` is set. *The LE variant is unverified: there's no real certificate here.*
6b. *Laravel's package/service manifests go to `/tmp` (`APP_PACKAGES_CACHE`/`APP_SERVICES_CACHE`), since `bootstrap/cache` is in the read-only image.*
7. `APP_AUTO_MIGRATE` (default `true`) gates `migrate --seed --force` on boot.
8. Look up the `nginx` uid/gid in the image (Alpine is normally `100:101`; don't hardcode `101`) and document it in `.github/docker/README.md`.

**Status:** done in `94bad2e1d` (`.dockerignore`) and `799808981` (image). `nginx` is uid `100`, gid `101`. In read-only mode `php artisan tinker` needs `-e HOME=/tmp` (documented).
**Acceptance:**
- [x] `docker run --read-only --tmpfs /tmp --user nginx -v var:/app/var -v storage:/app/storage …` boots to healthy. *About 40 s against MariaDB 11.4 + Redis 7.*
- [x] In that container, `ps -o user,comm` shows no `root` processes.
- [x] The unchanged `docker-compose.example.yml` still boots, serves on 80, and the LE path still runs when `LE_EMAIL` is set. *Run with an override that only swaps the image and redirects the `/srv` bind mounts. The LE path produces output identical to the v1.15.1 image up to the ACME call.*
- [x] The scheduler runs in both modes: a scheduled task fires within 2 min of boot (check `activity_logs` or a test schedule). *`p:schedule:process` runs under `schedule:work` as `nginx` in both modes.*
- [x] The image is ≤ the v1.15.1 image size + 5%, and the build context is < 50 MB with `.dockerignore`. *322 MB vs 340 MB (`ghcr.io/pterodactyl/panel:v1.15.1`); context 9.1 MB.*

### 1.3 Fork compose stack · P1 · M · Upstream: no (fork-only, `deploy/compose/`) · Depends: 1.2
**Files:** `compose.yml`, `init.sh`, `panel.env.example`, `README.md`, `verify.sh`. *Changed from separate db/cache env files: the DB password is needed by both `panel` and `database`, so `init.sh` generates all passwords once into compose's own `.env` (mode 0600, gitignored) and copies `panel.env.example` → `panel.env` for settings.*

**Spec:**
- Services: `panel` (fork image built from 1.2, running as `nginx`), `database` (`mariadb:11.4` LTS, pinned by digest), `cache` (`redis:7-alpine`, pinned by digest; see Open question 3).
- Networks: `backend` (`internal: true`) has `database`, `cache` and `panel`. `frontend` has `panel` only, since it needs outbound access to Wings, SMTP and reCAPTCHA. **Only the panel publishes a port:** `${PANEL_BIND:-127.0.0.1}:${PANEL_PORT:-8080}:8080`. TLS terminates at the user's reverse proxy. Document `TRUSTED_PROXIES` with Caddy, Traefik and nginx examples.
- Every service gets: an explicit non-root `user:`; `security_opt: [no-new-privileges:true]`; `cap_drop: [ALL]` with **no** add-backs (the README must justify any added cap); `read_only: true` plus `tmpfs` (`/tmp`; plus `/run/mysqld` for MariaDB); PID, memory and CPU limits (all under `deploy.resources.limits`: Compose 2.26 rejects `pids_limit` alongside them); `restart: unless-stopped`; `logging: { driver: local, options: { max-size: 10m, max-file: "3" } }`.
- MariaDB: `user: mysql`, `MARIADB_AUTO_UPGRADE=1`, `MARIADB_ROOT_HOST=localhost`, named volume `db-data`, no `--default-authentication-plugin`. Healthcheck: `healthcheck.sh --connect --innodb_initialized`.
- Redis: the password comes from env (`REDISCLI_AUTH`), never from argv. An ACL (`--user default reset on >… -flushall -config -debug -module`) denies those commands to the default user (`rename-command` is deprecated). **AOF persistence on** (`--appendonly yes`), not `--save ""` as first drafted: sessions and queued jobs live in Redis, and `--save ""` would contradict the "session survives restart" criterion below. Healthcheck: `redis-cli ping`.
- Panel: `depends_on: { database: { condition: service_healthy }, cache: { condition: service_healthy } }`. Healthcheck on `/up` (inherited from the image). Named volumes `panel-var` and `panel-storage`. `DB_CONNECT_TIMEOUT=2` (with 3 s, `/up` took 6.1 s with the DB down).
- README: setup in three commands, upgrades (pull, then `up -d`; `APP_AUTO_MIGRATE`), backup and restore (`mariadb-dump` plus the `panel-var` volume, which holds `APP_KEY`), and how to run Wings next to the stack. Wings must **not** join `backend`.

**Status:** done in `b637fde1d`. `verify.sh` runs 38 checks and passes on the stack as shipped. The queue check was shown to go red with the worker stopped.
**Not verified:** the Caddy, nginx and Traefik snippets in the README (no public host or certificates here). The `TRUSTED_PROXIES` = frontend-gateway guidance *was* verified: a login sending `X-Forwarded-For: 203.0.113.9` was logged with that IP.
**Acceptance** (automated in `verify.sh`, runnable in CI):
- [x] `docker compose config` validates. From a clean checkout with copied env examples, `up -d` reaches all-`healthy` in ≤ 120 s. *50 s from empty volumes (after `./init.sh`).*
- [x] `docker compose port database 3306` and `docker compose port cache 6379` return nothing, and `ss -ltn` on the host shows no 3306/6379 listener.
- [x] `docker compose exec database` can't reach the internet (internal network). The panel can reach DB, cache and an external host.
- [x] `docker inspect` on each service shows `ReadonlyRootfs=true`, `no-new-privileges`, `CapDrop=[ALL]`, empty `CapAdd`, non-root `User`, and memory/PID limits.
- [x] `php artisan cache:clear` and the queue worker still work under the Redis ACL. *`FLUSHALL`/`CONFIG` return NOPERM; a queued `cache:forget` job runs.*
- [x] After setup, `git status` shows no new tracked files, and `*.env` is ignored.
- [x] `p:user:make` → log in → `docker compose restart` → the session is still valid. *Tested with reCAPTCHA disabled via a scratch override; an unauthenticated control gets 401.*

---

## Phase 2 — Security hardening (P1/P2)

Each item is its own PR.

### 2.1 Content-Security-Policy and HSTS · P2 · M · Upstream: yes · Depends: 0.4
**Change:**
- `APP_CSP_MODE=off|report|enforce` (upstream default `off`; fork stack `report`). `APP_CSP_EXTRA_{SCRIPT,STYLE,IMG,CONNECT,FONT,FRAME}_SRC` let admins append sources. Reports go to `report-uri /csp-report`, which logs at a rate-limited debug level.
- A per-request nonce, exposed to Blade (e.g. `@cspNonce`) and to webpack (`__webpack_nonce__`), on **all** inline scripts. That includes every Blade view, not just `wrapper.blade.php`/`scripts.blade.php` (F14: 28 views).
- Baseline: `default-src 'self'; script-src 'self' 'nonce-…' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' data: https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self' wss: https:; frame-src https://www.google.com/recaptcha/; frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'`. `'unsafe-inline'` styles are needed by styled-components.
- Remove the IE-only `oss.maxcdn.com` shims from the admin layout instead of allowlisting them.
- HSTS: `Strict-Transport-Security: max-age=31536000` only when `$request->isSecure()` **and** `APP_HSTS=true` (default off). `Cross-Origin-Opener-Policy: same-origin` ships here too, behind the same mode flag (it can break reCAPTCHA popups).
- Split it into two PRs if review asks: (a) nonce plumbing with no headers, then (b) the header and modes.

**Status:** done in `46c83c067` (IE shims), `f41abfeeb` (CSP/HSTS/COOP) and `cceee10b7` (fork stack in `report` mode). Corrections to the spec above, all found by the browser check:
- **reCAPTCHA comes from `recaptcha.net`**, not `google.com`: the login form's `reaptcha` library hardcodes `https://recaptcha.net/recaptcha/api.js`. The baseline as drafted blocked reCAPTCHA, so login broke in `enforce` mode. `script-src`/`frame-src` now allow `recaptcha.net`, `www.recaptcha.net` and `www.google.com` under `/recaptcha/`, plus `www.gstatic.com/recaptcha/` for scripts.
- **`JavaScript::put()` (laracasts/utilities) echoes its own inline `<script>`**: 6 admin pages (node allocation/servers, server new/startup/manage, egg new). The static view scan can't see it. `Pterodactyl\Extensions\Laracasts\NonceViewBinder` replaces the package's binder and adds the nonce.
- **Plain-HTTP Wings nodes**: the console uses `ws://`, which `wss:` doesn't cover, so the console broke in `enforce` mode. `connect-src` now lists the exact `http://`/`ws://` origins of `scheme = http` nodes (cached for 60 s). Past 50 such nodes it falls back to `http: ws:` to keep the header within proxy buffer limits.
- No `__webpack_nonce__` was needed: chunks load as same-origin files, and styled-components' `<style>` tags are covered by `'unsafe-inline'` for styles.
- COOP is sent only in `enforce` mode.

**How it was checked:** `ContentSecurityPolicyTest` (14 tests: each mode, nonce rotation and matching, admin pages, injected JS variables, extra sources, an injection attempt via extra sources, HTTP nodes and the 50-node fallback, HSTS, the report endpoint, and a static scan that every inline `<script>` in the views uses `@cspNonce`). Plus headless Chrome (official Puppeteer image) against `php -S` with `APP_CSP_MODE=enforce`, recording `securitypolicyviolation` events and console errors.
**Acceptance:**
- [x] Feature tests assert the headers for each mode, and that the nonce changes per request and matches the rendered `<script nonce>`.
- [ ] With `enforce`, zero CSP violations in the browser console on this checklist (included in the PR): login with reCAPTCHA on, 2FA checkpoint, dashboard, console (websocket to a Wings node), file editor, backups, every admin index page, and admin node/server/egg edit pages. *56 of 56 pages clean, including reCAPTCHA's script and both frames, the 2FA checkpoint through to the dashboard, every client and server page, and every admin index and edit page. The console was checked against both an HTTPS and an HTTP node. **Left open** because there was no live Wings: the websocket connection, file listing and backups were only attempted, so console output, opening a real file in the editor (language-mode chunks) and uploads are unexercised.*
- [x] With `off` (the default), the response headers are byte-identical to post-0.4. *Diffed every header of `GET /auth/login` booted from `03d448cd6` and from the final tree: identical.*
- [x] *(added)* `report` mode works end to end: an injected violation is allowed, reported by the browser to `/csp-report`, and logged at debug level with only the trimmed fields.

### 2.2 Login, 2FA and account rate limits · P1 · S · Upstream: yes
**Change** (`RouteServiceProvider::configureRateLimiting`):
- **2FA checkpoint** (`auth.login-checkpoint`; the previous draft used the wrong route name) gets its own limiter: 5/min per IP **and** 5/min per pending `user_id` from the `auth_confirmation_token` session. That caps TOTP guesses per account no matter how many IPs are used.
- **Login** gets an added 5/min per normalized (`mb_strtolower(trim())`) `user` input, on top of the existing 10/min per IP.
- **Account-security actions** get a new per-user limiter `account.sensitive`: 10/hour per user for password change, email change, API key create, 2FA enable/disable, SSH key create. Configurable in `config/http.php`.
- **Server reinstall** gets a new `ResourceLimit::Reinstall` case (per server, 1 per 5 min), matching the existing pattern. Backups already have a `ResourceLimit` and are out of scope.
- **UI:** `httpErrorToHuman` shows "Too many attempts — try again in N s" on 429, using `Retry-After`.

**Status:** done in `d8798b058` (limits) and `c5e9628b0` (UI message). `account.sensitive` is keyed **per user and action** (the spec didn't say), so creating API keys can't lock a user out of changing their password.
**Found, not fixed (latent upstream bugs, worth an upstream issue):** the controllers' existing `ThrottlesLogins` lockout keys on `input('email')` (the trait's default) while the login field is `user`, so it's really **5 failures per IP per minute, regardless of username**. Its 429 has no `Retry-After`. And `config('auth.lockout.attempts'/'time')` is never applied: the controller sets `$maxLoginAttempts`, but the trait reads `$maxAttempts`.
**Acceptance:**
- [x] One integration test per limiter: going over the limit returns 429 with `Retry-After`, and the bucket keys behave as specified (a different IP against the same account is still limited). *10 tests, covering per-account login across IPs, the checkpoint per pending login across IPs and per IP, each sensitive action, and reinstall per server across users. All fail without the change.*
- [x] Jest test for the 429 message formatting. *`formatters.spec.ts` plus `http.spec.ts`; the latter fails without the `httpErrorToHuman` change.*
- [x] The existing test suite passes unchanged.

### 2.3 Session and cookie hardening · P1 · S · Upstream: yes
**Change:**
- `session.secure` defaults to `str_starts_with(env('APP_URL'), 'https://')` when `SESSION_SECURE_COOKIE` is unset. An explicit env value still wins.
- Regenerate the session ID on 2FA enable/disable and email change (password change already logs out other devices).
- Optional `SESSION_ABSOLUTE_LIFETIME` (minutes, default off) through a small middleware that stores the login timestamp. Document `SESSION_LIFETIME` and the opt-in `__Host-` cookie name.
**Status:** done in `db404d884` (secure default, docs in `.env.example`, `__Host-` test) and `e8b814570` (regeneration plus absolute lifetime). Regeneration uses `regenerate(true)` (the old session is destroyed) and is skipped for API-key requests, which have no session. The lifetime middleware calls `forgetGuards()` after logging out, because Sanctum's guard may already have cached the user earlier in the request.
**Acceptance:**
- [x] Tests: with an https `APP_URL` the cookie has `Secure`; with http it doesn't; an explicit env value overrides both. *Config evaluated under each environment, plus the real `Set-Cookie`.*
- [x] Tests: the session ID changes after 2FA enable, 2FA disable and email change. *And the old session is gone; a rejected change keeps the ID.*
- [x] Test: with the absolute lifetime set, a session older than it is logged out even when active. *Session authenticated through the guard's session key and used every 2 h; 401 at 4 h. Unset means unchanged behavior.*

### 2.4 API key expiry in the client UI · P2 · S · Upstream: yes
**Why:** F11. The column exists but users can't use it.
**Change:** `expires_at` on client API key create (presets: never, 7, 30, 90 days, or a custom date) and shown in the key list ("Expired" badge). Confirm that Sanctum rejects expired tokens for both client and application keys, and add a test either way.
**Status:** done in `dd8281c33` (API) and `220210bee` (UI). Custom dates expire at the end of that day in the user's time zone. Sanctum already refused expired tokens; the tests confirm it for both APIs.
**Acceptance:**
- [x] Integration test: an expired key returns 401 on the client API and the application API.
- [x] Creating a key with no expiry behaves exactly as it does today. *New test plus the unchanged `ApiKeyControllerTest`.*
- [x] Jest or RTL test for the expiry preset → date mapping. *`lib/expiry.spec.ts`. Also smoke-tested in headless Chrome: a 7-day key was stored with exactly 168 h of validity and listed, and a past custom date was rejected.*

### 2.5 2FA nudges (no forced change) · P2 · S · Upstream: yes (open a Discussion first)
**Status:** skipped in this pass at the owner's request (2026-10-15), pending an upstream Discussion and Open question 1.
**Change:**
- A dismissible dashboard banner for users without TOTP, linking to account security. Dismissal lasts 30 days (see Open question 1 for where to store it).
- The admin user list gets a 2FA column and filter. The admin overview shows a count of admins without 2FA.
- When `2fa_required` is `1` (admins) or `2` (all) and some users who are required to have 2FA don't, the admin settings page shows a warning with the count.
**Acceptance:**
- [ ] Jest test for the banner visibility logic (TOTP on → never shown; dismissed less than 30 days ago → hidden).
- [ ] PHP test for dismissal persistence.
- [ ] Nothing changes for users who already have 2FA.

### 2.6 CI and supply-chain hygiene · P1 · S · Upstream: partly (pins and Dependabot yes; audit gate is fork-first)
**Change:**
- `ci.yaml`: add `vendor/bin/phpstan analyse` and `composer audit --locked`. `build.yaml`: add `yarn test` and `yarn audit --groups dependencies --level high` (Yarn 1, so **not** `yarn npm audit`). The audit allowlist is `.github/audit-allowlist.json`, and each entry needs a reason and an expiry date.
- `.github/dependabot.yml`: composer, npm, github-actions and docker ecosystems, grouped weekly.
- Pin every `uses:` by commit SHA, with the tag in a trailing comment.
- `docker.yaml`: Trivy scan of the built image (fail on critical with a fix available), a `syft` SBOM as a build artifact, and the SARIF uploaded to code scanning. (Can be a follow-up PR, P2.)
- Track major frontend upgrades as separate future work, outside this plan: React 16 → 18, react-router 5 → 6, xterm 4 → `@xterm/xterm` 5, swr 0.2 → 2.
**Status:** done in `cda4771ef`, `875444d80`, `45f864859`, `5c4b58dc0`, `18d8ada63`, `879a2489c`, `6eb63a64c` and `fce6c9a7e`. What changed from the spec:
- **The dependencies were fixed before the audit gate went in.** `composer audit` reported 15 advisories (10 high, in `league/commonmark`), fixed by updates within the existing constraints (commonmark, flysystem, phpseclib, laravel/framework 12.70). axios 1.13.5 had 12 high advisories and was bumped to 1.20.0, still within `^1.13.2`.
- **phpstan has no baseline.** All 19 errors were fixed at the source: `self::` for private statics, `@return $this` on the Wings repository setters, unused constructor dependencies removed, the generics-aware ignore pattern. The 7 stale inline ignores were deleted, and `reportUnmatchedIgnoredErrors` stays on: CI analyses one PHP version against the lockfile, so the result is deterministic.
- **The yarn audit gate is a dependency-free script** (`.github/scripts/yarn-audit.js`), since Yarn 1 can't ignore advisories. The 12 remaining high advisories are allowlisted with reasons, all expiring 2027-01-15. Composer is clean, so its native ignore list isn't needed yet.
- **Every `uses:` is SHA-pinned** with the exact release in a comment, including `softprops/action-gh-release` (v2.5.0).
- **Not done:** the Trivy/SBOM step (optional follow-up), and CI triggers for `nitro/main`; the workflows only run on PRs to `1.0-develop`.
**Acceptance:**
- [ ] CI is green on `nitro/main` with the new steps. *Not verifiable without a push. Every step passes locally: phpstan (0 errors), composer audit (clean), the yarn audit gate, Jest (56), tsc, lint, build, and phpunit.*
- [ ] A test PR that adds a known-vulnerable package fails the audit job. *Shown locally only: with `lodash@4.17.15` added, the gate exits 1. An expired entry → 1, a removed entry → 1, a malformed entry → 2.*
- [x] `grep -E 'uses: [^@]+@v[0-9]' .github/workflows/*` returns nothing. *All 17 `uses:` lines are a 40-hex SHA plus a tag comment.*

---

## Phase 3 — UX polish (P2)

Low-risk and mostly frontend; each PR should be reviewable in under 15 minutes. **Shared acceptance:** before/after screenshots in the PR, a Jest test wherever there's logic, and no layout breakage at a 375 px viewport.

| ID | Item | Acceptance (beyond the shared criteria) |
|---|---|---|
| 3.1 | **Cross-platform search hotkey** (F13): accept `Ctrl+/` on non-Mac and `⌘/` on Mac, and show the matching hint. Ignore it while typing in inputs, textareas or the console. | Jest: the right modifier per `navigator.platform`; no trigger from inside an `<input>`. **P1, XS** (do it early). |
| 3.2 | **Console scroll:** a "Jump to bottom" button when scrolled up; new output doesn't move a user who has scrolled up; pause and resume autoscroll. | Manual: scroll up during heavy output and the view stays put, then the button returns to live output. |
| 3.3 | **Power state feedback:** elapsed time while `starting`/`stopping`. | Jest for the timer formatting. |
| 3.4 | **Websocket and empty states:** consistent copy for no servers, backups or schedules. On websocket disconnect, show a reconnect countdown and a "Retry now" button. | Manual: kill Wings → countdown appears; Retry reconnects. |
| 3.5 | **Forms:** map server 422 field errors to inline field messages on screens that currently flash a generic error (audit them and list the screens in the PR). Disable submit while pending. | Jest for the 422 → Formik error mapping helper. |
| 3.6 | **Accessibility:** focus trap and `aria-*` on `Modal`/`Dialog`, visible focus rings, labels on icon-only buttons, a contrast fix for `neutral` text on `gray-700`. | Lighthouse a11y ≥ 90 on dashboard, console and files (scores in the PR). |
| 3.7 | **File manager:** breadcrumbs that collapse on mobile; a "Show hidden files" toggle (persisted per user via `usePersistedState`). | Jest for the hidden-file filter. |
| 3.8 | **Timestamps:** every relative timestamp shows the absolute local and ISO time on hover/focus, through one shared component. | Jest for the component. |

---

## Phase 4 — Branding isolated from upstream code (P2)

**Principle:** branding is *data and overrides*, never edits to upstream components.

### 4.1 Branding seams · P2 · M · Upstream: yes (open a Discussion first)
- `config/branding.php` driven by env: `APP_BRAND_NAME` (falls back to `APP_NAME`), `APP_BRAND_LOGO_URL`, `APP_BRAND_FAVICON_DIR`, `APP_BRAND_THEME_CSS`. Exposed to the SPA through `SiteConfiguration` in `wrapper.blade.php`.
- Tailwind `primary` palette uses CSS variables (`rgb(var(--color-primary-500) / <alpha-value>)`), and the defaults equal the current `colors.blue`.
- User-facing hard-coded "Pterodactyl" strings in Blade/TSX use the brand name. Copyright, license and footer credit lines stay unchanged (MIT attribution).
**Acceptance:**
- [ ] With every `APP_BRAND_*` unset, screenshots of login, dashboard and admin pages are pixel-identical to upstream.
- [ ] Setting the env vars changes name, logo, favicon and primary color without a rebuild.

### 4.2 Fork branding package · P2 · S · Upstream: no · Depends: 4.1
- `branding/` (top-level): `logo.svg`, `favicons/`, `theme.css`, `brand.json`. A Docker build stage copies it into `public/branding/`, and `deploy/compose/panel.env.example` sets the env vars.
- Decide how the update banner should behave for the fork (it polls upstream's `latest.json`); see Open question 4.
**Acceptance:**
- [ ] `git diff upstream/1.0-develop -- resources/ app/ config/` shows no fork-specific branding.
- [ ] A trial `git merge upstream/1.0-develop` into `nitro/main` has no conflicts in `branding/` or `deploy/`.

---

## Phase 5 — Interface redesign: client and admin (P1/P2)

**Brief (owner, 2026-10-16):** for both audiences, beginner-first, with density, shortcuts and the command palette opt-in. Hybrid scope: shared improvements go upstream-mergeable; tokens are CSS variables whose defaults are today's look; the fork's look ships only as theme values at the 4.1 seams. No image generation: directions are rendered in HTML. Product truth lives in `PRODUCT.md`, durable visual decisions in `DESIGN.md`.

**Skills applied** (from `~/.claude/skills`): impeccable (Operate mode, `concept-seed` deal, craft floor, detector), app-designer (three-direction exploration, keep list, before/after), frontend-design, taste-skill v1/v2/v2-experimental, gpt-tasteskill, soft-skill and minimalist-skill (their rules where they fit an app; their landing-page mandates do not, which v2-experimental's own section 13 says for admin panels), design-audit and redesign-skill (audit first, targeted upgrades in the existing stack), stitch-skill (DESIGN.md format), typography, ui-animation, web-design-guidelines, shadcn (principles only: the panel is React 16 and Tailwind 3), bencium controlled, impact and innovative (asking before visual decisions; the owner delegated the pick), imagegen-frontend-web and image-to-code (not used: the owner ruled out image generation), unslop (all copy). **Precedence when they conflict:** accessibility floor, then the owner's brief, then impeccable's Operate rules, then the rest.

### Audit of the current UI (baseline screenshots, 2026-10-16)
- Server status is a 2 px colored bar at the row edge: color alone carries state, and on phones it is the only cue (HIGH).
- The top navigation overflows at 390 px, hiding the account and sign-out icons with no cue (HIGH). Its icon-only links have no accessible names (HIGH).
- `outline-none` on buttons, inputs and `:focus-visible` removes keyboard focus everywhere (HIGH). The viewport meta blocks pinch-zoom (HIGH).
- The console sidebar is seven identical icon cards; power, file and admin screens show two to four filled colored buttons competing as primary.
- All-caps micro labels ("SHOWING YOUR SERVERS"); two icon libraries (Font Awesome solid and Heroicons outline).
- The admin area is stock AdminLTE 2: four saturated buttons on the overview, uppercase section labels.

### Direction (exploration and choice)
Three directions were rendered on the real server list and console with seeded data, at 1440 x 900 and 390 x 844. Screenshots and sources are in `branding/design/directions/`. The impeccable `concept-seed` deal (seed `579d82bc`, mode operate) assigned direction 4 of the grounded list: *line map*.

| Direction | Ground, type, accent | Signature move | Verdict |
|---|---|---|---|
| **Line map** (chosen) | Light sign-white ground with a dark shell, Overpass (OFL), signal-blue actions; dark mode under `prefers-color-scheme` | Each node is a colored line; each server is a station whose marker shape (filled, half, ring, square, struck) and word give its state | Chosen: the grouping is real API data (`server.node`), status is readable without color, clearest on a phone, distinct from the category's dark grey |
| Dyno sheet | Engine-oil brown-black, Archivo semi-condensed, amber | Load against the plan limit as gauge bands with a redline | Rejected as a world: near-black plus one amber accent is a known default. Its gauge bands become the opt-in dense view |
| Departure board | Racing-green field, Barlow Semi Condensed, signal yellow | Server list as a departures board with a Next column | Rejected: the Next column needs one schedules request per server; identical filled rows; yellow overloaded |

Rejected screenshots: `branding/design/directions/dyno-list-desktop.png`, `dyno-console-desktop.png`, `board-list-desktop.png`, `board-console-desktop.png` (and their `-mobile` lists).

### Tokens
Upstream seam (defaults equal today's values, so the default look does not change):
- `--color-gray-{50..900}`, `--color-neutral-*` (alias), `--color-primary-{50..900}` as HSL channels consumed by Tailwind as `hsl(var(--x) / <alpha-value>)`.
- `--color-shell` (navigation bar), `--font-sans`, `--font-header`.
- Theme stylesheet seam: `APP_BRAND_THEME_CSS` (config `branding.theme_css`) adds one stylesheet after the app's own, on the client and the admin layouts.

Fork theme (`branding/theme.css`), light by default, dark under `prefers-color-scheme: dark`, values in `DESIGN.md`:
- Light: ground `#f2f4f7`, surface `#ffffff`, border `#7b8794`, ink `#15202b`, secondary ink `#4a5866`, muted ink `#56626e`, shell `#15202b`, actions `#1d4ed8`. Full light and dark roles with measured contrast are in `DESIGN.md`.
- Node lines (one per node, by name): magenta, cyan, olive, violet, slate; kept apart from the state hues (running green, starting amber, suspended red, installing blue, stopped grey).
- Type: Overpass for interface text (tabular figures for numbers), the terminal keeps its monospace.

### Screens, in priority order
Each item ships tests for any new logic, keeps `tsc`, `lint`, `jest` and the production build green, and is checked by screenshot at desktop and mobile with the default theme and the fork theme.

| ID | Item | Upstream | Acceptance |
|---|---|---|---|
| 5.0 | Accessibility floor: visible `:focus-visible` ring, pinch-zoom allowed, names on icon-only navigation, no clipped navigation at 320 px | Yes | Keyboard focus visible on every control on the four priority screens; no horizontal overflow at 320 px; axe-style name check passes for the nav |
| 5.1 | Token seam (CSS variables, font and shell tokens) | Yes | With no theme set, screenshots of dashboard, console, files and admin are pixel-identical to before |
| 5.2 | Theme stylesheet seam (`APP_BRAND_THEME_CSS`) | Yes | Feature test: link present when set, absent when unset, on client and admin layouts |
| 5.3 | Fork theme: line-map values, Overpass, dark mode | Fork | Contrast of every text token pair on its ground is at least 4.5:1, measured; both schemes screenshotted |
| 5.4 | Server list: grouped by node line, station markers with state words, filters (all, running, needs attention), opt-in dense view with load bands | Yes | Status readable with color removed (grayscale screenshot); Jest covers grouping, state words and filters; pagination and the admin "other servers" switch still work |
| 5.5 | Console: header with state, one primary power action, compact facts list in place of seven cards | Yes | Power actions keep their confirmations and permissions; stats readable at 390 px |
| 5.6 | Files: one primary action, breadcrumb that collapses on phones, clear empty and loading states | Yes | Existing file actions unchanged; empty directory shows how to add a file |
| 5.7 | Settings (account and server): one form vocabulary, labels above inputs, errors below | Yes | Every existing setting still saves; validation messages shown inline |
| 5.8 | Power-user layer, opt-in from Account: command palette (`Ctrl`/`⌘`+`K`), shortcut overlay (`?`), cross-platform search hotkey (PLAN 3.1) | Yes | Off by default; no animation on palette open or close; Jest covers matching and shortcut handling; full keyboard path |
| 5.9 | Admin: AdminLTE colors on the same tokens, fork theme for admin, one primary action per page | Partly | Admin index pages and node/server/egg edit pages screenshot clean in both schemes; no regression in admin tests |

---

## Backlog — power-user features (not scheduled)

Each one needs a short design note and upstream buy-in (a GitHub Discussion) before any code. Listed roughly in order of value for effort.

- **Command palette (`Ctrl/⌘+K`)** built on `SearchModal`: jump to server or page, run power actions, open a file path. Client-side fuzzy matching; actions gated by `Can`. Builds on 3.1.
- **Keyboard shortcuts** with a `?` overlay: `g d`, `g c`/`g f`/`g b`, `/` to filter. Power keys (`s`/`r`/`x`) always confirm. Off by default and user-toggleable.
- **Bulk actions:** multi-select servers for power actions (client API fan-out, a result toast per server); bulk-delete backups; bulk-toggle schedules. Admin: bulk suspend/unsuspend.
- **Scoped client API keys** (read-only, power-only, files-only), "copy as curl", revoke-all. Builds on 2.4.
- **Console:** per-server highlight rules, timestamp prefixes, download the visible log, multi-line input.
- **Schedules:** cron helper with a human-readable preview and the next 5 run times.
- **Saved dashboard views** and an optional compact table view.
- **Resource graphs:** time-range selector.
- **Webhooks** for server lifecycle events (crash, backup done, install finished), HMAC-signed.
- **Admin security view:** filter the activity log by `auth:fail`/`auth:ip-blocked` and IP, with CSV/JSON export. The data is already being recorded.
- **Light, dark and high-contrast themes** through the CSS variables from 4.1.

---

## Delivery order

| # | ID | PR | Pri | Size | Upstream | Depends |
|---|---|---|---|---|---|---|
| 1 | 0.1 | Guard CDN dispatch to upstream repo | P0 | XS | Yes | — |
| 2 | 0.2 | Stop logging APP_KEY/salt | P0 | XS | Yes | — |
| 3 | 0.3 | Throttle password reset | P0 | XS | Yes | — |
| 4 | 0.4 | Security header defaults | P0 | XS | Yes | — |
| 5 | 2.6 | CI: phpstan, jest, audit, Dependabot, SHA pins | P1 | S | Partly | — |
| 6 | 1.1 | `/up` health endpoint | P1 | S | Yes | — |
| 7 | 2.2 | Checkpoint, per-account and sensitive-action limits | P1 | S | Yes | — |
| 8 | 2.3 | Secure-cookie default, session regeneration | P1 | S | Yes | — |
| 9 | 3.1 | Ctrl+/ search hotkey | P1 | XS | Yes | — |
| 10 | 1.2 | Non-root, read-only-capable image | P1 | M | Discuss | 0.2, 1.1 |
| 11 | 1.3 | Fork compose stack + `verify.sh` | P1 | M | Fork | 1.2 |
| 12 | 2.4 | API key expiry UI | P2 | S | Yes | — |
| 13 | 2.1 | CSP (nonce plumbing → modes) + HSTS | P2 | M | Yes | 0.4 |
| 14 | 2.5 | 2FA nudges | P2 | S | Discuss | — |
| 15 | 3.2–3.8 | UX polish (one PR each) | P2 | XS–S | Yes | — |
| 16 | 4.1 | Branding seams | P2 | M | Discuss | — |
| 17 | 4.2 | Fork branding package | P2 | S | Fork | 4.1 |

---

## Open questions (decide before the item starts)

1. **2.5: where to store the banner dismissal.** Options: a nullable `users.mfa_nudge_dismissed_at` column (survives across devices, but adds a migration, which raises the upstream review bar), or `localStorage` (no migration, per device). *Recommendation: column.*
2. **2.1: should upstream default to `report` rather than `off`?** `report` costs nothing for users but adds log noise. *Recommendation: `off` upstream, `report` in the fork.*
3. **1.3: Redis or Valkey?** Redis 7.4+ is under RSAL/SSPL and Redis 8 under AGPL. Valkey 8 is BSD and drop-in compatible. Fine for self-hosting either way. *Recommendation: `valkey/valkey:8-alpine`, unless upstream docs need to say "Redis".*
4. **4.2: update banner on the fork.** Keep tracking upstream releases, point at a fork manifest, or hide it? This depends on the fork's versioning scheme.

---

## Testing notes

- **Docker access:** use `sudo docker` / `sudo docker compose` (the daemon was started manually; storage driver **vfs**, so prune with `docker builder prune` now and then). **Gotcha (fixed 2026-10-15, lasts until reboot):** a stale iptables-legacy ruleset had `FORWARD` policy `DROP`, which silently blocked traffic between containers on user-defined networks (DNS resolved, TCP timed out). Fixed with `sudo iptables-legacy -P FORWARD ACCEPT`. If containers can't reach each other after a reboot, check `sudo iptables-legacy -S FORWARD` first. This host's DNS also answers *unknown* names with a black-hole address (`198.18.0.1`), so a stopped container's name hangs instead of failing; always put a hard `timeout -s KILL` on wait loops.
- **PHP toolchain without a host install:** build a throwaway `php:8.3-cli-alpine` image with `bcmath gd pdo_mysql zip` + composer, and run it as `--user 1000:1000 -v $PWD:/app`. For integration tests, run MariaDB 11.4 on a user-defined network (or `--network container:<db>` with `DB_HOST=127.0.0.1`). The full suite takes several minutes; baseline is 374 integration tests (all flagged "risky" for error-handler cleanup, which is pre-existing) and 93 unit tests. `phpstan` reports 29 pre-existing errors.
- **Wings** needs the host Docker socket (`/var/run/docker.sock`, `/var/lib/pterodactyl`). Run it beside the stack, not inside `backend`. Nested Docker under vfs is fine for smoke tests only; test real game servers on a real node.
- **PHP:** `vendor/bin/phpunit tests/Unit` (with `DB_HOST=UNIT_NO_DB`) and `vendor/bin/phpunit tests/Integration` with `.env.ci`, the same as `ci.yaml`. For integration tests against the compose stack, use `docker compose run`, not published ports.
- **Frontend:** `yarn tsc && yarn lint && yarn test && yarn build:production` (in `node:22-alpine`). Jest specs are co-located `*.spec.ts` files.
- **Test harness gotchas (found in Phase 2):**
  - JSON test requests send cookies only after `withCredentials()`.
  - The test app is shared across requests, so guards (and config changed by `shouldUse`) carry over.
  - `/admin` views need `LARAVEL_START` defined.
  - The shared `testing` DB accumulates rows (e.g. nodes) from tests that commit, so don't assert on table-wide contents.
- **Browser checks without the firewall change:** run the panel with `php -S 127.0.0.1:8000 …/Illuminate/Foundation/resources/server.php` from `public/`. `artisan serve` drops most env vars. Run it and `ghcr.io/puppeteer/puppeteer` with `--network container:<db>`; sharing a network namespace needs no bridge forwarding.
- **Security checks:** `curl -sI http://127.0.0.1:8080/` for headers (compare against Mozilla Observatory's rules by hand, since the box isn't public); `trivy image <img>`; `composer audit --locked`; `yarn audit`.
- **Smoke test for each PR:** log in, 2FA enable/disable, create a server against a test Wings node (when available), open the console, edit a file, create and restore a backup.
