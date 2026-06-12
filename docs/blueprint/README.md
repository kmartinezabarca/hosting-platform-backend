# ROKE Platform — Next-Generation Blueprint

> AI-powered hosting & game infrastructure. Vercel/Railway simplicity + Pterodactyl game servers,
> behind a single intelligent abstraction layer. Users never touch Coolify or Pterodactyl.

**Date:** 2026-06-11 · **Status:** Architecture blueprint (grounded in the current codebase)

## Index

| Doc | Contents |
|---|---|
| [01-system-architecture.md](01-system-architecture.md) | Target system architecture, new domain model, database design |
| [02-api-and-modules.md](02-api-and-modules.md) | API design (v2), Laravel module structure |
| [03-ai-assistant.md](03-ai-assistant.md) | AI assistant architecture, tool permissions, smart troubleshooting engine |
| [04-integrations.md](04-integrations.md) | Coolify, Pterodactyl, GitHub App, framework detection engine |
| [05-mobile-flutter.md](05-mobile-flutter.md) | Flutter app architecture, UX flows, notifications |
| [06-security-monetization.md](06-security-monetization.md) | Security review & hardening, pricing/plans strategy |
| [07-roadmap.md](07-roadmap.md) | Deployment & monitoring strategy, 30-day MVP, 90-day growth, 12-month enterprise roadmap |

## Executive summary

ROKE Platform evolves from a **billing-first hosting panel** (buy plan → admin provisions → client manages)
into a **deploy-first developer platform** (connect repo → AI analyzes → platform provisions → billing follows usage).

The pivot is achieved by adding a **compute plane** on top of the existing billing plane — not by rewriting:

```
              ┌────────────────────────────────────────────────┐
              │  EXPERIENCE PLANE (new)                        │
              │  AI Assistant · React portal · Flutter app     │
              ├────────────────────────────────────────────────┤
              │  COMPUTE PLANE (new)                           │
              │  Projects · Environments · Resources ·         │
              │  Deployments · Framework Detection ·           │
              │  Orchestrator (sagas) · Provider Drivers       │
              ├────────────────────────────────────────────────┤
              │  BILLING PLANE (existing — keep)               │
              │  Services · Plans · Invoices · Stripe/Cashier  │
              │  CFDI/Facturama · Quotations · Receipts        │
              ├────────────────────────────────────────────────┤
              │  INFRASTRUCTURE (existing — abstracted)        │
              │  Coolify · Pterodactyl · Cloudflare · Docker   │
              └────────────────────────────────────────────────┘
```

## Gap analysis: current code → target

| Capability | Today (in repo) | Target | Action |
|---|---|---|---|
| Billing, Stripe, CFDI | `PaymentService`, `InvoiceService`, `RenewalAccountingService`, Cashier | Same, plus usage-based metering | **Keep & extend** |
| Game servers | `PterodactylService`, `GameServerProvisioningService`, `GameServerRuntimeService`, admin-triggered | Self-service one-click wizard, instant provision on payment | **Automate existing path** |
| App hosting | `CoolifyService` + `HostingProvisioningService` (early), HestiaCP legacy | Coolify as sole app runtime; GitHub-driven deploys; HestiaCP sunset | **Build out** |
| Provisioning | `ProvisioningJob` model, `provisioning` queue, admin confirm step | Event-driven saga orchestrator, zero admin touch | **Extend** |
| AI | Anthropic support chat (`config/anthropic.php`, Haiku) | Tool-using platform agent (deploy, diagnose, scale) with permission tiers | **Extend** |
| GitHub | None | GitHub App: repos, webhooks, auto-deploy, PR previews | **New** |
| Framework detection | None | Detection engine (composer.json / package.json / Dockerfile signatures) | **New** |
| Mobile | None (React web only) | Flutter app (client surface only, reuses API v2) | **New** |
| Realtime | Reverb (`private-user.*`, chat channels) + direct Wings WS | Same + deployment log streaming channels | **Extend** |
| Automation | n8n outbound webhooks (`config/n8n.php`) | n8n for back-office only; platform actions move into the orchestrator | **Re-scope** |

## Non-negotiable design principles

1. **Abstraction is the product.** No Coolify/Pterodactyl ID, URL, or term ever reaches a client API response. Provider IDs live in `resource_provider_refs`, never in client payloads.
2. **The AI is an operator, not a chatbot.** It calls the same internal APIs users do, under the same authorization, with an explicit confirmation gate for destructive actions.
3. **Billing follows compute, never blocks it.** Trial/free-tier resources provision instantly; the billing plane attaches a subscription asynchronously.
4. **Every mutation is an event.** Provisioning, deploys, scaling — all emitted as domain events; audit log, notifications, AI context, and n8n all subscribe.
5. **Mobile-first means API-first.** One API v2 (OpenAPI-specified) serves React, Flutter, and the AI agent identically.
