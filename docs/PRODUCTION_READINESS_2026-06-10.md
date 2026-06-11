# Production Readiness Completion Report

**Date:** 2026-06-10 · **Scope:** every "must fix" and hardening item from `PLATFORM_AUDIT_REPORT_2026-06-10.md`.
**Method:** each fix implemented with regression tests, validated against the dev environment, committed in small logical commits to `develop` (backend + frontend). Nothing below is claimed without a passing test or a verified build.

---

## Executive Summary

**The platform is ready for a controlled launch**, conditioned on three **manual operational steps** that cannot be automated from a dev session: executing the APP_KEY rotation, registering the Stripe webhook endpoints in staging/production, and one live Stripe-test verification of the renewal cycle. All code-level critical and high-risk items from the audit are **closed with tests**.

Final state: **backend suite 249 tests / 811 assertions, 0 failures** (was 206/675 after the audit; +43 tests this pass). Both frontend bundles (portal + admin) build clean. Zero pending migrations on dev.

---

## Fixes Completed

| # | Audit item | Status | Commit |
|---|---|---|---|
| 1 | APP_KEY rotation tooling + runbook | ✅ `security:rotate-app-key` (dry-run, ciphertext backup, round-trip verification, refuses without OLD/NEW keys, never prints secrets). **Bonus fix:** the `connection_secrets` accessor never decrypted — encrypted rows always read as `null`; now tolerant of all 3 historical formats | `cd22d81` |
| 2 | Renewal receipts / transactions / CFDI | ✅ `RenewalAccountingService` on `invoice.paid`/`invoice.payment_succeeded`; idempotent via `receipts.provider_invoice_id`; skips $0 anchor invoices and already-receipted initial PIs; CFDI per existing rules (fiscal profile → pending_stamp, else Público en General 72h); stores invoice/subscription/PI/charge + billing period | `988ffff` |
| 3 | Stripe webhook live readiness | ✅ Routes/signature/idempotency verified by tests; `docs/STRIPE_WEBHOOKS.md` runbook (dev `stripe listen`, staging/prod setup, full required-event list); env vars present in `.env.example` | `a2c7b24` |
| 4 | Support role permissions | ✅ Service create/update/delete/status/reprovision moved to admin+; support keeps reads. UI hides the buttons (`services.manage` capability) | `173e1ce`, frontend `7d7ef0ae` |
| 5 | Refunds & disputes | ✅ `charge.refunded` (delta-idempotent, partial-aware), `charge.dispute.created/updated/closed` (disputed → won restores / lost records chargeback). Admin double-click guards: already-refunded check, remaining-amount cap, Stripe idempotency key. Fixed pre-existing bug: partial refunds incorrectly marked receipts fully refunded | `f5c765c` |
| 6 | Coolify orphaned projects | ✅ Resumable provisioning: every step persists immediately; retry resumes with stored UUIDs; completion flag `coolify_provisioned_at` (app-without-DB no longer counts as provisioned); terminate cleans only known resources | `158b9f4` |
| 7 | FRP reconciliation | ✅ Idempotent `ensureFrpProxy`; provisioning resumes past server creation (never a duplicate server); "provisioned" now requires server **and** FRP; hourly `game-servers:reconcile-frp` with admin notification after 3 failures | `158b9f4` |
| 8 | Contract without quote | ✅ Paid plans require a valid, unexpired, unconsumed `quote_id` (server-priced, hash-bound, atomically claimed). Free/trial unaffected. Portal already sends quotes everywhere | `8212908` |
| 9 | Hardcoded IPs | ✅ `COOLIFY_HOSTING_DNS_IP`, `PTERODACTYL_RELAY_IP`, `PTERODACTYL_WINGS_INTERNAL_URL`, `COOLIFY_URL` — no production-IP defaults; missing values warn and skip explicitly; `.env.example` updated | `eba9511` |
| 10 | Public endpoint throttling | ✅ `/blog/subscribe`, `/blog/unsubscribe`, `/documentation-requests` at 5/min/IP | `775a4e3` |
| 11 | Receipt index | ✅ Indexes on `receipts.payment_reference` **and** `receipts.provider_invoice_id` (renewal idempotency lookup) | `988ffff` |
| 12 | ROKE Pet pending work | ✅ Moderation/reactions/entitlement work committed; migrations verified applied; route-level `pet.admin` middleware added on top of per-method checks (`/admin/check` intentionally outside) | `a4b99ab`, `d134f46` |

