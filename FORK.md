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
This is not a rewrite. Backend AND frontend generation are both covered now (see "What's fixed"
below); **MobileApp generation remains explicitly out of scope and untouched** (`src/Generators/
MobileApp/**`) — it is opt-in, disabled by default, and never enabled by a legacy-repointed module.

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
- **Frontend generation** (`src/Generators/Frontend/**`) — the same class of gap as backend routing:
  every route/component/stub hardcoded `uuid` as a vue-router path segment, a JS variable/prop, or an
  API-response field read, with zero `has_uuid` awareness. A pre-existing `features.frontend.view.
  idParam` escape hatch already existed (used inconsistently, and always defaulting to the bare literal
  `'uuid'`) — now resolved centrally: `FrontendRoutesGenerator::$idParam` (constructor-computed) and
  `BaseComponentGenerator::idParam()` (a shared method every other Frontend component generator calls,
  since they all extend it) both resolve `idParam ?? (ModuleConfigContract::hasUuid() ? 'uuid' :
  'id')`, and every generator/stub that builds a route, an API request URL, or reads this module's own
  record identifier off a fetched response now goes through one of those two. Covers: routes.ts (list/
  create/edit/delete/view/action-page routes), the CRUD forms+pages (Create/Edit/Delete), the View/
  Details surface (modal, layout, history, overview), delegation/custom-feature/action components
  (including the delegating module's own `parentKey` default, the same four-independent-hardcoded-
  copies shape the backend `parentKey` fix already closed), and `PlaywrightTestGenerator` (its own
  `$idParam`, since it extends `BaseGenerator` directly — the frontend twin of the backend
  `PhpUnitTestGenerator` sweep: fixed the two places a generated e2e spec reads `.data.uuid` off a
  create response, via an `__ID_PARAM__` nowdoc placeholder).
- **`RelatedRecordLink` cross-module identifier resolution** — closing the gap the frontend pass above
  flagged but deliberately left open (see the removed bullet below): a generated FK cell renderer's
  `<RelatedRecordLink module="{RelatedModule}" :uuid="row.x?.uuid">` hardcoded the *related* module's
  own identifier field, not just the current module's. `BaseComponentGenerator::resolveRelatedIdParam()`
  resolves it via `PathManager::findModuleInRegistry()` (the same lookup shape
  `BaseServiceGenerator::resolveChildAuditColumn()` already established for a delegation child's audit
  columns), defaulting to `'uuid'` when the related module isn't registered yet — unchanged from before
  this fix, and consistent with `RelatedRecordLink.vue` itself degrading to inert text for an
  unregistered target regardless of prop name. `FrontendRoutesGenerator::generateModuleConfigExport()`
  now also emits `idParam` on every `{Module}ModuleConfig`, so a *different* module's `RelatedRecordLink`
  pointed at *this* one can resolve it the same way. On the `shelui_erp` side (a different, hand-written
  repository — see below): `useEntityNavigation.ts`'s `EntityModuleConfig` gained `idParam?: string`, and
  `RelatedRecordLink.vue` now binds its target panel's identifier prop dynamically
  (`moduleConfig.value?.idParam ?? 'uuid'`) instead of a hardcoded `:uuid="uuid"` — its own external
  `uuid` prop is unchanged, since every caller (hand-written and generated) still passes the source
  record's relation id under that name; only the prop key *forwarded* onto the resolved target component
  varies. Full suite: 1402 tests passing (was 1334 before the frontend pass began).
