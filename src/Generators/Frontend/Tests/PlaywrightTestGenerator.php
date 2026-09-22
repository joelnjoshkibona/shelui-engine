<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

/**
 * Generates one Playwright e2e spec per testable surface of a module, all in
 * that module's own e2e/ directory, instead of one monolithic file:
 *
 *   - {module-route}-create.e2e.js — the create form: empty-required-field
 *     validation, missing-required-file validation, fill + submit, assert
 *     the new row appears. Written only when hasCreate (see
 *     writeCreateSpecFile()). Drives the ONLY spec in this set that creates
 *     its own record from scratch rather than via _fixtures.js — that IS
 *     what it's testing — and best-effort deletes it afterward via
 *     _fixtures.js's cleanupRecord() when hasDelete.
 *   - {module-route}-list.e2e.js   — list rendering, filter (apply + clear),
 *     related-record FK-cell navigation, bulk-action/export/import. Always
 *     written — every module has a list (see writeListSpecFile()).
 *   - {module-route}-view.e2e.js   — opens the view modal, asserts it
 *     rendered, closes it. Written only when hasView (see
 *     writeViewSpecFile()).
 *   - {module-route}-edit.e2e.js   — opens the view modal (Edit's own button
 *     lives inside it), edits one field, asserts the list reflects the
 *     change. Written only when hasEdit (see writeEditSpecFile()).
 *   - {module-route}-delete.e2e.js — opens the view modal, More Actions ->
 *     Delete, wrong-confirm-text-stays-disabled check, confirms, asserts the
 *     record is gone. Written only when hasDelete (see writeDeleteSpecFile()).
 *   - _fixtures.js                 — shared createFixtureRecord()/
 *     cleanupRecord() pair EVERY one of the above (except create's own
 *     create step) uses to get an independent record to act on, so each
 *     file runs standalone, in any order, without depending on another
 *     spec having run first (see writeFixturesFile(), now unconditional —
 *     every module needs at least the list spec's fixture record).
 *   - {module-route}-{delegation-key}.e2e.js — one per config['delegations']
 *     key (see writeDelegationSpecFile()).
 *   - {module-route}-{action-key}.e2e.js     — one per config['actions'] key
 *     with hasUI true (see writeActionSpecFile()).
 *
 * Splitting the base CRUD surfaces this finely (not just delegations/
 * actions, as before) mirrors the same file-per-artifact rationale
 * DelegationServiceGenerator/ActionServiceGenerator already use on the
 * backend: a hand-edited edit.e2e.js never risks getting clobbered by a
 * --force regenerate that only needed to add a create-side field, and a CI
 * run can shard/target one surface (e.g. re-run only the delete specs)
 * instead of an all-or-nothing monolithic file.
 *
 * No bulk migration ever renames/splits an existing module's old-style
 * {module-route}.e2e.js or {module-route}-crud.e2e.js — old- and new-style
 * modules coexist indefinitely; deleteStaleMonolithicFileIfPresent()/
 * deleteStaleCrudFileIfPresent() only remove them once a --force run has
 * successfully written the new split files for that module.
 *
 * Structurally mirrors the hand-written reference pattern in
 * SYSTEM_SHELL/FRONTEND/e2e/location-types-crud.e2e.js (retry/settle
 * helpers, self-cleaning try/finally) while targeting the view-modal-first
 * DOM shape that the CURRENT frontend generator actually produces (see
 * Templates/frontend/features/list/page.stub, features/view/modal.stub,
 * features/delete/form.stub, and BaseComponentGenerator's submit-button
 * markup) rather than the older hand-written LocationTypes page, which
 * predates that pattern and has no view/submit testids of its own:
 *   - list row "view" button:            [[moduleName]]-view-{uuid}
 *   - view-modal "edit" button:          [[moduleName]]-edit-{uuid}
 *   - view-modal "More Actions" -> item: [[moduleName]]-delete-{uuid}
 *   - create/edit form submit button:    [[moduleName]]-submit
 *   - delete-form confirm button:        [[moduleName]]-confirm-delete
 *   - view-modal action trigger:         [[moduleName]]-action-{key}-{uuid}
 *     (ViewModalGenerator::renderMainButton()/renderMoreItem(), both
 *     placements — used by writeActionSpecFile())
 *   - batch-mode toggle (shows checkboxes): batch-mode-toggle (aria-pressed)
 *   - bulk-select checkbox (per row):    [[moduleName]]-bulk-select-{uuid}
 *   - bulk-action toolbar button:        [[moduleName]]-bulk-action-{key}
 *   - bulk-action confirm dialog button: [[moduleName]]-bulk-confirm
 *   - export dropdown trigger/items:     [[moduleName]]-export-open / -csv / -xlsx / -pdf
 *   - import dialog trigger/controls:    [[moduleName]]-import-open / -file / -dry-run /
 *     -submit / -template-csv / -template-xlsx
 *     (all six computed from CrudListPanel's testIdPrefix inside
 *     ListTable.vue — see buildBulkActionBlock()/buildExportBlock()/
 *     buildImportBlock())
 *   - bulk-action/import result drawer:  batch-result-drawer (no module
 *     prefix — BatchResultDrawer.vue is shared across every module)
 */
class PlaywrightTestGenerator extends BaseGenerator
{
    /**
     * field_type values that render as a relation/option picker rather than
     * a plain fillable `<input>`/`<textarea>` — driven by fillSelectField()
     * (see selectFieldHelperBlock()), never by the default fillField().
     *
     * MUST include both 'select' AND 'api-select': IntrospectionToConfig::
     * buildFrontendFields() (the actual, only producer of features.frontend.
     * {create,edit}.fields in this codebase) emits 'api-select' for every
     * foreign-key/relation column — it never emits a bare 'select'. Before
     * this fix, every check in this class looked for 'select' only, so
     * every real FK field (item_type_id, item_category_id, item_id,
     * self-referential parent_id, ...) fell through to the plain-input
     * branch. Confirmed live: a generated Items e2e test tried
     * `fillField(page, '[role="dialog"] #item_type_id', ...)` against an
     * ApiSelect2Field control that has no element with that id at all
     * (ApiSelect2Field only applies `id` to its `<label for>`, never to a
     * root/input element — see ApiSelect2Field.vue) — every such test
     * timed out on the very first FK field it tried to fill, and
     * pickEditField()/isScalarField() could additionally pick an FK field
     * as the edit test's "one changed field", compounding the failure.
     * 'select' is kept alongside 'api-select' in case a future field_type
     * for a local (non-API) static dropdown is introduced under that exact
     * name — both shapes are already handled generically by the same
     * fillSelectField() helper.
     */
    protected const SELECT_FIELD_TYPES = ['select', 'api-select'];

    protected bool $hasCreate;
    protected bool $hasView;
    protected bool $hasEdit;
    protected bool $hasDelete;

    /** features.backend.list.{bulk_actions,export,import} — a separate config location from features.frontend, same as $filterFields above. */
    protected bool $hasBulkActions;
    protected bool $hasExport;
    protected bool $hasImport;
    /** @var array<int, array<string, mixed>> features.backend.list.bulk_actions */
    protected array $bulkActions;

    /** @var array<int, array<string, mixed>> features.frontend.create.fields */
    protected array $createFields;
    /** @var array<int, array<string, mixed>> features.frontend.edit.fields */
    protected array $editFields;

    /**
     * features.backend.list.filterFields — NOT features.frontend.list.
     * frontend.list only carries display `fields` + `primaryField`; the
     * filterFields the list's filter panel actually exposes live under
     * backend.list (confirmed against the real LocationTypes/Locations
     * module.json files, and independently against how
     * PhpUnitTestGenerator::firstFilterFieldKey() reads this same key for
     * the analogous backend test generator).
     *
     * Introspected modules routinely leave this array empty in module.json
     * (see e.g. ItemTypes) — the actual filter panel the running app renders
     * is NOT built from this raw config key, it's built from whatever the
     * generated ListService's filterFields() response carries, which falls
     * back to deriving plain-text filters from `filterableFields` when
     * `filterFields` is empty (see
     * BaseServiceGenerator::generateFilterFields()). Mirror that same
     * fallback here so pickTextFilterField() sees the filter controls that
     * genuinely exist in the UI instead of treating the module as
     * text-filter-less and falling through to the id-based Variant B.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $filterFields;

    protected ?string $primaryField;

    /**
     * Keys from features.frontend.list.fields[] whose defaultVisible is not
     * explicitly false — i.e. fields actually rendered as a <td> in the
     * generated list table's <tr>. pickEditField() needs this: the edit
     * step's own row.textContent.includes(editedValue) assertion (below,
     * buildEditBlock()) can only ever pass for a field that is genuinely
     * rendered in that row. Before this existed, pickEditField() picked any
     * scalar edit field with no awareness of list-column visibility at all,
     * so hiding a field via defaultVisible: false (a real, supported config
     * option — see docs/module-config.md) silently broke the edit step's
     * own verification for any module where that field happened to be
     * picked, timing out after 15s with no indication of the real cause.
     * Confirmed live against a real display-config pass across 31 modules:
     * 5 of the resulting failures were exactly this.
     *
     * @var array<int, string>
     */
    protected array $listVisibleFieldKeys;

    protected ?string $anchorField = null;
    protected bool $anchorFieldResolved = false;

    /**
     * shelui-engine fork: this module's own record-identifier field/route-param
     * name -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'. Identical
     * resolution rule to FrontendRoutesGenerator::$idParam/BaseComponentGenerator::
     * idParam() (this generator extends BaseGenerator directly, not
     * BaseComponentGenerator, so it gets its own constructor-computed copy rather
     * than inheriting the shared helper method).
     *
     * The DOM-driven paths in this file (uuidFromTestId() reading a rendered
     * data-testid attribute back, then threading that value through as
     * `recordUuid`) already resolve correctly for a has_uuid: false module
     * without touching this property at all -- they never assume which field
     * name backs the value, only that a `[[moduleName]]-{action}-{value}`
     * attribute exists and that {value} is a real, followable identifier. This
     * property is only needed where a generated test reads THIS module's own
     * record identifier directly off an API JSON response body (`.data.uuid`),
     * which does NOT exist on a has_uuid: false module's response -- only
     * `.data.id` does (see buildCreateBlock()'s and buildFixtureCreateBody()'s
     * own "no anchor field" branches, the only two such reads in this file).
     */
    protected string $idParam;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        $frontend = $config['features']['frontend'] ?? [];
        $this->hasCreate = !empty($frontend['create']);
        $this->hasView   = !empty($frontend['view']);
        $this->hasEdit   = !empty($frontend['edit']);
        $this->hasDelete = !empty($frontend['delete']);

        $this->idParam = $frontend['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($config) ? 'uuid' : 'id');

        $this->createFields = $this->excludeInlineTotalTargets($this->excludeJsonColumnFields($frontend['create']['fields'] ?? []));
        $this->editFields    = $this->excludeInlineTotalTargets($this->excludeJsonColumnFields($frontend['edit']['fields'] ?? []));
        $this->filterFields  = $this->resolveFilterFields($config);
        $this->primaryField  = $frontend['list']['primaryField'] ?? null;

        $this->listVisibleFieldKeys = [];
        foreach (($frontend['list']['fields'] ?? []) as $listField) {
            if (($listField['defaultVisible'] ?? true) !== false && !empty($listField['key'])) {
                $this->listVisibleFieldKeys[] = $listField['key'];
            }
        }

        $backendList = $config['features']['backend']['list'] ?? [];
        $this->bulkActions    = is_array($backendList) ? ($backendList['bulk_actions'] ?? []) : [];
        $this->hasBulkActions = !empty($this->bulkActions);
        $this->hasExport      = is_array($backendList) && !empty($backendList['export']);
        $this->hasImport      = is_array($backendList) && !empty($backendList['import']);
    }

    /**
     * Excludes any field whose underlying column is JSON-typed
     * (config['columns'][].type === 'json' — see IntrospectionToConfig::
     * buildColumn(), which threads the DB's normalized_type straight
     * through). A JSON column has no generic plain-input representation:
     * IntrospectionToConfig::buildFrontendFormFields() no longer emits a
     * create/edit field for one at all (see its own 'json' branch), but this
     * class independently guards the same bug for a module.json generated
     * BEFORE that fix landed — an already-generated module.json is not
     * rewritten by a scoped e2e-only regenerate (see regenerateOnly()),
     * only by a full --force module regenerate. Confirmed live: a generated
     * UserLocations e2e spec tried to fillField() '#roles'/
     * '#granted_permissions'/'#denied_permissions', none of which exist in
     * the real form at all — see UserLocationsCreateForm.vue's own comment:
     * those three columns are managed exclusively via dedicated Manage
     * Roles/Manage Permissions endpoints, never as raw text input.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    protected function excludeJsonColumnFields(array $fields): array
    {
        if (empty($fields) || empty($this->config['columns'])) {
            return $fields;
        }

        $jsonColumns = [];
        foreach ($this->config['columns'] as $column) {
            if (($column['type'] ?? '') === 'json' && !empty($column['name'])) {
                $jsonColumns[$column['name']] = true;
            }
        }

        if (empty($jsonColumns)) {
            return $fields;
        }

        return array_values(array_filter(
            $fields,
            static fn (array $field): bool => !isset($jsonColumns[$field['field'] ?? ''])
        ));
    }

    /**
     * Drop every field an inline_items `totals[].sync_to` computes. The form fills such a
     * field itself -- the parent's `@totals-change` handler writes the sum into it and adds
     * it to `disabledFieldsList` the moment the line-items component mounts -- so by the time
     * a spec reaches it, `#total` is a disabled input and `fill()` waits its full 15 s for it
     * to become editable. Found by the super-suite fixture's full Playwright lane: every
     * SuiteOrders spec (six of them) died on `locator.fill: element is not enabled`.
     * The field is still submitted (the form holds the computed value), so validation is
     * unaffected; the spec simply must not type into a control the app owns.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    protected function excludeInlineTotalTargets(array $fields): array
    {
        $targets = [];
        foreach ((array) ($this->config['inline_items'] ?? []) as $item) {
            foreach ((array) ($item['totals'] ?? []) as $total) {
                if (is_array($total) && !empty($total['sync_to'])) {
                    $targets[(string) $total['sync_to']] = true;
                }
            }
        }

        if (empty($targets) || empty($fields)) {
            return $fields;
        }

        return array_values(array_filter(
            $fields,
            static fn (array $field): bool => !isset($targets[$field['field'] ?? ''])
        ));
    }

    /**
     * features.backend.list.filterFields, falling back to type-aware filters
     * derived from features.backend.list.filterableFields when the former is
     * empty — the same fallback BaseServiceGenerator::generateFilterFields()
     * applies when it renders the actual runtime filter panel. Without this,
     * introspected modules (empty filterFields, non-empty filterableFields)
     * look text-filter-less to pickTextFilterField() even though the
     * generated app genuinely renders text filter controls for them.
     *
     * Bug (found live 2026-08-07): every derived entry used to be hardcoded
     * 'type' => 'text' regardless of the column's real type. That mirrored
     * BaseServiceGenerator::generateFilterFields()'s OWN fallback faithfully
     * until 2026-08-03/04, when that method was fixed to derive a real
     * per-column type via getFilterFieldType() (an FK column now renders as
     * 'select_paginated', an enum/boolean as 'select') — this class's own
     * fallback was never updated to match, so it silently drifted out of
     * sync with the filter panel it's supposed to be driving. Confirmed
     * live: UserLocations' user_id/location_id/role_id filterable FK columns
     * (filterFields left empty in module.json, derived from
     * filterableFields) looked like plain text filters to
     * pickTextFilterField()/buildFilterVariantB(), which called
     * setFilterTextValue() against a Select2/ApiSelect2 control that has no
     * `<input>` at all — Playwright threw "resolved to a <div>, not
     * <input>" (see filters.js's setFilterTextValue(), which throws exactly
     * that diagnostic).
     *
     * Fixed by inferFallbackFilterFieldType() below, ported from
     * BaseServiceGenerator::getFilterFieldType()/isForeignKey() — see that
     * method's own docblock for the full history. buildFilterVariantB() now
     * dispatches on the resolved type (see pickVisibleFilterField()
     * threading it through) to choose setFilterSelect2Value() over
     * setFilterTextValue() when appropriate.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveFilterFields(array $config): array
    {
        $filterFields = $config['features']['backend']['list']['filterFields'] ?? [];
        if (!empty($filterFields)) {
            return $filterFields;
        }

        $filterable = $config['features']['backend']['list']['filterableFields'] ?? [];
        if (is_string($filterable)) {
            $filterable = array_filter(array_map('trim', explode(',', $filterable)));
        }

        $columnsByName = [];
        foreach ($config['columns'] ?? [] as $column) {
            if (!empty($column['name'])) {
                $columnsByName[$column['name']] = $column;
            }
        }

        $derived = [];
        foreach ($filterable as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $derived[] = [
                'key'  => $name,
                'type' => $this->inferFallbackFilterFieldType($name, $columnsByName[$name] ?? []),
            ];
        }

        return $derived;
    }

    /**
     * Ported from BaseServiceGenerator::getFilterFieldType()/isForeignKey()
     * (src/Generators/Backend/Services/BaseServiceGenerator.php) — the REAL
     * logic the running app's own filterFields() fallback now uses (fixed
     * 2026-08-03), so resolveFilterFields()'s fallback-derived entries carry
     * the same 'type' the actual DataTableFilter.vue panel renders instead
     * of a stale hardcoded 'text'. $column is a config['columns'][] entry
     * (IntrospectionToConfig::buildColumn()'s output — 'type' here is
     * already the NORMALIZED type, e.g. 'foreignId', not the raw DB type).
     *
     * Deliberately does not port buildFilterFieldOptions() alongside this —
     * this class never renders an actual dropdown, it only needs to know
     * when NOT to call the plain-text fill helper.
     */
    protected function inferFallbackFilterFieldType(string $name, array $column): string
    {
        if (($column['type'] ?? '') === 'foreignId' || str_ends_with($name, '_id')) {
            return 'select_paginated';
        }
        if (!empty($column['enum_values'])) {
            return 'select';
        }
        if (($column['type'] ?? '') === 'boolean' || $name === 'is_default' || $name === 'is_active') {
            return 'select';
        }
        if (in_array($column['type'] ?? '', ['date', 'datetime', 'timestamp'], true)) {
            return 'date';
        }
        if (in_array($column['type'] ?? '', ['integer', 'bigInteger', 'decimal'], true)) {
            return 'number';
        }
        return 'text';
    }

    /** True for a filterField 'type' that DataTableFilter.vue renders as a Select2/ApiSelect2 control rather than a plain `<input>` — see buildFilterVariantB()'s dispatch. */
    protected function isSelectShapedFilterType(string $type): bool
    {
        return in_array($type, ['select', 'select_paginated'], true);
    }

    public function generate(): bool
    {
        // Every split file below (except create's own create step) gets its
        // acting record via _fixtures.js — unconditional now, not gated on
        // delegations/actions the way it was when only THEY needed it.
        $allWritten = $this->writeFixturesFile();

        if ($this->hasCreate) {
            $allWritten = $this->writeCreateSpecFile() && $allWritten;
        }
        $allWritten = $this->writeListSpecFile() && $allWritten;
        if ($this->hasView) {
            $allWritten = $this->writeViewSpecFile() && $allWritten;
        }
        if ($this->hasEdit) {
            $allWritten = $this->writeEditSpecFile() && $allWritten;
        }
        if ($this->hasDelete) {
            $allWritten = $this->writeDeleteSpecFile() && $allWritten;
        }

        if (!empty($this->config['delegations']) || $this->hasAnyUiAction()) {
            foreach ($this->config['delegations'] ?? [] as $delegationKey => $delegation) {
                if (!is_array($delegation)) {
                    continue;
                }
                $allWritten = $this->writeDelegationSpecFile((string) $delegationKey, $delegation) && $allWritten;
            }

            foreach ($this->config['actions'] ?? [] as $actionKey => $action) {
                if (!is_array($action) || empty($action['hasUI'])) {
                    continue;
                }
                $allWritten = $this->writeActionSpecFile((string) $actionKey, $action) && $allWritten;
            }
        }

        $this->deleteStaleMonolithicFileIfPresent();
        $this->deleteStaleCrudFileIfPresent();

        return $allWritten;
    }

    /**
     * Scoped regeneration for MakeDelegation.php/MakeAction.php: write only
     * the ONE spec matching $key (via writeFileAlways(), bypassing the
     * skip-if-exists contract for that file only), leaving every other
     * delegation/action spec in the directory — including hand-edited ones —
     * completely untouched. $kind is 'delegation' or 'action'.
     */
    public function regenerateOnly(string $key, string $kind): bool
    {
        if (!is_file($this->fixturesFilePath())) {
            $this->writeFixturesFile();
        }

        if ($kind === 'delegation') {
            $delegation = $this->config['delegations'][$key] ?? null;
            if (!is_array($delegation)) {
                return false;
            }
            return $this->writeDelegationSpecFile($key, $delegation, force: true);
        }

        if ($kind === 'action') {
            $action = $this->config['actions'][$key] ?? null;
            if (!is_array($action) || empty($action['hasUI'])) {
                return false;
            }
            return $this->writeActionSpecFile($key, $action, force: true);
        }

        return false;
    }

    protected function e2eDir(): string
    {
        return PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . '/e2e';
    }

    protected function fixturesFilePath(): string
    {
        return $this->e2eDir() . '/_fixtures.js';
    }

