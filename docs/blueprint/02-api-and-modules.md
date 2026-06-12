# 02 — API v2 Design & Laravel Module Structure

## 1. API v2 principles

- Base path `/api/v2`, cookie-Sanctum for web, **token Sanctum (ability-scoped) for Flutter and the AI agent**.
- OpenAPI 3.1 spec is the source of truth (`docs/openapi/v2.yaml`), generated TS + Dart clients.
- All IDs are UUIDs; provider IDs never appear. Errors follow RFC 9457 (`application/problem+json`).
- Long-running operations return `202 Accepted` + an `orchestration` object; clients follow progress over Reverb (`private-project.{uuid}`) or poll `GET /orchestrations/{uuid}`.

## 2. Endpoint map

### Projects & deploys

```
GET    /v2/teams                          list my teams
POST   /v2/teams                          create team
GET    /v2/projects?team={uuid}
POST   /v2/projects                       { team, name, repo? } → triggers detection if repo given
GET    /v2/projects/{uuid}
POST   /v2/projects/{uuid}/analyze        re-run framework detection → detected_stack + plan recommendation
DELETE /v2/projects/{uuid}

GET    /v2/projects/{p}/environments
POST   /v2/projects/{p}/environments      { name, type, branch, auto_deploy }
GET    /v2/environments/{e}/env-vars      keys + masked values
PUT    /v2/environments/{e}/env-vars      bulk upsert (values write-only)

POST   /v2/environments/{e}/resources     { kind, name, spec } → 202 + orchestration
GET    /v2/resources/{r}                  status, health, spec, domains, current deployment
PATCH  /v2/resources/{r}/spec             scale ram/cpu → 202
POST   /v2/resources/{r}/actions          { action: start|stop|restart } → 202
DELETE /v2/resources/{r}                  → 202 (confirmation header required: X-Confirm: <resource-name>)

POST   /v2/resources/{r}/deployments      manual deploy { branch?, sha? } → 202
GET    /v2/resources/{r}/deployments      history (status, sha, message, duration, who/AI)
GET    /v2/deployments/{d}/logs?stream=build|runtime&after_seq=
POST   /v2/deployments/{d}/rollback       → 202
GET    /v2/deployments/{d}/diagnosis      troubleshooting report (see doc 03)

GET    /v2/resources/{r}/metrics?window=1h|24h|7d    downsampled usage_samples
GET    /v2/resources/{r}/backups · POST …/backups · POST /v2/backups/{b}/restore (X-Confirm)
```

### Game servers

```
GET    /v2/catalog/games                          games, versions (GameSoftwareVersion), regions, slot/ram presets
POST   /v2/environments/{e}/resources             kind=game_server, spec:{game, version, slots, ram_mb, region} → 202
POST   /v2/resources/{r}/console-session          → { ws_url, signed_token }  (ROKE-signed Wings proxy credential)
POST   /v2/resources/{r}/actions                  start|stop|restart|kill
GET    /v2/resources/{r}/players                  online players (GameServerPing data)
PUT    /v2/resources/{r}/game-settings            whitelisted startup vars only (never raw egg vars)
```

### GitHub

```
GET    /v2/github/installations               connected orgs/accounts
GET    /v2/github/installations/{id}/repos    paginated, searchable
GET    /v2/github/repos/{owner}/{name}/branches
POST   /webhooks/github                       (public, HMAC-verified) push / pull_request / installation events
```

### AI assistant

```
POST   /v2/ai/conversations                       create (optional project/resource context)
POST   /v2/ai/conversations/{c}/messages          user message → SSE stream of assistant tokens + tool events
POST   /v2/ai/actions/{a}/confirm                 user approves a proposed destructive action
POST   /v2/ai/actions/{a}/reject
GET    /v2/ai/conversations/{c}                   history
```

### Billing (bridges to existing v1 logic)

```
GET    /v2/billing/plans?category=app|game|db     from ServicePlan/PlanPricing
POST   /v2/resources/{r}/subscribe                { plan, cycle } → reuses ServiceContractingService
GET    /v2/teams/{t}/invoices · GET /v2/teams/{t}/usage
```

## 3. Realtime channels (Reverb)

