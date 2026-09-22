# shelui-engine

Fork of [`blutrixx/generator-engine`](https://github.com/joelnjoshkibona/generator-engine), forked
from `main` at `v3.5.31` (commit `5f0da200d74a046ae04182b0a3ae53c089517350`) — the exact version
`shelui_erp/BACKEND` is currently pinned to.

## Why this fork exists

shelui_erp adapts to a real, live production database (see `shelui_erp`'s own
`docs/specs/AUTH_DATA_ARCHITECTURE.md`) instead of scaffolding new tables. Vanilla generator-engine's
`module.json` flags (`has_uuid`, `has_timestamps`, `has_soft_deletes`, `has_creator_updater`) already
correctly gate *whether* those columns appear (confirmed by reading `MigrationGenerator`/`ModelGenerator`
directly — this is real, working code, via the shared `ModuleConfigContract`), but not:

1. **Routing** — every `view`/`edit`/`delete`/`deleteCheck`/`actionSplash` route/controller/service stub
   hardcodes the literal string `uuid` (URL segment, PHP variable, array key, validation rule) with no
   flag gating it at all. shelui_erp's real tables have no `uuid` column and, at this database's scale,
   won't be given one — routing needs to be id-based instead.
2. **Column naming** — `has_timestamps`/`has_soft_deletes`/`has_creator_updater` control presence but
   always emit Laravel's own column names (`created_at`/`updated_at`, `deleted_at`, `created_by_id`/
   `updated_by_id`). The real tables use `created_date`/`modified_date`, `is_deleted` (int flag, not
   `deleted_at`), and `created_by`/`modified_by` (or just `created_by` alone, or neither).

## Scope

Intentionally narrow — everything else (delegations, actions/wizards, morphs, bulk_actions, blueprint
generation, frontend generation) stays identical to upstream. This is not a rewrite.

## Tracking upstream

The `upstream` remote points at the real `generator-engine`:

```
git remote add upstream https://github.com/joelnjoshkibona/generator-engine.git
git fetch upstream
git log upstream/main --oneline    # see what's new since v3.5.31
```

Periodically rebase/merge `upstream/main` to pick up real fixes; resolve conflicts against the four
deviations above only.

## Status

Not yet patched — this is the pre-divergence baseline, forked and ready for the four changes above.
