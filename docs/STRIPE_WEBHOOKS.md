# Stripe Webhooks — Setup & Operations

Two independent endpoints (each with its **own** signing secret):

| Platform | Endpoint | Env var (signing secret) |
|---|---|---|
| ROKE Industries Hosting | `POST /api/stripe/webhook` | `STRIPE_WEBHOOK_SECRET` |
| ROKE Pet | `POST /api/rp/stripe/webhook` | `ROKEPET_STRIPE_WEBHOOK_SECRET` |

Both endpoints:
- **Verify the Stripe signature** (`Stripe-Signature` header) before doing anything; invalid payload/signature → 400.
- Are **idempotent at the DB level**: every event id is claimed in a UNIQUE-keyed table (`stripe_events` / `stripe_webhook_events`) before processing. Duplicate deliveries return `duplicate`/`in_progress` without re-processing; handler failures return 500 so Stripe retries (re-processing is safe).
- Require **no auth/CSRF** (they're outside the session middleware and verified by signature).

## Required events (platform endpoint)

Configure the production/staging endpoint with at least:

```
checkout.session.completed
checkout.session.expired
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.paid
invoice.payment_succeeded
invoice.payment_failed
invoice.payment_action_required
charge.refunded
charge.dispute.created
charge.dispute.updated
charge.dispute.closed
```

Handled effects:
- `invoice.paid` / `invoice.payment_succeeded` → renew subscription period, **create internal Receipt + Transaction + CFDI** (idempotent per Stripe invoice), reactivate service if suspended for non-payment.
- `invoice.payment_failed` → `past_due` + grace window (non-resetting); hourly `subscriptions:process-overdue` suspends after grace.
- `invoice.payment_action_required` → notifies the customer (3DS).
- `customer.subscription.deleted` → local subscription `canceled`, service `cancelled`.
- `charge.refunded` → refund Transaction (delta-idempotent), receipt `refunded` when fully refunded.
- `charge.dispute.*` → receipt/transaction `disputed`; closed `won` restores, `lost` records a chargeback and marks `refunded`.
- `checkout.session.*` → logged only (platform uses PaymentIntents, not Checkout Sessions).

For the **ROKE Pet** endpoint select: `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_failed`.

## Local development (stripe listen)

```bash
stripe login
# Platform endpoint:
stripe listen --forward-to localhost:8000/api/stripe/webhook \
  --events invoice.paid,invoice.payment_succeeded,invoice.payment_failed,invoice.payment_action_required,customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,charge.refunded,charge.dispute.created,charge.dispute.updated,charge.dispute.closed,checkout.session.completed,checkout.session.expired
# Copy the whsec_... it prints into .env:
#   STRIPE_WEBHOOK_SECRET=whsec_...
# (Second terminal, optional) ROKE Pet endpoint:
stripe listen --forward-to localhost:8000/api/rp/stripe/webhook
#   ROKEPET_STRIPE_WEBHOOK_SECRET=whsec_...
```

Verification checklist (one-time in dev, recommended before launch):
1. Contract a paid plan with a Stripe test card via the portal.
2. Confirm the subscription is created **`trialing` with `trial_end` = service `next_due_date`** (renewal anchor).
3. `stripe trigger invoice.payment_failed` (or use a test clock) → subscription `past_due`, grace banner appears.
4. Pay → service reactivates; a renewal **Receipt appears under /client/invoices**.
5. Refund the charge from the Stripe dashboard → receipt becomes `refunded`.
6. Check `stripe_events` rows exist and duplicates are marked `duplicate`.

## Staging / Production setup

1. Stripe Dashboard → Developers → Webhooks → *Add endpoint*.
   - URL: `https://api.<your-domain>/api/stripe/webhook`
   - Events: the list above.
2. Copy the endpoint's signing secret → set `STRIPE_WEBHOOK_SECRET` in that environment (each endpoint/environment has its own `whsec_`).
3. Repeat for the Pet endpoint → `ROKEPET_STRIPE_WEBHOOK_SECRET`.
4. Deploy, then send a test event from the dashboard ("Send test webhook") and confirm a 200 + a `stripe_events` row.
5. Monitor: Dashboard → Webhooks shows delivery attempts/failures; handler errors return 500 and are retried by Stripe for up to 3 days.

## Env vars (all present in .env.example)

```
STRIPE_KEY=pk_...
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...
ROKEPET_STRIPE_SECRET=          # empty = shares STRIPE_SECRET
ROKEPET_STRIPE_WEBHOOK_SECRET=whsec_...
```