| Channel | Events |
|---|---|
| `private-project.{uuid}` | deployment.*, resource.status, orchestration.step |
| `private-deployment.{uuid}` | log.chunk (build logs streamed live) |
| `private-user.{id}` | existing notifications (unchanged) |
| `private-ai.{conversation}` | assistant token stream fallback, action.proposed/executed |

Game console stays a **direct Wings WebSocket** with a ROKE-issued short-lived token (existing pattern, kept).

## 4. Laravel module structure

Extend the existing `app/Domains/Platform` domain — new sub-namespaces, no framework change:

```
app/Domains/Platform/
├── Compute/                          # NEW bounded context
│   ├── Models/        Project, Environment, Resource, Deployment, EnvVar,
│   │                  ResourceProviderRef, Orchestration, UsageSample, Team, TeamMember
│   ├── Http/
│   │   ├── Controllers/V2/           ProjectController, EnvironmentController,
│   │   │                             ResourceController, DeploymentController, MetricController…
│   │   ├── Requests/                 CreateResourceRequest (spec validation per kind)…
│   │   └── Resources/                JSON:API-ish transformers — strips provider data
│   ├── Orchestrator/
│   │   ├── Flows/                    ProvisionAppFlow, ProvisionGameServerFlow,
│   │   │                             DeployFlow, RollbackFlow, PreviewEnvFlow, DestroyFlow
│   │   ├── Steps/                    CreateCoolifyApp, SetEnvVars, TriggerBuild, AwaitHealthy,
│   │   │                             CreateDnsRecord, IssueSsl, CreatePteroServer, …
│   │   └── OrchestrationRunner.php   step executor: retries, compensation, state persistence
│   ├── Detection/
│   │   ├── DetectionEngine.php
│   │   └── Detectors/                LaravelDetector, NextJsDetector, … (see doc 04)
│   ├── Providers/                    # driver layer — ONLY place that talks upstream
│   │   ├── Contracts/                AppRuntimeDriver, GameRuntimeDriver, DnsDriver
│   │   ├── Coolify/CoolifyDriver.php          (wraps existing CoolifyService)
│   │   ├── Pterodactyl/PterodactylDriver.php  (wraps existing PterodactylService)
│   │   └── Cloudflare/CloudflareDnsDriver.php (wraps existing CloudflareService)
│   ├── Events/  Jobs/  Listeners/  Policies/
│   └── Metering/                     UsageCollector (scheduled), UsageRollup
│
├── Ai/                               # NEW — grows out of the support-chat code
│   ├── Agent/        AgentRunner, ToolRegistry, RiskClassifier, ConfirmationGate
│   ├── Tools/        GetResourceStatus, GetDeploymentLogs, TriggerDeploy, ScaleResource,
│   │                 RollbackDeployment, CreateGameServer, DiagnoseFailure, …
│   ├── Troubleshooting/  LogAnalyzer, FailureClassifier, FixSuggester (doc 03)
│   ├── Models/       AiConversation, AiMessage, AiAction
│   └── Http/         ConversationController (SSE), ActionController
│
├── Git/                              # NEW
│   ├── GitHubAppClient.php           JWT + installation tokens
│   ├── Models/       GithubInstallation
│   ├── Http/         WebhookController (HMAC), InstallationController
│   └── Jobs/         HandlePushEvent, HandlePullRequestEvent, SyncRepos
│
├── Services/…                        # EXISTING billing/provisioning services — untouched
└── Models/…                          # EXISTING Service, Invoice, Plan… — untouched
```

**Conventions carried over from the current codebase:** UUID public IDs, encrypted casts for secrets,
queue separation (`provisioning` + new `deployments`, `ai`), events → listeners for side effects,
policies for authorization, `AuditLog` on every admin/AI mutation.

## 5. Backward compatibility

- v1 routes (`routes/api.php`, `client.php`, `admin.php`) untouched; portal pages migrate to v2 screen-by-screen.
- Existing game-server services get a backfill migration: each active `Service` (category `game_server`) generates a `Project("Legacy")` → `Environment(production)` → `Resource(kind=game_server)` + `resource_provider_refs` row from `configuration.pterodactyl_id`.
- Admin panel gains read-only views over compute tables first; admin-triggered provisioning remains the fallback path until the orchestrator hits >99% auto-success.
