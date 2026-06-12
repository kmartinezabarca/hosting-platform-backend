# 04 — Integrations: GitHub App, Framework Detection, Coolify, Pterodactyl

## 1. GitHub integration (GitHub App, not OAuth App)

A **GitHub App** gives per-repo install scopes, webhooks at the app level, and short-lived installation
tokens — strictly better than user OAuth for deployments. User OAuth (existing Google-style flow) is
only used to *identify* the user; the App installation grants repo access to the **team**.

```
Connect flow:
  Portal → GET github.com/apps/roke-platform/installations/new?state=<signed team token>
  → user installs on account/org, picks repos
  → GitHub redirects + sends `installation` webhook
  → backend stores github_installations(team_id, installation_id)

Token flow (server-side only):
  App private key → JWT (10 min) → POST /app/installations/{id}/access_tokens
  → installation token (1h, cached in Redis at 50 min) → repo/contents/webhook API calls
  Private key in env/secret store; never in DB.
```

**Webhooks** (`POST /webhooks/github`, HMAC-SHA256 verified, queued):

| Event | Handler |
|---|---|
| `push` on tracked branch | `HandlePushEvent` → create `Deployment(trigger=push)` → DeployFlow |
| `pull_request` opened/synchronize | create/refresh ephemeral preview environment (`pr-{n}`), comment deploy URL on the PR |
| `pull_request` closed | destroy preview env (TTL fallback: `environments.expires_at`, swept hourly) |
| `installation` / `installation_repositories` | sync `github_installations`, repo cache |

**Deploy mechanics:** ROKE requests a tarball/clone URL with the installation token and hands Coolify a
deploy via its API using a deploy key per project (Coolify supports private repos via app-managed key).
Rollback = redeploy a previous commit SHA (Coolify deployment per SHA retained), surfaced as
`POST /deployments/{d}/rollback`.

## 2. Framework Detection Engine

Input: repo file tree + key manifests (fetched via GitHub contents API — `composer.json`,
`package.json`, `Dockerfile`, `docker-compose.yml`, lockfiles, `artisan`, `next.config.*`, `nuxt.config.*`,
`wp-config.php`, `.nvmrc`, `runtime.txt`).

```
DetectionEngine
  └── runs all Detectors, each returns { framework, confidence 0-1, evidence[], config }
      LaravelDetector      composer.json requires laravel/framework + artisan file
      NextJsDetector       package.json deps.next (+ next.config)        → distinguishes static export
      NuxtDetector / VueDetector / ReactDetector (CRA/Vite static)
      NestJsDetector       deps.@nestjs/core
      NodeDetector         fallback: package.json with start script
      WordPressDetector    wp-config.php / wp-content dir
      StaticDetector       index.html, no build manifest
      DockerfileDetector   Dockerfile present (wins if confidence tie — explicit beats inferred)
      ComposeDetector      docker-compose.yml → kind=compose
  → highest confidence wins; ties broken by specificity (Dockerfile > framework > generic)
```

Output (`projects.detected_stack`):

```json
{
  "framework": "laravel", "confidence": 0.98,
  "language": "php", "runtime_version": "8.3",
  "build": { "method": "nixpacks", "commands": ["composer install --no-dev", "npm ci && npm run build"] },
  "run":   { "command": "php-fpm + nginx preset", "port": 8080, "healthcheck": "/up" },
  "needs": { "database": "mysql", "redis": true, "queue_worker": true, "scheduler": true },
  "env_template": [
    {"key":"APP_KEY","generate":"laravel_key"},
    {"key":"DB_HOST","bind":"database.host"}, {"key":"DB_PASSWORD","bind":"database.password"},
    {"key":"REDIS_HOST","bind":"redis.host"}
  ],
  "recommended_plan": "starter",
  "warnings": ["No .env.example found", "package-lock and pnpm-lock both present"]
}
```

Key design point: detectors output **declarative needs**; the orchestrator turns `needs.database` into a
sibling `Resource(kind=database)`, auto-binds credentials into `env_vars` (`source=detection`), and the
AI explains what it did. Laravel niceties (key generation, `php artisan migrate` release command,
separate queue-worker process, scheduler cron) are first-class in the Laravel detector config since it's
ROKE's flagship audience.

## 3. Coolify integration architecture

`CoolifyDriver implements AppRuntimeDriver` — wraps the existing `CoolifyService` HTTP client.

| Driver method | Coolify API |
|---|---|
| createApp(spec, repo) | create application (public/private repo or docker image), set build pack (nixpacks/dockerfile/static), resources |
| setEnvVars(map) | bulk env endpoint (secrets flagged) |
| deploy(sha?) / cancelDeploy | deploy endpoint, returns deployment uuid |
| streamDeployLogs(uuid) | poll/SSE → republished as `deployment_logs` chunks + Reverb |
| createDatabase(engine, ver) | standalone MySQL/PostgreSQL/Redis with generated creds |
| start/stop/restart, delete | lifecycle endpoints |
| getRuntimeStats | container metrics → `usage_samples` |
| attachDomain(fqdn) | + ROKE creates the DNS record via CloudflareDnsDriver; SSL via Coolify/Traefik LE |

Mapping: ROKE team → Coolify project; ROKE environment → Coolify environment; ROKE resource → Coolify
application/database. All refs in `resource_provider_refs`. **Webhook back-channel:** Coolify deploy
status webhooks → `/webhooks/coolify` (secret-verified) so DeployFlow steps advance event-driven rather
than by polling (polling stays as the fallback watchdog).

Failure containment: driver calls are idempotent where possible (create checks for existing ref first),
all calls wrapped with timeout + retry (3x exponential), circuit breaker per provider (Redis-backed) —
when Coolify is down, orchestrations pause in a resumable state instead of failing.

## 4. Pterodactyl integration architecture

`PterodactylDriver implements GameRuntimeDriver` — wraps existing `PterodactylService`,
`GameServerProvisioningService`, `GameServerRuntimeService`.

One-click flow (replaces the admin-confirm step that exists today):

```
POST /v2/environments/{e}/resources kind=game_server
  → payment/plan check (existing ServiceContractingService creates Service + Invoice)
  → ProvisionGameServerFlow:
      1. EnsurePanelUser        (synthetic per-team user)
      2. PickNodeAllocation     (region + capacity-aware: query node utilization)
      3. CreatePteroServer      (egg from PterodactylEgg + PterodactylPlanConfig, env from
                                 GameSoftwareVersion: download_url, version)
      4. AwaitInstalled         (webhook/poll)
      5. CreateDnsRecords       (SRV/A via CloudflareDnsDriver — pattern already exists)
      6. ScheduleBackups        (BackupSchedule — existing model)
      7. EnableMonitoring       (GameServerPing collector + Uptime Kuma registration)
      8. NotifyUser             (push + email with connect address)
```

Console access stays the proven pattern: client asks ROKE → ROKE mints the Wings WS credential →
client connects directly to Wings. Add: ROKE-branded hostname for Wings endpoints (clients see
`gs-mx1.roke.cloud`, never the panel).

Game settings exposure: per-game **whitelist** of safe startup variables (slots, difficulty, motd,
modpack id…) defined alongside `PterodactylEgg` rows (`display_name` pattern already exists for this
curation); raw egg variables are never exposed.

## 5. Cloudflare & domains

Existing TXT-verification + DNS management flow is kept as-is and becomes a step library for the
orchestrator (`CreateDnsRecord`, `VerifyDomainOwnership`, `AwaitPropagation`). App domains get a free
`{project}-{env}.roke.app` subdomain instantly (wildcard zone, proxied); custom domains use the
existing import/verify flow then CNAME to the edge.