    protected function hasAnyUiAction(): bool
    {
        foreach ($this->config['actions'] ?? [] as $action) {
            if (is_array($action) && !empty($action['hasUI'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * {module-route}-create.e2e.js — the only split file that creates its own
     * record from scratch (buildCreateBlock()) rather than via
     * _fixtures.js's createFixtureRecord(), since the create flow itself is
     * what's under test. Best-effort deletes what it created via
     * _fixtures.js's cleanupRecord() when hasDelete — matching every other
     * split file's "leave the DB as you found it" contract — via a plain
     * trailing call rather than a try/finally: cleanupRecord() already
     * swallows its own errors internally (see buildFixtureCleanupBody()), so
     * there is nothing here that needs unwinding protection.
     */
    protected function writeCreateSpecFile(): bool
    {
        $stub = $this->getTemplateContent('tests/create.e2e', 'frontend');

        $content = str_replace(
            ['[[helperFunctions]]', '[[testDescription]]', '[[testBody]]'],
            [$this->buildHelperFunctions(), 'create flow (auto-generated)', $this->buildCreateSpecBody()],
            $stub
        );

        $content = $this->replacePlaceholders($content);

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . '-create.e2e.js';

        return $this->writeFile($filePath, $content);
    }

    protected function buildCreateSpecBody(): string
    {
        $body = $this->buildCreateBlock();

        if ($this->hasDelete) {
            $body .= "\n\n" . $this->buildTargetRowBlock() . "\n" . $this->buildUuidCaptureBlock();
            $body .= <<<'JS'

		if (recordUuid) {
			await cleanupRecord(page, recordUuid);
			console.log(`[${MODULE_LABEL}] cleaned up the record this test created`);
		}
JS;
        }

        return $body;
    }

    /**
     * {module-route}-list.e2e.js — list rendering, filter, related-record
     * navigation, bulk-action/export/import. Always written: every module
     * has a list. Uses a fixture record (not "whatever's already seeded")
     * so this also passes against a freshly seeded, otherwise-empty e2e DB —
     * the same reasoning _fixtures.js already existed for.
     */
    protected function writeListSpecFile(): bool
    {
        $inner = [];
        $inner[] = $this->buildFixtureTargetRowBlock();
        $inner[] = $this->buildFilterBlock(true);
        $inner[] = $this->buildRelatedRecordBlock();
        if ($this->hasBulkActions) {
            $inner[] = $this->buildBulkActionBlock();
        }
        if ($this->hasExport) {
            $inner[] = $this->buildExportBlock();
        }
        if ($this->hasImport) {
            $inner[] = $this->buildImportBlock();
        }
        $testBody = implode("\n\n", array_filter($inner, fn ($s) => trim($s) !== ''));

        return $this->writeSurfaceSpecFile('list', 'List', 'list, filter, and related-record navigation (auto-generated)', $testBody);
    }

    protected function writeViewSpecFile(): bool
    {
        return $this->writeSurfaceSpecFile('view', 'View', 'view (auto-generated)', $this->buildViewBlock(false));
    }

    protected function writeEditSpecFile(): bool
    {
        $testBody = $this->buildViewBlock(true) . "\n\n" . $this->buildEditBlock();

        return $this->writeSurfaceSpecFile('edit', 'Edit', 'edit (auto-generated)', $testBody);
    }

    protected function writeDeleteSpecFile(): bool
    {
        return $this->writeSurfaceSpecFile('delete', 'Delete', 'delete (auto-generated)', $this->buildDeleteBlock());
    }

    /**
     * Shared writer for the list/view/edit/delete split specs: each gets its
     * own fixture record via split.e2e.stub's createFixtureRecord() call
     * (see renderSplitSpec()), so $testBody is written one tab level deeper
     * than the block-builders' own hardcoded 2-tab indent to sit correctly
     * inside that stub's `try { ... } finally { cleanupRecord(...) }` —
     * same indentBlock() call buildTestBody() used to use for its own
     * hasDelete try/finally wrapper.
     *
     * Passes null for renderSplitSpec()'s $fieldsForHelpers: unlike a
     * delegation/action spec (whose helpers are scoped to ONE feature's own
     * field set), these test the module's own native create/edit fields, so
     * they need the full buildHelperFunctions() gating, not the narrower
     * splitSpecHelperFunctionsFor() set.
     */
    protected function writeSurfaceSpecFile(string $slug, string $label, string $description, string $testBody): bool
    {
        $content = $this->renderSplitSpec($slug, $slug, $label, $description, $this->indentBlock($testBody), null);
        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . "-{$slug}.e2e.js";

        return $this->writeFile($filePath, $content);
    }

    protected function writeFixturesFile(): bool
    {
        $stub = $this->getTemplateContent('tests/fixtures.e2e', 'frontend');

        $content = str_replace(
            ['[[helperFunctions]]', '[[fixtureCreateBody]]', '[[fixtureCleanupBody]]'],
            [$this->buildFixtureHelperFunctions(), $this->buildFixtureCreateBody(), $this->buildFixtureCleanupBody()],
            $stub
        );

        $content = $this->replacePlaceholders($content);

        return $this->writeFile($this->fixturesFilePath(), $content);
    }

    /**
     * If $force is true, writes via writeFileAlways() (bypassing the
     * skip-if-exists contract) regardless of $this->force — the mechanism
     * regenerateOnly() uses to touch exactly one spec.
     */
    protected function writeDelegationSpecFile(string $delegationKey, array $delegation, bool $force = false): bool
    {
        $uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? 'tab');
        $rawName = $delegation['name'] ?? $delegationKey;
        $slug = Str::kebab((string) $rawName);
        $label = (string) ($delegation['label'] ?? $rawName);

        if ($uiType === 'modal') {
            $createOp = $delegation['operations']['create'] ?? [];
            $fieldsForHelpers = !empty($createOp['enabled']) ? ($createOp['frontend']['fields'] ?? []) : [];
            $testBody = $this->buildModalDelegationSpecBody($delegationKey, $delegation, $label);
        } elseif ($uiType === 'tab' || $uiType === 'tab-action') {
            $fieldsForHelpers = [];
            $testBody = $this->buildTabDelegationSpecBody($delegationKey, $delegation, $label);
        } else {
            return false;
        }

        $content = $this->renderSplitSpec(
            $slug,
            'delegation',
            $label,
            "delegation \"{$label}\" smoke test (auto-generated)",
            $testBody,
            $fieldsForHelpers
        );

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . "-{$slug}.e2e.js";

        return $force ? $this->writeFileAlways($filePath, $content) : $this->writeFile($filePath, $content);
    }

    protected function writeActionSpecFile(string $actionKey, array $action, bool $force = false): bool
    {
        $rawName = $action['name'] ?? $actionKey;
        $slug = Str::kebab((string) $rawName);
        $label = (string) ($action['label'] ?? $rawName);

        $testBody = $this->buildActionSpecBody($actionKey, $action, $label);

        $content = $this->renderSplitSpec(
            $slug,
            'action',
            $label,
            "action \"{$label}\" smoke test (auto-generated)",
            $testBody,
            $action['fields'] ?? []
        );

        $filePath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . "-{$slug}.e2e.js";

        return $force ? $this->writeFileAlways($filePath, $content) : $this->writeFile($filePath, $content);
    }

    /**
     * Deletes this module's pre-split {module-route}.e2e.js once a --force
     * run has written the new split files, so PlaywrightTestGenerator's
     * `testMatch` glob never double-runs the same coverage under two names.
     * Fires only under --force, only after the writes above ran (never
     * delete-then-fail, leaving a module with zero e2e coverage).
     */
    protected function deleteStaleMonolithicFileIfPresent(): void
    {
        if (!$this->force) {
            return;
        }

        $legacyPath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . '.e2e.js';
        if (!is_file($legacyPath)) {
            return;
        }

        if (!$this->removeFile($legacyPath)) {
            return;
        }
        PathManager::reportIssue(
            "Removed legacy monolithic e2e spec: " . Str::kebab($this->moduleName) . ".e2e.js (replaced by per-surface split)",
            'info'
        );
    }

    /**
     * Same retirement pattern as deleteStaleMonolithicFileIfPresent(), one
     * generation later: {module-route}-crud.e2e.js (the single-file list ->
     * create -> filter -> view -> edit -> delete spec) is superseded by the
     * list/create/view/edit/delete split above. Removed only under --force,
     * only after the new files have been written successfully.
     */
    protected function deleteStaleCrudFileIfPresent(): void
    {
        if (!$this->force) {
            return;
        }

        $legacyPath = $this->e2eDir() . '/' . Str::kebab($this->moduleName) . '-crud.e2e.js';
        if (!is_file($legacyPath)) {
            return;
        }

        if (!$this->removeFile($legacyPath)) {
            return;
        }
        PathManager::reportIssue(
            "Removed legacy combined e2e spec: " . Str::kebab($this->moduleName) . "-crud.e2e.js (replaced by the list/create/view/edit/delete split)",
            'info'
        );
    }

    // ------------------------------------------------------------------
    // Field selection helpers
    // ------------------------------------------------------------------

    protected function hasFieldType(string $type): bool
    {
        // Create/Edit-only by design: this only gates buildHelperFunctions(), the CRUD spec's own
        // helper block. An action never shares that block — it gets a separate split-spec file
        // whose helpers come from splitSpecHelperFunctionsFor() instead (see that method's own
        // 'number-input'/'number' handling), so this method has no reason to look at
        // $this->config['actions'] at all.
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (($field['field_type'] ?? 'input') === $type) {
                return true;
            }
        }
        return false;
    }

    /** True if any create/edit field uses a select-style field_type (see SELECT_FIELD_TYPES). */
    protected function hasAnySelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if any create/edit select-style field is REQUIRED — these are
     * filled via the throwing fillSelectField() helper (a required relation
     * genuinely cannot be skipped).
     */
    protected function hasRequiredSelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true) && !empty($field['required'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if any create/edit select-style field is OPTIONAL — these are
     * filled via the best-effort, never-throwing tryFillSelectField() helper
     * (see renderFieldFill()'s optional-select branch).
     */
    protected function hasOptionalSelectFieldType(): bool
    {
        foreach (array_merge($this->createFields, $this->editFields) as $field) {
            if (in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true) && empty($field['required'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * First create-field that is both a plain scalar (text/number/date/etc,
     * per isScalarField()) AND required — used to drive the "submit with a
     * required field empty" validation-error step. Null when the module has
     * no required scalar create field (the step is then omitted entirely).
     */
    protected function pickRequiredScalarField(): ?array
    {
        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && !empty($field['required']) && $this->isScalarField($field)) {
                return $field;
            }
        }
        return null;
    }

    /**
     * First required create-field of field_type 'file-input' — used to
     * drive the "submit without the required file" validation-error step.
     * Null when the module has no required file-input create field.
     */
    protected function pickRequiredFileField(): ?array
    {
        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && !empty($field['required']) && ($field['field_type'] ?? 'input') === 'file-input') {
                return $field;
            }
        }
        return null;
    }

    /**
     * key => label for every required, UNIQUE relation field among the create fields.
     *
     * @return array<string, string>
     */
    protected function uniqueRelationFields(): array
    {
        $fields = [];
        foreach ($this->createFields as $field) {
            if ($this->isUniqueRequiredRelationField($field)) {
                $key = (string) $field['field'];
                $fields[$key] = (string) ($field['label'] ?? $key);
            }
        }

        return $fields;
    }

    /**
     * Wrap the create submit in a retry that moves a required UNIQUE relation field on to the
     * next option whenever the server answers 422 on that very field.
     *
     * Found by the super-suite fixture's full Playwright lane: every spec of a module with a
     * unique FK took option[0], so the first one passed and every later one (each creates its own
     * fixture record) failed with "The owner id has already been taken". The value cannot be
     * chosen up front -- a soft-deleted row still holds it yet appears in no list endpoint -- so
     * the spec asks the server, which is the only party that knows. The retry only reacts to a
     * 422 that names one of these fields; any other outcome (success, or a different validation
     * error) leaves the loop on the first pass, exactly as a plain click would behave.
     *
     * The loop declares createResponsePromise itself, so the caller's own arming line is dropped.
     *
     * @return array{0: string, 1: string} [$submit, $responseArm]
     */
    protected function applyUniqueOptionRetry(string $submit, string $responseArm, string $indent): array
    {
        $unique = $this->uniqueRelationFields();
        if ($unique === []) {
            return [$submit, $responseArm];
        }

        $click = $indent . "await page.locator('[role=\"dialog\"] [data-testid=\"[[moduleName]]-submit\"]').click();";
        $pos = strpos($submit, $click);
        if ($pos === false) {
            return [$submit, $responseArm];
        }

        $picks = [];
        foreach ($unique as $key => $label) {
            $picks[] = json_encode($key) . ": { label: '" . addcslashes($label, "'\\") . "', index: 0 }";
        }

        $lines = [
            "// Required UNIQUE relation field(s): the server owns which records are still free, so a 422 on",
            "// one of them means \"taken\" -- pick the next option and submit again.",
            "const uniquePicks = { " . implode(', ', $picks) . " };",
            "let createResponsePromise;",
            "for (;;) {",
            "\tcreateResponsePromise = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'));",
            "\tawait page.locator('[role=\"dialog\"] [data-testid=\"[[moduleName]]-submit\"]').click();",
            "\tconst attemptResponse = await createResponsePromise;",
            "\tconst attemptErrors = attemptResponse.status() === 422 ? ((await attemptResponse.json().catch(() => null))?.errors ?? {}) : {};",
            "\tconst takenKey = Object.keys(uniquePicks).find((k) => attemptErrors[k]);",
            "\tif (!takenKey) break;",
            "\tuniquePicks[takenKey].index += 1;",
            "\tawait fillSelectField(page, '[role=\"dialog\"]', uniquePicks[takenKey].label, { index: uniquePicks[takenKey].index });",
            "}",
        ];
        $loop = implode("\n", array_map(static fn (string $l): string => $indent . $l, $lines));

        return [substr_replace($submit, $loop, $pos, strlen($click)), ''];
    }

    protected function isScalarField(array $field): bool
    {
        return !in_array($field['field_type'] ?? 'input', [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox', 'morph-select'], true);
    }

    /**
     * True when $field is a required select-type field whose underlying
     * column the schema itself marks unique — a true 1:1 relation, not an
     * ordinary many-to-one FK. See renderFieldFill()'s own comment at its
     * call site for why this needs to be told apart from every other
     * required relation field.
     */
    protected function isUniqueRequiredRelationField(array $field): bool
    {
        if (empty($field['required']) || !in_array($field['field_type'] ?? 'input', self::SELECT_FIELD_TYPES, true)) {
            return false;
        }

        $key = $field['field'] ?? '';
        if ($key === '') {
            return false;
        }

        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['name'] ?? null) === $key) {
                return !empty($column['unique']);
            }
        }

        return false;
    }

    /**
     * First plain scalar (text/number) create field — preferring
     * features.frontend.list.primaryField when it points at one — used as
     * the row we can reliably re-find by visible text after creation.
     * Memoized: called from several block-builders.
     */
    protected function pickAnchorField(): ?string
    {
        if ($this->anchorFieldResolved) {
            return $this->anchorField;
        }
        $this->anchorFieldResolved = true;

        $byKey = [];
        foreach ($this->createFields as $field) {
            if (!empty($field['field'])) {
                $byKey[$field['field']] = $field;
            }
        }

        if ($this->primaryField && isset($byKey[$this->primaryField]) && $this->isScalarField($byKey[$this->primaryField])) {
            $this->anchorField = $this->primaryField;
            return $this->anchorField;
        }

        foreach ($this->createFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                $this->anchorField = $field['field'];
                return $this->anchorField;
            }
        }

        return null;
    }

    /**
     * The one edit field the EDIT block changes. Prefers a plain scalar
     * field that ISN'T the anchor field used to find/filter the row (so the
     * row stays reliably re-findable by its original text throughout the
     * View step, mirroring location-types-crud.e2e.js editing "code" while
     * leaving "name" — its search key — untouched); falls back to the
     * anchor field itself, then to whatever the first edit field is, rather
     * than skip editing entirely.
     *
     * Within each of those passes, a field present in $listVisibleFieldKeys
     * is preferred over one that isn't — buildEditBlock()'s own verification
     * asserts the edited value shows up in row.textContent, which can only
     * ever be true for a field actually rendered as a list column. A field
     * hidden via defaultVisible: false is still a legitimate, edit()-able
     * field, so this is a same-pass preference, not an exclusion: falling
     * through to a hidden field is still correct when every scalar edit
     * field happens to be list-hidden.
     */
    protected function pickEditField(): ?array
    {
        $anchor = $this->pickAnchorField();

        $isListVisible = fn (array $field): bool =>
            !empty($field['field']) && in_array($field['field'], $this->listVisibleFieldKeys, true);

        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $field['field'] !== $anchor && $this->isScalarField($field) && $isListVisible($field)) {
                return $field;
            }
        }
        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $field['field'] !== $anchor && $this->isScalarField($field)) {
                return $field;
            }
        }
        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field) && $isListVisible($field)) {
                return $field;
            }
        }
        foreach ($this->editFields as $field) {
            if (!empty($field['field']) && $this->isScalarField($field)) {
                return $field;
            }
        }
        return $this->editFields[0] ?? null;
    }

    /**
     * Chosen filterFields entry for the FILTER block's Variant A. Variant A
     * types `createdRowText` (the anchor field's created value) into this
     * entry's filter control, so the entry is only usable when its key
     * matches the anchor field — filtering a DIFFERENT field by the anchor's
     * value would find nothing. Prefer the entry whose key equals the anchor
     * field; only fall back to the first non-id text entry (which
     * buildFilterBlock()'s equality check will then reject unless it
     * happens to be the anchor) when no anchor-matching entry exists.
     */
    protected function pickTextFilterField(): ?array
    {
        $anchor = $this->pickAnchorField();

        if ($anchor !== null) {
            foreach ($this->filterFields as $ff) {
                $key = $ff['key'] ?? '';
                if ($key === $anchor && ($ff['type'] ?? '') === 'text') {
                    return $ff;
                }
            }
        }

        foreach ($this->filterFields as $ff) {
            $key = $ff['key'] ?? '';
            if ($key !== '' && $key !== 'id' && ($ff['type'] ?? '') === 'text') {
                return $ff;
            }
        }
        return null;
    }

    /**
     * FILTER block fallback for when Variant A doesn't apply (no
     * anchor-matching text filterField — e.g. hasCreate is false, or the
     * anchor simply isn't one of the module's filterable fields): a
     * filterFields entry (excluding "id", which v2.15.0 stopped rendering as
     * a list column — see class docblock) whose key is ALSO a visible
     * features.frontend.list.fields column, paired with that column's
     * display title.
     *
     * Unlike Variant A, this doesn't need to know a value we typed in at
     * create time — it reads whatever the target row is CURRENTLY showing
     * for that column (via getRowColumnValue(page, targetRow, label)) and
     * filters by that, which is valid whether or not this test created the
     * row. This is what replaces the old "read the ID column" Variant B: the
     * id filter control still exists in the real filter panel (the backend
     * always adds one — see BaseServiceGenerator::generateFilterFields()),
     * but its value is no longer visible in the DOM anywhere (no ID column,
     * and unlike "uuid" it isn't embedded in a data-testid either), so it
     * can no longer be read back client-side. "uuid" itself was
     * investigated as a replacement identifier (its value IS always
     * available, via uuidFromTestId()) but generateFilterFields() explicitly
     * never exposes "uuid" as a user-facing filter control ("hidden but
     * filterable" — backend-only), so there is no filter-operator-uuid /
     * filter-value-uuid element for setFilterOperator()/setFilterTextValue()
     * to drive. A real, currently-displayed column is therefore the only
     * value Playwright can both read AND filter by through the UI.
     *
     * Threads the matched filterFields entry's own 'type' through (default
     * 'text' when absent) so buildFilterBlock()/buildFilterVariantB() can
     * tell a Select2/ApiSelect2-shaped filter control (type 'select' or
     * 'select_paginated' — see resolveFilterFields()/
     * inferFallbackFilterFieldType()) apart from a plain `<input>`, instead
     * of always assuming the latter.
     */
    /**
     * Say WHY each declared filterable field was rejected, per field, in the generated spec.
     *
     * pickVisibleFilterField() drops unusable candidates silently, so a module whose filters were
     * misconfigured produced a generic "no safe candidate" line naming none of them. Three distinct
     * misconfigurations land there, all derivable from the schema this generator already reads, and
     * all of which previously surfaced only as a confusing e2e failure minutes into a suite run:
     *
     *  1. The field is not a visible list column. The filter step reads the target row's CURRENT
     *     cell text to filter by, so a column that is never rendered has nothing to read.
     *  2. The field is FK-typed and its list column has no dot-path `data` (e.g. "status.name"),
     *     so the cell renders a raw numeric id while the picker offers resolved labels — the two
     *     can never match. In practice this means the referenced module has no literal `name`
     *     column for the list cell to resolve through.
     *  3. The column is nullable. A NULL renders as "N/A", which is not one of the picker's
     *     options, so the filter can never be satisfied for a row that has no value.
     *
     * Follows v3.4.23's approach for the unique-FK case: emit a specific comment at the collision
     * point rather than guessing a fix or failing opaquely. (3) is reported as a caution rather
     * than a rejection — a nullable column still filters correctly for rows that DO have a value,
     * so rejecting it outright would remove working coverage.
     *
     * @return array{rejections: list<array{key: string, reason: string}>, cautions: list<array{key: string, reason: string}>}
     */
    protected function filterCandidateDiagnostics(): array
    {
        $titleByKey = [];
        $dataPathByKey = [];
        foreach ($this->config['features']['frontend']['list']['fields'] ?? [] as $lf) {
            if (!empty($lf['key'])) {
                $titleByKey[$lf['key']] = true;
                $dataPathByKey[$lf['key']] = (string) ($lf['data'] ?? $lf['key']);
            }
        }

        $nullableByName = [];
        foreach ($this->config['columns'] ?? [] as $column) {
            if (!empty($column['name'])) {
                $nullableByName[$column['name']] = !empty($column['nullable']);
            }
        }

        $rejections = [];
        $cautions = [];
        foreach ($this->filterFields as $ff) {
            $key = (string) ($ff['key'] ?? '');
            if ($key === '' || $key === 'id') {
                continue;
            }
            $type = (string) ($ff['type'] ?? 'text');
            $isFk = $type === 'select' || $type === 'select_paginated';

            if (!isset($titleByKey[$key])) {
                $rejections[] = [
                    'key' => $key,
                    'reason' => 'not a visible list column — the filter step reads the row\'s current cell text, '
                        . 'so give it defaultVisible: true (or drop it from filterableFields)',
                ];
                continue;
            }

            if ($isFk && strpos($dataPathByKey[$key] ?? '', '.') === false) {
                $rejections[] = [
                    'key' => $key,
                    'reason' => 'FK column whose list cell renders a raw id, not a resolved label — the picker\'s '
                        . 'options can never match it; the referenced module needs a literal `name` column',
                ];
                continue;
            }

            if (!empty($nullableByName[$key])) {
                $cautions[] = [
                    'key' => $key,
                    'reason' => 'nullable column — a row with no value renders "N/A", which is not a selectable option',
                ];
            }
        }

        return ['rejections' => $rejections, 'cautions' => $cautions];
    }

    /** Render diagnostics as tab-indented JS comment lines, or '' when there is nothing to say. */
    protected function renderFilterDiagnostics(array $entries, string $heading): string
    {
        if ($entries === []) {
            return '';
        }

        $lines = ["\t\t// {$heading}"];
        foreach ($entries as $entry) {
            $lines[] = "\t\t//   - `{$entry['key']}`: {$entry['reason']}";
        }

        return implode("\n", $lines) . "\n";
    }

    protected function pickVisibleFilterField(): ?array
    {
        $titleByKey = [];
        $dataPathByKey = [];
        foreach ($this->config['features']['frontend']['list']['fields'] ?? [] as $lf) {
            if (!empty($lf['key'])) {
                $titleByKey[$lf['key']] = (string) ($lf['title'] ?? $lf['key']);
                $dataPathByKey[$lf['key']] = (string) ($lf['data'] ?? $lf['key']);
            }
        }

        // Bug (found 2026-08-16, live, running the retail-ERP demo fixture's
        // own generated e2e suite): a Select2/ApiSelect2-shaped filter field
        // (type 'select'/'select_paginated', i.e. an FK column) is only a
        // safe Variant B target when the SAME key's list column also
        // resolves to a related record's display value (a dot-path `data`,
        // e.g. "status.name") — that's the only way getRowColumnValue()'s
        // captured cell text can ever match one of the Select2 popup's
        // option labels. Confirmed live: V1's actual wizard never emits
        // that dot-path shape for a list column today (BaseComponentGenerator
        // ::generateCustomCellRenderersFromListFields()'s own `isFk` branch,
        // which WOULD wire up a resolved `<RelatedRecordLink>` cell, has no
        // way to fire without it) — every FK list column in a real V1
        // project renders the raw numeric id. Picking such a field here
        // captured "1" (the row's raw id) and searched the Select2 popup for
        // an option literally labelled "1", which never exists — a
        // guaranteed failure, not a flaky one. Until V1's own wizard can
        // produce that dot-path shape (a separate, real gap — see this
        // method's own class-level note), skip straight past any FK-typed
        // candidate here rather than pick one that cannot work.
        $isUnsafeFkField = function (array $ff) use ($dataPathByKey): bool {
            $type = (string) ($ff['type'] ?? 'text');
            if ($type !== 'select' && $type !== 'select_paginated') {
                return false;
            }
            $dataPath = $dataPathByKey[$ff['key'] ?? ''] ?? '';
            return strpos($dataPath, '.') === false;
        };

        $anchor = $this->pickAnchorField();
        if ($anchor !== null && isset($titleByKey[$anchor])) {
            foreach ($this->filterFields as $ff) {
                if (($ff['key'] ?? '') === $anchor && !$isUnsafeFkField($ff)) {
                    return ['key' => $anchor, 'label' => $titleByKey[$anchor], 'type' => (string) ($ff['type'] ?? 'text')];
                }
            }
        }

        foreach ($this->filterFields as $ff) {
            $key = $ff['key'] ?? '';
            if ($key !== '' && $key !== 'id' && isset($titleByKey[$key]) && !$isUnsafeFkField($ff)) {
                return ['key' => $key, 'label' => $titleByKey[$key], 'type' => (string) ($ff['type'] ?? 'text')];
            }
        }

        return null;
    }

    /** JS expression (as a PHP string) for a field's create-time unique value. References the in-scope `stamp` const. */
    /**
     * An author-declared JS test value for $field, or null when none is declared.
     *
     * Mirrors PhpUnitTestGenerator::sampleValueLiteral() — see that method for the full rationale
     * and the config shape. The browser side hits the identical wall: a field whose real constraint
     * lives in a `processing_service` normalizer rather than in any validation rule cannot be
     * satisfied by anything derivable from the schema, and the generated fill 422s on create.
     *
     * `sample_value_js` exists for values that must vary per run — the spec's own `stamp` const is
     * in scope at every fill site, so an expression like `('+2557' + String(stamp).slice(-8))`
     * keeps a unique-indexed column unique across runs.
     */
    protected function sampleValueExpr(array $field): ?string
    {
        $key = (string) ($field['field'] ?? $field['key'] ?? '');
        if ($key === '') {
            return null;
        }

        // The field's OWN declaration wins. That is what makes this work for an action field
        // (actions.{name}.fields[]): it is hand-authored input config, not a Create/Edit column, so
        // there is no features.backend.{create,edit}.fields[] entry to hang a sample value on --
        // and the rules that constrain it usually live in the hand-written action service, which
        // this generator cannot read. Confirmed live on Countries' updateCountryCodes: the service
        // enforces `iso3` max:3 while every schema-derived length says 255, so the generated smoke
        // test typed a 40-character string, the submit 422'd, and the dialog never closed.
        $own = $this->declaredSampleExpr($field);
        if ($own !== null) {
            return $own;
        }

        foreach (['create', 'edit'] as $op) {
            foreach (($this->config['features']['backend'][$op]['fields'] ?? []) as $backendField) {
                if (($backendField['field'] ?? null) !== $key) {
                    continue;
                }

                $declared = $this->declaredSampleExpr($backendField);
                if ($declared !== null) {
                    return $declared;
                }
            }
        }

        return null;
    }

    /** The JS expression a `sample_value_js` / `sample_value` declaration on $declaration names, or null. */
    private function declaredSampleExpr(array $declaration): ?string
    {
        $expression = $declaration['sample_value_js'] ?? null;
        if (is_string($expression) && trim($expression) !== '') {
            return trim($expression);
        }

        if (array_key_exists('sample_value', $declaration)) {
            $value = $declaration['sample_value'];
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_string($value)) {
                return var_export($value, true);
            }
        }

        return null;
    }

    /** The backend validation rules declared for $key, preferring the edit form's over create's. */
    protected function backendRulesFor(string $key): string
    {
        foreach (['edit', 'create'] as $op) {
            foreach (($this->config['features']['backend'][$op]['fields'] ?? []) as $backendField) {
                if (($backendField['field'] ?? null) === $key) {
                    return (string) ($backendField['rules'] ?? '');
                }
            }
        }

        return '';
    }

    /**
     * A JS literal drawn from the field's own `in:` rule, or null when it has none.
     *
     * An `in:` rule names the column's entire valid domain, so no value this generator invents by
     * type can be right. Confirmed live on a rental-CRM domain: `billing_cycle_months` is
     * `in:1,2,3,6,12` while the numeric branch fills `(1000000 + (stamp % 900000))` — a 7-digit
     * number that fits the column perfectly and is rejected outright by the very rules this
     * generator wrote ("The selected billing cycle months is invalid"), so every create 422'd and
     * the spec failed on a step it never completed.
     *
     * $preferSecond picks a different member for the edit step where the domain has one, so the
     * edit is a real change; a single-member domain correctly reuses it.
     */
    protected function allowedValueExpr(array $field, bool $preferSecond = false): ?string
    {
        $key = (string) ($field['field'] ?? $field['key'] ?? '');
        if ($key === '') {
            return null;
        }

        $allowed = PhpUnitTestGenerator::allowedValuesFromRules($this->backendRulesFor($key));
        if ($allowed === []) {
            return null;
        }

        $value = ($preferSecond && isset($allowed[1])) ? $allowed[1] : $allowed[0];

        return is_numeric($value) ? (string) $value : var_export((string) $value, true);
    }

    protected function fieldValueExpr(array $field): string
    {
        // An explicit sample_value beats everything, including `in:` — it is the author saying so.
        $sampleExpr = $this->sampleValueExpr($field);
        if ($sampleExpr !== null) {
            return $sampleExpr;
        }

        // An `in:` rule names the whole valid domain — nothing type-shaped below can beat it.
        $allowedExpr = $this->allowedValueExpr($field, false);
        if ($allowedExpr !== null) {
            return $allowedExpr;
        }

        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));
        $fieldType = strtolower((string) ($field['field_type'] ?? ''));

        // An action field (actions.{name}.fields[]) carries ONLY field_type — it is
        // hand-authored input config, not derived from a real schema column, so it has no
        // 'type' key at all for a Create/Edit field's own $field['type'] to fall back on here.
        // Checking field_type too (not just the schema type) is what makes this branch apply to
        // action fields as well. Found live building Pangisha's 09.01 Activate (2026-08-25):
        // payment_amount (field_type: 'number', no 'type' key) fell through to the generic
        // `E2E __MODULE__ __LABEL__ ${stamp}` text template, which a NumberInputField cannot
        // accept — fillField()'s post-fill readback never matched what was typed, since the
        // widget silently stripped every non-digit character out of the string as it typed.
        if (in_array($type, $numericTypes, true) || $fieldType === 'number') {
            return $this->constrainNumericExpr($field, '(1000000 + (stamp % 900000))', 1);
        }

        // A real 'date' column MUST get a real yyyy-mm-dd value: it renders
        // as a native `<input type="date">` (see IntrospectionToConfig::
        // buildFrontendFields() — 'date' columns get field_type: 'date' AND
        // type: 'date'), and browsers silently reject/clear any value that
        // isn't valid date-input syntax. The generic `E2E __MODULE__
        // __LABEL__ ${stamp}` template below produced exactly that kind of
        // nonsense string for a 'date' field before this check existed —
        // confirmed live: a generated ItemPrices e2e test tried to
        // `fillField()` "E2E ItemPrices Effective Date 1784869998451" into
        // `#effective_date` (type="date"), which the input rejects outright,
        // so fillField()'s post-fill readback never matched and it threw
        // "Could not fill #effective_date: stuck at ''". Playwright's own
        // `.fill()` on a date input requires exactly this yyyy-mm-dd shape.
        //
        // field_type === 'date' checked too, same reasoning as the numeric branch above: an
        // action field has no schema 'type' at all, only field_type — and `paid_at`-style action
        // fields render through the identical native `<input type="date">` (form.stub's InputField
        // gets `type="date"` whenever field_type is 'date', same as any Create/Edit date field).
        if ($type === 'date' || $fieldType === 'date') {
            return $this->localIsoDateExpr(0);
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ ${stamp}`
JS;
        $expr = str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );

        return $this->constrainToColumnLength($field, $expr);
    }

    /**
     * Clamp a generated string to its column's length.
     *
     * `E2E {Module} {Label} ${stamp}` is ~30-40 characters, so any short column
     * (a varchar(20) code, a currency, a colour) was guaranteed to fail the
     * backend's max: rule — the create POST returned 422, the dialog stayed
     * open, and the spec timed out waiting for it to close. The frontend field
     * config carries no length, so this reads columns[].length, which is the
     * same source the migration and the validation rule come from.
     *
     * `slice(-n)` keeps the stamp's low-order digits rather than the constant
     * prefix, so truncated values stay unique across runs — the point of the
     * stamp in the first place.
     *
     * `.trimStart()`: the cut can land on a word boundary, leaving a leading space
     * (`"E2E … Line Kind 1789…".slice(-24)` starts with one). The app trims strings on
     * the way in, so the stored value has no space while the spec's expected text
     * still does, and the "created row is in the list" check never matches — a failure
     * that depends on where the cut falls, i.e. on the stamp's length. Found by the
     * super-suite fixture's full Playwright lane (SuiteOrderLines create).
     */
    protected function constrainToColumnLength(array $field, string $expr): string
    {
        $key = (string) ($field['field'] ?? $field['key'] ?? '');
        if ($key === '') {
            return $expr;
        }

        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['name'] ?? null) !== $key) {
                continue;
            }

            $length = (int) ($column['length'] ?? 0);
            if ($length > 0) {
                return "({$expr}).slice(-{$length}).trimStart()";
            }

            break;
        }

        return $expr;
    }

    /**
     * Bug (found + fixed 2026-08-08 while running all 5 generator-engine
     * integration-test suites simultaneously against SYSTEM_SHELL): every
     * numeric field's create/edit value is the fixed formula
     * `(1000000 + (stamp % 900000))` / `(2000000 + (stamp % 900000))` --
     * always a 7-digit integer (1,000,000-1,899,999 / 2,000,000-2,899,999).
     * That fits comfortably in a `decimal(12,2)` or plain `integer` column,
     * but a narrower decimal -- `unit_price decimal(10,4)` (6 integer
     * digits, max 999999.9999) -- can never hold it. Confirmed live: a
     * freshly generated OrderItems create request 500'd with
     * "SQLSTATE[22003]: Numeric value out of range: 1264 Out of range
     * value for column 'unit_price'". `constrainToColumnLength()` already
     * solves the exact same class of problem for string fields (clamping
     * to the column's `length`); this is its numeric counterpart, clamping
     * to the column's `precision - scale` (the integer part's digit
     * capacity -- IntrospectionToConfig::buildColumn() only threads
     * `precision`/`scale` for a real introspected decimal column, so a
     * plain integer/bigint field has neither key and is left at the
     * original formula, matching its effectively unbounded range).
     *
     * $editOffset (1 for create, 2 for edit) keeps the two values distinct
     * by a fixed +1 no matter how small the column's capacity is -- using
     * the SAME `% cap` term for both and only varying by this small
     * constant, rather than modulus-ing the existing 1000000-/2000000-
     * prefixed formulas directly (those prefixes are both multiples of
     * 10^6, so any modulus base that is itself a power of ten -- true for
     * every cap this method computes -- would silently cancel the prefix
     * out and make create/edit collide on the exact same value).
     */
    protected function constrainNumericExpr(array $field, string $defaultExpr, int $editOffset): string
    {
        $key = (string) ($field['field'] ?? $field['key'] ?? '');
        if ($key === '') {
            return $defaultExpr;
        }

        foreach ($this->config['columns'] ?? [] as $column) {
            if (($column['name'] ?? null) !== $key) {
                continue;
            }

            if (!isset($column['precision'])) {
                break; // no known capacity constraint (e.g. a plain int/bigint) -- keep the default
            }

            $maxDigits = max((int) $column['precision'] - (int) ($column['scale'] ?? 0), 1);
            if ($maxDigits >= 7) {
                break; // fits the default formula's max 7-digit value (1,899,999) already
            }

            $cap = max((10 ** $maxDigits) - 2, 1);

            return "((stamp % {$cap}) + {$editOffset})";
        }

        return $defaultExpr;
    }

    /**
     * JS expression for the `yyyy-mm-dd` date `$dayOffset` days from today, in the BROWSER'S local
     * timezone -- the one the date picker selects in.
     *
     * This used to be `new Date().toISOString().slice(0, 10)`, which is the UTC date. The picker
     * (`today(getLocalTimeZone())`, and fillDatePickerField() beside it) works in local time, so for
     * the hours a day when the two calendars disagree (00:00-03:00 in UTC+3, for one) the spec
     * selected one date and then waited for a row to show another: every edit spec of a module with
     * a date field timed out, and only then. Found by the super-suite fixture's gate landing on
     * 00:46 local time; the same gate had passed that afternoon.
     */
    protected function localIsoDateExpr(int $dayOffset): string
    {
        $adjust = $dayOffset === 0 ? '' : " d.setDate(d.getDate() + {$dayOffset});";

        return '(() => { const d = new Date();' . $adjust
            . ' return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, \'0\')}-${String(d.getDate()).padStart(2, \'0\')}`; })()';
    }

    /** Like fieldValueExpr() but produces a distinguishable value for the EDIT block's one changed field. */
    protected function editedFieldValueExpr(array $field): string
    {
        // An explicit sample_value beats everything, including `in:` — it is the author saying so.
        $sampleExpr = $this->sampleValueExpr($field);
        if ($sampleExpr !== null) {
            return $sampleExpr;
        }

        // An `in:` rule names the whole valid domain — nothing type-shaped below can beat it.
        $allowedExpr = $this->allowedValueExpr($field, true);
        if ($allowedExpr !== null) {
            return $allowedExpr;
        }

        $numericTypes = ['number', 'integer', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedinteger', 'smallinteger'];
        $type = strtolower((string) ($field['type'] ?? 'text'));
        $fieldType = strtolower((string) ($field['field_type'] ?? ''));

        // See fieldValueExpr()'s matching branch for the full rationale — field_type checked
        // too, not just the schema type, since an action field carries only field_type.
        if (in_array($type, $numericTypes, true) || $fieldType === 'number') {
            return $this->constrainNumericExpr($field, '(2000000 + (stamp % 900000))', 2);
        }

        // See fieldValueExpr()'s matching 'date' branch for the full
        // rationale. Offset by one day so the edit test's value is
        // genuinely distinguishable from whatever the create step wrote.
        if ($type === 'date') {
            return $this->localIsoDateExpr(1);
        }

        $label = (string) ($field['label'] ?? ($field['field'] ?? 'Field'));
        $tpl = <<<'JS'
`E2E __MODULE__ __LABEL__ EDIT ${stamp}`
JS;
        $expr = str_replace(
            ['__MODULE__', '__LABEL__'],
            [addcslashes($this->moduleName, '\\`$'), addcslashes($label, '\\`$')],
            $tpl
        );

        // The edit value is even longer than the create one, so it needs the
        // same clamp — otherwise edit 422s on exactly the columns create does.
        return $this->constrainToColumnLength($field, $expr);
    }

    /**
     * Renders the JS statement(s) that fill one create/edit field inside the
     * open `[role="dialog"]`, dispatching on field_type. `$valueExpr` is
     * used for the default (plain input) branch only.
     */
    protected function renderFieldFill(array $field, string $valueExpr = ''): string
    {
        $key = (string) ($field['field'] ?? '');
        if ($key === '') {
            return '';
        }
        $fieldType = $field['field_type'] ?? 'input';
        $label = (string) ($field['label'] ?? $key);

        if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
            // An optional (non-required) relation field must NOT be FORCED
            // to select something: fillSelectField() always clicks whatever
            // the first available option happens to be, which throws
            // outright the moment the referenced table has zero rows —
            // a certainty, not an edge case, for a self-referential
            // hierarchy FK on its very first record (e.g.
            // item_categories.parent_id -> item_categories.id: there is
            // categorically no row yet to pick as "parent"). Confirmed
            // live: a generated ItemCategories e2e test failed exactly
            // this way with "no selectable options found for Parent — check
            // seed data". But leaving it permanently untouched means the
            // optional-picker path is never exercised at all. The
            // best-effort tryFillSelectField() helper (see
            // tryFillSelectFieldHelperBlock()) reconciles both: it sets the
            // field when a selectable option exists, and closes the picker
            // and returns `false` — never throws — when the referenced
            // table has zero rows. A REQUIRED relation field (e.g.
            // Items.item_type_id) has no such escape: the test genuinely
            // cannot proceed without a real row in the referenced table,
            // which is a distinct, deeper gap (this generator has no
            // mechanism to seed one) — that case still emits the throwing
            // fillSelectField() call below unchanged.
            if (empty($field['required'])) {
                $tpl = <<<'JS'
		// '__LABEL__' is optional and relation-backed — best-effort: set it
		// when a selectable option exists, otherwise leave it unset (see
		// tryFillSelectField()'s docblock: forcing a selection here would
		// fail outright whenever the referenced table has no rows yet, e.g.
		// a self-referential hierarchy FK's very first record).
		const __KEY__Selected = await tryFillSelectField(page, '[role="dialog"]', '__LABEL__');
		console.log(`[${MODULE_LABEL}] optional field '__LABEL__' ${__KEY__Selected ? 'set' : 'left unset (no options available)'}`);
JS;
                return str_replace(['__KEY__', '__LABEL__'], [$key, addcslashes($label, "'\\")], $tpl);
            }

            // A required relation field whose OWN column the schema marks
            // unique is a true 1:1 relation (e.g. a one-per-user "Profile"
            // module's user_id FK) -- unlike an ordinary many-to-one FK, at
            // most ONE row in this table may ever reference a given related
            // record, so taking option[0] is only free until something else
            // (an earlier spec, the seeder, a soft-deleted row that still holds
            // the value) has used it. The first attempt still takes option[0];
            // what makes the rest work is the submit that follows, which
            // retries with the next option whenever the server answers 422 on
            // this very field -- see applyUniqueOptionRetry(). It is a retry,
            // not a lookup, on purpose: a soft-deleted row occupies the value
            // but is invisible to every list endpoint, so no client-side
            // "which are free?" query can be right.
            if ($this->isUniqueRequiredRelationField($field)) {
                $tpl = <<<'JS'
		// '__LABEL__' is required AND UNIQUE (a true 1:1 relation): this takes the
		// first option, and the submit below moves on to the next one if the server
		// says that record is already taken.
		await fillSelectField(page, '[role="dialog"]', '__LABEL__');
JS;
                return str_replace('__LABEL__', addcslashes($label, "'\\"), $tpl);
            }

            $tpl = <<<'JS'
		await fillSelectField(page, '[role="dialog"]', '__LABEL__');
JS;
            return str_replace('__LABEL__', addcslashes($label, "'\\"), $tpl);
        }

        if ($fieldType === 'checkbox') {
            $tpl = <<<'JS'
		await page.locator('[role="dialog"] #__KEY__').click();
JS;
            return str_replace('__KEY__', $key, $tpl);
        }

        if ($fieldType === 'file-input') {
            // A bare 8-byte PNG magic-number prefix is NOT a valid image: real
            // MIME sniffing (libmagic/PHP's finfo, which is exactly what
            // Laravel's `file`/`image` validation rules use server-side) reads
            // actual file content, not just a magic number, and rejects a
            // truncated 8-byte "file" outright — so a test built that way can
            // never pass a real upload against the real backend. This is a
            // genuine, complete, valid 1x1 transparent PNG (70 bytes),
            // base64-embedded and decoded via Buffer.from(..., 'base64') so
            // Playwright's setInputFiles() receives real image bytes;
            // confirmed live with PHP's finfo_file() reporting "image/png"
            // for these exact decoded bytes.
            return <<<'JS'
		await page.locator('[role="dialog"] input[type="file"]').setInputFiles({
			name: 'e2e-fixture.png',
			mimeType: 'image/png',
			buffer: Buffer.from(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
				'base64',
			),
		});
JS;
        }

        if ($fieldType === 'date') {
            // See dateFieldHelperBlock()'s docblock for the full rationale --
            // 'date' fields render through DatePickerField.vue's popover
            // Calendar, not a fillable <input>. Day offset 0 = today,
            // matching fieldValueExpr()'s 'date' branch.
            $tpl = <<<'JS'
		await fillDatePickerField(page, '[role="dialog"]', '__KEY__', 0);
JS;
            return str_replace('__KEY__', $key, $tpl);
        }

        if ($fieldType === 'morph-select') {
            $tpl = <<<'JS'
		await fillMorphSelectField(page, '[role="dialog"]', '__KEY__');
JS;
            return str_replace('__KEY__', $key, $tpl);
        }

        // 'number-input' is IntrospectionToConfig::build()'s internal alias, always used for a
        // schema-derived Create/Edit numeric field. 'number' is the raw config value
        // docs/modules/actions.md documents for a hand-authored action field — never aliased,
        // since actions.{name}.fields[] is never schema-introspected. Both render through the
        // identical NumberInputField.vue, so both need the identical comma-tolerant fill below.
        if ($fieldType === 'number-input' || $fieldType === 'number') {
            // A plain fillField() readback-check compares the DOM input's
            // literal displayed string against the exact value typed — but
            // NumberInputField.vue (the component every 'number-input'
            // field_type renders as) formats its value through Cleave.js
            // with thousands separators (see that component's `delimiter:
            // ','` option), so `.inputValue()` legitimately returns
            // "1,875,449" for an underlying value of 1875449. Confirmed
            // live: a generated ItemImages e2e test threw `Could not fill
            // #sort_order: stuck at "1,875,449", expected "1875449"` on
            // its very first attempt — fillField()'s exact-string
            // comparison can never pass for this component. fillNumberField()
            // is the same fill-then-verify contract, just comma-tolerant.
            $tpl = <<<'JS'
		await fillNumberField(page, '[role="dialog"] #__KEY__', __VALUE__);
JS;
            return str_replace(['__KEY__', '__VALUE__'], [$key, $valueExpr], $tpl);
        }

        $tpl = <<<'JS'
		await fillField(page, '[role="dialog"] #__KEY__', __VALUE__);
JS;
        return str_replace(['__KEY__', '__VALUE__'], [$key, $valueExpr], $tpl);
    }

    /**
     * The `const {$varName} = { ...fieldValueExpr() per field };` declaration
     * block shared by buildCreateBlock() and the fixture/delegation create
     * bodies below — extracted so all three stay byte-for-byte consistent
     * with each other rather than drifting via copy-paste. Fields with no
     * plain-fillable value (select/file-input/checkbox — those are filled
     * directly, not via a declared value) are skipped, same as
     * buildFieldFillLines() below. Returns '' when nothing qualifies.
     */
    protected function buildFieldDeclarationsBlock(array $fields, string $varName = 'createValues'): string
    {
        $declLines = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? '';
            $fieldType = $field['field_type'] ?? 'input';
            if ($key === '' || in_array($fieldType, [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox', 'morph-select'], true)) {
                continue;
            }
            $declLines[] = "\t\t\t{$key}: " . $this->fieldValueExpr($field) . ',';
        }

        if (empty($declLines)) {
            return '';
        }

        return "\n\n\t\tconst {$varName} = {\n" . implode("\n", $declLines) . "\n\t\t};";
    }

    /**
     * renderFieldFill() lines for every field, split into the non-file lines
     * (filled before submit) and file-only lines (attached last — see
     * buildCreateBlock()'s file-validation-isolation rationale). $varName
     * must match whatever buildFieldDeclarationsBlock() declared the values
     * under.
     *
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    protected function buildFieldFillLines(array $fields, string $varName = 'createValues'): array
    {
        $fillLinesNonFile = [];
        $fillLinesFileOnly = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? '';
            if ($key === '') {
                continue;
            }
            $fieldType = $field['field_type'] ?? 'input';
            $valueExpr = in_array($fieldType, [...self::SELECT_FIELD_TYPES, 'file-input', 'checkbox', 'morph-select'], true) ? '' : ($varName . '.' . $key);
            $line = $this->renderFieldFill($field, $valueExpr);
            if ($line === '') {
                continue;
            }
            if ($fieldType === 'file-input') {
                $fillLinesFileOnly[] = $line;
            } else {
                $fillLinesNonFile[] = $line;
            }
        }

        return [$fillLinesNonFile, $fillLinesFileOnly];
    }

    /**
     * Strips one leading tab from every non-blank line of a block built by
     * the helpers above (which all emit at the CRUD file's 2-tabs-deep test()
     * body level) so it reads correctly when reused at _fixtures.js's
     * 1-tab-deep exported-function level. Purely cosmetic — JS doesn't care
     * — but keeps generated output readable.
     */
    protected function dedentBlock(string $block, int $levels = 1): string
    {
        $prefix = str_repeat("\t", $levels);
        $lines = explode("\n", $block);
        foreach ($lines as &$line) {
            if (str_starts_with($line, $prefix)) {
                $line = substr($line, strlen($prefix));
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Helper-function block builders (top of file, above test.describe)
    // ------------------------------------------------------------------

    protected function buildHelperFunctions(): string
    {
        $blocks = [];

        if ($this->hasCreate || $this->hasEdit || $this->hasDelete) {
            $blocks[] = $this->fillFieldHelpersBlock();
        }

        if ($this->hasRequiredSelectFieldType()) {
            $blocks[] = $this->selectFieldHelperBlock();
        }

        if ($this->hasOptionalSelectFieldType()) {
            $blocks[] = $this->tryFillSelectFieldHelperBlock();
        }

        if ($this->hasCreate && ($this->pickRequiredScalarField() !== null || $this->pickRequiredFileField() !== null)) {
            $blocks[] = $this->fieldErrorLocatorHelperBlock();
        }

        if ($this->hasFieldType('number-input')) {
            $blocks[] = $this->numberFieldHelperBlock();
        }

        if ($this->hasFieldType('date')) {
            $blocks[] = $this->dateFieldHelperBlock();
        }

        if ($this->hasFieldType('morph-select')) {
            $blocks[] = $this->morphSelectFieldHelperBlock();
        }

        $blocks[] = $this->rowHelpersBlock();

        // cleanupHelperBlock()'s cleanupStrayRecord() is NOT included here:
        // it existed only for the old monolithic file's own try/finally
        // failure-unwind step (see buildTestBody(), removed). Every current
        // split file's teardown goes through _fixtures.js's cleanupRecord()
        // instead (create.e2e.js calls it directly; list/view/edit/delete's
        // split.e2e.stub shell calls it from its own finally block) — a
        // second, unused cleanup function would just be dead code in every
        // generated spec.

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    protected function fillFieldHelpersBlock(): string
    {
        return <<<'JS'
/** Replace an input's value directly and dispatch a real `input`/`change`
 * event (avoids selection/keystroke quirks with select-all + retype on
 * Vue-controlled inputs). */
async function setInputValue(page, selector, value) {
	await page.evaluate(
		({ sel, val }) => {
			const el = document.querySelector(sel);
			el.value = val;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		},
		{ sel: selector, val: String(value) },
	);
}

/**
 * Fill a field, then verify the value actually landed — falls back to a
 * direct value-set if `.fill()` dropped keystrokes or a background
 * re-render reset the field. Throws with a clear diagnostic rather than
 * letting a blank field silently reach form submit.
 */
async function fillField(page, selector, value) {
	const expected = String(value);
	await page.locator(selector).fill(expected);
	let actual = await page.locator(selector).inputValue();
	if (actual !== expected) {
		await setInputValue(page, selector, expected);
		actual = await page.locator(selector).inputValue();
		if (actual !== expected) {
			throw new Error(`Could not fill ${selector}: stuck at "${actual}", expected "${expected}"`);
		}
	}
}
JS;
    }

    protected function selectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort driver for a Select2Field/ApiSelect2Field control inside
 * `dialogSelector`, located by its <label> text (mirrors the nested-dialog
 * pattern used by helpers/filters.js's setFilterOperator and the ApiSelect2
 * helpers in locations.e2e.js). Opens the trigger, waits for the picker
 * popup stacked on top, and clicks the first selectable option — enough to
 * satisfy a required relation/select field without knowing real seed data.
 * Generic across both the local Select2Field (`.cursor-pointer` option rows)
 * and the API-backed ApiSelect2Field (`.divide-y > div` option rows) shapes;
 * may need per-module adjustment for more elaborate pickers.
 *
 * `{ index }` picks the Nth option instead of the first. Only a required UNIQUE
 * relation field needs it: a 1:1 column can be given to one row, so a spec that
 * always takes option[0] collides with whatever an earlier spec left behind.
 */
async function fillSelectField(page, dialogSelector, labelText, { index = 0 } = {}) {
	const trigger = page
		.locator(dialogSelector)
		// Anchored, not a bare substring match -- label.textContent() is always
		// "labelText " or "labelText *" (trailing space/required-asterisk from
		// the shared Field.vue label template), so a plain substring `hasText`
		// would also match any other field whose OWN label merely contains this
		// one as a suffix (e.g. "Status" matching "Payment Status" too).
		// Field-wrapper-class-agnostic: different consuming projects wrap a
		// <Label> + control + error message in different divs (Boot Box-
		// based projects use `flex flex-col gap-1.5`; the class this used to
		// hardcode, `.space-y-2`, was specific to SYSTEM_SHELL's OLD, now-
		// replaced field components). The label's own immediate parent IS
		// that wrapper, whatever class it carries -- no assumption needed.
		.locator('label', { hasText: new RegExp('^' + labelText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\*?\\s*$') })
		.locator('xpath=..')
		.locator('.select2-trigger button, [data-slot="select2-trigger"]');
	if ((await trigger.count()) === 0) {
		throw new Error(`fillSelectField: no select2 trigger found for label "${labelText}" in "${dialogSelector}"`);
	}

	const beforeCount = await page.locator('[role="dialog"]').count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });

	const popup = page.locator('[role="dialog"]').last();
	// ApiSelect2 mounts three MUTUALLY EXCLUSIVE branches while its fetch is in
	// flight: a spinner (`.animate-spin` + "Loading..."), an empty state
	// ("No results found" / "No options available"), or the option list. Only
	// the list carries `.divide-y`/`.cursor-pointer` rows at all -- so reading
	// rows after a FIXED sleep samples whichever branch happened to be mounted
	// at that instant, and reports "no selectable options" when the truth is
	// "not loaded yet".
	//
	// Confirmed from a real trace, not a theoretical race: a 2026-08-23 run of
	// a 15-module suite caught this picker mid-fetch with its list area holding
	// only `<div class="animate-spin">Loading...</div>`, and the same suite had
	// the same spec pass on the previous run -- the classic signature of a
	// duration-based wait. Wait on the component's own settled state instead.
	const apiRows = () => popup.locator('.divide-y > div');
	const localRows = () => popup.locator('.cursor-pointer');
	const emptyState = () => popup.getByText(/^\s*(No results found|No options available)\s*$/);
	const deadline = Date.now() + 10000;
	let timedOut = false;
	for (;;) {
		if ((await apiRows().count()) > 0 || (await localRows().count()) > 0) break;
		const stillLoading = (await popup.locator('.animate-spin').count()) > 0;
		if (!stillLoading && (await emptyState().count()) > 0) break; // genuinely empty
		if (Date.now() > deadline) { timedOut = true; break; }
		await new Promise((r) => setTimeout(r, 100));
	}

	const option = (await apiRows().count()) > 0 ? apiRows().nth(index) : localRows().nth(index);
	if ((await option.count()) === 0) {
		// Two genuinely different causes, worth telling apart: the picker
		// settling on its own "No results"/"No options" empty state really does
		// mean the seed data is missing -- but a 10s timeout with the picker
		// STILL loading (or in some other transient state) never resolved to
		// either outcome, and blaming seed data there sends a debugging session
		// straight into the wrong file. Found live: this exact message fired
		// while a real trace showed the picker mid-fetch, not empty.
		const reason = index > 0
			? `the picker has no option at position ${index + 1} -- a UNIQUE (1:1) field consumes one related record per row created, so the related table needs more seed rows`
			: timedOut
				? 'the picker never settled within 10s (still loading, or a hung/slow API response) -- a timing or network issue, not necessarily missing seed data'
				: 'check seed data';
		throw new Error(`fillSelectField: no selectable options found for "${labelText}" — ${reason}`);
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
}
JS;
    }

    protected function tryFillSelectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort variant of fillSelectField() for an OPTIONAL relation field:
 * identical mechanics (open the trigger, wait for the stacked picker popup,
 * click the first selectable option), but — unlike fillSelectField(), which
 * throws when the referenced table has zero rows (a certainty, not an edge
 * case, for some self-referential hierarchies on their very first record) —
 * closes the picker and returns `false` instead of throwing when there is
 * nothing to select. This is what lets an optional relation field actually
 * get exercised when data is available, without reintroducing the
 * zero-row flakiness fillSelectField() was fixed to avoid.
 */
async function tryFillSelectField(page, dialogSelector, labelText) {
	const trigger = page
		.locator(dialogSelector)
		// Anchored, not a bare substring match -- label.textContent() is always
		// "labelText " or "labelText *" (trailing space/required-asterisk from
		// the shared Field.vue label template), so a plain substring `hasText`
		// would also match any other field whose OWN label merely contains this
		// one as a suffix (e.g. "Status" matching "Payment Status" too).
		// Field-wrapper-class-agnostic: different consuming projects wrap a
		// <Label> + control + error message in different divs (Boot Box-
		// based projects use `flex flex-col gap-1.5`; the class this used to
		// hardcode, `.space-y-2`, was specific to SYSTEM_SHELL's OLD, now-
		// replaced field components). The label's own immediate parent IS
		// that wrapper, whatever class it carries -- no assumption needed.
		.locator('label', { hasText: new RegExp('^' + labelText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\*?\\s*$') })
		.locator('xpath=..')
		.locator('.select2-trigger button, [data-slot="select2-trigger"]');
	if ((await trigger.count()) === 0) {
		return false;
	}

	const beforeCount = await page.locator('[role="dialog"]').count();
	await trigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });

	const popup = page.locator('[role="dialog"]').last();
	// ApiSelect2 mounts three MUTUALLY EXCLUSIVE branches while its fetch is in
	// flight: a spinner (`.animate-spin` + "Loading..."), an empty state
	// ("No results found" / "No options available"), or the option list. Only
	// the list carries `.divide-y`/`.cursor-pointer` rows at all -- so reading
	// rows after a FIXED sleep samples whichever branch happened to be mounted
	// at that instant, and reports "no selectable options" when the truth is
	// "not loaded yet".
	//
	// Confirmed from a real trace, not a theoretical race: a 2026-08-23 run of
	// a 15-module suite caught this picker mid-fetch with its list area holding
	// only `<div class="animate-spin">Loading...</div>`, and the same suite had
	// the same spec pass on the previous run -- the classic signature of a
	// duration-based wait. Wait on the component's own settled state instead.
	const apiRows = () => popup.locator('.divide-y > div');
	const localRows = () => popup.locator('.cursor-pointer');
	const emptyState = () => popup.getByText(/^\s*(No results found|No options available)\s*$/);
	const deadline = Date.now() + 10000;
	for (;;) {
		if ((await apiRows().count()) > 0 || (await localRows().count()) > 0) break;
		const stillLoading = (await popup.locator('.animate-spin').count()) > 0;
		if (!stillLoading && (await emptyState().count()) > 0) break; // genuinely empty
		if (Date.now() > deadline) break;
		await new Promise((r) => setTimeout(r, 100));
	}

	const option = (await apiRows().count()) > 0 ? apiRows().first() : localRows().first();
	if ((await option.count()) === 0) {
		// Close via the picker's own Close button, NOT Escape -- the picker is
		// itself an AppDialog (same component the View dialog uses), and
		// AppDialog's `persistent` prop defaults true with no override here
		// either, so Escape is captured and preventDefault()'d, same as the
		// v3.4.1/v3.4.2 bug. Confirmed live: with Escape, the picker silently
		// stayed open (both .catch()es swallowed the failure) while this
		// function returned `false` as if it had cleanly closed -- the NEXT
		// field's own trigger click then hung 15s behind the still-open
		// picker's own overlay, which was never closing-animation residue,
		// genuinely still `data-state="open"` the whole time.
		await popup.getByRole('button', { name: 'Close' }).click();
		await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
		return false;
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
	return true;
}
JS;
    }

    protected function fieldErrorLocatorHelperBlock(): string
    {
        return <<<'JS'
/**
 * Locator for the inline validation-error message rendered directly under
 * the field labeled `labelText` inside `dialogSelector`. Every field-wrapper
 * component (InputField.vue, FileInputField.vue, Select2Field.vue, etc.)
 * wraps its `<Label>` + control in a div with a sibling
 * `<p class="... text-destructive ...">` that only renders `v-if="error"` —
 * found by walking up from the label to its own parent, not by any assumed
 * wrapper class name (that class differs by project — see fillSelectField()'s
 * identical lookup, which this mirrors).
 */
function fieldErrorLocator(page, dialogSelector, labelText) {
	return page
		.locator(dialogSelector)
		// Anchored, not a bare substring match -- label.textContent() is always
		// "labelText " or "labelText *" (trailing space/required-asterisk from
		// the shared Field.vue label template), so a plain substring `hasText`
		// would also match any other field whose OWN label merely contains this
		// one as a suffix (e.g. "Status" matching "Payment Status" too).
		// Field-wrapper-class-agnostic: different consuming projects wrap a
		// <Label> + control + error message in different divs (Boot Box-
		// based projects use `flex flex-col gap-1.5`; the class this used to
		// hardcode, `.space-y-2`, was specific to SYSTEM_SHELL's OLD, now-
		// replaced field components). The label's own immediate parent IS
		// that wrapper, whatever class it carries -- no assumption needed.
		.locator('label', { hasText: new RegExp('^' + labelText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\*?\\s*$') })
		.locator('xpath=..')
		.locator('p.text-destructive');
}
JS;
    }

    protected function numberFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Like fillField(), but tolerant of NumberInputField.vue's Cleave.js
 * thousands-separator formatting (`delimiter: ','` — see that component):
 * `.inputValue()` legitimately returns "1,875,449" for an underlying value
 * of 1875449, so a bare string-equality readback check can never pass for
 * a 'number-input' field_type. Commas are stripped from both sides before
 * comparing; everything else mirrors fillField()'s fill-then-verify,
 * fall-back-to-setInputValue contract.
 */
async function fillNumberField(page, selector, value) {
	const stripCommas = (s) => String(s).replace(/,/g, '');
	const expected = String(value);
	await page.locator(selector).fill(expected);
	let actual = await page.locator(selector).inputValue();
	if (stripCommas(actual) !== stripCommas(expected)) {
		await setInputValue(page, selector, expected);
		actual = await page.locator(selector).inputValue();
		if (stripCommas(actual) !== stripCommas(expected)) {
			throw new Error(`Could not fill ${selector}: stuck at "${actual}", expected "${expected}"`);
		}
	}
}
JS;
    }

    /**
     * Bug (found + fixed 2026-08-08 while running all 5 generator-engine
     * integration-test suites simultaneously against SYSTEM_SHELL): every
     * 'date' field_type fell through renderFieldFill()'s default branch,
     * which calls fillField() (a plain `.locator(selector).fill()`) --
     * correct for a native `<input type="date">`, but SYSTEM_SHELL's date
     * fields have rendered through the shadcn-vue DatePickerField.vue
     * popover+Calendar component (id={fieldId} on a <button>, not an
     * <input>) since that migration landed earlier the same day. Confirmed
     * live: `fillField()` threw "Element is not an <input>, <textarea>,
     * <select>..." on a freshly generated Payments/ItemPrices module's date
     * field, on the very first create attempt -- a hard failure, not a
     * flake, for every module with a required 'date' field. The edit-step
     * scalar-field branch (buildEditBlock()) had the identical assumption
     * via setInputValue()+.inputValue() and needed the same fix -- see its
     * own 'date' branch.
     *
     * Mirrors the hand-written fillDatePickerField() already proven working
     * in users-crud.e2e.js (added when DatePickerField.vue itself shipped),
     * generalized with a $dayOffset param so the edit step can select
     * "tomorrow" (editedFieldValueExpr()'s date branch) as well as
     * "today" (fieldValueExpr()'s). `[data-today]` only identifies today's
     * cell, so any non-zero offset falls back to locating the cell by its
     * day-of-month number, clicking the calendar's "next month" nav once if
     * the target date isn't in the currently-displayed month (the only way
     * a same-year ±1-day offset can cross a month boundary).
     */
    protected function dateFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Open a DatePickerField (id={fieldId} on the trigger <button> — see
 * DatePickerField.vue) and select the date `dayOffset` days from today
 * (0 = today, matching fieldValueExpr()'s create-time value; a non-zero
 * offset matches editedFieldValueExpr()'s edit-time value). The trigger
 * renders as a <button>, not a fillable input/textarea, so this drives
 * the calendar grid instead of fillField()/setInputValue().
 *
 * Scoped to the Calendar itself (`[data-slot="calendar"]`), not to whatever
 * surface hosts it: older field components open it in a Popover, Boot Box-
 * based ones in a stacked AppDialog (same "click trigger -> modal"
 * interaction as Select2). Both mount the same Calendar, so this works
 * against either without knowing which.
 */
async function fillDatePickerField(page, dialogSelector, fieldId, dayOffset = 0) {
	const beforeCount = await page.locator('[role="dialog"]').count();
	await page.locator(`${dialogSelector} #${fieldId}`).click();
	const calendar = page.locator('[data-slot="calendar"]').last();
	await calendar.waitFor({ timeout: 8000 });

	if (dayOffset === 0) {
		const todayCell = calendar.locator('[data-slot="calendar-cell-trigger"][data-today]');
		await todayCell.waitFor({ timeout: 8000 });
		await todayCell.click();
	} else {
		const today = new Date();
		const target = new Date(Date.now() + dayOffset * 86400000);
		if (target.getMonth() !== today.getMonth() || target.getFullYear() !== today.getFullYear()) {
			await calendar.locator('[data-slot="calendar-next-button"]').click();
		}
		const dayCell = calendar
			.locator('[data-slot="calendar-cell-trigger"]:not([data-outside-view])')
			.getByText(String(target.getDate()), { exact: true });
		await dayCell.waitFor({ timeout: 8000 });
		await dayCell.click();
	}

	await calendar.waitFor({ state: 'hidden', timeout: 8000 }).catch(() => {});
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
}
JS;
    }

    /**
     * Not built on fillSelectField()'s label-text locator strategy: a
     * morph-select field renders TWO `.select2-trigger` controls (a type
     * dropdown and, once a type is picked, an API record picker) inside the
     * SAME outer labeled field -- fillSelectField()'s "first .select2-trigger
     * under this field's label" locator can't disambiguate them once both
     * are mounted. MorphSelectField.vue renders explicit
     * data-testid="{key}-type-wrapper"/"{key}-record-wrapper" anchors for
     * exactly this reason -- see that component's own template comment.
     */
    protected function morphSelectFieldHelperBlock(): string
    {
        return <<<'JS'
/**
 * Drive a morph-select field (MorphSelectField.vue: a type dropdown, then an
 * API-backed record picker scoped to the chosen type) — two sequential
 * picker interactions, each mirroring fillSelectField()'s own mechanics
 * (open trigger, wait for the stacked dialog, click the first option, wait
 * for it to close), scoped by data-testid rather than label text since both
 * pickers share the same outer labeled field.
 */
async function fillMorphSelectField(page, dialogSelector, key) {
	const typeWrapper = page.locator(dialogSelector).locator(`[data-testid="${key}-type-wrapper"]`);
	const typeTrigger = typeWrapper.locator('.select2-trigger button, [data-slot="select2-trigger"]');
	if ((await typeTrigger.count()) === 0) {
		throw new Error(`fillMorphSelectField: no type trigger found for "${key}" in "${dialogSelector}"`);
	}

	let beforeCount = await page.locator('[role="dialog"]').count();
	await typeTrigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });

	let popup = page.locator('[role="dialog"]').last();
	// Same settled-state wait as fillSelectField() -- see its comment. A fixed
	// sleep here samples whichever of ApiSelect2's mutually exclusive
	// loading/empty/list branches happens to be mounted at that instant.
	// Returns true when the deadline was hit without ever resolving to a
	// definitive state (options present, or a genuine empty state) -- the
	// caller uses this to tell "settled empty, seed data really is missing"
	// apart from "never settled, this is a timing/network issue" instead of
	// blaming seed data for both.
	const settle = async (scope, hasApiRows) => {
		const deadline = Date.now() + 10000;
		for (;;) {
			if ((await scope.locator('.cursor-pointer').count()) > 0) return false;
			if (hasApiRows && (await scope.locator('.divide-y > div').count()) > 0) return false;
			const stillLoading = (await scope.locator('.animate-spin').count()) > 0;
			if (!stillLoading && (await scope.getByText(/^\s*(No results found|No options available)\s*$/).count()) > 0) return false;
			if (Date.now() > deadline) return true;
			await new Promise((r) => setTimeout(r, 100));
		}
	};
	const typeTimedOut = await settle(popup, false);
	let option = popup.locator('.cursor-pointer').first();
	if ((await option.count()) === 0) {
		const reason = typeTimedOut
			? ' (the picker never settled within 10s -- a timing or network issue, not necessarily missing seed data)'
			: '';
		throw new Error(`fillMorphSelectField: no type options found for "${key}"${reason}`);
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });

	// The record picker only mounts after a type is selected.
	const recordWrapper = page.locator(dialogSelector).locator(`[data-testid="${key}-record-wrapper"]`);
	await recordWrapper.waitFor({ state: 'visible', timeout: 8000 });
	const recordTrigger = recordWrapper.locator('.select2-trigger button, [data-slot="select2-trigger"]');
	if ((await recordTrigger.count()) === 0) {
		throw new Error(`fillMorphSelectField: no record trigger found for "${key}" after selecting type`);
	}

	beforeCount = await page.locator('[role="dialog"]').count();
	await recordTrigger.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeCount, { timeout: 8000 });

	popup = page.locator('[role="dialog"]').last();
	const recordTimedOut = await settle(popup, true);
	const apiOptions = popup.locator('.divide-y > div');
	option = (await apiOptions.count()) > 0 ? apiOptions.first() : popup.locator('.cursor-pointer').first();
	if ((await option.count()) === 0) {
		const reason = recordTimedOut
			? 'the picker never settled within 10s (still loading, or a hung/slow API response) -- a timing or network issue, not necessarily missing seed data'
			: 'check seed data for the chosen type';
		throw new Error(`fillMorphSelectField: no selectable records found for "${key}" — ${reason}`);
	}
	await option.click();
	await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeCount, { timeout: 8000 });
}
JS;
    }

    protected function rowHelpersBlock(): string
    {
        return <<<'JS'
/** Locator for the row(s) whose text contains `text`. */
function rowLocator(page, text) {
	return page.locator('table tbody tr', { hasText: text });
}

async function rowExists(page, name) {
	return (await rowLocator(page, name).count()) > 0;
}

/** Extract the uuid segment from a `data-testid="[[moduleName]]-{action}-{uuid}"` attribute. */
function uuidFromTestId(testId, action) {
	if (!testId) return null;
	const prefix = `[[moduleName]]-${action}-`;
	return testId.startsWith(prefix) ? testId.slice(prefix.length) : null;
}
JS;
    }

    protected function cleanupHelperBlock(): string
    {
        return <<<'JS'
/**
 * Best-effort delete of a record left over from a failed run. Invoked from a
 * `finally` block once the record's uuid has been captured right after
 * creation, so this runs regardless of whether the view/edit/delete steps
 * after creation succeeded or threw.
 *
 * Every failure here is swallowed and logged as a WARNING rather than
 * thrown — this executes during failure unwinding and must never replace or
 * mask the original error that triggered it.
 */
async function cleanupStrayRecord(page, uuid) {
	try {
		if ((await page.locator('[role="dialog"]').count()) > 0) {
			await page.keyboard.press('Escape').catch(() => {});
			await sleep(500);
		}

		const stillPresent = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count().catch(() => 0)) > 0;
		if (!stillPresent) {
			console.log(`[${MODULE_LABEL}] cleanup: uuid ${uuid} no longer in the list — nothing to do`);
			return;
		}

		console.log(`[${MODULE_LABEL}] cleanup: attempting best-effort delete of uuid ${uuid} after failure`);

		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`cleanup view button (data-testid="[[moduleName]]-view-${uuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${uuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await sleep(500);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		const stillThere = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count()) > 0;
		if (stillThere) {
			console.log(`[${MODULE_LABEL}] WARNING: cleanup delete did not remove uuid ${uuid} — manual removal may be required`);
		} else {
			console.log(`[${MODULE_LABEL}] cleanup: deleted stray record (uuid ${uuid})`);
		}
	} catch (cleanupErr) {
		console.log(
			`[${MODULE_LABEL}] WARNING: cleanup failed for uuid ${uuid} — manual removal may be required:`,
			cleanupErr && cleanupErr.stack ? cleanupErr.stack : cleanupErr,
		);
	}
}
JS;
    }

    // ------------------------------------------------------------------
    // Test body helpers
    // ------------------------------------------------------------------

    /**
     * Indent every non-blank line of a multi-line generated JS block by one
     * extra tab level. Blank lines are left untouched so no trailing
     * whitespace gets introduced.
     */
    protected function indentBlock(string $block, int $levels = 1): string
    {
        $prefix = str_repeat("\t", $levels);
        $lines = explode("\n", $block);
        foreach ($lines as &$line) {
            if ($line !== '') {
                $line = $prefix . $line;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Individual step blocks
    // ------------------------------------------------------------------

    protected function buildCreateBlock(): string
    {
        if (!$this->hasCreate) {
            return '';
        }

        $open = <<<'JS'
		// ── Create ────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			() => page.locator('[data-testid="[[moduleName]]-create"]').click(),
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '02-create-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing
JS;

        // ── Validation: submit the pristine, entirely empty form ────────────
        // Runs BEFORE anything below fills a single field, on the freshly
        // opened dialog — the only point in the whole flow where every field
        // is still blank. The create form has no client-side `required`
        // gate (see input.stub: `:required` is forwarded to InputField.vue
        // only to render the red "*" label, never onto the underlying
        // `<input>` — confirmed against SYSTEM_SHELL/FRONTEND/src/
        // components/form-fields/InputField.vue and Input.vue), so this
        // submit always reaches the backend and comes back as a real 422.
        // The dialog never closes/navigates on a failed submit (handleSubmit
        // only emits 'created'/navigates when `response.status` is true), so
        // every fill/submit step below runs against the exact same
        // still-open dialog and is completely unaffected by this step.
        $createWizard = $this->createWizard();
        $open = str_replace('-submit"]', '-' . $this->createOpenReadyTestId() . '"]', $open);
        $requiredScalarField = $createWizard === null ? $this->pickRequiredScalarField() : null;
        $validationBlock = $createWizard === null ? '' : <<<'JS'

		// (Multi-step Create form: the submit button only exists on the last step, behind the Review &
		// Confirm checkbox, so "submit the pristine form and expect an inline error" cannot be asked
		// here. The wizard's own fill below drives every step instead.)
JS;
        if ($requiredScalarField !== null) {
            $validationLabel = (string) ($requiredScalarField['label'] ?? $requiredScalarField['field']);
            $validationTpl = <<<'JS'

		// ── Validation: submitting with required "__LABEL__" empty ─────────────
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
		const requiredFieldError = fieldErrorLocator(page, '[role="dialog"]', '__LABEL__');
		await requiredFieldError.first().waitFor({ timeout: 10000 });
		expect(await requiredFieldError.count(), `Expected an inline validation error for "__LABEL__" after submitting it empty`).toBeGreaterThan(0);
		expect(await page.locator('[role="dialog"]').count(), 'Dialog should remain open after a failed validation submit').toBeGreaterThan(0);
		console.log(`[${MODULE_LABEL}] validation OK — submitting with "__LABEL__" empty rendered an inline error and kept the dialog open`);
JS;
            $validationBlock = str_replace('__LABEL__', addcslashes($validationLabel, "'\\"), $validationTpl);
        }

        $declBlock = $this->buildFieldDeclarationsBlock($this->createFields);
        if ($createWizard !== null) {
            $fillLinesNonFile = $this->buildWizardFillLines(
                $this->createFields,
                $createWizard['steps'],
                $createWizard['confirm'],
                'createValues',
                "\t\tawait page.locator('[role=\"dialog\"] [data-testid=\"[[moduleName]]-wizard-next\"]').click();"
            );
            $fillLinesFileOnly = [];
        } else {
            [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($this->createFields);
        }
        $fillBlock = implode("\n", $fillLinesNonFile);

        // ── Validation: submit with every field filled EXCEPT the required
        // file — isolates the file requirement from the rest of the form
        // (every other required field, including required relations, is
        // already filled by $fillBlock above) so this genuinely exercises
        // the file-required path rather than conflating it with some other
        // missing field. Same "dialog never closes on failure" guarantee as
        // the empty-form validation step above, so the file itself is
        // simply attached afterwards and the happy-path submit proceeds
        // completely unaffected.
        $requiredFileField = $this->pickRequiredFileField();
        $fileValidationBlock = '';
        if ($requiredFileField !== null && !empty($fillLinesFileOnly)) {
            $fileLabel = (string) ($requiredFileField['label'] ?? $requiredFileField['field']);
            $fileValidationTpl = <<<'JS'

		// ── Validation: submitting without the required "__LABEL__" file ────────
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
		const missingFileError = fieldErrorLocator(page, '[role="dialog"]', '__LABEL__');
		await missingFileError.first().waitFor({ timeout: 10000 });
		expect(await missingFileError.count(), `Expected an inline validation error for missing required "__LABEL__" file`).toBeGreaterThan(0);
		expect(await page.locator('[role="dialog"]').count(), 'Dialog should remain open after a failed file-required submit').toBeGreaterThan(0);
		console.log(`[${MODULE_LABEL}] validation OK — submitting without required "__LABEL__" file rendered an inline error and kept the dialog open`);
JS;
            $fileValidationBlock = str_replace('__LABEL__', addcslashes($fileLabel, "'\\"), $fileValidationTpl);
        }
        $fileFillBlock = implode("\n", $fillLinesFileOnly);

        // CrudListPanel.vue's onCreated() (generator-engine v3.2.2, "create -> view by
        // default") opens the new record's own View dialog immediately after a successful
        // create, instead of just closing back to the list -- but only when this module has
        // a View component wired ($this->hasView, mirroring onCreated()'s own
        // `props.viewComponent && payload?.[props.rowKey]` guard). Assert accordingly: a
        // dialog is expected to REMAIN present (now the View one, not the Create one), then
        // close it via its own Close button to get back to the list for the rest of this
        // flow. NOT Escape (v3.4.1's original approach, reverted in v3.4.2): AppDialog.vue's
        // `persistent` prop defaults to true and the View dialog usage never overrides it,
        // so Escape is captured and preventDefault()'d -- confirmed live, every module's
        // create step hung for 15s waiting on a dialog that Escape can never close. The
        // dialog's own DialogClose button (reka-ui DialogContent.vue) has no data-testid but
        // its accessible name is always literally "Close" (a visually-hidden span, not
        // conditional per module), so getByRole is reliable across every generated module. A
        // view-disabled module keeps the original "no dialog at all" assertion, since
        // onCreated() itself falls back to close-and-refresh-only in that case.
        if ($this->hasView) {
            $submit = <<<'JS'
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		// Create succeeded -> onCreated() swaps the Create dialog for a View dialog of the
		// new record; a dialog is expected to still be present throughout, never absent.
		await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 });
		await sleep(300);
		expect(await page.locator('[role="dialog"]').count(), 'Expected the View dialog to open automatically after a successful create').toBeGreaterThan(0);
		await page.locator('[role="dialog"]').getByRole('button', { name: 'Close' }).click();
		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
JS;
        } else {
            $submit = <<<'JS'
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
JS;
        }

        $anchor = $this->pickAnchorField();
        $responseArm = '';
        if ($anchor !== null) {
            $confirmTpl = <<<'JS'

		const createdRowText = String(createValues.__ANCHOR__);
		await page.waitForFunction(
			(rowText) => Array.from(document.querySelectorAll('table tbody tr')).some((tr) => tr.textContent.includes(rowText)),
			createdRowText,
			{ timeout: 15000 },
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" did not appear in the list`).toBe(true);
		console.log(`[${MODULE_LABEL}] create OK — row "${createdRowText}" present`);
JS;
            $confirm = str_replace('__ANCHOR__', $anchor, $confirmTpl);
        } else {
            // No plain text/number field to search the list for -- "first row after
            // reload" is NOT a safe stand-in: a pre-existing row can permanently sort
            // above every freshly created one (found live, 2026-08-23, on UserLocations
            // -- a legacy non-UUIDv7 id happens to sort above every real id under
            // ORDER BY id DESC, so `.first()` kept grabbing an unrelated seeded row
            // instead of the one this test just created). Arm a response listener
            // BEFORE the submit click below (buildTargetRowBlock() awaits it after) so
            // the created record is identified by the record key the backend actually
            // returned, never by DOM position.
            $responseArm = <<<'JS'

		const createResponsePromise = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'));
JS;
            // shelui-engine fork: this used to read `?.data?.uuid` unconditionally --
            // a has_uuid: false module's create response has no `uuid` key at all
            // (only `.data.id`, see ModuleConfigContract::hasUuid() and this
            // generator's own $idParam, resolved identically to
            // FrontendRoutesGenerator::$idParam/BaseComponentGenerator::idParam()),
            // so createdRecordUuid was always null here and every such module's
            // create spec threw "no uuid was found in the response" on every run.
            // The local var/property names stay literally "uuid" (recordUuid,
            // createdRecordUuid, uuidFromTestId(), the { uuid: ... } fixture
            // shape) -- only the field actually read off the JSON body changes;
            // see this class's cleanupHelperBlock()/uuidFromTestId() docblocks for
            // why renaming those is unneeded churn, not a correctness fix.
            $confirm = <<<'JS'

		console.log(`[${MODULE_LABEL}] create submitted (no plain text/number field available — targeting the created record by its API-returned record key instead)`);
		const createResponse = await createResponsePromise;
		if (!createResponse.ok()) {
			const createResponseBody = await createResponse.json().catch(() => null);
			throw new Error(`[${MODULE_LABEL}] create request failed: HTTP ${createResponse.status()} ${JSON.stringify(createResponseBody)}`);
		}
		const createdRecordUuid = (await createResponse.json())?.data?.__ID_PARAM__ ?? null;
		if (!createdRecordUuid) {
			throw new Error(`[${MODULE_LABEL}] create succeeded (HTTP ${createResponse.status()}) but no __ID_PARAM__ was found in the response`);
		}
JS;
            $confirm = str_replace('__ID_PARAM__', $this->idParam, $confirm);
        }

        $shotLine = "\n\t\tawait shot(page, '03-after-create');";

        $fileFillSection = $fileFillBlock !== '' ? "\n" . $fileFillBlock : '';

        [$submit, $responseArm] = $this->applyUniqueOptionRetry($submit, $responseArm, "\t\t");

        return $open . $validationBlock . $declBlock . "\n\n" . $fillBlock . $fileValidationBlock . $fileFillSection . $responseArm . "\n\n" . $submit . $confirm . $shotLine;
    }

    protected function buildTargetRowBlock(): string
    {
        if ($this->hasCreate && $this->pickAnchorField() !== null) {
            return <<<'JS'
		const targetRow = rowLocator(page, createdRowText).first();
JS;
        }

        if ($this->hasCreate) {
            // No anchor field -- buildCreateBlock() captured createdRecordUuid from the
            // create response above; filter for the <tr> containing THAT record's own
            // view button rather than trusting DOM/sort position (see the comment in
            // buildCreateBlock() for why position is unsafe here).
            return <<<'JS'
		const targetRow = page.locator('table tbody tr').filter({ has: page.locator(`[data-testid="[[moduleName]]-view-${createdRecordUuid}"]`) }).first();
JS;
        }

        return <<<'JS'
		const targetRow = page.locator('table tbody tr').first();
JS;
    }

    /**
     * targetRow for the list/view/edit/delete split specs: these get their
     * record from _fixtures.js's createFixtureRecord() (split.e2e.stub
     * already declares `recordUuid` from it before [[testBody]] runs), never
     * from a create step of their own — so there is no createdRowText/
     * createdRecordUuid in scope the way buildTargetRowBlock() assumes.
     * Same "filter by the row containing this uuid's own view button" shape
     * as buildTargetRowBlock()'s own no-anchor-field branch, just sourced
     * from the fixture's uuid instead.
     */
    protected function buildFixtureTargetRowBlock(): string
    {
        return <<<'JS'
		const targetRow = page.locator('table tbody tr').filter({ has: page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`) }).first();
JS;
    }

    /**
     * $forceVariantB: true for the split list.e2e.js spec, which has no
     * createdRowText (its record comes from _fixtures.js, not a create step
     * of its own — see buildFixtureTargetRowBlock()) for Variant A to
     * search the list for.
     */
    protected function buildFilterBlock(bool $forceVariantB = false): string
    {
        $textFilter = $this->pickTextFilterField();
        $anchor = $this->pickAnchorField();
        $useVariantA = !$forceVariantB && $textFilter !== null && $this->hasCreate && $anchor !== null && $textFilter['key'] === $anchor;

        if ($useVariantA) {
            return $this->buildFilterVariantA((string) $textFilter['key']);
        }

        $visible = $this->pickVisibleFilterField();
        if ($visible === null) {
            // No safe candidate exists: every declared filterField is either
            // not a visible list column at all, or is FK-typed with no
            // resolved-display list column to read back (see
            // pickVisibleFilterField()'s own docblock on the isUnsafeFkField
            // guard — this is the expected, common case for a module whose
            // ONLY filterable business column happens to be a foreign key,
            // e.g. PaymentTerms.status_id, confirmed live against the
            // retail-ERP demo fixture). Emitting a filter step here would be
            // a guaranteed failure, not a flaky one (id/uuid have no visible
            // DOM value to read at all — see the docblock above; an FK
            // column's dropdown options never match its own raw-id cell
            // text). Skip the filter portion of this spec entirely with a
            // clear, honest log line instead of asserting something that
            // cannot currently pass — this is a real, documented generator
            // limitation (V1's wizard doesn't yet emit the dot-path list
            // column shape FK-column display resolution needs), not
            // something this generated test should paper over by picking a
            // doomed field or a fake pass.
            $diagnostics = $this->filterCandidateDiagnostics();
            $why = $this->renderFilterDiagnostics(
                $diagnostics['rejections'],
                'Why each declared filterableFields entry was rejected:'
            );

            $skipTpl = <<<'JS'
		// ── Filter — skipped ──────────────────────────────────────────
		// No declared filterField is both a visible list column AND safely
		// matchable by its own displayed cell text. Filter coverage for this
		// module is intentionally skipped rather than asserting something
		// that cannot pass.
__WHY__		console.log(`[${MODULE_LABEL}] filter: skipped — no filterable field is both a visible column and safely matchable (FK columns show raw ids, not resolved names)`);
JS;

            return str_replace('__WHY__', $why, $skipTpl);
        }

        $diagnostics = $this->filterCandidateDiagnostics();
        $caution = $this->renderFilterDiagnostics(
            array_values(array_filter(
                $diagnostics['cautions'],
                static fn (array $entry): bool => $entry['key'] === (string) $visible['key']
            )),
            'Caution — this filter target is not guaranteed matchable for every row:'
        );

        return $caution . $this->buildFilterVariantB((string) $visible['key'], (string) $visible['label'], (string) ($visible['type'] ?? 'text'));
    }

    protected function buildFilterVariantA(string $filterKey): string
    {
        $tpl = <<<'JS'
		// ── Filter (Variant A: plain text field "__KEY__") ───────────────────
		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterTextValue(page, '__KEY__', createdRowText);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after filtering by __KEY__`).toBe(true);
		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by __KEY__="${createdRowText}" to narrow the list to 1 row, got ${filteredRowCount}`).toBe(1);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__ narrowed the list to exactly 1 row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		expect(await rowExists(page, createdRowText), `Created row "${createdRowText}" not visible after clearing filters`).toBe(true);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace('__KEY__', $filterKey, $tpl);
    }

    /**
     * Filter block Variant B: filters by whatever value the target row is
     * CURRENTLY showing for a real, visible list column — chosen by
     * pickVisibleFilterField() as a filterFields entry that's also a
     * features.frontend.list.fields column. Replaces the pre-v2.15.0
     * "read the ID column" approach (see class docblock and
     * pickVisibleFilterField()'s docblock for why "id"/"uuid" can't stand
     * in for it): $filterKey drives the filter panel control,
     * $columnLabel is that same field's list-column header text, used to
     * read the row's live value via getRowColumnValue().
     *
     * $filterType (from pickVisibleFilterField()/resolveFilterFields() —
     * see inferFallbackFilterFieldType()) dispatches to
     * buildFilterVariantBSelect() for a Select2/ApiSelect2-shaped filter
     * ('select'/'select_paginated' — an FK, enum, or boolean column), which
     * has no `<input>` for setFilterTextValue() to drive at all. Found live:
     * UserLocations' user_id/location_id/role_id FK filters, which
     * pickVisibleFilterField() picks here since none of them qualify for
     * Variant A (see buildFilterBlock()).
     */
    protected function buildFilterVariantB(string $filterKey, string $columnLabel, string $filterType = 'text'): string
    {
        if ($this->isSelectShapedFilterType($filterType)) {
            return $this->buildFilterVariantBSelect($filterKey, $columnLabel);
        }

        $tpl = <<<'JS'
		// ── Filter (Variant B: visible column "__LABEL__", field "__KEY__") ──
		const targetValue = await getRowColumnValue(page, targetRow, '__LABEL__');
		console.log(`[${MODULE_LABEL}] filter: captured target row's __LABEL__ = "${targetValue}"`);

		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterOperator(page, '__KEY__', 'Equals');
		await setFilterTextValue(page, '__KEY__', targetValue);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		const filteredRowCount = await getVisibleRowCount(page);
		expect(filteredRowCount, `Expected filtering by __KEY__=${targetValue} (Equals) to narrow the list to exactly 1 row, got ${filteredRowCount}`).toBe(1);
		const onlyRow = page.locator('table tbody tr').first();
		const onlyRowValue = await getRowColumnValue(page, onlyRow, '__LABEL__');
		expect(
			onlyRowValue,
			`Expected the sole remaining row after filtering by __KEY__=${targetValue} to be the target row, got __LABEL__="${onlyRowValue}"`,
		).toBe(targetValue);
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__="${targetValue}" (Equals) narrowed the list to exactly the target row`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace(['__KEY__', '__LABEL__'], [$filterKey, $columnLabel], $tpl);
    }

    /**
     * Filter block Variant B, Select2/ApiSelect2-shaped control variant —
     * same "filter by whatever the target row currently shows" strategy as
     * buildFilterVariantB(), but the value control at
     * `[data-testid="filter-value-{key}"]` resolves to a Select2/ApiSelect2
     * `.select2-container` div, not an `<input>` (see setFilterTextValue()'s
     * own guard in filters.js). Uses setFilterSelect2Value() instead, which
     * opens the picker and clicks the option matching the target row's
     * currently-displayed text — the same value getRowColumnValue() reads
     * here, so the two stay in lockstep. No setFilterOperator() call: the
     * DEFAULT operator DataTableFilter.vue picks per field type already
     * produces the right single-vs-multi value-control shape
     * (getDefaultOperatorForField() — 'in' for 'select_paginated' unless the
     * field explicitly opts into `multiple: false`, 'eq' for a static
     * 'select'), and setFilterSelect2Value() itself handles both the
     * auto-closing single-select and the explicit-Confirm multi-select
     * interaction shapes generically.
     */
    protected function buildFilterVariantBSelect(string $filterKey, string $columnLabel): string
    {
        $tpl = <<<'JS'
		// ── Filter (Variant B: visible column "__LABEL__", Select2/ApiSelect2 field "__KEY__") ──
		const targetValue = await getRowColumnValue(page, targetRow, '__LABEL__');
		console.log(`[${MODULE_LABEL}] filter: captured target row's __LABEL__ = "${targetValue}"`);

		const baselineRowCount = await getVisibleRowCount(page);
		console.log(`[${MODULE_LABEL}] filter: baseline row count = ${baselineRowCount}`);

		await openFilterPanel(page);
		await setFilterSelect2Value(page, '__KEY__', targetValue);
		await applyFilters(page, waitForListSettled);
		await shot(page, '03b-after-filter-apply');

		const filteredRowCount = await getVisibleRowCount(page);
		// A foreign key is legitimately many-to-one — an item owns several images,
		// a price list several prices — so "exactly 1 row" is not a valid
		// expectation here: any pre-seeded sibling sharing the same FK fails a
		// filter that is working correctly. What "the filter works" actually means
		// is that at least one row survives and EVERY surviving row carries the
		// target value.
		expect(filteredRowCount, `Expected filtering by __KEY__="${targetValue}" to leave at least one row, got ${filteredRowCount}`).toBeGreaterThanOrEqual(1);
		const filteredRows = page.locator('table tbody tr');
		for (let i = 0; i < filteredRowCount; i++) {
			const rowValue = await getRowColumnValue(page, filteredRows.nth(i), '__LABEL__');
			expect(
				rowValue,
				`Every row surviving the __KEY__="${targetValue}" filter must carry that value, but row ${i + 1} has __LABEL__="${rowValue}"`,
			).toBe(targetValue);
		}
		console.log(`[${MODULE_LABEL}] filter OK — filtering by __KEY__="${targetValue}" left ${filteredRowCount} row(s), all matching`);

		await clearAllFilters(page, waitForListSettled);
		await shot(page, '03c-after-clear-filters');

		const restoredRowCount = await getVisibleRowCount(page);
		expect(restoredRowCount, `Expected row count to return to the baseline (${baselineRowCount}) after clearing filters, got ${restoredRowCount}`).toBe(
			baselineRowCount,
		);
		console.log(`[${MODULE_LABEL}] filter OK — clearing filters restored row count to baseline (${baselineRowCount})`);
JS;

        return str_replace(['__KEY__', '__LABEL__'], [$filterKey, $columnLabel], $tpl);
    }

    /**
     * Click a foreign-key cell's RelatedRecordLink and assert it opens the
     * related record's view modal.
     *
     * FK columns render through <RelatedRecordLink> so a user can jump from,
     * say, an Item's "Item Category" cell straight into that category's own
     * view modal without leaving the list. Nothing tested that jump: the
     * generated spec asserted the cell's TEXT ("Beverages") and never that the
     * link works, so a link that rendered as inert text — which is exactly what
     * RelatedRecordLink degrades to when the target module is unregistered or
     * the user lacks {Module}.view — looked identical to a working one.
     */
    protected function buildRelatedRecordBlock(): string
    {
        $fk = null;
        foreach ($this->config['features']['frontend']['list']['fields'] ?? [] as $field) {
            if (($field['isFk'] ?? false) && !empty($field['relatedModule'])) {
                $fk = $field;
                break;
            }
        }

        if ($fk === null) {
            return '';
        }

        $relatedModule = $fk['relatedModule'];

        return <<<JS
		// ── Related record: FK cell -> related module's view modal ───────────
		{
			const relatedLink = page.locator('[data-testid^="related-{$relatedModule}-"]').first();

			if ((await relatedLink.count()) > 0) {
				const relatedLabel = (await relatedLink.innerText()).trim();
				await relatedLink.click();

				// openEntity() mounts the RELATED module's view modal, so the
				// dialog that appears belongs to {$relatedModule}, not this module.
				const relatedDialog = page.locator('[role="dialog"]').first();
				await relatedDialog.waitFor({ timeout: 15000 });
				await sleep(1200);

				expect(
					(await relatedDialog.innerText()).trim().length,
					'related-record modal opened but rendered nothing'
				).toBeGreaterThan(0);
				console.log(`[\${MODULE_LABEL}] related record "\${relatedLabel}" opened {$relatedModule}'s view modal`);
				await shot(page, 'related-{$relatedModule}');

				// Back to a clean list before the create/view/edit steps below.
				await page.keyboard.press('Escape');
				await sleep(600);
				await page.goto(`\${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
					waitUntil: 'networkidle',
					timeout: 30000,
				});
				await waitForListSettled(page);
			} else {
				// Not a silent skip: an inert FK cell is the bug this guards.
				console.log(`[\${MODULE_LABEL}] WARNING: no clickable {$relatedModule} related-record link found — FK cell may be rendering as plain text`);
			}
		}
JS;
    }

    /**
     * Exercise each tab-type delegation on the record's details page.
     *
     * Delegations had no e2e coverage at all: PlaywrightTestGenerator did not
     * mention them, so a generated spec drove the parent module's own CRUD and
     * never opened a child tab. That gap is how a broken tab link survived —
     * the nav emitted `/details/ScratchItems` while the route was registered as
     * `scratch-items` (see generateTabNavigation()).
     *
     * Navigates directly to the tab route rather than clicking the nav link,
     * then asserts the nav link exists and points at the same path — a click
     * alone would pass on a mismatched link by silently staying put.
     */
    protected function buildDelegationBlock(): string
    {
        $delegations = $this->config['delegations'] ?? [];
        $blocks = [];

        foreach ($delegations as $featureKey => $delegation) {
            $uiType = $delegation['uiType'] ?? ($delegation['displayType'] ?? '');
            if ($uiType !== 'tab' && $uiType !== 'tab-action') {
                continue;
            }

            $tabId = Str::kebab($delegation['name'] ?? $featureKey);
            $label = addslashes($delegation['label'] ?? $tabId);
            $filterKey = $delegation['filterKey'] ?? 'parent_id';

            $blocks[] = <<<JS
		// ── Delegation: {$label} ────────────────────────────────────────────
		if (recordUuid) {
			await page.goto(`\${BASE_URL}/[[moduleRoute]]/\${recordUuid}/details/{$tabId}`, {
				waitUntil: 'networkidle',
				timeout: 30000,
			});

			// The tab must be reachable from the nav, not just by URL: assert the
			// generated link resolves to the route that actually exists.
			const {$this->jsIdent($tabId)}Tab = page.locator('[data-testid="[[moduleName]]-tab-{$tabId}"]');
			await expect({$this->jsIdent($tabId)}Tab, 'delegation tab "{$label}" missing from the details nav').toHaveCount(1);
			expect(
				await {$this->jsIdent($tabId)}Tab.getAttribute('href'),
				'delegation tab "{$label}" links to a path the router does not register'
			).toContain('/details/{$tabId}');

			// The child list renders inside the tab's router-view. Waiting for a
			// `table` alone is not enough: a delegation tab with no columns
			// renders a table shell with no header and no rows while the fetch
			// returns 200 — it looks like missing data and is not. Assert the
			// header row, and that no label leaked as a raw i18n key.
			const childTable = page.locator('table').first();
			await childTable.waitFor({ timeout: 15000 });
			const childHeaders = (await childTable.locator('thead th').allTextContents())
				.map((h) => h.trim())
				.filter(Boolean);

			expect(
				childHeaders.length,
				'delegation "{$label}" rendered a table with no column headers — the tab generated an empty columns array'
			).toBeGreaterThan(0);
			expect(
				childHeaders.filter((h) => /^[a-z0-9-]+\.[a-z0-9_]+$/.test(h)),
				'delegation "{$label}" column headers are raw i18n keys — the label namespace does not resolve'
			).toEqual([]);

			await shot(page, 'delegation-{$tabId}');
			console.log(`[\${MODULE_LABEL}] delegation "{$label}" tab rendered — headers: \${childHeaders.join(', ')}`);

			// ── Child CREATE ────────────────────────────────────────────────
			// Only the child LIST was ever exercised, so the create path could
			// break silently — and did: the FK default sent the parent's uuid
			// into an integer column and every create 422'd with "must be an
			// integer". Opening the form and submitting is what catches it.
			const addChild = page.getByRole('button', { name: /^Add / }).first();
			if ((await addChild.count()) > 0) {
				// The create request is intercepted and answered with a synthetic
				// success rather than allowed through. Letting it complete leaves
				// a real child row, and the parent's own delete step later in
				// this spec then fails with "Cannot delete — active relationships
				// found" — a test creating data that breaks its own teardown.
				// It is FULFILLED, not aborted: aborting raises a `requestfailed`
				// event, which this spec's own diagnostics gate treats as a
				// failure. Either way the payload is captured, which is the point.
				let submitted = null;
				await page.route('**/{$tabId}/create', async (route) => {
					submitted = route.request().postData() ?? '';
					await route.fulfill({
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify({ status: true, code: 200, message: 'intercepted by e2e', data: {} }),
					});
				});

				await addChild.click();
				await page.locator('[role="dialog"]').last().waitFor({ timeout: 10000 });
				await sleep(1000);
				await shot(page, 'delegation-{$tabId}-create-form');

				const submit = page.locator('[role="dialog"]').last().getByRole('button', { name: /^(Create|Save|Submit)/ }).first();
				if ((await submit.count()) > 0) {
					await submit.click();
					await sleep(1500);
				}
				await page.unroute('**/{$tabId}/create');

				if (submitted !== null) {
					// The FK must carry the parent's integer id. Sending the uuid
					// string here is what produced "The item id field must be an
					// integer" on every delegation create.
					// Forms post multipart/form-data (they may carry an upload), so
					// the FK appears as a Content-Disposition part, not JSON. Both
					// shapes are accepted so this keeps working if that changes.
					const jsonMatch = submitted.match(/"{$filterKey}"\s*:\s*"?([^",}]*)"?/);
					const formMatch = submitted.match(/name="{$filterKey}"[\s\S]*?\\r?\\n\\r?\\n([^\\r\\n]*)/);
					const fkValue = (jsonMatch?.[1] ?? formMatch?.[1] ?? '').trim();

					expect(
						fkValue !== '',
						`delegation "{$label}" create sent no {$filterKey} at all — the parent FK default is missing`
					).toBeTruthy();
					expect(
						/^\d+$/.test(fkValue),
						`delegation "{$label}" create sent {$filterKey}="\${fkValue}" — expected the parent's integer id, not its uuid`
					).toBeTruthy();
					console.log(`[\${MODULE_LABEL}] delegation "{$label}" create sends {$filterKey}=\${fkValue} (parent id)`);
				} else {
					console.log(`[\${MODULE_LABEL}] WARNING: delegation "{$label}" create was never submitted — FK payload unverified`);
				}

				// Leave nothing open: a lingering dialog swallows the clicks of
				// every step after this block, surfacing far away as a cleanup
				// timeout rather than as a failure here. Close button, not
				// Escape -- every AppDialog instance (View dialog, this create
				// dialog, a select2 picker) defaults `persistent: true` with no
				// override, so Escape is captured and preventDefault()'d and can
				// never actually close any of them (confirmed live 2026-08-20).
				for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
					await page.locator('[role="dialog"]').last().getByRole('button', { name: 'Close' }).click().catch(() => {});
					await sleep(700);
				}
			} else {
				console.log(`[\${MODULE_LABEL}] delegation "{$label}" has no create action enabled — skipping child create`);
			}

			// Return to the list. The edit/delete steps that follow operate on
			// the list page's row actions, and leaving the browser parked on the
			// details route made them fail with a missing-button error that
			// looked like a permissions or rendering bug rather than navigation.
			await page.goto(`\${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
				waitUntil: 'networkidle',
				timeout: 30000,
			});
			await waitForListSettled(page);
		}
JS;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * kebab-case tab id -> a safe JS identifier prefix (scratch-items -> scratchItems).
     */
    protected function jsIdent(string $kebab): string
    {
        return lcfirst(Str::studly($kebab));
    }

    protected function buildUuidCaptureBlock(): string
    {
        return <<<'JS'
		const recordTestId = await targetRow
			.locator('[data-testid^="[[moduleName]]-view-"]')
			.first()
			.getAttribute('data-testid')
			.catch(() => null);
		const recordUuid = uuidFromTestId(recordTestId, 'view');
		if (recordUuid) {
			console.log(`[${MODULE_LABEL}] captured uuid ${recordUuid} — view/edit/delete steps below are now targetable`);
		} else {
			console.log(`[${MODULE_LABEL}] WARNING: could not capture a record uuid — view/edit/delete steps below may fail`);
		}
JS;
    }

    /**
     * $leaveOpenForEdit: true when writeEditSpecFile() calls this to reach
     * Edit's own button, which lives INSIDE this dialog (its submit path
     * already waits for the dialog to close before continuing); false for
     * writeViewSpecFile()'s standalone view spec, which must close the
     * dialog itself via the Close button — NOT page.keyboard.press('Escape'):
     * AppDialog.vue defaults `persistent: true` and CrudListPanel.vue's View
     * dialog usage never overrides it, so Escape is captured and
     * preventDefault()'d and can never close it — the same v3.4.1/v3.4.2
     * regression buildCreateBlock() already hit and fixed for this identical
     * dialog (see its own docblock: "confirmed live, every module's create
     * step hung for 15s"). Reusing that same proven
     * `getByRole('button', { name: 'Close' })` click here instead.
     *
     * No longer checks $this->hasCreate/pickAnchorField() for its assertion:
     * both writeViewSpecFile() and writeEditSpecFile() get their record from
     * _fixtures.js's createFixtureRecord(), which returns only a uuid, not
     * the field values it typed in — there is no createdRowText in scope
     * here to assert against, unlike the old single-file spec where a create
     * step always ran immediately before this in the same test.
     */
    protected function buildViewBlock(bool $leaveOpenForEdit): string
    {
        $tpl = <<<'JS'
		// ── View ──────────────────────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 }).catch(() => {});
		await sleep(500);
		await shot(page, '04-view-modal');
__VIEW_ASSERT__
__VIEW_TEARDOWN__
JS;

        $assert = <<<'JS'

		const viewText = (await page.locator('[role="dialog"]').textContent()) || '';
		expect(viewText.trim().length, 'view modal opened but rendered nothing').toBeGreaterThan(0);
		console.log(`[${MODULE_LABEL}] view OK — modal opened for the target record`);
JS;

        if ($leaveOpenForEdit) {
            // Edit's own button lives inside this dialog -- leave it open.
            $teardown = '';
        } else {
            $teardown = <<<'JS'

		// No Edit step follows (read-only module) -- close the View dialog via
		// its own Close button (NOT Escape -- persistent: true blocks it) or
		// the next step clicks into a still-open modal overlay.
		await page.locator('[role="dialog"]').getByRole('button', { name: 'Close' }).click();
		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
JS;
        }

        return str_replace(['__VIEW_ASSERT__', '__VIEW_TEARDOWN__'], [$assert, $teardown], $tpl);
    }

    /**
     * Fills for every date field that must stay AFTER the one this edit step is moving.
     *
     * The edit step advances its anchor date by a day and, until 2026-08-25, touched nothing else.
     * When another field carries `after:{anchor}` / `after_or_equal:{anchor}` — a start/end pair,
     * which is the common case — that single move breaks the very rule this generator wrote into
     * the module's own validation, so the update 422s ("The end date field must be a date after or
     * equal to start date"), the dialog never closes, and the spec times out on a row assertion.
     * The failure reads like a product bug; it is the generated spec contradicting the generated
     * rules.
     *
     * Only `after`/`after_or_equal` dependents move. A `before:{anchor}` field is unaffected by the
     * anchor moving forward, and the anchor's own `after:` constraint on some third field likewise
     * only gets safer. The dependent must itself be a date-typed field in the edit form: a field
     * the form never renders cannot be filled, and one that isn't a date has no picker to drive.
     *
     * @return string A JS block (leading newline, tab-indented) or '' when there is nothing to move.
     */
    protected function buildDependentDateFills(string $anchorKey, int $dayOffset): string
    {
        $rulesFor = [];
        foreach (['edit', 'create'] as $op) {
            foreach (($this->config['features']['backend'][$op]['fields'] ?? []) as $backendField) {
                $name = $backendField['field'] ?? null;
                if ($name !== null && !isset($rulesFor[$name])) {
                    $rulesFor[$name] = (string) ($backendField['rules'] ?? '');
                }
            }
        }

        $lines = [];
        foreach ($this->editFields as $field) {
            $key = $field['field'] ?? null;
            if ($key === null || $key === $anchorKey) {
                continue;
            }
            if (($field['field_type'] ?? 'input') !== 'date') {
                continue;
            }

            $rules = $rulesFor[$key] ?? '';
            $dependsOnAnchor = false;
            foreach (explode('|', $rules) as $rule) {
                $rule = trim($rule);
                if ($rule === "after:{$anchorKey}" || $rule === "after_or_equal:{$anchorKey}") {
                    $dependsOnAnchor = true;
                    break;
                }
            }
            if (!$dependsOnAnchor) {
                continue;
            }

            $lines[] = "\t\t// `{$key}` is constrained to fall after `{$anchorKey}`, so it moves with it —"
                . " otherwise this step's own edit violates the module's generated validation.";
            $lines[] = "\t\tawait fillDatePickerField(page, '[role=\"dialog\"]', '{$key}', {$dayOffset});";
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    protected function buildEditBlock(): string
    {
        $field = $this->pickEditField();
        if ($field === null || empty($field['field'])) {
            return <<<'JS'
		// ── Edit ──────────────────────────────────────────────────────────────
		console.log(`[${MODULE_LABEL}] edit skipped — no editable fields declared in features.frontend.edit.fields`);
JS;
        }

        $key = (string) $field['field'];
        $fieldType = $field['field_type'] ?? 'input';
        // pickEditField() prefers a list-visible field, but still falls
        // through to a list-hidden one if that's the only scalar edit field
        // available (e.g. every scalar field was set defaultVisible: false).
        // The row.textContent.includes(editedValue) assertion below can
        // never pass for a field that isn't rendered as a <td> at all, so
        // skip it in that case and fall back to the same lenient
        // dialog-closed-only check the non-scalar branch above already uses.
        $editedFieldIsListVisible = in_array($key, $this->listVisibleFieldKeys, true);

        $openTpl = <<<'JS'
		// ── Edit (via the view modal's Edit button) ──────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const editBtn = page.locator(`[data-testid="[[moduleName]]-edit-${recordUuid}"]`);
				if ((await editBtn.count()) === 0) throw new Error(`Edit button (data-testid="[[moduleName]]-edit-${recordUuid}") not found`);
				await editBtn.click();
			},
			'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
		);
		await shot(page, '05-edit-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before editing
JS;

        if (!$this->isScalarField($field)) {
            // Non-scalar edit field (select/checkbox/file-input): reuse the
            // same fill logic as create, submit, and only assert the flow
            // completed — there's no deterministic text value to diff
            // against for these field types the way a plain input has.
            $fillLine = $this->renderFieldFill($field, '');
            $submitTpl = <<<'JS'

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
		console.log(`[${MODULE_LABEL}] edit OK — submitted an updated __KEY__ value`);
		await shot(page, '06-after-edit');
JS;
            $submit = str_replace('__KEY__', $key, $submitTpl);

            return $openTpl . "\n" . $fillLine . $submit;
        }

        $valueExpr = $this->editedFieldValueExpr($field);

        if (($field['field_type'] ?? 'input') === 'date') {
            // Same rationale as renderFieldFill()'s 'date' branch: the field
            // is a DatePickerField.vue popover trigger (<button>), which has
            // no .inputValue() to set/read back -- setInputValue() and the
            // readback check below both assume a real <input>. Day offset 1
            // ("tomorrow") is what editedFieldValueExpr()'s 'date' branch
            // computes, so the calendar click and the row-text assertion
            // stay consistent with each other. The list column renders the
            // raw Y-m-d value (no display-format transform), so the
            // row-text-includes-editedValue assertion still applies as-is.
            $dateRowCheckTpl = $editedFieldIsListVisible ? <<<'JS'

		await page.waitForFunction(
			({ uuid, value }) => {
				const btn = document.querySelector(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				const row = btn ? btn.closest('tr') : null;
				return !!row && row.textContent.includes(value);
			},
			{ uuid: recordUuid, value: editedValue },
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] edit OK — record now shows the updated __KEY__ value`);
JS
            : <<<'JS'

		console.log(`[${MODULE_LABEL}] edit OK — submitted an updated __KEY__ value (list-hidden field, not row-verified)`);
JS;

            $dateEditTpl = <<<'JS'

		const editedValue = __VALUE__;
		await fillDatePickerField(page, '[role="dialog"]', '__KEY__', 1);
__DEPENDENT_FILLS__
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
__ROW_CHECK__
		await shot(page, '06-after-edit');
JS;
            $dateEditTpl = str_replace('__ROW_CHECK__', $dateRowCheckTpl, $dateEditTpl);
            $dateEditTpl = str_replace('__DEPENDENT_FILLS__', $this->buildDependentDateFills($key, 1), $dateEditTpl);
            $fillEdit = str_replace(['__VALUE__', '__KEY__'], [$valueExpr, $key], $dateEditTpl);

            return $openTpl . $fillEdit;
        }

        $scalarRowCheckTpl = $editedFieldIsListVisible ? <<<'JS'

		await page.waitForFunction(
			({ uuid, value }) => {
				const btn = document.querySelector(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				const row = btn ? btn.closest('tr') : null;
				return !!row && row.textContent.includes(value);
			},
			{ uuid: recordUuid, value: editedValue },
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] edit OK — record now shows the updated __KEY__ value`);
JS
            : <<<'JS'

		console.log(`[${MODULE_LABEL}] edit OK — submitted an updated __KEY__ value (list-hidden field, not row-verified)`);
JS;

        $fillEditTpl = <<<'JS'

		const editedValue = __VALUE__;
		await setInputValue(page, '[role="dialog"] #__KEY__', editedValue);
		const editedActual = await page.locator('[role="dialog"] #__KEY__').inputValue();
		// String(...) + comma-stripping, not a bare `!==`: editedValue is a raw
		// JS expression that for a numeric field is a NUMBER literal (see
		// editedFieldValueExpr()'s numeric branch), while .inputValue() always
		// returns a STRING — a bare `!==` type-mismatches and fails for every
		// numeric field regardless of its actual value. Comma-stripping
		// additionally tolerates NumberInputField.vue's Cleave.js thousands-
		// separator display (see fillNumberField()'s docblock) for the same
		// 'number-input' fields. Both were confirmed live against a generated
		// ItemPrices e2e test's "price" edit field.
		const normalizeForCompare = (v) => String(v).replace(/,/g, '');
		if (normalizeForCompare(editedActual) !== normalizeForCompare(editedValue)) {
			throw new Error(`Could not set edit #__KEY__: stuck at "${editedActual}", expected "${editedValue}"`);
		}

		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);
__ROW_CHECK__
		await shot(page, '06-after-edit');
JS;
        $fillEditTpl = str_replace('__ROW_CHECK__', $scalarRowCheckTpl, $fillEditTpl);
        $fillEdit = str_replace(['__VALUE__', '__KEY__'], [$valueExpr, $key], $fillEditTpl);

        return $openTpl . $fillEdit;
    }

    /**
     * Checks the first 2 rows, clicks the first configured bulk_actions[]
     * key's toolbar button, confirms the dialog, asserts a success
     * indicator (the BatchResultDrawer testid ListTable.vue always opens
     * after a bulk action completes). Skips gracefully with fewer than 2
     * rows rather than assuming seed data — matches
     * buildFilterBlock()'s own getVisibleRowCount()-gated defensive style.
     */
    protected function buildBulkActionBlock(): string
    {
        $firstKey = (string) ($this->bulkActions[0]['key'] ?? '');
        if ($firstKey === '') {
            return '';
        }
        $keyJs = addslashes($firstKey);

        return <<<JS
		// ── Bulk action ({$keyJs}) ────────────────────────────────────────────
		{
			const rowCount = await getVisibleRowCount(page);
			if (rowCount >= 2) {
				// The row checkboxes only exist once the list's "Select" (batch mode)
				// toggle is on. aria-pressed keeps this idempotent: a list that starts
				// in batch mode (batchModeDefault) must not be toggled back off.
				const batchToggle = page.locator('[data-testid="batch-mode-toggle"]');
				if (await batchToggle.count() > 0 && (await batchToggle.getAttribute('aria-pressed')) !== 'true') {
					await batchToggle.click();
				}
				await page.locator('table tbody tr').nth(0).locator('[data-testid^="[[moduleName]]-bulk-select-"]').click();
				await page.locator('table tbody tr').nth(1).locator('[data-testid^="[[moduleName]]-bulk-select-"]').click();
				await page.locator(`[data-testid="[[moduleName]]-bulk-action-{$keyJs}"]`).click();

				// A configured confirmMessage opens the confirm dialog; without one
				// the action fires immediately (see ListTable.vue's requestBulkAction()).
				const confirmBtn = page.locator('[data-testid="[[moduleName]]-bulk-confirm"]');
				if (await confirmBtn.count() > 0 && await confirmBtn.isVisible().catch(() => false)) {
					await confirmBtn.click();
				}

				await page.locator('[data-testid="batch-result-drawer"]').waitFor({ timeout: 15000 });
				await shot(page, 'bulk-action-result');
				console.log(`[\${MODULE_LABEL}] bulk action '{$keyJs}' OK — result drawer shown`);
				await page.keyboard.press('Escape');
				// A blind sleep(500) here used to race the Sheet's close animation
				// under load: the underlying dialog-overlay can still be mid
				// fade-out past 500ms, intercepting the next click. Wait for the
				// drawer itself to actually be gone instead (see buildImportBlock()'s
				// identical fix -- confirmed live 2026-08-08 against a freshly
				// generated PurchaseOrders module: "<div data-slot="dialog-overlay">
				// intercepts pointer events" on the very next click, retried for the
				// full 15s timeout because the drawer never actually closed).
				await page.locator('[data-testid="batch-result-drawer"]').waitFor({ state: 'hidden', timeout: 15000 });
				await waitForListSettled(page);
			} else {
				console.log(`[\${MODULE_LABEL}] bulk action '{$keyJs}' skipped — fewer than 2 rows available`);
			}
		}
JS;
    }

    /**
     * Intercepts (fulfills, not continues, so it doesn't count against the
     * spec's own zero-failedRequests diagnostics gate) the export request
     * rather than actually downloading a file, and asserts the requested
     * format param — proving the button/endpoint wiring works without
     * depending on Playwright's download handling.
     */
    protected function buildExportBlock(): string
    {
        return <<<'JS'
		// ── Export ────────────────────────────────────────────────────────────
		{
			let exportUrl = null;
			await page.route('**/*/export*', async (route) => {
				exportUrl = route.request().url();
				await route.fulfill({ status: 200, contentType: 'text/csv', body: '' });
			});
			await page.locator('[data-testid="[[moduleName]]-export-open"]').click();
			await page.locator('[data-testid="[[moduleName]]-export-csv"]').click();
			await sleep(500);
			await page.unroute('**/*/export*');

			expect(exportUrl, 'export button did not trigger a request').not.toBeNull();
			expect(exportUrl, `export request missing format=csv: ${exportUrl}`).toContain('format=csv');
			console.log(`[${MODULE_LABEL}] export OK — ${exportUrl}`);
		}
JS;
    }

    /**
     * Downloads the module's own real generated template (not a hand-built
     * fixture file) via Playwright's download event, feeds that exact file
     * back into the file input, submits with dry-run checked (so nothing
     * is actually committed — a real row is not required to exist), and
     * asserts the result drawer confirms the upload was processed.
     */
    protected function buildImportBlock(): string
    {
        return <<<'JS'
		// ── Import ───────────────────────────────────────────────────────────
		{
			const [download] = await Promise.all([
				page.waitForEvent('download'),
				page.locator('[data-testid="[[moduleName]]-import-open"]').click().then(() =>
					page.locator('[data-testid="[[moduleName]]-import-template-csv"]').click()
				),
			]);
			// download.path() returns Playwright's internal temp file, which
			// carries no filename extension. FileInputField (the shared
			// import-modal file field, generic across every module) applies a
			// client-side accept-type check against the File object's name, so
			// re-save under the suggested filename -- which does carry the
			// real .csv extension -- before feeding it back into the input.
			const suggestedPath = path.join(path.dirname(await download.path()), download.suggestedFilename());
			await download.saveAs(suggestedPath);
			expect(fs.existsSync(suggestedPath), 'import template download did not produce a file').toBe(true);
			const templatePath = suggestedPath;

			await page.locator('[data-testid="[[moduleName]]-import-file"]').setInputFiles(templatePath);
			await page.locator('[data-testid="[[moduleName]]-import-dry-run"]').check();
			await page.locator('[data-testid="[[moduleName]]-import-submit"]').click();

			await page.locator('[data-testid="batch-result-drawer"]').waitFor({ timeout: 15000 });
			await shot(page, 'import-result');
			console.log(`[${MODULE_LABEL}] import OK — result drawer shown after dry-run upload`);
			await page.keyboard.press('Escape');
			// Bug (found + fixed 2026-08-08): a blind sleep(500) here raced the
			// Sheet's close animation under load -- confirmed live against a
			// freshly generated PurchaseOrders module: the very next click (view,
			// ahead of Delete) retried for the full 15s timeout against
			// "<div data-slot=\"dialog-overlay\"> intercepts pointer events"
			// because the drawer had not actually finished closing. Wait for the
			// drawer itself to be gone instead of guessing a fixed delay.
			await page.locator('[data-testid="batch-result-drawer"]').waitFor({ state: 'hidden', timeout: 15000 });
		}
JS;
    }

    protected function buildDeleteBlock(): string
    {
        // Unconditional now: writeDeleteSpecFile() is this method's ONLY
        // caller (a standalone {module}-delete.e2e.js spec), and nothing
        // earlier in that file has opened the view modal yet the way the
        // old single-file spec's preceding View/Edit steps did.
        $reopen = <<<'JS'
		// Open the view modal — Delete lives behind its "More Actions" menu.
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button (data-testid="[[moduleName]]-view-${recordUuid}") not found before delete`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

JS;

        $body = <<<'JS'
		// ── Delete (via the view modal's "More Actions" -> Delete) ───────────
		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${recordUuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await shot(page, '07-delete-modal');
		await sleep(500); // let the dialog's focus-trap/animation settle before typing

		// Wrong confirmation text must NOT allow the delete to proceed: the
		// confirm button is only ever enabled when confirmText === 'YES'
		// exactly (see [[ModuleName]]DeleteForm.vue's `isConfirmValid`
		// computed, which drives `:disabled="!isConfirmValid || isDeleting"`
		// on the confirm button) — no click is even attempted here, since
		// Playwright's own actionability check would fail on a genuinely
		// disabled element; asserting the disabled state is the direct,
		// non-flaky way to prove the wrong text is rejected.
		await fillField(page, '[role="dialog"] #confirm', 'nope');
		await expect(page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]')).toBeDisabled();
		console.log(`[${MODULE_LABEL}] delete OK — wrong confirmation text keeps the confirm button disabled`);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		expect(await page.locator(`[data-testid="[[moduleName]]-view-${recordUuid}"]`).count(), 'Row still present after delete').toBe(0);
		console.log(`[${MODULE_LABEL}] delete OK — record gone`);
		await shot(page, '08-after-delete');
JS;

        // Soft-deleted rows use Eloquent's default global scope, which
        // excludes deleted_at-not-null rows automatically — only meaningful
        // to re-assert for modules that actually have that column (see
        // ModuleConfigContract::hasSoftDeletes(), THE single sanctioned
        // place to read this derived fact, rather than re-deriving it here).
        // No createdRowText-based re-check the old single-file spec had
        // alongside the testid one: this file's record comes from
        // _fixtures.js's createFixtureRecord(), which returns only a uuid —
        // the testid-based assertion above is the only one available here,
        // and is sufficient on its own (a testid disappearing IS the row
        // disappearing, for this generator's own markup).
        if (ModuleConfigContract::hasSoftDeletes($this->config)) {
            $body .= <<<'JS'

		console.log(`[${MODULE_LABEL}] soft-delete OK — record no longer appears in the list`);
JS;
        }

        return $reopen . $body;
    }

    // ------------------------------------------------------------------
    // _fixtures.js body builders
    // ------------------------------------------------------------------

    /**
     * Helper functions _fixtures.js needs, scoped to the specific field set
     * it fills (createFixtureRecord() only ever fills $this->createFields —
     * never edit fields) rather than reusing buildHelperFunctions()'s
     * whole-module gating, which would pull in edit-only helpers this file
     * never calls.
     */
    protected function buildFixtureHelperFunctions(): string
    {
        $blocks = [];

        if ($this->hasCreate) {
            $blocks[] = $this->fillFieldHelpersBlock();

            $hasRequiredSelect = false;
            $hasOptionalSelect = false;
            $hasNumberInput = false;
            $hasMorphSelect = false;
            $hasDateField = false;
            foreach ($this->createFields as $field) {
                $fieldType = $field['field_type'] ?? 'input';
                if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
                    if (!empty($field['required'])) {
                        $hasRequiredSelect = true;
                    } else {
                        $hasOptionalSelect = true;
                    }
                }
                if ($fieldType === 'number-input') {
                    $hasNumberInput = true;
                }
                if ($fieldType === 'morph-select') {
                    $hasMorphSelect = true;
                }
                if ($fieldType === 'date') {
                    $hasDateField = true;
                }
            }

            if ($hasRequiredSelect) {
                $blocks[] = $this->selectFieldHelperBlock();
            }
            if ($hasOptionalSelect) {
                $blocks[] = $this->tryFillSelectFieldHelperBlock();
            }
            if ($hasNumberInput) {
                $blocks[] = $this->numberFieldHelperBlock();
            }
            if ($hasMorphSelect) {
                $blocks[] = $this->morphSelectFieldHelperBlock();
            }
            if ($hasDateField) {
                // buildFixtureHelperFunctions() previously omitted this
                // entirely -- createFixtureRecord()'s own body (built
                // elsewhere, see the 'date' branch a few hundred lines up)
                // always emits a fillDatePickerField() call for any 'date'
                // create field, but nothing ever added the helper's own
                // definition to this file. Confirmed live: any module whose
                // create form has a date/datetime field threw
                // "ReferenceError: fillDatePickerField is not defined" from
                // every delegation/action spec that shares this _fixtures.js
                // (the CRUD spec itself was unaffected -- it gets its helper
                // blocks from buildHelperFunctions(), a separate method that
                // already included dateFieldHelperBlock() correctly).
                $blocks[] = $this->dateFieldHelperBlock();
            }
        } elseif ($this->hasDelete) {
            // cleanupRecord() still needs fillField() for the #confirm input
            // even when there's no create step to need it for.
            $blocks[] = $this->fillFieldHelpersBlock();
        }

        $blocks[] = $this->rowHelpersBlock();

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    /**
     * createFixtureRecord()'s body. Mirrors buildCreateBlock()'s happy path
     * only (see this class's docblock and fixtures.e2e.stub's own docblock
     * for why the two validation-negative sub-steps are deliberately
     * excluded here) and THROWS instead of logging+continuing when it can't
     * capture a uuid afterwards.
     *
     * When the module has no create feature at all, falls back to grabbing
     * whatever the first existing list row already is — mirrors
     * buildTargetRowBlock()'s same fallback in the CRUD file — since there's
     * no UI path to create one.
     */
    protected function buildFixtureCreateBody(): string
    {
        if (!$this->hasCreate) {
            return $this->buildFixturePickExistingRowBody();
        }

        $declBlock = $this->dedentBlock($this->buildFieldDeclarationsBlock($this->createFields));
        $createWizard = $this->createWizard();
        if ($createWizard !== null) {
            $fillLinesNonFile = $this->buildWizardFillLines(
                $this->createFields,
                $createWizard['steps'],
                $createWizard['confirm'],
                'createValues',
                "\t\tawait page.locator('[role=\"dialog\"] [data-testid=\"[[moduleName]]-wizard-next\"]').click();"
            );
            $fillLinesFileOnly = [];
        } else {
            [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($this->createFields);
        }
        $fillBlock = $this->dedentBlock(implode("\n", $fillLinesNonFile));
        $fileFillBlock = !empty($fillLinesFileOnly) ? "\n" . $this->dedentBlock(implode("\n", $fillLinesFileOnly)) : '';

        $open = <<<'JS'
	await clickAndWaitForSelector(
		page,
		() => page.locator('[data-testid="[[moduleName]]-create"]').click(),
		'[role="dialog"] [data-testid="[[moduleName]]-submit"]',
	);
	await sleep(500); // let the dialog's focus-trap/animation settle before typing
JS;
        $open = str_replace('-submit"]', '-' . $this->createOpenReadyTestId() . '"]', $open);

        // Same "create -> view by default" behavior buildCreateBlock() accounts for
        // (onCreated(), generator-engine v3.2.2) applies here too -- this is a SEPARATE
        // generated function from buildCreateBlock()'s CRUD-test create step, so it needs
        // its own identical fix, not inherited from that one. Uses the dialog's real Close
        // button, not Escape -- v3.4.1 shipped an Escape-based fix for buildCreateBlock()
        // that reproducibly hung 15s on every module in a live run, since AppDialog.vue's
        // `persistent` prop defaults true and the View dialog usage never overrides it;
        // v3.4.2 corrected that to a Close-button click, applied here from the start.
        if ($this->hasView) {
            $submit = <<<'JS'

	await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();

	// Create succeeded -> onCreated() swaps the Create dialog for a View dialog of the
	// new record; a dialog is expected to still be present throughout, never absent.
	// (No expect() here -- unlike the per-module crud.e2e.js spec, this shared fixtures
	// file imports nothing from @playwright/test; a plain throw matches this function's
	// own established convention, e.g. the uuid-capture check just below.)
	await page.waitForFunction(() => !document.querySelector('[role="dialog"] svg.animate-spin'), { timeout: 15000 });
	await sleep(300);
	if ((await page.locator('[role="dialog"]').count()) === 0) {
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: expected the View dialog to open automatically after a successful create`);
	}
	await page.locator('[role="dialog"]').getByRole('button', { name: 'Close' }).click();
	await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
	await waitForListSettled(page);
