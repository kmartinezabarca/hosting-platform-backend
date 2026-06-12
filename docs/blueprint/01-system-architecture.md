# 01 — System Architecture & Database Design

## 1. Target topology

```
                         ┌──────────────────────────────────────────────┐
   Flutter App ──────────┤                                              │
   React Portal ─────────┤        Laravel API (api.roke.cloud)          │
   React Admin ──────────┤                                              │
   AI Assistant (in-app)─┤  API v1 (existing, frozen)  API v2 (new)     │
                         └───────┬──────────────────────────┬───────────┘
                                 │                          │
              ┌──────────────────┼──────────────┬───────────┼─────────────────┐
              ▼                  ▼              ▼           ▼                 ▼
        ┌──────────┐      ┌───────────┐  ┌──────────┐ ┌──────────┐   ┌──────────────┐
        │  MySQL   │      │   Redis   │  │  Reverb  │ │ Horizon  │   │ AI Gateway   │
        │          │      │ cache/Q   │  │    WS    │ │ workers  │   │ (Anthropic)  │
        └──────────┘      └───────────┘  └──────────┘ └──────────┘   └──────────────┘
                                 │ queues: provisioning, deployments, ai, billing
                                 ▼
                    ┌─────────────────────────────┐
                    │   Orchestrator (sagas)      │
                    │   ProviderDriver contracts  │
                    └──────┬─────────────┬────────┘
                           ▼             ▼
                    ┌────────────┐ ┌─────────────┐      ┌────────────┐
                    │  Coolify   │ │ Pterodactyl │      │ Cloudflare │
                    │  (apps,DBs)│ │ (game srv)  │      │ (DNS/SSL)  │
                    └────────────┘ └──────┬──────┘      └────────────┘
                                          ▼
                                   Wings (console WS — client connects
                                   via ROKE-signed proxy token only)
```

**Decisions:**

- **Coolify is the single application runtime** (apps, databases, docker compose). HestiaCP is sunset (existing accounts migrate or run until churn); Proxmox stays admin-only for node capacity, not a client product.
- **Pterodactyl remains the game-server runtime.** ROKE proxies/signs the Wings console token so clients never see the panel domain.
- **Laravel Horizon** replaces bare `queue:work` for observability of the new queues.
- **API v1 is frozen** (current portal keeps working); **API v2** is the contract for React rewrite pages, Flutter, and the AI agent. One OpenAPI 3.1 spec, generated clients.

## 2. New domain model (compute plane)

The existing `Service` stays as the **billing aggregate**. New tables model the **compute aggregate**:

```
User ─┬─ Team (new, optional owner of everything below)
      │
      └─ Project ──── Environment (production, staging, pr-123…)
                          │
                          └─ Resource  ──── ResourceProviderRef (coolify/ptero IDs — internal only)
                             │  kind: app | database | game_server | static_site | compose
                             ├─ Deployment (apps only) ── DeploymentLog chunks
                             ├─ Domain / DnsRecord (existing models, FK added)
                             ├─ Backup (existing model, FK added)
                             └─ service_id → Service (billing linkage, nullable during trial)
```

### Core tables

