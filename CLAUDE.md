# LoraPMS — AntTech Task Tracking (mandatory)

This repository is linked to AntTech project **23** ("Villa Mucho") via `.anttech/config.json`.
All work — code, analysis, infra, data repairs — is tracked in AntTech (MCP tools prefixed `mcp__anttech__`).

## Session Start
Call `mcp__anttech__get_handoff` for project 23 FIRST — it returns last session context, in-progress tasks, and blockers.

## Session End
Call `mcp__anttech__log_session` with: summary, current_state, next_steps, warnings, files_modified.

## Before ANY code change

**Planned work (features, multi-step):** discuss with the user → `create_plan` (sets Audit Plan → dev tasks → Audit Dev → Test Dev; wire `depends_on`) → work tasks in dependency order with `start_work` / `complete_work`.

**Single task (bug fix, quick change):** `semantic_search` first → no match → `create_task` (call `list_tags` first) → `start_work` → work → `complete_work`.

`complete_work` always needs a summary AND verification (commands run, observed results, stranger-repeatable steps). Tasks may have expert agents assigned — call `get_agent_directive(agent, task_id)` at the moment you begin that kind of work; `complete_work` refuses if an assigned agent was never consulted.

**No task = no code.**

## Project facts

- Laravel + Vue 3 (Inertia) multi-tenant PMS. PMS routes live under the `/pms` prefix.
- Locales: `lang/sq.json` + `lang/en.json` — every user-facing string goes in BOTH, keep key parity. Product copy is Albanian-first; report metric explanations live under `reports360.help.*`.
- Chat/PR discussion with Renato in English; Albanian only in product copy and UI strings.
- **Reports standing rules:** every evaluated report gets explanatory tooltips (InfoTip) in both locales, and dual-currency display via the `useReportCurrency` composable (selling-currency amount with the base equivalent beneath; `pricingCurrency` + `baseToPricingRate` in the controller payload) — nothing about currency display may be hardcoded.
- Local dev (Renato's machine): Laragon — PHP 8.5.8, MySQL 8.4; `php artisan serve` on port 8000; tenant 2 is mapped to localhost (use 127.0.0.1 if localhost resolves to IPv6).
- Production: Hetzner, SSH alias `lora-production`, app at `~/lorapms.com/current`; tenant 2 = Saturn Apart Hotel (saturn.lorapms.com). One-off prod scripts: write locally → `scp` to `/tmp` → `php artisan tinker --execute="require '/tmp/x.php';"` → remove. Data repairs always run a dry-run first and apply only on Renato's go.
- Branch flow: work branches → PR to `staging` (Renato merges) → cherry-pick promotion PR to `main` (Renato merges) → auto-deploy to production.

## Always
- Before `git push`, scan the diff for leaked secrets (API keys, tokens, private keys, passwords in connection strings) and remove them.
- Read AntTech tool output — blocks, hints, and gate warnings are guidance, not noise.
