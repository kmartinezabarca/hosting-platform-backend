# 06 — Security Review & Monetization Strategy

## 1. Security review (current state → required hardening)

### Already solid (keep)
HttpOnly cookie Sanctum + CSRF, TOTP 2FA with encrypted secret, login/register throttling, session
timeout middleware, role middleware, bcrypt, `SANCTUM_STATEFUL_DOMAINS` allowlist, secrets in `.env`,
recent APP_KEY rotation procedure (docs/APP_KEY_ROTATION.md), Stripe webhooks signature-verified.

### Required for the next-gen platform

| Area | Risk | Mitigation |
|---|---|---|
| **Multi-tenant compute** | cross-team access via IDOR on new resources | Global `BelongsToTeam` scope + Policy on every compute model; UUID route binding; authz tests per endpoint in CI |
| **Provider credentials** | Coolify/Ptero admin keys are platform-god keys | Keys only in env; drivers are the single call-site; panels reachable only over Tailscale; per-environment Coolify tokens when available; quarterly rotation runbook |
| **Env var storage** | user app secrets in our DB | `value_encrypted` (AES via encrypted cast) + write-only API + masked reads + never logged; envelope encryption (per-team data key) in the enterprise phase |
| **GitHub App key** | full repo access if leaked | private key in secret store (not repo/DB), JWT TTL 10 min, installation tokens cached ≤50 min, webhook HMAC verified, repo allowlist per installation |
| **AI agent** | prompt injection → destructive action; data exfiltration | risk tiers enforced in code (doc 03 §2): destructive needs out-of-band user confirmation; agent runs under the user's own policies; secrets write-only; log content treated as untrusted input (tool results wrapped, never executed as instructions); per-team token budgets |
| **Wings console tokens** | token leak = server console access | short TTL (existing pattern), bind to user+server, one-time use, revoke on session end |
| **Preview environments** | PR from fork runs arbitrary code | previews only for branches in the same repo by default; fork PRs require maintainer approval (GitHub-style); preview envs get no production env vars (separate env var sets per environment — already the model) |
| **Webhooks** | forged Coolify/GitHub/Stripe/n8n calls | HMAC/secret verification on every inbound webhook (n8n shared secret already exists), replay protection via delivery-ID dedupe |
| **Mobile** | token theft on device | flutter_secure_storage + biometric gate, token abilities scoped (`mobile`), device list + remote revoke (UserSession model already tracks sessions) |
| **Billing** | plan-limit bypass via direct API | limits enforced server-side in orchestrator steps (not UI), spec validation against plan caps on every scale/create |
| **DoS / abuse** | free tier crypto-mining, build abuse | free tier: CPU caps, sleep-after-idle, no outbound SMTP, build minute quotas; anomaly alerts on usage_samples; KYC-light (verified email + rate limits) before free compute |

### Compliance posture
Stripe = SAQ-A (card data never touches backend — keep Elements/SetupIntent pattern). CFDI pipeline
unchanged. Add: data-processing inventory for AI (logs sent to Anthropic — disclose in ToS, offer
team-level opt-out toggle that disables the troubleshooting LLM pass).

## 2. Monetization strategy

### Principles
- **Free tier exists to feed the AI-deploy wow moment**, not to host production for free → sleep-after-idle.
- Game servers are **margin anchors** (predictable RAM-based pricing); app platform is the **growth wedge**.
- Pricing in MXN and USD (existing dual-currency invoicing supports this).

### Application hosting

| | Free | Starter $5/mo | Pro $15/mo | Team $49/mo |
|---|---|---|---|---|
| Apps | 1 (sleeps after 30 min idle) | 3 | 10 | 25 |
| RAM/app | 256 MB | 512 MB | 2 GB | 4 GB |
| Databases | shared 256 MB | 1 GB | 10 GB | 50 GB |
| Custom domains | — | ✔ | ✔ | ✔ |
| Preview environments | — | — | 2 concurrent | 10 concurrent |
| AI assistant | 20 msg/mo, read-only tools | 200 msg/mo | 1,000 msg/mo + auto-heal | unlimited* + Opus reports |
| Team members | 1 | 1 | 3 | 10 |
| Build minutes | 100/mo | 500/mo | 2,000/mo | 10,000/mo |

### Game servers (Minecraft anchor; FiveM/Rust/ARK/Palworld at +premium)

| | Copper 2 GB | Iron 4 GB | Gold 8 GB | Netherite 16 GB |
|---|---|---|---|---|
| Price | $6/mo | $11/mo | $20/mo | $38/mo |
| Slots | ~10 | ~25 | ~60 | unlimited |
| Backups | daily ×3 | daily ×7 | 6h ×14 | hourly ×30 |
| AI ops (crash diagnosis, lag analysis) | basic | ✔ | ✔ + auto-restart rules | ✔ |

### Databases (standalone)
MySQL/PostgreSQL/Redis: $4/mo per GB-RAM tier with daily backups; bundled free within app plans.

### Levers
- **Trial:** 14 days of Pro on signup (card-less), one game server 72h trial with card.
- **Annual:** 2 months free (pay 10/12) — aligns with existing renewal accounting.
- **Agency plan ($149/mo):** white-label client portal subset, 50 apps, priority support, reseller
  invoicing (Quotation system already exists and fits this).
- **Usage add-ons:** extra build minutes, extra AI messages, extra backup retention — metered via
  `usage_samples`/`ai_messages`, billed through existing invoice items.
- **Migration concierge:** free "move from cPanel/other host" done by the AI + a human check — CAC weapon for the MX SMB market.