```sql
teams (
  id, uuid, name, slug, owner_user_id FK users,
  plan_tier ENUM('free','starter','pro','team','agency'),
  created_at, updated_at
);
team_members (team_id, user_id, role ENUM('owner','admin','developer','billing','viewer'), PRIMARY KEY(team_id,user_id));

projects (
  id, uuid, team_id FK, name, slug,
  github_installation_id NULL FK github_installations,
  repo_full_name NULL,             -- "roke/app" — display only
  default_branch NULL,
  detected_stack JSON NULL,        -- output of the detection engine
  created_at, updated_at, archived_at NULL
);

environments (
  id, uuid, project_id FK, name, slug,            -- production / staging / pr-<n>
  type ENUM('production','staging','preview','development'),
  branch NULL, auto_deploy BOOL DEFAULT true,
  ephemeral BOOL DEFAULT false, expires_at NULL,  -- PR previews
  UNIQUE(project_id, slug)
);

resources (
  id, uuid, environment_id FK,
  kind ENUM('app','static_site','database','redis','game_server','compose'),
  name, status ENUM('creating','provisioning','running','stopped','sleeping',
                    'degraded','failed','suspended','deleting'),
  spec JSON,                 -- desired state: image/buildpack, cpu, ram_mb, disk_mb, region, game, slots…
  service_id NULL FK services,   -- billing linkage; NULL while on free trial
  health JSON NULL,          -- last health snapshot (cpu%, ram%, uptime)
  created_at, updated_at, deleted_at
);

resource_provider_refs (        -- the ONLY place upstream IDs live
  resource_id FK, provider ENUM('coolify','pterodactyl','cloudflare'),
  external_id VARCHAR, external_meta JSON,    -- e.g. ptero identifier+uuid, coolify app uuid
  PRIMARY KEY(resource_id, provider)
);

deployments (
  id, uuid, resource_id FK,
  trigger ENUM('push','manual','rollback','ai','pr_open','pr_sync'),
  status ENUM('queued','building','deploying','running','success','failed','cancelled','rolled_back'),
  commit_sha NULL, commit_message NULL, branch NULL, pr_number NULL,
  initiated_by_user_id NULL, initiated_by_ai BOOL DEFAULT false,
  build_seconds NULL, error_summary TEXT NULL,    -- AI-generated root cause (see 03)
  created_at, started_at, finished_at
);

deployment_logs (deployment_id FK, seq INT, stream ENUM('build','deploy','runtime'), chunk MEDIUMTEXT, created_at);

env_vars (
  id, environment_id FK, key, value_encrypted,   -- encrypted cast, write-only API
  is_secret BOOL DEFAULT true, source ENUM('user','detection','platform'),
  UNIQUE(environment_id, key)
);

github_installations (id, team_id FK, installation_id BIGINT, account_login, suspended_at NULL);

orchestrations (                 -- saga state (generalizes existing ProvisioningJob)
  id, uuid, resource_id FK NULL, deployment_id FK NULL,
  flow VARCHAR,                  -- 'provision_app','provision_game_server','rollback',…
  state VARCHAR,                 -- current step
  steps JSON,                    -- [{step, status, started_at, finished_at, error}]
  attempts INT, last_error TEXT NULL, completed_at NULL, failed_at NULL
);

ai_conversations (id, uuid, user_id FK, team_id FK NULL, context JSON, created_at);
ai_messages (id, conversation_id FK, role ENUM('user','assistant','tool'), content MEDIUMTEXT,
             tool_calls JSON NULL, tokens_in INT, tokens_out INT, created_at);
ai_actions (                     -- every side-effect the AI requests
  id, uuid, conversation_id FK, user_id FK,
  tool VARCHAR, arguments JSON,
  risk ENUM('read','safe_write','destructive'),
  status ENUM('proposed','confirmed','executed','rejected','failed'),
  confirmed_at NULL, executed_at NULL, result JSON NULL
);

usage_samples (resource_id FK, sampled_at, cpu_pct, ram_mb, disk_mb, net_rx_mb, net_tx_mb,
               INDEX(resource_id, sampled_at));   -- rollups feed metering + AI context
```

### How it links to the existing billing schema

- `resources.service_id → services.id`: when a paid plan attaches, the existing renewal/suspension machinery (`RenewalAccountingService`, `ServiceSuspensionService`) works unchanged — suspension listener stops the resource via its driver.
- `services.category` gains values `app_platform` alongside existing `game_server`, `hosting`, `database`.
- Existing `Backup`, `Domain`, `DnsRecord` get a nullable `resource_id` FK (dual-linked during migration, `service_id` path deprecated later).
- `PterodactylPlanConfig` / `CoolifyPlanConfig` (already in repo) remain the bridge from `ServicePlan` → resource `spec` defaults.

## 3. State machines

**Resource:** `creating → provisioning → running ⇄ stopped`, with `degraded`, `failed` (from orchestrator), `sleeping` (free-tier idle), `suspended` (billing), `deleting`. Transitions only via the orchestrator — controllers never mutate `status` directly.

**Deployment:** `queued → building → deploying → running → success`, failure exits to `failed` with `error_summary` populated by the troubleshooting engine; `rolled_back` set when a later rollback supersedes it.

## 4. Event backbone

Domain events (all under `App\Domains\Platform\Events\Compute\`):

`ResourceProvisionRequested/Provisioned/ProvisionFailed`, `DeploymentQueued/Started/Succeeded/Failed`,
`ResourceScaled`, `ResourceSlept/Woke`, `BackupCompleted/Failed`, `ResourceSuspended/Resumed`,
`PreviewEnvironmentCreated/Destroyed`, `AiActionExecuted`.

Subscribers: `AuditLog` writer, Reverb broadcaster (`private-project.{uuid}` channels), notification fan-out
(mail + FCM push), n8n bridge (back-office flows), usage metering.

## 5. Multi-tenancy & isolation

- **DB:** single-database tenancy, every compute-plane query scoped by `team_id` through a global `BelongsToTeam` scope. Policies (`ProjectPolicy`, `ResourcePolicy`) enforce membership + role.
- **Runtime:** one Coolify *project* per ROKE team, one *environment* per ROKE environment — Coolify's own isolation (separate docker networks) maps 1:1. Pterodactyl: one panel user per ROKE team (not per ROKE user), servers owned by that synthetic user.
- **Network:** all panel/API traffic to Coolify & Pterodactyl rides Tailscale; panels are not publicly exposed. Client-facing endpoints go through Cloudflare (Tunnel) only.
