# 03 — AI Assistant & Smart Troubleshooting Architecture

## 1. Position in the system

The assistant is a **tool-using agent inside the Laravel backend**, evolved from the existing support
chat (`config/anthropic.php`). It does not get its own infrastructure credentials — every action goes
through the same internal services and policies a human user would hit.

```
User (web/Flutter chat, or "Fix this" buttons)
        │
        ▼
ConversationController ── SSE stream ──► client
        │
        ▼
AgentRunner (loop)
  ├── builds context pack (user, team, project, resource, recent events)
  ├── calls Anthropic Messages API (tool use)        model: claude-sonnet-4-6 (agent)
  │                                                  model: claude-haiku-4-5 (classify/summarize)
  ├── ToolRegistry resolves tool → RiskClassifier
  │      read          → execute immediately
  │      safe_write    → execute, audit
  │      destructive   → persist AiAction(status=proposed) → STOP, ask user
  └── tool results loop back until final answer
```

## 2. Tool catalog & risk tiers

| Tier | Behavior | Tools |
|---|---|---|
| `read` | auto-execute | get_resource_status, get_deployment_logs, get_metrics, list_deployments, get_env_var_keys (names only, never values), get_invoice_status, search_docs, diagnose_failure |
| `safe_write` | auto-execute + audit + notify | trigger_deploy, restart_resource, create_preview_env, retry_failed_job, set_env_var (new keys), create_backup |
| `destructive` | **proposed → user must confirm** | rollback_deployment, scale_resource (changes billing), delete_resource, restore_backup, overwrite_env_var, stop_production_resource, change_plan |

Hard rules enforced in code (`ConfirmationGate`), not in the prompt:

1. Destructive tools **cannot execute** without a row in `ai_actions` with `status=confirmed` by the resource owner (or team admin). Confirmation is a separate authenticated API call — the model can't forge it.
2. The agent runs **as the user**: every tool call passes through the same Policies; the agent can never see another team's data because scoping happens in the query layer, not the prompt.
3. Secrets are write-only to the agent: it can set env vars, it can list key names, it can never read values.
4. Per-conversation budget caps (tool calls, tokens) + global rate limit; runaway loops abort with a summarized state.
5. Every executed action lands in `ai_actions` + `AuditLog` with full arguments — admin panel shows an "AI activity" feed.

## 3. Context pack (what the model sees)

Assembled per message, token-budgeted (~8k):

- User/team profile: plan tier, role, language (es/en — current support chat is bilingual).
- Focused resource (if conversation opened from a resource page): spec, status, current deployment, last 3 deployments with statuses.
- **Event window:** last 20 domain events for the project (provision steps, failures, scalings).
- On demand via tools: logs (tail + error-window extraction, not full dumps), metrics rollups.
- Platform knowledge: distilled docs (existing `Documentation` model) via simple retrieval.

## 4. Smart Troubleshooting Engine

Runs in two modes: **automatic** (every `DeploymentFailed` / `ResourceProvisionFailed` event) and
**interactive** ("Why is my deployment failing?").

```
DeploymentFailed
   │
   ▼
LogAnalyzer            extract error window from build/runtime logs (regex pass first:
   │                   exit codes, npm/composer/php/node signatures, OOM, port binds)
   ▼
FailureClassifier      Haiku call → one of ~25 failure taxa:
   │                   missing_env_var · composer_dep_conflict · node_version_mismatch ·
   │                   build_oom · migration_failed · port_in_use · dockerfile_error ·
   │                   healthcheck_timeout · dns_not_propagated · disk_full · …
   ▼
FixSuggester           taxon → fix template + Sonnet refinement with actual log excerpt
   │                   output: { root_cause, explanation, fixes[], auto_fixable: bool }
   ▼
Deployment.error_summary persisted; notification sent with the human-readable cause
   │
   └─ if auto_fixable (e.g. missing APP_KEY, known env var, RAM bump within plan):
      AI proposes the fix as an AiAction → one-tap "Apply fix" in the UI/push notification
```

Detection signal sources: Coolify deployment logs (API), container runtime logs, orchestration step
errors, Cloudflare DNS lookups, resource metrics (OOM ↔ usage_samples), env_var diff vs detection
engine expectations (e.g. Laravel without `APP_KEY`).

**Auto-fix policy:** auto-fixes are still AiActions; tier `safe_write` ones (set missing non-secret env
var, restart) can be applied automatically if the user enabled "Auto-heal" per environment (off by
default, opt-in toggle stored on `environments`).

## 5. Natural-language → action examples

| Utterance | Agent plan |
|---|---|
| "Deploy my GitHub repository" | list_repos → (pick/confirm) → create project → analyze → present detected stack + recommended plan → provision (safe_write per-step, paid plan attach asks confirmation) |
| "Create a Minecraft server with 20 slots" | catalog lookup → propose spec (Paper latest recommended, 4GB) + price → confirmation (creates billing) → ProvisionGameServerFlow |
| "Why is my deployment failing?" | get latest failed deployment → diagnose_failure → explain + propose fixes |
| "Rollback my last deployment" | list_deployments → identify last good → propose rollback_deployment (destructive → confirm) |
| "Increase RAM for this application" | current spec + plan limits → if within plan: scale (confirm, billing delta shown); if not: propose plan upgrade |
| "Restore yesterday's backup" | list backups → propose restore (destructive → confirm with explicit backup timestamp) |

## 6. Model & cost strategy

| Task | Model | Notes |
|---|---|---|
| Agent loop / chat | claude-sonnet-4-6 | tool use, ~bounded by budget caps |
| Failure classification, log summarization, title generation | claude-haiku-4-5 | already the support-chat model |
| Deep "explain my architecture" reports (paid tiers) | claude-opus-4-8 | rare, gated by plan |

Prompt caching on the static system prompt + tool definitions; conversation context trimmed via
rolling summary stored on `ai_conversations.context`. AI usage metered per team (`ai_messages.tokens_*`)
→ plan limits (see doc 06).
