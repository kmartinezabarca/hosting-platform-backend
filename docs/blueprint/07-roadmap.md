# 07 — Monitoring, Deployment Strategy & Roadmaps

## 1. Monitoring strategy

| Layer | Tool | What |
|---|---|---|
| Customer resources | usage collector (driver stats → `usage_samples`) + Uptime Kuma (auto-registered per resource) | metrics screens, alerts, AI context |
| Platform API | Laravel Horizon (queues), Sentry (already in frontend — add Laravel SDK), structured JSON logs | errors, queue latency, p95 |
| Infra | Grafana (existing) + node/docker exporters on Coolify & Wings nodes | capacity planning, node picker input |
| Business | Grafana dashboard over MySQL: signups, first-deploy conversion, deploy success rate, AI confirm/reject ratio, MRR | weekly review |
| SLOs | deploy success ≥ 97%, time-to-first-deploy p50 < 3 min, provision p50 < 90 s, API p95 < 300 ms | alerts to ops channel via n8n |

The **deploy success rate** and **AI action confirm-rate** dashboards are the two product-health
metrics that gate roadmap promotions (e.g., removing the admin fallback).

## 2. Platform deployment strategy

- ROKE's own services deploy via the existing Jenkins pipelines; add a staging environment of the full
  stack (staging Coolify + staging Ptero node) — the `build:*:staging` frontend modes already exist.
- DB migrations: expand/contract only (new tables are additive; `resource_id` FKs nullable) — zero-downtime.
- Feature flags (simple `features` config + per-team override) gate: API v2 surface, AI tools tiers,
  self-service game provisioning. Rollout: internal team → 10% cohort → all.
- Rollback: backend is stateless containers (Coolify-deployed itself); migrations contract only after
  two stable releases.

## 3. 30-day MVP roadmap

Goal: **"Connect GitHub → AI detects → deployed app with URL" + one-click Minecraft, end-to-end self-service.**

| Week | Backend | Frontend | Mobile |
|---|---|---|---|
| 1 | Compute schema migrations + models + policies; Team backfill (1 personal team per user); OpenAPI v2 skeleton | v2 API client setup; Projects list/create UI shell | — |
| 2 | GitHub App (install flow, webhooks, repo list); Detection engine (Laravel, Next.js, Node, static, Dockerfile) | GitHub connect + repo picker + detection result card | — |
| 3 | Orchestrator runner + ProvisionAppFlow + DeployFlow on CoolifyDriver; deployment log streaming (Reverb); `*.roke.app` wildcard domains | Deploy screen with live logs; resource status page | — |
| 4 | ProvisionGameServerFlow (remove admin-confirm for paid-in-full); AI agent v1: read tools + diagnose_failure on Sonnet; push-event auto-deploy | Game wizard (game→version→slots→pay→provision progress); AI chat on resource pages | — |

Out of scope for MVP: previews, teams >1 member, Flutter, auto-fix, scale-from-chat. Cut line is firm.

**MVP success criteria:** 10 internal/beta apps deployed via the flow; deploy success ≥ 90%;
game server provisions < 3 min with zero admin touch.

## 4. 90-day growth roadmap

- **Month 2 — depth:** PR preview environments + GitHub PR comments; rollbacks; env-var UX (import
  .env, detection bindings); database resources self-service; troubleshooting engine full taxonomy +
  "Apply fix"; AI safe_write tools; usage metering → plan limit enforcement; Horizon + Sentry backend.
- **Month 3 — breadth & mobile:** Flutter app Phase 1–2 (auth, projects, logs, game console, push,
  AI chat); WordPress + compose detectors; more games (FiveM, Rust, Palworld presets); team plans
  (members, roles); annual billing; free-tier sleep/wake; public launch of app platform tier;
  HestiaCP migration tooling (AI-assisted importer).

## 5. 12-month enterprise roadmap

| Quarter | Theme | Highlights |
|---|---|---|
| Q3 | Scale & trust | Multi-region nodes (MX + US East); SLA tiers + status page; envelope encryption for env vars; SSO (SAML/OIDC) for Team/Agency; audit log export; SOC2 groundwork |
| Q4 | Platform expansion | Docker registry support (deploy from image); cron/worker resource kinds as first-class; managed Redis clusters; game-server marketplace (modpacks, plugins, one-click install); Flutter Phase 3 + tablet/desktop |
| Q1 next | AI autonomy | Auto-heal GA (opt-in self-driving ops: scale, restart, rollback within guardrails); AI capacity planning ("your Black Friday traffic needs…"); natural-language infra reports (Opus); AI cost optimizer (rightsizing suggestions tied to real usage) |
| Q2 next | Ecosystem | Public API + CLI (`roke deploy`); Terraform provider; agency white-label GA; referral program; marketplace revenue share |

## 6. Sequencing rationale

1. **App platform before mobile** — Flutter without v2 API would bind to legacy shapes.
2. **Game-server automation early** — it's existing revenue; removing the admin step is pure margin
   and proves the orchestrator on a flow you already understand.
3. **AI read-only → safe_write → destructive → autonomous** — trust ladder; each tier ships only after
   the previous tier's confirm/reject telemetry looks healthy.
4. **HestiaCP sunset in month 3, not month 1** — migration tooling needs the app platform to be stable
   first; until then it's frozen, not growing.
