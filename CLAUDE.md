# NitroDiesel/panel

Fork of `pterodactyl/panel` v1.15.1. Fork branch `nitro/main`; upstream base `1.0-develop`. Remotes: `origin` = fork, `upstream` = pterodactyl. Daemon counterpart: `/workspace/game-wings` (NitroDiesel/wings).

**Goals:** efficiency, reliability, security, and a UI that is intuitive yet fast for advanced users, shipped as small changes upstream can merge. The roadmap is [`PLAN.md`](PLAN.md): read it before starting any item, cite its item ID (e.g. `2.2`) in branches and commits, and tick its acceptance boxes only when each one is actually verified.

## Upstream mergeability

Every change is judged by whether it rebases cleanly onto `upstream/1.0-develop`.

- One concern per branch. Upstreamable work branches from `upstream/1.0-develop`; fork-only work branches from `nitro/main`.
- Behavior changes sit behind an env/config flag whose default is upstream's current behavior.
- Fork-only material lives only in `branding/` and `deploy/`. Keep the `Pterodactyl\` namespace, file layout, and license/footer credits as they are.
- Match the existing structure even where Laravel 12 offers a newer one: `app/Http/Kernel.php`, `RouteServiceProvider`, and the classic `bootstrap/app.php` stay.
- **Upstream policy (`CONTRIBUTING.md`):** new features need an approved GitHub Discussion first; bug-fix PRs need a linked issue; AI assistance must be disclosed; and **PR titles, descriptions and comments sent upstream must be written by the human**. For upstream PRs, prepare the code and a factual change summary, and leave the PR text to the user.

## Stack

PHP 8.2/8.3, Laravel 12, Sanctum, Fractal transformers, MariaDB, Redis. Frontend: React 16 + TypeScript (strict), webpack, Tailwind 3 via `twin.macro` (`tw`) and styled-components, easy-peasy store, SWR 0.2, Formik + yup, xterm 4. Yarn 1 (`yarn.lock` v1); Node ≥ 22 (`package.json` engines).

## Where things go

- **Backend:** controllers stay thin. Business logic lives in `app/Services/<Domain>/`; validation and authorization in `app/Http/Requests/`; API output in `app/Transformers/`; calls to Wings in `app/Repositories/Wings/Daemon*Repository.php`.
- **Routes** live in `routes/*.php`. Middleware groups and the auth/API rate limiters are in `app/Providers/RouteServiceProvider.php`; per-server creation throttles are in `app/Enum/ResourceLimit.php`. Add new limits beside those.
- **Activity log:** record user-visible security and lifecycle events with `Activity::event('<scope>:<action>')`, using the existing event names as the pattern.
- **Frontend:** API calls go in `resources/scripts/api/`, shared UI in `components/elements/`, and permission gating through `Can`. Import with `@/…`, `@definitions/…` and `@feature/…`. `usePersistedState` stores to `localStorage`.
- **Panel ↔ Wings contract:** `app/Repositories/Wings/` calls Wings' `router/`, and `routes/api-remote.php` serves Wings' `remote/` client. A change on one side needs the matching side checked in `/workspace/game-wings`.

## Checks

These mirror `.github/workflows/ci.yaml` and `build.yaml`. Every PR passes all of them:

```bash
cp .env.ci .env && composer install
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse                          # level 4; not yet in CI (PLAN 2.6)
DB_HOST=UNIT_NO_DB vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit
vendor/bin/phpunit tests/Integration                # needs MariaDB from .env.ci
yarn install --frozen-lockfile && yarn tsc && yarn lint && yarn test && yarn build:production
```

- `tests/Unit` runs without a database. `tests/Integration` extends `IntegrationTestCase` and hits a real MariaDB.
- Jest is configured (`jest.config.js`, `resources/scripts/setup-tests.ts`) but **no Jest tests exist yet**. The first one sets the pattern: co-locate it as `*.test.ts(x)` next to the code it covers.

**This box (as of 2026-10-15):** PHP, Composer and Yarn are not installed. Node is v20, below the required ≥ 22. The user cannot reach the Docker socket (permission denied). Report checks you could not run as not run; see PLAN.md → Testing notes for the Docker route once access is fixed.
