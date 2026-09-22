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

Intentionally narrow — everything else (morphs, blueprint generation) stays identical to upstream.
This is not a rewrite. The boundary is **backend generation only**: frontend and MobileApp generation
still hardcode `uuid` throughout, deliberately left untouched (see "What's deliberately NOT fixed"
below) since every shelui_erp Core module's frontend is hand-written
(`features.frontend.enabled: false`, see shelui_erp's own `CLAUDE.md`) and MobileApp is opt-in,
disabled by default, and never enabled by a legacy-repointed module.

## What's fixed

All four deviations above, across every backend generation path that is actually reachable from
`make:module`/`make:modules-from-db`:

- **`ModuleConfigContract`**: new `timestampColumns()`, `softDeleteType()`/`softDeleteColumn()`,
  `creatorUpdaterColumns()` accessors — the same explicit-flag-with-a-documented-default pattern as
  the existing `has_uuid`/`has_soft_deletes`/etc. accessors, so every generator resolves these facts
  identically instead of re-deriving them.
- **`MigrationGenerator`/`ModelGenerator`**: emit the configured column names (timestamps, soft-delete
  column + type — `'flag'` selects `App\Project\_Src\Traits\HasIsDeleted` instead of Laravel's
  `SoftDeletes`, ported from ongeza-pro — and creator/updater columns, including a single-actor
  creator-only module) directly into the migration/model file, replacing what a hand-maintained
  `LegacyBaseModel` subclass used to provide via inheritance in shelui_erp (removed; models extend the
  shared `BaseModel` directly now).
- **Create/Edit service stubs, `BaseServiceGenerator`, `FactoryGenerator`, `SeederGenerator`**: actor
  column population, filterable/sortable field lists, generated factories/seeders/PHPUnit fixtures all
  use the resolved column names instead of the Laravel defaults, and correctly omit what a module
  doesn't have (no audit columns, no timestamps, single-actor).
- **Routing** — `[[routeKeyParam]]`/`[[routeKeyLabel]]`/`[[routeKeyRule]]` (`BaseGenerator`'s shared
  defaults) cover every standard CRUD route/controller/service, **plus** the delegation `parentKey`
  default (four independent call sites: `DelegationConfigNormalizer::normalize()`, `RoutesGenerator`,
  `ControllerGenerator`, `DelegationServiceGenerator` — all previously hardcoded `'uuid'` separately),
  the action-splash route/controller-method/service (including the app-level companion fix in
  shelui_erp's `HasActivityHistory`/`BaseActivityListService`), and an action's own urlParams-driven
  record-lookup seam (`ActionServiceGenerator`).
- **List/bulk actions** — `ListServiceGenerator` emits `$bulkRecordKeyColumn` for a `has_uuid: false`
  module; shelui_erp's own `App\Project\_Src\ListServiceTrait::processBulkAction()` (the shared
  dispatcher, hand-maintained app-side) and `BulkActionServiceGenerator`'s per-key action services
  both read it instead of assuming `'uuid'`.
- **`PhpUnitTestGenerator`** — had zero `has_uuid` awareness anywhere (confirmed via grep before this
  fix, not an assumption): every generated test method hardcoded `$fixture->uuid`. Fully swept —
  fixture helper, every CRUD/forbidden/activity/delete-check/soft-delete/bulk-action/delegation test
  method now resolves `$this->routeKeyParam` (`'uuid'` or `'id'`) consistently, plus the flag-vs-
  timestamp soft-delete assertion split (`assertSoftDeleted()` with a custom `$deletedAtColumn`, or
  `assertDatabaseHas([..., $flagColumn => 1])` for the `'flag'` type).

## What's deliberately NOT fixed

- **Frontend generation** (`src/Generators/Frontend/**`) and **MobileApp generation**
  (`src/Generators/MobileApp/**`) still hardcode `uuid` throughout their own routes/components/tests.
  Not reachable for a shelui_erp Core module (frontend is hand-written; MobileApp is opt-in and never
  enabled for a legacy-repointed module) — see "Scope" above.
- **`RoutesGenerator::generateCustomFeatureRoutes()`** and **`ControllerGenerator::
  generateCustomFeatureMethods()`** (both marked `@deprecated Use generateDelegationRoutes/Methods or
  generateActionRoutes/Methods`) — confirmed via grep to be dead code, never called from `generate()`
  or anywhere else in this package. Left untouched rather than fixing unreachable code.
- **A delegation's RELATED/child record's own key** (the `{itemUuid}` route segment, `$related->uuid`
  in `DelegationServiceGenerator`'s edit/view/delete methods and `PhpUnitTestGenerator`'s matching
  tests) — this is the RELATED module's own `has_uuid`, a separate question from the delegating
  (parent) module's `parentKey` fixed above. No real shelui_erp delegation currently points at a
  `has_uuid: false` related module; deferred rather than guessed at.
- **`inline_items`' own child-row `uuid` field** (`unset($inlineItem['uuid'])` etc. in
  `CreateServiceGenerator`/`EditServiceGenerator`/`BaseServiceGenerator`) — a separate, self-contained
  convention for identifying rows *within* an inline-items JSON payload, unrelated to whether the
  parent or child module has a real `uuid` column. `inline_items` children are this project's own
  generator-native tables, not legacy-repointed ones, so this was never in scope.

## Tracking upstream

The `upstream` remote points at the real `generator-engine`:

```
git remote add upstream https://github.com/joelnjoshkibona/generator-engine.git
git fetch upstream
git log upstream/main --oneline    # see what's new since v3.5.31
```

Periodically rebase/merge `upstream/main` to pick up real fixes; resolve conflicts against the
deviations documented above only.

## Status

All four deviations patched and tested (full suite green after every change; new tests added per
capability — see git log). `docs/specs/AUTH_DATA_ARCHITECTURE.md` §5.2 in shelui_erp should be
updated to reflect `LegacyBaseModel`'s removal in favor of this fork's per-model column-naming
emission.