JS;
        } else {
            $submit = <<<'JS'

	await page.locator('[role="dialog"] [data-testid="[[moduleName]]-submit"]').click();
	await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
	await waitForListSettled(page);
JS;
        }

        $anchor = $this->pickAnchorField();
        // Armed for BOTH branches -- not just to source the uuid when there's no
        // anchor field, but so a failed capture in EITHER branch can tell "the
        // create request itself failed validation" apart from "it succeeded but
        // the uuid couldn't be read back". A bare "could not capture a uuid
        // after create" fires identically for both and sends debugging into the
        // wrong file when the real cause is e.g. a 422 on a unique index --
        // found live, this cost real time chasing a DOM-capture bug that didn't
        // exist.
        $responseArm = <<<'JS'

	const createResponsePromise = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith('/create'));
JS;
        if ($anchor !== null) {
            $targetRowExpr = "rowLocator(page, String(createValues.{$anchor})).first()";
            $captureTpl = <<<'JS'

	const recordTestId = await (__TARGET_ROW__)
		.locator('[data-testid^="[[moduleName]]-view-"]')
		.first()
		.getAttribute('data-testid')
		.catch(() => null);
	const recordUuid = uuidFromTestId(recordTestId, 'view');
	if (!recordUuid) {
		const createResponse = await createResponsePromise;
		if (!createResponse.ok()) {
			const body = await createResponse.json().catch(() => null);
			throw new Error(`[${MODULE_LABEL}] createFixtureRecord: create request failed: HTTP ${createResponse.status()} ${JSON.stringify(body)}`);
		}
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: create succeeded (HTTP ${createResponse.status()}) but no matching row/uuid was found afterwards`);
	}
	return { uuid: recordUuid };
JS;
            $capture = str_replace('__TARGET_ROW__', $targetRowExpr, $captureTpl);
        } else {
            // No anchor field -- DOM/sort position is not a safe way to find "the row
            // just created" (see buildCreateBlock()'s identical fix for why). Read the
            // record key straight from the create response instead of round-tripping
            // through the DOM at all.
            //
            // shelui-engine fork: reads `.data.{$this->idParam}` ('uuid' or 'id',
            // resolved once in the constructor -- see $idParam's docblock) instead of
            // the literal `.data.uuid`, which a has_uuid: false module's create
            // response never has. The returned shape stays `{ uuid: recordUuid }`
            // regardless -- every caller (split.e2e.stub's
            // `const { uuid: recordUuid } = await createFixtureRecord(page)`)
            // destructures that same literal key; only the VALUE read off the real
            // API response needed to change.
            $capture = <<<'JS'

	const createResponse = await createResponsePromise;
	if (!createResponse.ok()) {
		const body = await createResponse.json().catch(() => null);
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: create request failed: HTTP ${createResponse.status()} ${JSON.stringify(body)}`);
	}
	const recordUuid = (await createResponse.json())?.data?.__ID_PARAM__ ?? null;
	if (!recordUuid) {
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: create succeeded (HTTP ${createResponse.status()}) but no __ID_PARAM__ was found in the response`);
	}
	return { uuid: recordUuid };
JS;
            $capture = str_replace('__ID_PARAM__', $this->idParam, $capture);
        }

        [$submit, $responseArm] = $this->applyUniqueOptionRetry($submit, $responseArm, "\t");

        return $open . $declBlock . "\n\n" . $fillBlock . $fileFillBlock . $responseArm . $submit . $capture;
    }

    protected function buildFixturePickExistingRowBody(): string
    {
        return <<<'JS'
	const targetRow = page.locator('table tbody tr').first();
	const recordTestId = await targetRow
		.locator('[data-testid^="[[moduleName]]-view-"]')
		.first()
		.getAttribute('data-testid')
		.catch(() => null);
	const recordUuid = uuidFromTestId(recordTestId, 'view');
	if (!recordUuid) {
		throw new Error(`[${MODULE_LABEL}] createFixtureRecord: no existing record available to use as a fixture (module has no create feature and the list is empty)`);
	}
	return { uuid: recordUuid };
JS;
    }

    /**
     * cleanupRecord()'s body. Mirrors cleanupHelperBlock()'s
     * cleanupStrayRecord() logic (view -> More Actions -> Delete -> confirm
     * YES), but unconditional rather than failure-only — every
     * delegation/action spec calls this in its own `finally`, since (unlike
     * the CRUD spec) nothing else in those files ever deletes the fixture.
     *
     * A no-op, logged, when the module has no delete feature at all — the
     * same accepted "create without delete isn't self-cleaning" limitation
     * the CRUD spec already has (see this class's docblock).
     */
    protected function buildFixtureCleanupBody(): string
    {
        if (!$this->hasDelete) {
            return <<<'JS'
	// No delete feature on this module — nothing this fixture can clean up
	// via the UI. Matches [[moduleRoute]]-crud.e2e.js's own accepted
	// limitation: a create-without-delete module isn't self-cleaning either.
	console.log(`[${MODULE_LABEL}] cleanupRecord: module has no delete feature — leaving uuid ${uuid} as-is`);
JS;
        }

        return <<<'JS'
	try {
		if ((await page.locator('[role="dialog"]').count()) > 0) {
			await page.keyboard.press('Escape').catch(() => {});
			await sleep(500);
		}

		await page.goto(`${BASE_URL}/[[moduleRoute]]/list?per_page=100&sort=id&order=desc`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});
		await waitForListSettled(page);

		const stillPresent = (await page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`).count().catch(() => 0)) > 0;
		if (!stillPresent) {
			console.log(`[${MODULE_LABEL}] cleanupRecord: uuid ${uuid} no longer in the list — nothing to do`);
			return;
		}

		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-${uuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`cleanup view button (data-testid="[[moduleName]]-view-${uuid}") not found`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
		await page.locator(`[data-testid="[[moduleName]]-delete-${uuid}"]`).click();
		await page.locator('[role="dialog"] #confirm').waitFor({ timeout: 10000 });
		await sleep(500);

		await fillField(page, '[role="dialog"] #confirm', 'YES');
		await page.locator('[role="dialog"] [data-testid="[[moduleName]]-confirm-delete"]').click();

		await page.waitForFunction(() => !document.querySelector('[role="dialog"]'), { timeout: 15000 });
		await waitForListSettled(page);

		console.log(`[${MODULE_LABEL}] cleanupRecord: deleted fixture record (uuid ${uuid})`);
	} catch (cleanupErr) {
		console.log(
			`[${MODULE_LABEL}] WARNING: cleanupRecord failed for uuid ${uuid} — manual removal may be required:`,
			cleanupErr && cleanupErr.stack ? cleanupErr.stack : cleanupErr,
		);
	}