## Tests Added

43 new tests across 9 files (all passing):

- `RotateAppKeyCommandTest` (6) — refusal without keys, dry-run immutability, both columns rotate to new key, legacy plain-JSON normalization, ciphertext-only backup.
- `RenewalAccountingTest` (5) — receipt+transaction+CFDI on renewal, duplicate-event single receipt, $0 anchor skip, initial-PI skip, fiscal-profile CFDI.
- `RefundAndDisputeWebhookTest` (8) — full/partial/duplicate refund, dispute created/won/lost, service-level double-refund guards.
- `SupportServicePermissionsTest` (6) — support read-ok / mutations 403, admin still allowed.
- `CoolifyResumableProvisioningTest` (3) — no duplicate project on retry, partial ≠ provisioned, terminate cleans known resources only.
- `FrpReconciliationTest` (5) — FRP-only retry without new server, not-provisioned without FRP, reconcile command repair/threshold-notify/skip-healthy.
- `QuoteRequiredContractTest` (4) — paid-without-quote rejected, trial still works, unknown quote rejected, consumed quote rejected.
- `PublicEndpointThrottlingTest` (2) — 429 after limit on both endpoints.
- `PetAdminMiddlewareTest` (4) — route-level denial, `/admin/check` open, app_admin passes, unauthenticated rejected.

Updated: `ServiceCancellationAndRepairTest` (FRP-aware provisioned semantics), `RenewalAccountingTest` (PDF service mocked — dompdf exceeded the 128M default in full-suite runs).

## Test Results

```
php -d memory_limit=512M vendor/bin/phpunit
OK (249 tests, 811 assertions)        # 0 errors, 0 failures
```
- Migrations: 134 ran / **0 pending** (platform dev DB); roke_pet dev DB clean.
- Frontend: `pnpm build:portal` ✓ (2m34s) and `pnpm build:admin` ✓ (35s).
- Note for CI: run PHPUnit with `memory_limit >= 256M` (PDF-generating tests).

## Billing Readiness

- **First charge:** quote-bound (server-priced, hash-validated, atomically claimed), PI amount/currency/status re-verified, Stripe idempotency keys, `services.payment_intent_id` UNIQUE.
- **Renewals:** subscription anchored at `next_due_date` with the first-charge card as default PM; every paid renewal now produces Receipt + Transaction + CFDI exactly once.
- **Failure path:** grace window → hourly suspension → reactivation on payment, with webhook-loss reconciliation (pre-existing, tested).
- **Refunds/disputes:** synced from Stripe both ways; partial refunds accounted correctly; chargebacks recorded; admin double-refund impossible (three independent guards).
- **CFDI:** contract + renewal both follow fiscal-profile/Público-en-General rules; hourly stamping of scheduled CFDIs.
- **Remaining (manual):** one live Stripe-test cycle (see checklist) — dev has never received a real Stripe delivery.

## Security Readiness

- **APP_KEY:** rotation tooling + runbook complete and tested; **rotation itself must be executed** (key still in git history at `e9d7a4f`). The previously-broken `connection_secrets` decryption is fixed (prerequisite for a correct rotation).
- **Roles:** support is read-only on services (server-enforced + UI); pet admin has route-level middleware; audit/backups remain super_admin.
- **Abuse:** all public write endpoints throttled; paid contracts cannot bypass quotes; `trial_days` not client-controllable (previous pass).
- **Secrets:** no live keys in dev; no production IPs silently baked into code; `.env.example` clean.

## Provisioning Readiness

- **Pterodactyl:** unchanged robust core; provisioning is now resumable past server creation; a server without its FRP proxy is *not* "provisioned" and keeps retrying; hourly FRP reconciliation with capped admin alerts.
- **Coolify:** fully resumable step machine — no orphaned projects/apps/DBs on mid-provision failure; subdomain stable across retries; terminate handles partial states.
- **Config:** all infra endpoints/IPs from env, fail-explicit.

## Frontend Readiness

