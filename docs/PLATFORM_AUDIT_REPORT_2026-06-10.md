# Platform Audit Report

**Date:** 2026-06-10 · **Scope:** hosting-platform-backend (Laravel 12), hosting-platform-frontend (React/Vite portal + admin), ROKE Pet domain, Stripe billing, Pterodactyl & Coolify integrations, dev environment (`roke_dev` / `roke_pet_dev`).
**Method:** code review + live dev-database inspection + full test-suite runs + targeted fixes with regression tests. Nothing was marked "works" unless verified by a test or live data.

---

## Executive Summary

The platform is **architecturally solid and much closer to launch than a typical pre-launch codebase**: server-side pricing with hash-bound quotes, signature-verified and DB-idempotent webhooks (both platforms), persistent provisioning jobs with backoff, a real dunning pipeline with tests, and role-gated admin routes.

However, the audit found and **confirmed with live dev data** three launch blockers in the billing/lifecycle path, all of which were **fixed during this audit** (with regression tests):

1. **Auto-renewal was broken end-to-end.** Stripe subscriptions were created without a billing anchor after the first period was already charged via PaymentIntent → the duplicate first invoice went unpaid (`default_incomplete`) and every subscription died as `incomplete_expired` within ~23h. Confirmed: the only real subscription in dev sat at `incomplete`. → Fixed with `trial_end` anchored at `next_due_date` + default payment method wiring.
2. **Service cancellation was impossible.** `services.status` ENUM lacked `'cancelled'` (and `'maintenance'`), while the client cancel endpoint, the `customer.subscription.deleted` webhook, and the admin status endpoint all write those values → guaranteed `QueryException` in strict MySQL. The webhook also wrote `subscriptions.status='cancelled'` where the ENUM defines `'canceled'`. → Fixed via additive migration + spelling fix.
3. **Clients could self-grant up to 365 trial days** through `POST /subscriptions` (`trial_days` passed straight to Stripe). → Fixed: trial now derives exclusively from the server-side plan.

Final state: **206 tests / 675 assertions green** (3 pre-existing failures repaired + 3 new regression tests added).

---

## Backend Readiness

### What works (verified)
- **Checkout trust boundary**: `CheckoutQuoteService` builds quotes server-side, binds them to a SHA-256 hash of plan/cycle/add-on/tax state, expires them (30 min TTL), and claims them atomically (`UPDATE … WHERE consumed_at IS NULL`) before charging — double-click/refresh safe, released on failure. `PaymentController::createPaymentIntentFromQuote` prices the PI from the quote, never from the client.
- **Flow A hardening**: backend re-validates PI `amount`, `currency`, and `status` against its own computation before fulfilling.
- **Flow B idempotency**: deterministic Stripe idempotency key (user + PM + amount + 10-min bucket); plus `services.payment_intent_id` is **UNIQUE at the DB level** — a hard duplicate-fulfillment guard (verified in schema).
- **Webhook pipeline (platform & Pet)**: Stripe signature verification, insert-first idempotency on a UNIQUE `event_id`, concurrent-delivery protection, 500-on-failure so Stripe retries, failed events reprocessable. Covered by `StripeWebhookIdempotencyTest`.
- **Dunning**: `invoice.payment_failed` → `past_due` + grace window (non-resetting on Stripe retries) → hourly `subscriptions:process-overdue` suspends at provider+DB after grace → `invoice.paid` reactivates; includes webhook-loss reconciliation. Covered by `SubscriptionDunningTest`.
- **Plan changes**: real Stripe proration (upgrade=`always_invoice`, downgrade=`create_prorations`), Stripe rollback if local persistence fails, best-effort Pterodactyl resize.
- **Auth**: Sanctum tokens in HttpOnly cookies + `X-Requested-With` enforcement (CSRF mitigation), throttled auth endpoints, 2FA, impersonation with single-use token exchange, role middleware (`super_admin`/`admin`/`support`) with active-status check.
- **Scheduler**: dunning, trial expiry, provisioning retries (5-min), egg/node sync, health checks, pings/metrics, SSL/domain alerts, Pet reminders/dunning — all `withoutOverlapping` + logged.