JS;
    }

    // ------------------------------------------------------------------
    // Split-spec (delegation/action) file rendering
    // ------------------------------------------------------------------

    /**
     * Fills tests/split.e2e.stub for one delegation or action spec.
     * $fieldsForHelpers scopes which of the fillField/select/number helpers
     * get emitted — empty for tab-delegation and action specs (neither fills
     * any field; see buildActionSpecBody()'s and buildTabDelegationSpecBody()'s
     * docblocks), the delegation's own create fields for a modal-type
     * delegation that fills them.
     */
    /**
     * $fieldsForHelpers: an array scopes the helper-function set to just
     * those fields (delegation/action specs — each fills only its own
     * feature's fields, via splitSpecHelperFunctionsFor()); null means "this
     * spec exercises the module's own native create/edit fields", so it
     * needs the full buildHelperFunctions() gating instead (the list/create/
     * view/edit/delete split — see writeSurfaceSpecFile()).
     */
    protected function renderSplitSpec(
        string $fileSlug,
        string $specKind,
        string $specLabel,
        string $testDescription,
        string $testBody,
        ?array $fieldsForHelpers
    ): string {
        $stub = $this->getTemplateContent('tests/split.e2e', 'frontend');
        $moduleRoute = Str::kebab($this->moduleName);

        $helperFunctions = $fieldsForHelpers === null
            ? $this->buildHelperFunctions()
            : $this->splitSpecHelperFunctionsFor($fieldsForHelpers);

        $content = str_replace(
            ['[[helperFunctions]]', '[[testBody]]', '[[testDescription]]', '[[specFileName]]', '[[specKind]]', '[[specLabel]]', '[[specSlug]]'],
            [
                $helperFunctions,
                $testBody,
                addcslashes($testDescription, "'\\"),
                "{$moduleRoute}-{$fileSlug}.e2e.js",
                $specKind,
                addcslashes($specLabel, "'\\"),
                $fileSlug,
            ],
            $stub
        );

        return $this->replacePlaceholders($content);
    }

    protected function splitSpecHelperFunctionsFor(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $blocks = [$this->fillFieldHelpersBlock()];

        $hasRequiredSelect = false;
        $hasOptionalSelect = false;
        $hasNumberInput = false;
        $hasMorphSelect = false;
        $hasDateField = false;
        foreach ($fields as $field) {
            $fieldType = $field['field_type'] ?? 'input';
            if (in_array($fieldType, self::SELECT_FIELD_TYPES, true)) {
                if (!empty($field['required'])) {
                    $hasRequiredSelect = true;
                } else {
                    $hasOptionalSelect = true;
                }
            }
            // 'number-input' is the schema-derived alias (delegation create fields, which come
            // from IntrospectionToConfig::build() same as any Create/Edit field); 'number' is the
            // raw config value an action field carries directly, hand-authored, never aliased.
            if ($fieldType === 'number-input' || $fieldType === 'number') {
                $hasNumberInput = true;
            }
            if ($fieldType === 'morph-select') {
                $hasMorphSelect = true;
            }
            if ($fieldType === 'date') {
                $hasDateField = true;
            }
        }

        if ($hasRequiredSelect) {
            $blocks[] = $this->selectFieldHelperBlock();
        }
        if ($hasOptionalSelect) {
            $blocks[] = $this->tryFillSelectFieldHelperBlock();
        }
        if ($hasNumberInput) {
            $blocks[] = $this->numberFieldHelperBlock();
        }
        if ($hasMorphSelect) {
            $blocks[] = $this->morphSelectFieldHelperBlock();
        }
        if ($hasDateField) {
            // A pre-existing gap, not something the v3.5.9/v3.5.11 number-field saga introduced:
            // this method never tracked 'date' fields at all, for delegations or actions alike --
            // renderFieldFill() already correctly dispatches to fillDatePickerField() for a
            // 'date' field_type, but nothing here ever emitted that helper's own definition into
            // a split action/delegation spec, so the call always threw a ReferenceError the
            // moment any such spec's field list included one. Confirmed live building Pangisha's
            // 09.01 Activate (2026-08-26) -- the wizard's own `paid_at` field is exactly this case.
            $blocks[] = $this->dateFieldHelperBlock();
        }

        return implode("\n\n", array_filter($blocks, fn ($b) => trim($b) !== ''));
    }

    /**
     * A 'tab'/'tab-action' delegation's spec body — the exact per-key
     * coverage buildDelegationBlock() used to emit inline in the CRUD file
     * (nav to the tab route, assert the nav link resolves, assert the child
     * table renders real headers, probe the child create form), now
     * standalone: $recordUuid always exists (createFixtureRecord() throws
     * otherwise, so no `if (recordUuid)` guard is needed), and there's no
     * "return to list" step at the end since no further CRUD steps follow it
     * in this file — cleanupRecord() navigates wherever it needs to itself.
     *
     * Fills no fields — the child-create probe only needs to know whether
     * the create request fired and what FK value it carried, not what the
     * child form's own fields are.
     */
    protected function buildTabDelegationSpecBody(string $delegationKey, array $delegation, string $label): string
    {
        $tabId = Str::kebab($delegation['name'] ?? $delegationKey);
        $escapedLabel = addslashes($label);
        $filterKey = $delegation['filterKey'] ?? 'parent_id';

        return <<<JS
		// ── Delegation (tab): {$escapedLabel} ─────────────────────────────────
		await page.goto(`\${BASE_URL}/[[moduleRoute]]/\${recordUuid}/details/{$tabId}`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});

		// The tab must be reachable from the nav, not just by URL: assert the
		// generated link resolves to the route that actually exists.
		const {$this->jsIdent($tabId)}Tab = page.locator('[data-testid="[[moduleName]]-tab-{$tabId}"]');
		await expect({$this->jsIdent($tabId)}Tab, 'delegation tab "{$escapedLabel}" missing from the details nav').toHaveCount(1);
		expect(
			await {$this->jsIdent($tabId)}Tab.getAttribute('href'),
			'delegation tab "{$escapedLabel}" links to a path the router does not register'
		).toContain('/details/{$tabId}');

		// The child list renders inside the tab's router-view. Waiting for a
		// `table` alone is not enough: a delegation tab with no columns
		// renders a table shell with no header and no rows while the fetch
		// returns 200 — it looks like missing data and is not. Assert the
		// header row, and that no label leaked as a raw i18n key.
		const childTable = page.locator('table').first();
		await childTable.waitFor({ timeout: 15000 });
		const childHeaders = (await childTable.locator('thead th').allTextContents())
			.map((h) => h.trim())
			.filter(Boolean);

		expect(
			childHeaders.length,
			'delegation "{$escapedLabel}" rendered a table with no column headers — the tab generated an empty columns array'
		).toBeGreaterThan(0);
		expect(
			childHeaders.filter((h) => /^[a-z0-9-]+\.[a-z0-9_]+\$/.test(h)),
			'delegation "{$escapedLabel}" column headers are raw i18n keys — the label namespace does not resolve'
		).toEqual([]);

		await shot(page, 'delegation-{$tabId}');
		console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" tab rendered — headers: \${childHeaders.join(', ')}`);

		// ── Child CREATE ────────────────────────────────────────────────
		// Only the child LIST was ever exercised, so the create path could
		// break silently. Opening the form and submitting is what catches it.
		const addChild = page.getByRole('button', { name: /^Add / }).first();
		if ((await addChild.count()) > 0) {
			// The create request is intercepted and answered with a synthetic
			// success rather than allowed through — a real child row here
			// would break this module's OWN cleanupRecord() delete via
			// "Cannot delete — active relationships found". It is FULFILLED,
			// not aborted: aborting raises a `requestfailed` event, which
			// this spec's own diagnostics gate treats as a failure. Either
			// way the payload is captured, which is the point.
			let submitted = null;
			await page.route('**/{$tabId}/create', async (route) => {
				submitted = route.request().postData() ?? '';
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ status: true, code: 200, message: 'intercepted by e2e', data: {} }),
				});
			});

			await addChild.click();
			await page.locator('[role="dialog"]').last().waitFor({ timeout: 10000 });
			await sleep(1000);
			await shot(page, 'delegation-{$tabId}-create-form');

			const submit = page.locator('[role="dialog"]').last().getByRole('button', { name: /^(Create|Save|Submit)/ }).first();
			if ((await submit.count()) > 0) {
				await submit.click();
				await sleep(1500);
			}
			await page.unroute('**/{$tabId}/create');

			if (submitted !== null) {
				// The FK must carry the parent's integer id, not its uuid.
				// Forms post multipart/form-data (they may carry an upload), so
				// the FK appears as a Content-Disposition part, not JSON. Both
				// shapes are accepted so this keeps working if that changes.
				const jsonMatch = submitted.match(/"{$filterKey}"\\s*:\\s*"?([^",}]*)"?/);
				const formMatch = submitted.match(/name="{$filterKey}"[\\s\\S]*?\\r?\\n\\r?\\n([^\\r\\n]*)/);
				const fkValue = (jsonMatch?.[1] ?? formMatch?.[1] ?? '').trim();

				expect(
					fkValue !== '',
					`delegation "{$escapedLabel}" create sent no {$filterKey} at all — the parent FK default is missing`
				).toBeTruthy();
				expect(
					/^\\d+\$/.test(fkValue),
					`delegation "{$escapedLabel}" create sent {$filterKey}=\${fkValue} — expected the parent's integer id, not its uuid`
				).toBeTruthy();
				console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" create sends {$filterKey}=\${fkValue} (parent id)`);
			} else {
				console.log(`[\${MODULE_LABEL}] WARNING: delegation "{$escapedLabel}" create was never submitted — FK payload unverified`);
			}

			// Leave nothing open: a lingering dialog swallows the clicks of
			// cleanupRecord()'s own view/delete steps after this test body.
			// Close button, not Escape -- every AppDialog instance defaults
			// `persistent: true` with no override, so Escape is captured and
			// preventDefault()'d and can never actually close any of them
			// (confirmed live 2026-08-20).
			for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
				await page.locator('[role="dialog"]').last().getByRole('button', { name: 'Close' }).click().catch(() => {});
				await sleep(700);
			}
		} else {
			console.log(`[\${MODULE_LABEL}] delegation "{$escapedLabel}" has no create action enabled — skipping child create`);
		}
