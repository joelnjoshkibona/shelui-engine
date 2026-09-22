# blutrixx/generator-engine

A config-driven code generation engine for Laravel + Vue 3 + NativePHP Mobile projects. Given a structured module-configuration array, the package emits a full set of backend (Laravel), frontend (Vue 3), and mobile app (NativePHP) source files for a module.

**Scope (as of v3.0.0): CRUD, actions, and delegations only.** An earlier blueprint-driven "UX Builder" pipeline (composites, wizards, shortcuts, dashboard quick-actions) has been removed entirely — see `CHANGELOG.md`.

The engine is config-source-agnostic. It can be driven by a UI that produces a config array (as in PROJECT_GENERATOR), by an Artisan command that introspects a database (as in SYSTEM_SHELL), or by any custom code that produces the same shape.

**Building a specific kind of module? See the [Examples section of the docs site](https://joelnjoshkibona.github.io/generator-engine/examples/) first** (source: `docs/examples/`) — task-oriented recipes (lookup tables, FK relationships, file uploads, parent-child `inline_items`, polymorphic `morphs`, related-record `delegations`, custom `actions`/`bulk_actions`) each pointing at a real, tested, permanent fixture. This document covers architecture and the full config reference; the docs site's Examples section covers "I want to build X — what do I actually write?"

Primary namespace: `Blutrixx\GeneratorEngine`

Sub-namespaces:

- `Blutrixx\GeneratorEngine\Generators\Backend\...`
- `Blutrixx\GeneratorEngine\Generators\Frontend\...`
- `Blutrixx\GeneratorEngine\Generators\MobileApp\...`
- `Blutrixx\GeneratorEngine\Schema\...`
- `Blutrixx\GeneratorEngine\Helpers\...`

---

## Architecture Overview

Generation follows a three-step pipeline:

```
Source of truth
      │
      ▼
  Config array  (GeneratorModule shape)
      │
      ▼
  Generators    (one class per artifact)
      │
      ▼
  Emitted files (PHP / Vue / JSON)
```

**Config sources.** The engine is config-driven: it does not care how the config array was produced.

- V1 builds the config through its database-backed UI (`ModuleGenerationService`).
- SHELL builds the config by introspecting a live database with `SchemaIntrospector`, then passing the result through `IntrospectionToConfig::build()`.

Both paths produce the same GeneratorModule-shaped array and pass it to the same generator classes.

**PathManager.** A static service-locator that holds all cross-cutting context: the project output root, the project context, the module registry, the FK graph, and the template root paths. Generators read from `PathManager` rather than accepting these as constructor arguments.

**Generators.** Each generator receives `($moduleName, $moduleGroup, $config)` and writes one or more files to the path computed by `PathManager`. Generators are standalone — they do not call each other, and they do not hit the database.

---

## Installation

The package is published on Packagist:

```bash
composer require blutrixx/generator-engine:^3.0
```

That's the only step needed for any standard Laravel application.

**Pinning a version** in `composer.json`:

```json
{
  "require": {
    "blutrixx/generator-engine": "^3.0"
  }
}
```

**Installing directly from Git** (for forks, pre-release branches, or environments that don't use Packagist):

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/joelnjoshkibona/generator-engine" }
  ],
  "require": {
    "blutrixx/generator-engine": "^3.0"
  }
}
```

**Runtime requirements** (from `composer.json`):

| Dependency | Constraint |
|---|---|
| PHP | `^8.2` |
| `illuminate/support` | `^11.0 \| ^12.0 \| ^13.0` |
| `illuminate/filesystem` | `^11.0 \| ^12.0 \| ^13.0` |

The package has zero `App\` dependencies at runtime. It works in any Laravel 11 / 12 application without modification, and in standalone PHP scripts that happen to have the two `illuminate/*` packages on their classpath.

---

## Public API

### 1. Bootstrap PathManager

`PathManager` must be seeded before any generator is invoked. All setters are static.

```php
use Blutrixx\GeneratorEngine\Generators\PathManager;

// Required: absolute path to the output root.
// Backend files land in {root}/BACKEND, frontend in {root}/FRONTEND.
PathManager::setProjectRoot('/var/www/my-project');

// Required: project identity used by some generators.
PathManager::setProjectContext(['id' => 42, 'uuid' => 'abc-123-...']);

// Optional but strongly recommended: module registry.
// Enables accurate PHP namespace and Vue import-path resolution for FKs.
PathManager::setModuleRegistry([
    ['name' => 'Users',    'module_type' => 'Core',   'table_name' => 'users'],
    ['name' => 'Products', 'module_type' => 'Custom',  'table_name' => 'products'],
    // ...
]);

// Optional: wire up a callable to receive warnings during generation.
PathManager::setIssueHandler(function (string $message, string $level): void {
    // e.g. forward to Artisan output, a logger, or a collection
    logger()->{$level}($message);
});

// Optional: FK graph for delete-check generation.
// Shape: [target_table => [['source_table' => string, 'source_column' => string], ...]]
PathManager::setForeignKeyGraph($graph);

// Optional: set a module sub-group (injected into output paths).
PathManager::setModuleSubGroup('Finance');  // → .../Custom/Finance/Invoices
```

### 2. Build a config from DB introspection

`IntrospectionToConfig` converts raw `SchemaIntrospector::columns()` output into a GeneratorModule config array.

**Prefer `IntrospectionToConfig::strict()`.** Strict mode throws `InvalidArgumentException` if `$meta` omits any of six schema-derived facts (`has_timestamps`, `has_soft_deletes`, `has_uuid`, `has_creator_updater`, `file_columns`, `index_groups`) instead of silently defaulting them. This exists because a downstream caller once forgot to thread `has_soft_deletes` through `$meta` — `build()` silently defaulted it to `false`, and every generated module quietly lost `SoftDeletes` until migrations had been hand-patched three times before anyone noticed. `SchemaIntrospector::meta()` returns exactly those six keys, so spreading its result into `$meta` satisfies strict mode with no extra work:

```php
use Blutrixx\GeneratorEngine\Schema\IntrospectionToConfig;

$columns = $schemaIntrospector->columns('products');

$config = IntrospectionToConfig::strict()->build($columns, [
    'module_name' => 'Products',   // StudlyCase singular
    'module_type' => 'Custom',     // StudlyCase group
    'table_name'  => 'products',   // snake plural
    'id_type'     => 'uuid',       // 'uuid' | 'bigint'
    'group_name'  => null,         // optional sub-group
    ...$schemaIntrospector->meta(), // has_timestamps, has_soft_deletes, has_uuid,
                                     // has_creator_updater, file_columns, index_groups
]);
```

Call sites that genuinely can't supply all six keys yet (e.g. a hand-authored `$meta` bag that predates this contract) can opt out explicitly via `IntrospectionToConfig::lenient()->build(...)` — equivalent to the old `new IntrospectionToConfig()->build(...)` call, which silently defaults any missing key rather than throwing.

The returned `$config` is the same shape that V1's UI produces — pass it directly to generators.

### 3. Run generators

Each generator follows the same constructor / `generate()` pattern:

```php
use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;

$moduleName  = 'Products';
$moduleGroup = 'Custom';

(new ModelGenerator($moduleName, $moduleGroup, $config))->generate();
(new MigrationGenerator($moduleName, $moduleGroup, $config))->generate();
(new ListPageGenerator($moduleName, $moduleGroup, $config))->generate();
// ... repeat for each desired generator
```

All output paths are resolved through `PathManager` using the project root set earlier.

By default, `generate()` returns `false` and skips writing when the target file already exists (preserving hand-edits). To overwrite existing files, call `setForce(true)` before `generate()`:

```php
// Re-running on an existing module: files are skipped unless force is set.
$gen = new ModelGenerator($moduleName, $moduleGroup, $config);
$gen->setForce(true); // overwrite any existing file
$gen->generate();
```

### 4. PathManager utility methods

| Method | Returns | Purpose |
|---|---|---|
| `PathManager::getProjectRoot()` | `?string` | Current project root |
| `PathManager::getBackendBasePath()` | `string` | `{root}/BACKEND` |
| `PathManager::getFrontendBasePath()` | `string` | `{root}/FRONTEND` |
| `PathManager::getBackendModulePath($group, $name)` | `string` | Full backend module directory |
| `PathManager::getFrontendModulePath($group, $name)` | `string` | Full frontend module directory |
| `PathManager::findModuleInRegistry($name)` | `?array` | Look up a module by name |
| `PathManager::findModuleByTable($table)` | `?array` | Look up a module by table name |
| `PathManager::resolveBackendModuleNamespace($name)` | `string` | Resolve PHP namespace for a related module |
| `PathManager::resolveFrontendImportSegment($name)` | `string` | Resolve Vue import path segment |
| `PathManager::getForeignKeyGraph()` | `array` | Current FK graph |
| `PathManager::normalizeGroupName($group)` | `string` | PascalCase normalization of a module type |
| `PathManager::resetProjectRoot()` | `void` | Clear project root + context |
| `PathManager::getMobileAppBasePath()` | `string` | `{root}/MOBILE_APP` |
| `PathManager::getMobileAppModulePath($group, $name)` | `string` | Full mobile frontend module directory |
| `PathManager::getMobileAppBackendModulePath($group, $name)` | `string` | `{root}/MOBILE_APP/app/Modules/{group}/{name}` |
| `PathManager::getMobileAppBackendTemplatePath()` | `string` | Mobile backend stub directory (overridable) |

---

## Configuration Shape

The GeneratorModule config array is the contract between config producers (V1 UI, `IntrospectionToConfig`) and generators. Top-level keys:

| Key | Type | Description |
|---|---|---|
| `module_name` | `string` | StudlyCase singular name, e.g. `"Products"` |
| `module_type` | `string` | Module group/category, e.g. `"Custom"`, `"Core"` |
| `table_name` | `string` | Database table, snake plural, e.g. `"products"` |
| `id_type` | `string` | Primary key strategy: `"uuid"` or `"bigint"` |
| `columns` | `array` | Column definitions including type, nullable, FK metadata, and per-feature visibility flags |
| `morphs` | `array` | Polymorphic morph pairs auto-detected from `*_type` + `*_id` column pairs. See [docs: Examples › Polymorphic (morphs)](https://joelnjoshkibona.github.io/generator-engine/examples/morphs). |
| `delegations` | `object` (keyed by delegation key) | Related modules rendered as embedded sub-tabs on the view page. Hand-authored, not introspected — see [docs: Examples › Related-record tabs](https://joelnjoshkibona.github.io/generator-engine/examples/delegations). |
| `actions` | `object` (keyed by action key) | Custom action definitions (state transitions, etc.). Hand-authored — see [docs: Examples › Custom & bulk actions](https://joelnjoshkibona.github.io/generator-engine/examples/actions) (also covers the separate `bulk_actions` mechanism). |
| `seeder` | `array` | Seed record definitions used by `SeederGenerator` |
| `menu_config` | `array\|null` | Sidebar menu entry configuration used by `MenusJsonGenerator` |
| `features.backend.*` | `array` | Per-operation backend config: endpoint paths, permissions, filterable/sortable fields, validation rules |
| `features.frontend.*` | `array` | Per-operation frontend config: list columns, form fields, view fields |
| `inline_items` | `array\|null` | Parent-child inline data (e.g. Order Items). Each key maps to an `InlineItemConfig` array describing the child module, `parent_fk`, field definitions, and optional `inject_from_parent` mappings. Drives `[[inlineItemsBlock]]` / `[[inlineItemsFieldDefs]]` on frontend forms and `[[inlineItemsSave]]` / `[[inlineItemsSync]]` / `[[inlineItemsLoad]]` on backend services. |

`features` contains sub-keys: `backend` (with `list`, `create`, `view`, `edit`, `delete`) and `frontend` (same operations). Each sub-key holds the relevant field lists and endpoint metadata consumed by the corresponding generator.

#### `inline_items` shape

`inline_items` is a **flat list** of item configs — not a map keyed by a
group/label name. (An earlier version of this doc showed a `'line_items' =>
[...]` wrapper key around the list below; every real consumer —
`CreateFormGenerator`, `EditFormGenerator`, `CreateServiceGenerator`,
`EditServiceGenerator`, `ViewServiceGenerator` — does `foreach
($config['inline_items'] ?? [] as $item)` expecting `$item` to be one of
these config dicts directly, so that extra key never did anything.)

```php
'inline_items' => [
    [
        'key'                       => 'line_items',
        'label'                     => 'Line Items',
        'child_module'              => 'OrderItems',
        'child_group'               => 'Custom',
        // Set this to true when the child module has the project's
        // standard created_by_id/updated_by_id columns (the default
        // convention almost every module has) -- see the note below.
        'child_has_creator_updater' => true,
        'parent_fk'                 => 'order_id',
        'primary_field'             => 'product_name',
        'fields'                    => [
            ['key' => 'product_name', 'label' => 'Product',  'type' => 'text',   'required' => true],
            ['key' => 'quantity',     'label' => 'Qty',       'type' => 'number', 'required' => true],
            ['key' => 'unit_price',   'label' => 'Price',     'type' => 'number', 'required' => true],
        ],
        // Optional: propagate parent fields to every child row at save time
        'inject_from_parent' => [
            ['child_field' => 'currency', 'parent_field' => 'currency'],
        ],
    ],
]
```

`child_group` is a direct namespace/path segment — same convention as
`module_type` everywhere else in this document (`Core`, `System`, `Custom`,
or anything a project invents), with no forced nesting under `System`. See
`tests/Fixtures/integration-schemas/orders-suite/` for a complete, tested
`Orders`/`OrderItems` example exercising this end to end (including
`inject_from_parent`).

**`child_has_creator_updater`.** inline_items is entirely hand-authored —
nothing here is ever introspected from the child module's own schema, so the
generator has no way to know whether the child table has creator/updater
columns. Set this to `true` when it does (the project-wide default
convention for almost every module); leave it `false`/omitted when it
doesn't. Getting it wrong in the `true` direction on a child module that
genuinely lacks these columns raises an "Unknown column" SQL error; getting
it wrong in the `false` direction on a child module that has them (NOT NULL
`created_by_id`, the common case) raises "Field 'created_by_id' doesn't have
a default value" the first time a real create/edit request runs — this was a
real, confirmed bug (found via a live `OrdersCreateService::execute()` call
against a real database, not just generated-source inspection) until this
flag was added. `CreateServiceGenerator` sets `created_by_id` on every child
row it creates; `EditServiceGenerator`'s sync sets `created_by_id` on rows it
creates (no uuid yet) and `updated_by_id` on rows it updates via
`updateOrCreate` (existing uuid) — never both on the same write, so an edit
never overwrites the original creator.

**Delete behavior.** `DeleteServiceGenerator` cascade-deletes every
`inline_items` child when the parent is deleted:
`{ChildModel}::where($parentFk, $model->id)->delete()`, emitted
automatically, no config needed. This is unconditional by design — an
inline_items child has no independent lifecycle to begin with (the parent's
own edit-sync logic already deletes any child row dropped from the form
payload on every edit, see `generateInlineItemsSync()`), so cascading on
parent delete is just the same ownership rule applied one more time. It
automatically respects the child model's own soft/hard delete mode (goes
through the child's own Eloquent query builder), no extra flag needed.

Separately, `DeleteCheckServiceGenerator`'s dependent-count check (the
`GET .../delete/check` endpoint) does **not** count an `inline_items` child
(v3.5.31). It used to, generically, through its FK-graph detection, and that was a
bug: the parent's delete cascades its children, so a parent WITH items reported
`can_delete: false` for a delete that would have succeeded. Now a child reached
through a declared `child_module` + `parent_fk` is skipped, with a comment in the
generated file saying why; any other FK from the same child, and any other
module, is still counted (`InlineItemsChildren`). The generated
`{Module}DeleteCheckServiceTest` asserts `can_delete: true` with a child row
present. Note this check is advisory,
not enforced: nothing currently stops a direct call to the delete endpoint
from bypassing it, for any module — that's a separate, larger architectural
question (whether every `{Module}DeleteService` should call its own
`{Module}DeleteCheckService` first) than this feature's scope.

---

## Generator Catalog

### Backend

All backend generators live under `Blutrixx\GeneratorEngine\Generators\Backend\`.

| Generator | Namespace segment | Emits |
|---|---|---|
| `ModelGenerator` | `Models\` | Eloquent model with relationships, fillable, casts |
| `MigrationGenerator` | `Migrations\` | `create_{table}` migration |
| `MigrationUpdateGenerator` | `Migrations\` | `update_{table}` migration (add/alter columns) |
| `ControllerGenerator` | `Controller\` | Resource controller wiring services |
| `RoutesGenerator` | `Routes\` | API route file for the module |
| `ListServiceGenerator` | `Services\` | Paginated list query with filters and sorts |
| `CreateServiceGenerator` | `Services\` | Record creation with validation |
| `EditServiceGenerator` | `Services\` | Record update with validation |
| `ViewServiceGenerator` | `Services\` | Single-record fetch with eager loads |
| `DeleteServiceGenerator` | `Services\` | Soft/hard delete |
| `DeleteCheckServiceGenerator` | `Services\` | FK constraint check before delete |
| `ActionServiceGenerator` | `Services\Action\` | Custom action handler |
| `BulkActionServiceGenerator` | `Services\` | Bulk operation handler |
| `DelegationServiceGenerator` | `Services\Delegation\` | Related sub-resource list service |
| `SeederGenerator` | `Seeders\` | Database seeder for seed data |
| `ModuleConfigGenerator` | `Config\` | Module config file registered in the app |
| `ActivityServiceGenerator` | `Services\` | Activity-log service stub |
| `CreateSplashServiceGenerator` | `Services\` | Splash/constants-driven create service |
| `EditSplashServiceGenerator` | `Services\` | Splash/constants-driven edit service |

### Frontend

All frontend generators live under `Blutrixx\GeneratorEngine\Generators\Frontend\`.

| Generator | Namespace segment | Emits |
|---|---|---|
| `ListPageGenerator` | `Pages\` | Vue list page with data table |
| `CreatePageGenerator` | `Pages\` | Vue create page wrapper |
| `CreateFormGenerator` | `Components\` | Create form with field bindings |
| `EditPageGenerator` | `Pages\` | Vue edit page wrapper |
| `EditFormGenerator` | `Components\` | Edit form with pre-populated fields |
| `ViewLayoutGenerator` | `Pages\` | View page layout shell |
| `ViewOverviewGenerator` | `Components\` | Overview panel on the view page |
| `DeletePageGenerator` | `Pages\` | Delete confirmation page |
| `DeleteFormGenerator` | `Components\` | Delete confirmation form |
| `FrontendRoutesGenerator` | `Routes\` | Vue Router route definitions |
| `MenusJsonGenerator` | — | Adds module entry to `menus.json` |
| `ActionComponentGenerator` | `Components\Actions\` | Vue component for a custom action |
| `DelegationTabComponentGenerator` | `Components\Delegations\` | Tab component for a delegated sub-resource |
| `DelegationModalComponentGenerator` | `Components\Delegations\` | Modal for delegation interaction |

### Mobile App (Frontend)

All mobile frontend generators live under `Blutrixx\GeneratorEngine\Generators\MobileApp\`.

| Generator | Namespace segment | Emits |
|---|---|---|
| `ListPageGenerator` | `Pages\` | NativePHP mobile list page |
| `ListComponentGenerator` | `Components\` | `Components/{Module}List.vue` — wraps `ListPageBareCards`; imported by convention by `ListPageGenerator`'s mobile output, so it must exist for every mobile module. (Distinct from the web `Frontend\Components\ListComponentGenerator`, removed in v2.29.0 — this mobile variant is still real and required.) |
| `CreatePageGenerator` | `Pages\` | NativePHP mobile create page |
| `EditPageGenerator` | `Pages\` | NativePHP mobile edit page |
| `ViewLayoutGenerator` | `Pages\` | NativePHP mobile details layout |
| `DeletePageGenerator` | `Pages\` | NativePHP mobile delete page |
| `MobileAppRoutesGenerator` | `Routes\` | Vue Router route definitions for mobile |
| `ActionModalGenerator` | `Components\Actions\` | Vue modal component for a custom action (`hasUI: true`) |

### Mobile App (Backend)

All mobile backend generators live under `Blutrixx\GeneratorEngine\Generators\MobileApp\Backend\`.
These generate a full PHP/Laravel backend inside `MOBILE_APP/app/Modules/{Group}/{Module}/`
for use with NativePHP Mobile's embedded Laravel runtime.

| Generator | Emits |
|---|---|
| `MobileModelGenerator` | `{Module}Model.php` |
| `MobileControllerGenerator` | `{Module}Controller.php` |
| `MobileApiRoutesGenerator` | `Routes/api.php` (CRUD + sync endpoints) |
| `MobileMigrationGenerator` | `Migrations/{date}_create_{table}_table.php` (SQLite-safe) |
| `MobileSeederGenerator` | `Seeders/{Module}SeederData.json` |
| `MobileListServiceGenerator` | `Services/{Module}ListService.php` |
| `MobileCreateServiceGenerator` | `Services/{Module}CreateService.php` |
| `MobileViewServiceGenerator` | `Services/{Module}ViewService.php` |
| `MobileEditServiceGenerator` | `Services/{Module}EditService.php` |
| `MobileDeleteServiceGenerator` | `Services/{Module}DeleteService.php` |
| `MobileDeleteCheckServiceGenerator` | `Services/{Module}DeleteCheckService.php` |
| `MobileActivityListServiceGenerator` | `Services/{Module}ActivityListService.php` |
| `MobileBulkActionServiceGenerator` | `Services/{Module}BulkActionService.php` |
| `MobileSyncServiceGenerator` | `Services/{Module}SyncService.php` (push/pull offline sync) |
| `MobileSyncComposableGenerator` | `resources/js/src/composables/use{Module}Sync.ts` |
| `MobileRegistryGenerator` | Updates `MOBILE_APP/app/Modules/registry.json` (always runs last) |

All mobile backend stubs are SQLite-safe (TEXT not JSON, LIKE not JSON operators, direct `uuid()->primary()`).
Every generated module includes `last_synced_at` and `/sync/push` + `/sync/pull` endpoints.

---

## Bundled Stub Templates

The package ships stub files under `src/Generators/Templates/`:

```
src/Generators/Templates/
├── backend/              PHP stubs for models, services, controllers, etc.
├── frontend/             Vue 3 / JS stubs for pages and components
└── mobile_app/           NativePHP mobile stubs
    ├── backend/          PHP stubs for mobile backend (SQLite-safe)
    │   └── services/     Service stubs including sync push/pull
    ├── features/         Per-feature Vue stubs (list, create, edit, delete, action)
    └── fields/           Field partial stubs
```

`PathManager::getBackendTemplatePath()`, `getFrontendTemplatePath()`, `getMobileAppTemplatePath()`,
and `getMobileAppBackendTemplatePath()` default to these bundled paths.

Consumers can override any or all of them by passing an array of absolute paths:

```php
PathManager::setTemplateRoots([
    'backend'    => '/path/to/custom/backend/stubs',
    'frontend'   => '/path/to/custom/frontend/stubs',
    'mobile_app' => '/path/to/custom/mobile_app/stubs',
]);
```

Only the keys that need overriding must be supplied; omitted keys continue to use the bundled stubs.

### Frontend stub assumptions

The bundled frontend stubs assume the following conventions in the target Vue 3 project:

| Assumption | Detail |
|---|---|
| **UUID source in child tabs** | Tab components (`delegation/tab.stub`, `custom/tab_action.stub`) read the parent record ID from `route.params.[[idParam]]` via `useRoute()`. The parent layout must define a route with the corresponding named parameter (e.g. `:uuid`). |
| **Loading skeleton component** | `details_layout.stub` imports `CardSkeleton` from `@/components/ui/loading/CardSkeleton.vue`. The target project must provide this component. |
| **Toast utility** | Action stubs import `{ toast }` from `@/lib/toast` (a Sonner wrapper). The shadcn `@/components/ui/toast` module is not used. |
| **Tabs strip styling** | The generated `*DetailsLayout` tabs wrapper uses the CSS class `tabs-header border-y bg-card`. The `tabs-header` class should be defined in the project's global stylesheet to handle the `-mb-px` tab underline trick. |

---

## Schema Introspection

`Blutrixx\GeneratorEngine\Schema\SchemaIntrospector` inspects a live DB table and returns
structured column metadata. It works with any Laravel-supported driver (MySQL, SQLite, PostgreSQL)
via `Schema::getColumns()` / `Schema::getForeignKeys()` / `Schema::getIndexes()` — no Doctrine DBAL.

```php
use Blutrixx\GeneratorEngine\Schema\SchemaIntrospector;

$introspector = new SchemaIntrospector('products');

if ($introspector->exists()) {
    $idType  = $introspector->idColumnType(); // 'uuid' | 'bigint' | 'string'
    $columns = $introspector->columns();      // structured column metadata array
}

// Build reverse FK graph across all application tables
$fkGraph = SchemaIntrospector::globalForeignKeys();
PathManager::setForeignKeyGraph($fkGraph);

// Wire up a warning handler (e.g. for missing indexes on FK columns)
SchemaIntrospector::setIssueHandler(function (string $message, string $level): void {
    logger()->{$level}($message);
});
```

**Column metadata shape** (each entry in `columns()`):

```
name, type (raw DB), normalized_type, length, nullable, default,
is_fk, foreign_table, foreign_column, is_unique, morph_role, morph_name,
precision, scale, indexed, enum_values
```

`precision`/`scale` are only populated for decimal columns (feed `IntrospectionToConfig`'s
decimal-column handling); `enum_values` only for `enum`-typed columns.

**`SKIP_COLUMNS`** (excluded from `columns()` output):
`id, uuid, created_at, updated_at, deleted_at, created_by_id, updated_by_id`

---

## Auto-Detection Features

When `IntrospectionToConfig` processes raw column data, it applies the following heuristics automatically:

- **Convention-based FK detection.** Columns ending in `_id` where the implied table (column name minus `_id`, pluralized) exists in the schema are flagged as foreign keys and given a `relatedModule` value derived from the foreign table name.
- **Polymorphic pair detection.** When a `{prefix}_type` (string) column and a `{prefix}_id` (integer) column share the same prefix, they are grouped into a `morphs` entry (`morphTo` relationship) and excluded from form/validation field lists.
- **Reverse FK graph.** The caller may pass a pre-computed FK graph (`PathManager::setForeignKeyGraph()`) built by `SchemaIntrospector::globalForeignKeys()`. `DeleteCheckServiceGenerator` reads this graph to emit referential-integrity checks before a delete is executed.
- **Index-presence warnings.** If a FK column is detected without a corresponding index, the engine emits a warning via `PathManager::reportIssue()` so the consumer's logger or Artisan output can surface it.
- **Non-filterable type exclusion.** Columns of type `text`, `longText`, `mediumText`, and `json` are automatically excluded from `filterableFields` and `sortableFields` in the generated list service, as full-text search on these types is generally undesirable.
- **Type-aware filter UI + always-on default filters.** Leaving `features.backend.list.filterFields` empty auto-derives a type-aware filter control per `filterableFields` column (FK → paginated select, enum/boolean → select with real options, date/number → matching picker, else text), and `id`/`uuid`/`created_at` are always added as default filters regardless of config. See [docs: Features Config › Filter fields](https://joelnjoshkibona.github.io/generator-engine/features-config#filter-fields-auto-derivation-and-default-filters) (not retroactive to already-generated modules).

### Strict vs. lenient construction

`IntrospectionToConfig` has two named constructors:

| Constructor | Behaviour |
|---|---|
| `IntrospectionToConfig::strict()` (recommended) | `build()` throws `InvalidArgumentException` if `$meta` is missing any of `has_timestamps`, `has_soft_deletes`, `has_uuid`, `has_creator_updater`, `file_columns`, or `index_groups` (`REQUIRED_STRICT_KEYS`), or if `$meta` contains an unknown/typo'd top-level key. |
| `IntrospectionToConfig::lenient()` (= `new IntrospectionToConfig()`) | Missing keys silently default (`has_timestamps`/`has_uuid`/`has_creator_updater` → `true`, `has_soft_deletes` → `false`, `file_columns`/`index_groups` → `[]`). Unknown keys still throw in both modes. |

Strict mode exists to close a real bug class: each of those six keys is a schema-derived fact that's easy to forget when hand-assembling `$meta`, and an absent key was previously indistinguishable from an intentionally-`false` one. The bug that motivated it: a caller discarded the live introspection result and never threaded `has_soft_deletes` through — `build()` silently defaulted it to `false`, so every generated module quietly lost `SoftDeletes` until it was hand-patched three times before anyone traced it back to this class. Callers that need the old silent-default behaviour (e.g. legacy call sites not yet updated to thread all six keys) can construct via `::lenient()` explicitly instead of relying on strict-by-default. `ModuleConfigContract` is the companion class that reads these same facts back off a built config array via a single documented resolution rule per fact, rather than each generator re-deriving them independently.

---

## Issue Handling

Generators and `PathManager` use a single internal reporting channel. When the engine encounters a non-fatal issue (an unresolvable FK module reference, a missing index, an ambiguous namespace), it calls:

```php
PathManager::reportIssue(string $message, string $level = 'warning'): void
```

This calls the registered handler if one is set, or silently no-ops if none is registered.

**Registering a handler:**

```php
// Artisan command context
PathManager::setIssueHandler(function (string $message, string $level) use ($output): void {
    $output->writeln("<comment>[{$level}] {$message}</comment>");
});

// Laravel log context
PathManager::setIssueHandler(fn($msg, $lvl) => logger()->{$lvl}($msg));
```

**Resetting to default (no-op):**

```php
PathManager::setIssueHandler(null);
```

---

## Decoupling Notes

The package is deliberately free of consumer-side dependencies:

- No `App\` classes are imported at runtime.
- No Eloquent models (`Module`, `GenerationQueue`, etc.) — all data is passed as plain arrays.
- No Laravel facades (`Log::`, `DB::`, `Storage::`, etc.).
- No V1 or SHELL service classes.
- `illuminate/support` and `illuminate/filesystem` are required only for `Str` helpers and filesystem writes — both are available in any standard Laravel application.

The package works identically when bootstrapped from a web-request context (e.g. a UI-driven generator), an Artisan command (e.g. a database-introspection scaffolder), or a standalone PHP script.

---

## Testing

### Automated tests (PHPUnit)

```bash
composer install
vendor/bin/phpunit
# or: composer test
```

`tests/Unit/` contains PHPUnit regression tests, one file per generator bug fixed (see `CHANGELOG.md` for what each one covers) — e.g. `BaseComponentGeneratorTest` (`generateColumnsFromListFields()`), `Backend/Services/BaseServiceGeneratorTest` (`generateFilterableFields()`/`generateSortableFields()`/`generateFilterFields()`), `FrontendLocaleGeneratorTest`, `MenusJsonGeneratorTest`, `Routes/FrontendRoutesGeneratorTest`, `MobileApp/Routes/MobileAppRoutesGeneratorTest`, `Backend/Seeders/SeederGeneratorTest`. Most build generator instances via `PathManager::setProjectRoot()` pointed at a temp directory (real `__construct()`/`generate()` calls, real file output, no Laravel app needed — `PathManager` degrades gracefully when Laravel's `config()`/`base_path()` helpers aren't defined). A few instead use `ReflectionClass::newInstanceWithoutConstructor()` to test pure string/array-generation methods directly, bypassing the constructor entirely.

### Manual smoke test

A manual smoke test is also available at:

```
tests/manual/run_introspection_to_config_smoke.php
```

Run it from the package root:

```bash
php tests/manual/run_introspection_to_config_smoke.php
```

The script exercises `IntrospectionToConfig::build()` with a synthetic column set and prints the resulting config array for visual inspection.

### Reusable integration-test schema

Five permanent, realistic schema fixtures live under
`tests/Fixtures/integration-schemas/` — each validates engine changes
end-to-end against a real consuming Laravel project (SYSTEM_SHELL): copy the
migrations in, `php artisan migrate`, then run `make:module` per table. Each
also ships a companion `columns.php` shaped as real
`SchemaIntrospector::columns()` output (dumped from a real introspection run,
not hand-typed, for the three newer suites), for fast unit tests that
exercise `IntrospectionToConfig` without a real database. See the
[docs site's Examples section](https://joelnjoshkibona.github.io/generator-engine/examples/)
(source: `docs/examples/`) for the task-oriented recipe each fixture
supports, and each fixture's own `README.md` for full usage instructions.

| Fixture | Exercises |
|---|---|
| `items-suite/` | Lookup table, self-referential FK, multi-FK entity, child records, file-upload column |
| `orders-suite/` | Parent-child `inline_items` (Orders/OrderItems) — save/sync/load/delete-cascade |
| `morphs-suite/` | Polymorphic `morphs` auto-detection (Payments belonging to either Suppliers or Customers) |
| `delegations-suite/` | Related-records sub-tab `delegations` (Warehouses/StockMovements) |
| `actions-suite/` | Custom `actions` (state-transition) and `bulk_actions` (PurchaseOrders) |

---

## Status

Actively maintained. v3.0.0 is the current stable release.

| | |
|---|---|
| Source | https://github.com/joelnjoshkibona/generator-engine |
| Packagist | https://packagist.org/packages/blutrixx/generator-engine |
| License | Apache-2.0 |
| Issues | GitHub issues on the source repo |
| Docs | `docs/` directory — see [docs/README.md](docs/README.md) |

## Contributing

Clone, branch, PR. Run `vendor/bin/phpunit` (see [Testing](#testing)) before opening a PR; the manual smoke test under `tests/manual/` remains a useful secondary check for `IntrospectionToConfig`. Keep changes free of `App\` imports and Laravel facades — see "Decoupling Notes" above.