- **Verified:** session handling (401/419 interceptors), quote-only checkout, cancel-at-period-end UX (confirmation + scheduled-cancellation message), admin role route gating, both bundles compile.
- **Fixed this pass:** support no longer sees service mutation buttons; `disputed` receipts render/filter correctly in admin (previously displayed as "Pendiente"); **client dashboard now shows billing banners** (failed payment + grace days, suspended for non-payment, provisioning, provisioning-failed) — these states previously existed only in the mobile app.
- **Renewal receipt visibility:** renewal receipts appear automatically in `/client/invoices` (standard receipts).

## Remaining Risks

1. **APP_KEY rotation not yet executed** — tooling ready; risk persists until run (High until done).
2. **No real Stripe webhook delivery observed yet** — all handlers are signature/idempotency-tested with simulated events, but the live round-trip (step list below) is pending (Medium until done).
3. Scheduled-cancellation has no "resume" button in the portal UI (backend endpoint exists) — minor UX gap, customer can contact support.
4. Coolify DNS A records target a Tailscale CGNAT IP (`COOLIFY_HOSTING_DNS_IP` in dev) — verify the production value is a publicly reachable IP or a Cloudflare-proxied tunnel.
5. Frontend repo still has ~36 uncommitted user-authored files (pet admin pages, copy edits) outside this work's scope — review and commit before the next deploy.

## Required Manual Steps Before Launch

```bash
# 1) APP_KEY rotation (full runbook: docs/APP_KEY_ROTATION.md)
php artisan key:generate --show                       # → NEW key; do NOT touch .env yet
mysqldump ... services users > pre-rotation-backup.sql
OLD_APP_KEY="base64:OLD" NEW_APP_KEY="base64:NEW" php artisan security:rotate-app-key --dry-run
OLD_APP_KEY="base64:OLD" NEW_APP_KEY="base64:NEW" php artisan security:rotate-app-key --backup=storage/app/key-rotation-backup.json
# set APP_KEY=NEW in env → restart PHP-FPM, queue:restart, scheduler, Reverb
# verify: login, 2FA user, hosting DB-credentials panel; then delete backup + OLD/NEW vars

# 2) Stripe webhooks (full runbook: docs/STRIPE_WEBHOOKS.md)
#    Dashboard → Webhooks → add https://api.<domain>/api/stripe/webhook  (13-event list in the doc)
#    and https://api.<domain>/api/rp/stripe/webhook ; set each whsec_ in env

# 3) Dev live verification (once)
stripe listen --forward-to localhost:8000/api/stripe/webhook
#    contract paid plan → subscription 'trialing' anchored at next_due_date
#    → simulate failed/paid invoice → banner + suspension + reactivation + renewal receipt visible

# 4) Production deploy
php artisan migrate --force        # 4 new migrations (enums + indexes), all additive
#    new env vars: COOLIFY_HOSTING_DNS_IP, PTERODACTYL_RELAY_IP, PTERODACTYL_WINGS_INTERNAL_URL
#    confirm: QUEUE_CONNECTION=redis with running worker, scheduler cron alive,
#    Reverb up, CACHE_DRIVER=redis (Cache::lock correctness)
php artisan config:cache && php artisan queue:restart
```

## Launch Readiness Score

| Area | Before audit | After audit | **Now** | Rationale |
|---|---|---|---|---|
| Backend | — | 82 | **92** | All audit items closed with tests; lifecycle + provisioning self-healing |
| Billing | — | 70 | **88** | Renewal accounting, refunds, disputes, anchors all tested; −live verification pending |
| Integrations | — | 75 | **88** | Resumable Coolify, FRP reconciliation, config hygiene |
| Security | — | 72 | **85** | Tooling + authz + throttling done; APP_KEY rotation execution pending |
| Frontend | — | 78 | **85** | Critical states (banners, disputes, role gating) fixed; builds verified |
| **Overall** | — | 75 | **88** | Ready for controlled launch after the 3 manual steps |

**Verdict:** no code-level critical or high-risk issues remain open. The platform is production-ready **once the APP_KEY rotation is executed and the Stripe webhook endpoints are registered and live-verified** — both are operational steps with complete runbooks in `docs/`.