JS;
    }

    /**
     * A 'modal' (header-action) delegation's spec body — net-new coverage,
     * none existed before this refactor (buildDelegationBlock() only ever
     * handled 'tab'/'tab-action'). The trigger only renders on the parent's
     * standalone details PAGE (ViewLayoutGenerator::generateHeaderActions()),
     * never inside the list-row view modal, so this navigates straight
     * there rather than opening the view modal first.
     *
     * The trigger button has no data-testid (unlike an Action's — see
     * ViewModalGenerator::renderMainButton()/renderMoreItem()) — located by
     * its visible label instead, the same fallback this generator already
     * uses elsewhere for untestid'd buttons (e.g. the tab-delegation child
     * "Add " button and "More Actions" above).
     *
     * When the delegation's create operation is enabled and declares create
     * fields, fills and submits them (happy path only) as a real create
     * probe; otherwise this is a shallower open-and-assert-rendered smoke
     * test, matching this generator's already-established "action files are
     * shallower than delegation files" precedent for surfaces with no
     * fields to introspect.
     */
    protected function buildModalDelegationSpecBody(string $delegationKey, array $delegation, string $label): string
    {
        $kebab = Str::kebab($delegation['name'] ?? $delegationKey);
        $escapedLabel = addcslashes($label, "'\\");

        $createOp = $delegation['operations']['create'] ?? [];
        $createFields = !empty($createOp['enabled']) ? ($createOp['frontend']['fields'] ?? []) : [];

        if (!empty($createFields)) {
            $declBlock = $this->buildFieldDeclarationsBlock($createFields, 'delegationValues');
            [$fillLinesNonFile, $fillLinesFileOnly] = $this->buildFieldFillLines($createFields, 'delegationValues');
            $fillBlock = implode("\n", $fillLinesNonFile);
            $fileFillBlock = !empty($fillLinesFileOnly) ? "\n" . implode("\n", $fillLinesFileOnly) : '';

            $probeTpl = <<<'JS'

		const delegationSubmit = delegationDialog.locator('button[type="submit"]');
		if ((await delegationSubmit.count()) > 0) {
			await delegationSubmit.click();
			await page
				.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length <= n, beforeDialogCount, { timeout: 15000 })
				.catch(() => {});
			console.log(`[${MODULE_LABEL}] delegation "__LABEL__" create submitted`);
		} else {
			console.log(`[${MODULE_LABEL}] delegation "__LABEL__" has no visible submit control — skipping create probe`);
		}
JS;
            $fillSection = $declBlock . "\n\n" . $fillBlock . $fileFillBlock . str_replace('__LABEL__', $escapedLabel, $probeTpl);
        } else {
            $fillSection = str_replace(
                '__LABEL__',
                $escapedLabel,
                <<<'JS'
		console.log(`[${MODULE_LABEL}] delegation "__LABEL__" has no create operation enabled — smoke test only opened and rendered the modal`);
JS
            );
        }

        $tpl = <<<'JS'
		// ── Delegation (modal): __LABEL__ ─────────────────────────────────────
		await page.goto(`${BASE_URL}/[[moduleRoute]]/${recordUuid}/details`, {
			waitUntil: 'networkidle',
			timeout: 30000,
		});
		await sleep(800);

		const beforeDialogCount = await page.locator('[role="dialog"]').count();
		const delegationTrigger = page.getByRole('button', { name: '__LABEL__' });
		await expect(delegationTrigger, 'delegation trigger button "__LABEL__" not found on the details page').toHaveCount(1);
		await delegationTrigger.click();
		await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeDialogCount, { timeout: 8000 });
		await sleep(500);

		const delegationDialog = page.locator('[role="dialog"]').last();
		expect(
			(await delegationDialog.innerText()).trim().length,
			'delegation modal "__LABEL__" opened but rendered nothing',
		).toBeGreaterThan(0);
		await shot(page, 'delegation-__KEBAB__-modal');
		console.log(`[${MODULE_LABEL}] delegation "__LABEL__" modal opened`);