### What is incomplete / risky
- **No internal Receipt/Transaction/CFDI for renewals** (HIGH): `onInvoicePaymentSucceeded` only updates statuses. Renewal charges leave no internal accounting record and no CFDI. First-period receipts exist; every renewal afterwards is invisible to your books.
- **Non-quote contract path mispricing** (MEDIUM): `POST /services/contract` with a raw `plan_id` (no `quote_id`) computes `base_price × months`, ignoring `plan_pricing` cycle discounts → can overcharge vs. catalog. Frontend always uses quotes; the API doesn't force it.
- **`local subscriptions.billing_cycle` collapses quarterly/semi-annual to `monthly`** (LOW, display only — Stripe price interval is correct).
- **FRP failure after server creation is never retried** (MEDIUM): retry sees `pterodactyl_server_id` set → marks success; the relay port may stay dead (`frp_enabled=false` in `connection_details`). Needs a reconciliation step or FRP-specific retry.
- **Support role can create/update/delete services** (MEDIUM): `routes/admin.php` puts `POST/PUT/DELETE /admin/services*` in the support group. Deletion at least should be admin+.
- Free-plan contracting lock uses `Cache::lock` on the `file` cache driver in dev (no cross-process guarantee); fine once Redis is used in prod — verify `CACHE_DRIVER` in prod.

### Fixed during audit
| Fix | File(s) |
|---|---|
| Stripe subscription renewal anchor (`trial_end` = `next_due_date`) + `default_payment_method` wiring + metadata | `ServiceContractingService::createStripeSubscription` |
| `services.status` ENUM extended with `cancelled`, `maintenance` (additive migration, applied to dev) | `2026_06_10_120000_extend_services_status_enum.php` |
| Webhook wrote `subscriptions.status='cancelled'` → `'canceled'` | `StripeWebhookController::onSubscriptionDeleted` |
| Client-controlled `trial_days` removed; trial from plan | `Client\SubscriptionController::createSubscription` |
| Provisioning retry now repairs `status='failed'` → `active` when provider already provisioned | `ProvisioningService::runJob` |
| Stale `invoice_id` → `receipt_id` in factory/tests (3 failing tests) | `TransactionFactory`, `StripeWebhookTest`, `PaymentServiceTest` |

---

## Frontend Readiness

- **Architecture**: clean layering (`core`/`infrastructure`/`application`/`presentation`), separate admin (`index-admin.html` → `AppAdmin`) and portal (`AppPortal`) entries, lazy routes, error boundaries.
- **Session handling**: cookie-based Sanctum with `withCredentials`, XSRF headers, global 401 → login redirect (with `session_expired` reason), 419 → reload, redirect-loop guard. Verified in `apiClient.ts`.
- **Route guards**: `ProtectedRoute` with `requireAdmin`/`requireClient` + `AccessDeniedPage`; admin separation enforced again server-side by role middleware (defense in depth).
- **Checkout**: UI flows through quote → server-priced intent → contract with `quote_id` (`CheckoutPage` → `StripeCheckout`/`ReviewAndPay`); no client-side amount is ever sent for charging.
- **Not exhaustively validated** (time-boxed): pixel-level responsiveness, dark/light parity, every empty/loading state. E2E scaffolding exists (`e2e/`, Playwright) — recommend a pre-launch click-through of billing screens against the now-fixed cancel/renewal flows.

---

## Payment & Subscription Review

- **Current state**: First-charge path is robust (see Backend). Renewal path was **broken** and is now fixed but **needs one live verification**: contract a paid plan in dev with Stripe test mode + webhook forwarding (`stripe listen --forward-to localhost:8000/api/stripe/webhook`) and confirm the subscription lands `trialing` with `trial_end = next_due_date` and renews on schedule. **`stripe_events` in dev was empty** — webhooks have never been exercised against dev outside the simulated test suite.
- **Missing safeguards**:
  - Renewal receipts/CFDI (above — biggest remaining billing gap).
  - No `charge.refunded`/`charge.dispute.*` webhook handling — refunds issued from the Stripe dashboard won't sync; disputes are invisible.
  - `refundReceipt` has no Stripe idempotency key and no already-refunded guard at the service layer (admin double-click on partial refund could double-refund; full refunds are protected by Stripe itself).
  - `getOrCreateStripeCustomer` has a small create-race (two concurrent calls → two customers).
- **IVA**: 16% exclusive, server-side from `BILLING_TAX_RATE_PERCENT`; quote lines include IVA; CFDI flow (Facturama) with Público en General fallback at 72h and hourly `cfdi:stamp-pending`.

---

## Pterodactyl Integration

- **Implemented & real**: full app-API client (timeouts, both app+client keys), user find-or-create, node selection (service → plan → env default → auto-select), free allocation pick, egg detail fetch with env-var merge (egg defaults → plan overrides → dynamic `MAX_PLAYERS`), server creation with `external_id`=service uuid, SRV/A DNS via Cloudflare (non-fatal), FRP proxy, suspend/unsuspend/reinstall/terminate (DNS + FRP cleanup), status sync, eggs/nodes sync commands, websocket/console/file-manager passthrough with ownership checks.
- **Failure handling**: provisioning failures → `provisioning_jobs` retry with exponential backoff (5 min base), admin notified, customer notified on exhaustion; idempotent via provider markers + `unique(service_id, provider)`.
- **Risks**: FRP retry gap (above); hardcoded relay IP fallback `178.156.225.26` in code (move to config); plan-change resize is best-effort with no reconciliation report.

