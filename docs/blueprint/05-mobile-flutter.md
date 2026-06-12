# 05 — Flutter Mobile App: Architecture & UX Flows

## 1. Scope

The Flutter app is the **client surface only** (no admin). It consumes API v2 exclusively — feature
parity is defined by the OpenAPI spec, not by the React portal. Platforms: iOS + Android.

## 2. Architecture

```
flutter_app/
├── packages/
│   ├── roke_api/            # generated Dart client from docs/openapi/v2.yaml (openapi-generator)
│   ├── roke_design/         # design system: tokens, theming (dark-first), components
│   └── roke_realtime/       # Reverb (pusher-protocol) channel client + Wings WS console client
├── lib/
│   ├── app/                 # router (go_router), DI (riverpod), flavors (dev/staging/prod)
│   ├── core/                # auth session, secure storage, error mapping, analytics
│   └── features/
│       ├── auth/            # email+password, Google, biometric unlock (local_auth),
│       │                    # token in flutter_secure_storage; 2FA TOTP entry
│       ├── projects/        # list, project home, environment switcher
│       ├── deployments/     # history, live build logs (Reverb), deploy/rollback actions
│       ├── resources/       # status, metrics charts, scale, start/stop/restart, env vars (masked)
│       ├── game_servers/    # console (xterm-style over Wings WS), players, power, backups
│       ├── ai_assistant/    # chat (SSE stream), action-confirmation cards, "Fix it" deep links
│       ├── billing/         # plans, invoices, payment methods (Stripe SDK), usage
│       └── notifications/   # FCM handling, notification center, per-event preferences
└── test/ · integration_test/
```

- **State:** Riverpod (async providers per API collection, websocket-driven invalidation).
- **Auth:** Sanctum token grant for mobile (`POST /v2/auth/token` with device name); biometric gate
  re-encrypts the stored token; refresh by silent re-auth.
- **Offline:** read-cache (drift/sqlite) for projects/resources lists; mutations require connectivity.

## 3. Push notifications (FCM + APNs)

Backend: notification fan-out listener (doc 01 §4) → FCM topics per user. Events → notification types:

| Event | Push | Tap action |
|---|---|---|
| DeploymentSucceeded | "✅ {app} deployed to production (34s)" | deployment detail |
| DeploymentFailed | "❌ {app} build failed — {error_summary}" + **"Apply fix"** action button when auto-fixable | diagnosis screen |
| Resource degraded / OOM | "⚠️ {app} memory at 94%" | metrics screen (AI suggests scale) |
| Downtime (Uptime Kuma webhook) | "🔴 {app} is down" | resource + AI diagnosis |
| BackupCompleted/Failed | silent / alert | backups list |
| Invoice due / payment failed | alert | billing |
| AiAction proposed | "Claude wants to rollback {app} — review" | confirmation card |

The **"Apply fix" push action** is the signature mobile moment: deployment fails while you're away,
the push tells you the root cause, one tap applies the AI-proposed fix.

## 4. Key UX flows

### Onboarding (target: first deploy < 3 minutes)
1. Sign up (Google one-tap or email) → 2. "What do you want to launch?" (App / Game server / Database)
→ 3a. App: connect GitHub (in-app browser → App install) → pick repo → **detection runs with live
progress** ("Found Laravel 11 + MySQL + Redis") → recommended config card (plan, region, resources) →
"Deploy" → live build log → confetti + URL.
→ 3b. Game: pick game → version (recommended pre-selected) → slots/RAM slider with live price →
region → pay (saved card / Apple Pay via Stripe) → provisioning progress (steps from orchestration) →
connect address + copy button.

No step asks for anything the platform can infer. Free tier deploys without a card.

### Deploy & rollback
Project → environment tab → "Deploy" (branch picker, defaults to tracked) → log stream → success/diagnosis.
History list: each row shows sha, message, who (or 🤖), duration; long-press → rollback (confirm sheet
shows what will change).

### Game console
Server screen: status header (players, TPS, RAM), console tab (Wings WS, command input with
autocomplete for common commands), quick actions (restart, backup now), settings tab (whitelisted vars).

### AI assistant
Persistent FAB on project/resource screens (opens with that context). Chat renders: token stream,
tool-activity chips ("Reading logs…"), **action cards** for destructive proposals (what/why/cost-delta +
Confirm/Reject), and inline charts when the agent cites metrics.

## 5. Parity & rollout

Phase 1 (MVP): auth, projects/resources read, deployment history + logs, game console, push, AI chat read-only tools.
Phase 2: deploy/rollback/scale actions, billing, env vars, AI safe_write tools.
Phase 3: full onboarding (GitHub connect + first deploy in-app), Apple Pay / Google Pay, AI destructive confirmations.