__FILL__

		// Leave nothing open before cleanupRecord() runs. Close button, not
		// Escape -- every AppDialog instance defaults `persistent: true` with
		// no override, so Escape is captured and preventDefault()'d and can
		// never actually close any of them (confirmed live 2026-08-20).
		for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > beforeDialogCount; i++) {
			await page.locator('[role="dialog"]').last().getByRole('button', { name: 'Close' }).click().catch(() => {});
			await sleep(700);
		}
JS;

        return str_replace(['__LABEL__', '__KEBAB__', '__FILL__'], [$escapedLabel, $kebab, "\n" . $fillSection], $tpl);
    }

    /**
     * An action's spec body — net-new coverage, PHPUnit's contract tests
     * aside this had none before (only the first enabled action ever got
     * PHPUnit coverage, and Playwright had none at all). Deliberately
     * shallower than a delegation spec: ActionComponentGenerator's
     * features/action/form.stub has no field placeholders to introspect (see
     * that stub — "Add your form fields here" is left for a human to fill
     * in), so this can only mechanically assert trigger-exists -> click ->
     * submit -> success, locating Submit as "the form's last button" (Cancel
     * is always first in the generated stub) since the button's own label is
     * a runtime-computed loading-state string, not a stable literal.
     *
     * $action['placement'] (default 'more', mirroring ActionConfigNormalizer)
     * decides whether the trigger sits in the view modal's primary row or
     * behind "More Actions" — same placement logic ViewModalGenerator uses
     * to decide where to render it.
     */
    protected function buildActionSpecBody(string $actionKey, array $action, string $label): string
    {
        // Fill the action's OWN input fields before submitting -- until 2026-08-25 this method
        // never referenced $action['fields'] at all, so any action with a required field got a
        // generated smoke test that submits an empty form forever. handleSubmit() only closes the
        // dialog on a 2xx response (the very success signal this test relies on), so a required
        // field left blank makes the submit 422 and the test time out waiting for a dialog that was
        // never going to close.
        //
        // Confirmed live: two Phase-2 action stubs (PaymentsVoidPaymentService,
        // InstallmentsWaiveService) returned a trivial, unconditional success with no validation at
        // all, so their generated smoke tests passed despite this gap -- the moment real validation
        // was added, both timed out. The bug was there the whole time; the stub's own leniency
        // masked it.
        $actionFields = $action['fields'] ?? [];
        $actionFieldsBlock = $this->buildFieldDeclarationsBlock($actionFields, 'actionValues');

        $wizardConfig = $action['wizard'] ?? [];
        $isWizardAction = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);

        if ($isWizardAction) {
            $actionFillLines = $this->buildWizardActionFillLines($actionFields, $wizardConfig, $action['confirm_step'] ?? []);
        } else {
            [$actionFillLines, $actionFileFillLines] = $this->buildFieldFillLines($actionFields, 'actionValues');
            // file-input fields have no plain-fillable value and are rare on an action; fold them in
            // alongside the rest rather than carrying a second isolation pass create/edit need for their
            // own reasons.
            $actionFillLines = array_merge($actionFillLines, $actionFileFillLines);
        }
        $actionFillBlock = $actionFillLines === [] ? '' : "\n" . implode("\n", $actionFillLines);

        $kebab = Str::kebab($action['name'] ?? $actionKey);
        $escapedLabel = addslashes($label);
        $uiType = $action['uiType'] ?? 'modal';
        $isMain = ($action['placement'] ?? 'more') === 'main';

        $openMore = $isMain ? '' : <<<'JS'

		await page.locator('[role="dialog"]').getByRole('button', { name: 'More Actions' }).click();