## Coolify Integration

- **Implemented & real** (not a placeholder): project + application + optional database creation, FQDN/subdomain logic, Coolify name sanitization, DNS record, connection details + secrets persisted (`connection_secrets`), start/stop/restart/redeploy, terminate with ordered cleanup and project-delete retry/backoff, health checks via HTTP probe (`hosting:check-health`), domain/SSL panels.
- **Risks**: **orphaned-resource leak** — the idempotency marker (`coolify_app_uuid`) is persisted only after app+DB creation; failure between `createProject` and persistence → retry creates a second project. Persist `coolify_project_uuid` immediately after step 1. Also: DNS A records point at `100.124.151.68` (Tailscale CGNAT) — unreachable from the public internet unless Cloudflare-proxied through a tunnel; verify this is intentional.

---

## ROKE Pet Review

- **Implemented**: pets, vaccines, medical records, weight history, vet contacts + token-based vet portal (throttled, PIN), lost mode + scan analytics + posters, adoption listings/requests/reviews/follow-ups (bidirectional reputation with recompute), community feed/comments/likes, moderation (reports auto-flag at 3+, admin queue), push + inbox, plan gating (`GatesPlanFeatures` + entitlement-aware `OwnerSubscription` with grace handling), Stripe checkout/portal/webhook (signature + insert-first idempotency), trial/dunning/expiry commands scheduled.
- **Uncommitted work in tree** (reviewed, coherent, migrations applied to dev): follow-up reactions, review moderation + `adoption_review_reports` (FK + indexes present), `currentForOwner` subscription resolution, feature-key normalization with plan fallback matrix. **Ready to commit.**
- **Data model**: 28 FKs / 29 tables in `roke_pet_dev`; idempotency and report tables indexed. No pending migrations.
- **Watch items**: Pet admin endpoints rely on per-method `requireAdmin` (every method checked — all covered today) — a route-level middleware would be safer against future omissions. `AdminController` & `PlanController` admin methods also self-check (verified pattern, same caveat).

---

## Security Findings