- **Creator/updater actor identity, default sort field, and `hasUuid()` on the model itself** — found
  while first generating a real `has_uuid: false` module with worker-attributed (not user-attributed)
  audit columns (`shelui_erp`'s `PermissionGroup`/`Permission`, against `permission_group`/`permission`):
  `ModelGenerator::generateAuditRelationships()` hardcoded `creator()`/`updater()` to point at
  `Users\UsersModel` regardless of what the audit columns actually mean, and `CreateServiceGenerator`/
  `EditServiceGenerator` hardcoded the assigned value to `Auth::id()`, with no way to say "this table's
  `created_by`/`modified_by` mean a different actor entity, and/or a different id than the authenticated
  model's own" — new `ModuleConfigContract::creatorUpdaterModel()`/`creatorUpdaterActorValue()` cover
  both independently (`"creator_updater_model": "...\\WorkerModel"`,
  `"creator_updater_actor_value": "Auth::user()?->worker_id"`). Separately, `BaseServiceGenerator::
  generateSortableFields()`'s emitted list was never the same thing as the DEFAULT sort column: `App\
  Project\_Src\ListServiceTrait::processListQuery()` (the shelui_erp app-side shared trait) hardcoded
  `'created_at'` as both the no-`?sort=`-given default and the invalid-`?sort=`-given fallback, 500ing
  every bare list request against a module whose timestamps are renamed via `timestamp_columns` —
  `ListServiceGenerator` now emits a `$defaultSortField` static property (`ModuleConfigContract::
  hasTimestamps()`/`timestampColumns()`-derived) that the trait reads via the same `getStaticProperty()`
  pattern `$sortableFields` already used, falling back to today's `'created_at'` for any module that
  doesn't declare it. Last: `ModelGenerator` never emitted a `$hasUuid` override at all for a
  `has_uuid: false` module — the migration/routes correctly dropped/rewired around the missing `uuid`
  column, but `ModelClass::hasUuid()` itself still answered `BaseModel`'s own default `true`, so any
  caller branching on the model's own `hasUuid()` (confirmed live: `App\Project\_Src\
  BaseActivityListService::execute()`, which trusts `uuid` over `id` when `hasUuid()` says true) got the
  wrong answer and 422'd a numeric id against the uuid-format regex. `ModelGenerator` now emits
  `protected static bool $hasUuid = false;` when `has_uuid` is false, silent (unchanged) otherwise.

## What's deliberately NOT fixed

- **MobileApp generation** (`src/Generators/MobileApp/**`) still hardcodes `uuid` throughout its own
  routes/components/tests, and was not opened at all during the frontend pass. Opt-in, disabled by
  default, never enabled by a legacy-repointed module — see "Scope" above.
- **`RoutesGenerator::generateCustomFeatureRoutes()`** and **`ControllerGenerator::
  generateCustomFeatureMethods()`** (both marked `@deprecated Use generateDelegationRoutes/Methods or
  generateActionRoutes/Methods`) — confirmed via grep to be dead code, never called from `generate()`
  or anywhere else in this package. Left untouched rather than fixing unreachable code.
- **A delegation's RELATED/child record's own key** — on the backend, the `{itemUuid}` route segment
  and `$related->uuid` in `DelegationServiceGenerator`'s edit/view/delete methods and
  `PhpUnitTestGenerator`'s matching tests; on the frontend, the identical shape shows up as the
  child/item's own key in `CustomFeatureTabComponentGenerator`/`CustomFeatureModalComponentGenerator`
  (the literal `{uuid}` item-route placeholder, `${deletingItem.value.uuid}`). This is the RELATED/child
  module's own `has_uuid`, a separate question from the delegating (parent) module's `parentKey`/
  `idParam` fixed above. No real shelui_erp delegation currently points at a `has_uuid: false` child
  module; deferred rather than guessed at, consistently on both backend and frontend. (`RelatedRecordLink`
  itself — the identical shape for a plain FK, not a delegation — is no longer in this bucket: see
  "RelatedRecordLink cross-module identifier resolution" above.)
- **`inline_items`' own child-row `uuid` field** (`unset($inlineItem['uuid'])` in
  `CreateServiceGenerator`/`EditServiceGenerator`/`BaseServiceGenerator` on the backend; `item.uuid` as
  a Vue `:key` in `fields/line-items-view-wrapper.stub` on the frontend) — a separate, self-contained
  convention for identifying rows *within* an inline-items JSON payload or list, unrelated to whether
  the parent or child module has a real `uuid` column. `inline_items` children are this project's own
  generator-native tables, not legacy-repointed ones, so this was never in scope.
- **`inline_items`' own child-row creator/updater actor value** — `BaseServiceGenerator::
  buildInlineInjectArray()` still hardcodes `Auth::id()` for a child row's own audit column
  (`resolveChildAuditColumn()`'s companion value, parallel to the `creator_updater_actor_value` fix
  above but for the inline-items code path specifically). No `inline_items` child in shelui_erp
  currently has worker-attributed (non-`Auth::id()`) audit columns, so this was left as a known,
  documented gap rather than guessed at — same reasoning as the delegation child-key gap below.
- **Cosmetic internal naming** — a JS variable, Vue prop, or PHPUnit local variable literally named
  `uuid` whose *value* is already correctly sourced from the right place (e.g. a route param already
  resolved via `idParam`/`routeKeyParam`) is left named `uuid` rather than renamed to `id`/`recordKey`
  throughout. Renaming these has no functional effect and was judged not worth the diff size/risk;
  every such spot found during the frontend pass is called out explicitly in its own commit/PR
  discussion rather than silently skipped.

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

All four deviations patched and tested across both backend and frontend generation, plus the
cross-module `RelatedRecordLink` identifier-resolution gap the frontend pass surfaced (full suite
green after every change; new tests added per capability — see git log). `docs/specs/
AUTH_DATA_ARCHITECTURE.md` §5.2 in shelui_erp has been updated to reflect `LegacyBaseModel`'s removal
in favor of this fork's per-model column-naming emission. MobileApp generation remains unfixed and
out of scope, deferred by explicit user request.