JS;

        $open = <<<JS
		// ── Action: {$escapedLabel} ────────────────────────────────────────────
		await clickAndWaitForSelector(
			page,
			async () => {
				const viewBtn = page.locator(`[data-testid="[[moduleName]]-view-\${recordUuid}"]`);
				if ((await viewBtn.count()) === 0) throw new Error(`View button not found ahead of action "{$escapedLabel}"`);
				await viewBtn.click();
			},
			'[role="dialog"]',
		);
		await sleep(500);
{$openMore}
		const actionTrigger = page.locator(`[data-testid="[[moduleName]]-action-{$kebab}-\${recordUuid}"]`);
		await expect(actionTrigger, 'action trigger "{$escapedLabel}" not found').toHaveCount(1);
JS;

        if ($uiType === 'page') {
            $tpl = <<<'JS'

		await actionTrigger.click();
		await page.waitForFunction(() => !!document.querySelector('form button[type="button"]'), { timeout: 15000 });
		await shot(page, 'action-__KEBAB__-form');
__ACTION_FILL__
		const formButtons = page.locator('form button');
		await expect(formButtons, 'action "__LABEL__" form rendered no buttons at all — expected at least Cancel + Submit').not.toHaveCount(0);
		const submitBtn = formButtons.last();
		await submitBtn.click();

		// A successful submit navigates away from the action's own route —
		// a failed one (toast.error, form stays put) is a real, unmasked
		// failure here, not swallowed, since actions are the newest surface
		// and least likely to already have a hand-tuned happy-path fixture.
		await page.waitForFunction(
			(kebab) => !window.location.pathname.includes(`/${kebab}`),
			'__KEBAB__',
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] action "__LABEL__" submitted and navigated away — treated as success`);
JS;
        } else {
            $tpl = <<<'JS'

		const beforeActionDialogCount = await page.locator('[role="dialog"]').count();
		await actionTrigger.click();
		await page.waitForFunction((n) => document.querySelectorAll('[role="dialog"]').length > n, beforeActionDialogCount, { timeout: 8000 });
		await sleep(500);
		await shot(page, 'action-__KEBAB__-form');
__ACTION_FILL__
		const actionDialog = page.locator('[role="dialog"]').last();
		const formButtons = actionDialog.locator('button');
		await expect(formButtons, 'action "__LABEL__" modal rendered no buttons at all — expected at least Cancel + Submit').not.toHaveCount(0);
		// The submit button's accessible name is always exactly the action's
		// own label (see features/action/form.stub's `{{ isSubmitting ?
		// 'Processing…' : '[[ActionLabel]]' }}`) -- formButtons.last() is NOT
		// reliable here: AppDialog renders its own chrome "Close" button
		// inside the same dialog, DOM-ordered after the form's Cancel/Submit
		// pair, so .last() silently resolves to Close instead of Submit.
		// Confirmed live (2026-08-21): this made the generated test's own
		// "dialog closed" success signal pass without the action's real
		// endpoint ever being called.
		const submitBtn = actionDialog.getByRole('button', { name: '__LABEL__', exact: true });
		await expect(submitBtn, 'action "__LABEL__" submit button (matched by its own label) not found in the modal').toHaveCount(1);
		await submitBtn.click();

		// handleSubmit() only closes the dialog on a successful response —
		// see features/action/form.stub — so the dialog closing IS the
		// success signal, same convention buildEditBlock()/buildCreateBlock()
		// already rely on for the CRUD spec.
		await page.waitForFunction(
			(n) => document.querySelectorAll('[role="dialog"]').length <= n,
			beforeActionDialogCount,
			{ timeout: 15000 },
		);
		console.log(`[${MODULE_LABEL}] action "__LABEL__" submitted and its dialog closed — treated as success`);

		// Close button, not Escape -- every AppDialog instance defaults
		// `persistent: true` with no override, so Escape is captured and
		// preventDefault()'d and can never actually close any of them
		// (confirmed live 2026-08-20).
		for (let i = 0; i < 3 && (await page.locator('[role="dialog"]').count()) > 0; i++) {
			await page.locator('[role="dialog"]').last().getByRole('button', { name: 'Close' }).click().catch(() => {});
			await sleep(700);
		}
JS;
        }

        $tpl = str_replace('__ACTION_FILL__', $actionFillBlock, $tpl);

        return $open . $actionFieldsBlock . str_replace(['__LABEL__', '__KEBAB__'], [$escapedLabel, $kebab], $tpl);
    }

    /**
     * Wizard-action sibling to buildFieldFillLines() above. Until 2026-08-25 this generator had
     * NO wizard awareness anywhere — Create/Edit and Action smoke tests alike always filled every
     * configured field in one flat block, with no step-navigation at all. For a wizard action the
     * generated smoke test tried to fill every step's fields while still sitting on step 0, where
     * only the FIRST step's fields actually exist in the DOM yet, so it timed out locating a field
     * that would only appear after a "Next" click the test never made. Found live building
     * Pangisha's 09.01 Activate (2026-08-25): a 2-step wizard ("Review Schedule" [fieldless] +
     * "Optional Payment") failed locating #record_payment because the test never advanced past
     * step 0 at all.
     *
     * Groups the action's fields by which wizard step owns them (via each step's own
     * `field_keys`), fills one step's group, clicks "Next" (features/action/form.stub's
     * `$t('common.next')`, which resolves to the literal "Next" — matches this generator's
     * existing convention of hardcoding resolved English button text over threading i18n keys
     * through, see the "Close" button locator elsewhere in this file), and repeats. The number of
     * "Next" clicks emitted always matches the real rendered `wizardSteps[]` array's actual
     * length: $confirmEnabled mirrors ActionComponentGenerator::generateAction()'s own
     * `($confirmStepConfig['enabled'] ?? $isWizard)` resolution exactly — since this method is
     * only ever called once the caller has confirmed `wizard.enabled: true`, defaulting to `true`
     * here reproduces that same resolution for the wizard case specifically.
     */
    protected function buildWizardActionFillLines(array $fields, array $wizardConfig, array $confirmStepConfig): array
    {
        return $this->buildWizardFillLines(
            $fields,
            $wizardConfig['steps'] ?? [],
            ($confirmStepConfig['enabled'] ?? true) === true,
            'actionValues',
            "\t\tawait page.locator('[role=\"dialog\"]').getByRole('button', { name: 'Next', exact: true }).click();"
        );
    }

    /**
     * The stepped fill shared by a wizard ACTION and a wizard CREATE form: for each configured step,
     * fill that step's fields (`field_keys`, in step order), then click Next; after the last step a
     * Next is still needed when a Review & Confirm step follows, and that step's checkbox is ticked.
     *
     * A field named by no step is never filled. That is the config's contract -- a wizard partitions
     * the form -- and a required field left out fails the spec with the server's own 422, which is the
     * right place to hear about it.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $steps
     * @return list<string>
     */
    protected function buildWizardFillLines(array $fields, array $steps, bool $confirmEnabled, string $varName, string $nextClickLine): array
    {
        $fieldsByKey = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? null;
            if ($key !== null) {
                $fieldsByKey[$key] = $field;
            }
        }

        $lines = [];
        $lastStepIndex = count($steps) - 1;

        foreach ($steps as $i => $step) {
            $stepFields = [];
            foreach ($step['field_keys'] ?? [] as $key) {
                if (isset($fieldsByKey[$key])) {
                    $stepFields[] = $fieldsByKey[$key];
                }
            }

            if ($stepFields !== []) {
                [$fillLines, $fileFillLines] = $this->buildFieldFillLines($stepFields, $varName);
                $lines = array_merge($lines, $fillLines, $fileFillLines);
            }

            // Advance past every configured step; when a confirm step is appended, one more
            // "Next" click is needed after the LAST configured step too, to reach it.
            if ($i < $lastStepIndex || $confirmEnabled) {
                $lines[] = $nextClickLine;
                $lines[] = "\t\tawait sleep(300);";
            }
        }

        if ($confirmEnabled) {
            $lines[] = "\t\t// Generator-automatic \"Review & Confirm\" step (the form's confirm checkbox).";
            $lines[] = "\t\tawait page.locator('[role=\"dialog\"] #wizard-confirm').click();";
        }

        return $lines;
    }

    /**
     * The create form's wizard, or null when it is a flat form. `confirm` mirrors CreateFormGenerator:
     * a Review & Confirm step is ON by default for a wizard unless `confirm_step.enabled` says otherwise.
     *
     * @return array{steps: array<int, array<string, mixed>>, confirm: bool}|null
     */
    protected function createWizard(): ?array
    {
        $create = $this->config['features']['frontend']['create'] ?? [];
        $wizard = $create['wizard'] ?? [];
        if (($wizard['enabled'] ?? false) !== true || empty($wizard['steps'])) {
            return null;
        }

        return ['steps' => array_values($wizard['steps']), 'confirm' => ($create['confirm_step']['enabled'] ?? true) === true];
    }

    /**
     * The control a freshly opened Create dialog is waited on. A flat form has its submit button
     * straight away; a wizard shows Next on the first step and the submit button only on the last, so
     * waiting on submit timed out, and the retry then clicked Create underneath the still-open
     * dialog's overlay -- every spec of a module with a create wizard failed there (found by the
     * super-suite fixture's SuiteTickets).
     */
    protected function createOpenReadyTestId(): string
    {
        $wizard = $this->createWizard();
        $stepCount = $wizard === null ? 1 : count($wizard['steps']) + ($wizard['confirm'] ? 1 : 0);

        return $stepCount > 1 ? 'wizard-next' : 'submit';
    }
}