| Severity | Finding | Status |
|---|---|---|
| **Critical** | Auto-renewal dead / duplicate first-period invoice (billing integrity) | **Fixed** (verify live) |
| **Critical** | `services.status` ENUM mismatch broke client cancel + `subscription.deleted` webhook | **Fixed** |
| **High** | Client-controlled `trial_days` (self-granted free service up to 365d) | **Fixed** |
| **High** | **Current `APP_KEY` is in git history** (commit `e9d7a4f`, later removed in `8c1c934`). It encrypts cookies and `connection_secrets`. | **Action required**: rotate `APP_KEY` (re-encrypt encrypted columns), or rewrite history if repo access is broad |
| **High** | Renewal payments create no internal receipt/CFDI | Open |
| **Medium** | Support role can create/update/**delete** services | Open |
| **Medium** | No `charge.refunded`/dispute webhook sync | Open |
| **Medium** | Coolify orphaned projects on partial provisioning failure | Open |
| **Medium** | Non-quote contract path ignores cycle pricing | Open |
| **Low** | `.env.testing` committed with a (test-only) `APP_KEY` | Open (acceptable if test-only) |
| **Low** | Hardcoded IPs in provisioning code (`178.156.225.26`, `100.124.151.68`) | Open (move to config) |
| **Low** | `POST /blog/subscribe`, `POST /documentation-requests` unthrottled | Open |
| **Low** | `receipts.payment_reference` unindexed (refund lookups) | Open |

Verified good: CORS locked to explicit origins/patterns with credentials; webhooks signature-verified on both platforms; uploads validated (`image` + size caps) with ownership checks; no live Stripe keys in dev `.env` (all `sk_test_`); secrets never logged in reviewed paths; mass assignment constrained by explicit `$fillable` in reviewed models.

---

## Database Findings

- **Schema vs code drift (critical, fixed)**: `services.status` ENUM (see above); `subscriptions.status` spelling.
- **Indexes**: all idempotency-critical columns verified **UNIQUE** (`stripe_events.event_id`, `pet stripe_webhook_events.event_id`, `checkout_quotes.uuid`, `services.payment_intent_id`); hot FKs indexed. Only `receipts.payment_reference` missing (minor).
- **FKs**: 62 constraints / 64 tables (platform), 28 / 29 (pet) — healthy.
- **Migrations**: zero pending on both dev DBs (after applying the new ENUM migration).
- **Drift source**: `TransactionFactory` + 2 tests still used the pre-rename `invoice_id` column — evidence migrations were edited in place rather than added; avoid editing applied migrations.

---

## Test Results

- **Run (baseline)**: 203 tests, 663 assertions — 3 errors, all from the stale `invoice_id` column reference. Fixed.
- **Created**: `ServiceCancellationAndRepairTest` (3 tests): `customer.subscription.deleted` → `canceled`/`cancelled` states; client immediate cancel; provisioning retry repairs `failed` service. All pass.
- **Final**: full suite green — **206 tests, 675 assertions, 0 failures** (see `storage/app/_audit_phpunit_final.txt`).
- **Manual/live validations**: dev DB state inspection (confirmed `incomplete` subscription bug and `failed`-but-provisioned service bug with real records); pet + platform migration status; index/FK introspection; dev fixtures created for support user, past-due + suspended, cancelled, failed-payment, overdue-receipt cases (`audit+*@rokeindustries.test`).
- **Not testable in this environment**: real Stripe round-trips (renewal anchor behavior), Pterodactyl/Coolify live provisioning, webhook delivery to dev (no `stripe listen` configured — `stripe_events` was empty).

## Launch Readiness Score

| Area | Score | Rationale |
|---|---|---|
| Backend | **82** | Strong architecture; blockers fixed; renewal receipts + support-role scope remain |
| Frontend | **78** | Sound auth/checkout plumbing; UI states not exhaustively verified |
| Billing | **70** | First charge solid; renewal fix needs live verification; renewal receipts/CFDI + refund sync missing |
| Integrations | **75** | Both integrations real and retried; FRP retry gap + Coolify orphan leak |
| Security | **72** | Good fundamentals; APP_KEY rotation pending; support-role scope |
| **Overall** | **75** | Launchable after the "must fix" list below |

## Priority Fix List

**Must fix before launch**
- [x] Stripe subscription renewal anchor (`trial_end`) — *fixed; verify with one live Stripe-test contract + `stripe listen`*
- [x] `services.status` ENUM + webhook `canceled` spelling — *fixed + migrated + tested*
- [x] Client-controlled `trial_days` — *fixed*
- [ ] Rotate `APP_KEY` (exposed in git history) and re-encrypt `connection_secrets`
- [ ] Generate internal Receipt + Transaction (+ CFDI) on `invoice.payment_succeeded` renewals
- [ ] Configure Stripe webhook endpoint for prod (and dev forwarding) — currently unexercised
- [ ] Run the new migration in production at deploy (`php artisan migrate`)

**Should fix soon**
- [ ] Restrict service create/update/delete to admin+ (remove from support group)
- [ ] Handle `charge.refunded` / `charge.dispute.created` webhooks
- [ ] Persist `coolify_project_uuid` immediately after project creation (orphan prevention)
- [ ] FRP reconciliation (retry proxy creation when `frp_enabled=false` on active game servers)
- [ ] Enforce `quote_id` on `/services/contract` (or apply `plan_pricing` in the no-quote path)
- [ ] Refund idempotency key + already-refunded guard in `PaymentService::refundReceipt`
- [ ] Index `receipts.payment_reference`

**Nice to have**
- [ ] Move hardcoded IPs to config; throttle blog-subscribe/doc-requests; SubscriptionFactory for tests; route-level admin middleware for Pet admin; fix `subscriptions.billing_cycle` display for quarterly/semi-annual; Redis cache driver confirmation in prod.

## Recommended Next Steps

1. **Commit the audit fixes** (factory/test repairs, ENUM migration, webhook spelling, renewal anchor, trial_days removal, provisioning repair, new tests) as small separate commits, plus the pending ROKE Pet moderation work already in the tree.
2. **Live-verify renewal**: dev contract with Stripe test card + `stripe listen`; confirm subscription = `trialing`, anchor = `next_due_date`; fast-forward with a test clock to see the first renewal invoice and dunning.
3. **Rotate APP_KEY** with a re-encryption script for `connection_secrets` (and any other encrypted casts).
4. Implement **renewal receipts/CFDI** in `onInvoicePaymentSucceeded` (reuse `InvoiceService::createWithItems` + `PaymentReceiptService`).
5. Add refund/dispute webhook handlers, then the "should fix" hardening list.
6. Pre-launch manual pass of portal billing screens + admin moderation against dev fixtures (`audit+dunning@rokeindustries.test` has suspended/cancelled/overdue cases ready).
