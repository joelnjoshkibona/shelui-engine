<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer;
use Illuminate\Support\Str;

class CustomFeatureTabComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        // This generator should be called via generateCustomFeature()
        return false;
    }

    public function generateCustomFeature(string $featureKey, array $customFeature): bool
    {
        $displayType = $customFeature['displayType'] ?? 'header-action';
        if ($displayType !== 'tab-action') {
            return false;
        }
        
        $content = $this->getTemplateContent('features/custom/tab_action', 'frontend');
        
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $relatedModule = $customFeature['relatedModule'] ?? null;
        
        // Handle relatedModule as object or string
        $relatedModuleName = '';
        if ($relatedModule) {
            if (is_array($relatedModule)) {
                $relatedModuleName = $relatedModule['name'] ?? '';
            } else {
                $relatedModuleName = $relatedModule;
            }
        }
        
        // Use enabled flags from features.backend (preferred) or enabledOperations (fallback)
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $enabledOps = $customFeature['enabledOperations'] ?? [];
        
        $hasCreate = isset($backendFeatures['create']['enabled']) 
            ? ($backendFeatures['create']['enabled'] ?? false)
            : ($enabledOps['create'] ?? true);
        $hasEdit = isset($backendFeatures['edit']['enabled'])
            ? ($backendFeatures['edit']['enabled'] ?? false)
            : ($enabledOps['edit'] ?? true);
        $hasView = isset($backendFeatures['view']['enabled'])
            ? ($backendFeatures['view']['enabled'] ?? false)
            : ($enabledOps['view'] ?? true);
        $hasDelete = isset($backendFeatures['delete']['enabled'])
            ? ($backendFeatures['delete']['enabled'] ?? false)
            : ($enabledOps['delete'] ?? true);
        
        // Needed before the column work below, which strips the parent FK column.
        $filterKey = $customFeature['filterKey'] ?? 'parent_id';

        // Generate columns with primary column pattern (same as ListPageGenerator)
        $listConfig = $customFeature['features']['frontend']['list'] ?? [];
        $listFields = $listConfig['fields'] ?? [];

        // A delegation entry carries only its operation flags — buildDelegationEntry()
        // never populates features.frontend.list.fields — so this was ALWAYS empty
        // and every delegation tab rendered `const columns = []`. The child list
        // fetched its rows correctly (200, meta 1-1 of 1) and then displayed a
        // table with no header and no rows, because ListTable has nothing to
        // render without columns. The real source is the RELATED module's own
        // module.json.
        if (empty($listFields)) {
            $relatedConfig = $this->loadRelatedModuleConfig($customFeature);
            $listConfig = $relatedConfig['features']['frontend']['list'] ?? $listConfig;
            $listFields = $listConfig['fields'] ?? [];
        }
        $columns = '';
        $primaryKey = 'name';
        $primaryCellContent = '';
        $customCellRenderers = '';
        
        // Drop the column that points back at the parent. Inside Item Details
        // every row's "Item" is the same record, so the column carries no
        // information — and it rendered "N/A" anyway, because the child list
        // does not eager-load the parent relation. Removing it is the fix;
        // adding the join would only make a redundant column populate.
        if (!empty($listFields) && is_array($listFields) && $filterKey !== '') {
            $listFields = array_values(array_filter(
                $listFields,
                static fn (array $f): bool => ($f['key'] ?? $f['field'] ?? '') !== $filterKey
            ));
        }

        if (!empty($listFields) && is_array($listFields)) {
            // Get primary field key first (needed for filtering)
            $primaryFieldKey = $listConfig['primaryField'] ?? '';
            if (empty($primaryFieldKey) && !empty($listFields)) {
                $firstField = $listFields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? 'name';
            }
            $primaryKey = $primaryFieldKey ?: 'name';
            
            // Generate columns from list fields (pass primary key to ensure correct column structure)
            $columns = $this->generateColumnsFromListFields($listFields, $primaryKey);

            // Column labels are emitted as t('{module}.col_x') using THIS
            // generator's module — the parent. The columns belong to the related
            // module, whose locale file is the one that actually defines those
            // keys, so the parent namespace resolved to nothing and vue-i18n
            // printed the raw key ("items.col_label") as the header.
            $relatedName = $customFeature['relatedModule']['name']
                ?? (is_string($customFeature['relatedModule'] ?? null) ? $customFeature['relatedModule'] : '');
            if ($relatedName !== '') {
                $columns = str_replace(
                    "t('" . Str::kebab($this->moduleName) . ".col_",
                    "t('" . Str::kebab($relatedName) . ".col_",
                    $columns
                );
            }
            
            // Generate primary cell content for responsive display (excludes primary field).
            // Both helpers default to emitting "row.xxx" — correct here too: tab_action.stub
            // wraps <CrudListPanel> -> <ListTable>/<ReportTable> (:row="row"), same as
            // list/page.stub. Passed explicitly for clarity, matching the default.
            $primaryCellContent = $this->generatePrimaryCellContentFromListFields($listFields, $primaryKey, 'row');

            // Generate custom cell renderers for badge/boolean fields.
            $customCellRenderers = $this->generateCustomCellRenderersFromListFields($listFields, $primaryKey, 'row');
        }
        
        // Build parent-scoped endpoints from module/feature routes — matching
        // RoutesGenerator::generateDelegationRoutes()'s own path scheme exactly
        // (/{module}/{parentKey}/{delegation}/{op} for list/create,
        // /{module}/{parentKey}/{delegation}/{itemUuid}/{op} for edit/delete,
        // /{module}/{parentKey}/{delegation}/{itemUuid}/delete/check for
        // deleteCheck) so the frontend never requests a path the backend
        // didn't register. ${uuid.value} is the PARENT's own record identifier,
        // already resolved client-side (tab components always live under a
        // parent details page) — see the [[idParam]] fix below for how that
        // value is now sourced correctly for a has_uuid: false parent module.
        // The variable is still named `uuid` in tab_action.stub: purely
        // cosmetic (its value, not its name, is what a has_uuid: false parent
        // needs fixed — see BaseComponentGenerator::idParam()'s docblock on
        // when a rename is/isn't warranted). The literal string '{uuid}' (not
        // a JS template expression) is the CHILD/item uuid — CrudListPanel
        // substitutes it with the currently-selected row's id right before
        // rendering the relevant dialog, since it isn't known until then.
        // That is the RELATED module's own key, a separate, deferred question
        // (mirrors the backend's "related module's own key" precedent).
        $moduleNameLower = \Illuminate\Support\Str::kebab($this->moduleName);
        $featureNameLower = \Illuminate\Support\Str::kebab($featureName);
        $base = "/{$moduleNameLower}/\${uuid.value}/{$featureNameLower}";

        $endpointPath = "{$base}/list";
        $createEndpointPath = "{$base}/create";
        $editEndpointPath = "{$base}/{uuid}/edit";
        $deleteEndpointPath = "{$base}/{uuid}/delete";
        $deleteCheckEndpointPath = "{$base}/{uuid}/delete/check";
        $viewEndpointPath = "{$base}/{uuid}/view";

        // Permission strings — same formula RoutesGenerator uses for the
        // backend route guard (DelegationConfigNormalizer::
        // resolveOperationPermission()), so frontend gating and backend
        // enforcement can never drift apart. Reuses the RELATED module's own
        // permission (e.g. "StockMovements.edit"), not a delegation-specific
        // one — a role granted on the related module works identically
        // whether reached standalone or through this tab. $customFeature
        // carries the delegation's per-operation endpoint config under
        // features.backend.{op}.endpoint (see DelegationTabComponentGenerator::
        // adaptDelegationToCustomFeature()).
        $createPermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'create', $backendFeatures['create']['endpoint'] ?? []
        );
        $editPermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'edit', $backendFeatures['edit']['endpoint'] ?? []
        );
        $viewPermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'view', $backendFeatures['view']['endpoint'] ?? []
        );
        $deletePermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'delete', $backendFeatures['delete']['endpoint'] ?? []
        );

        // bulk_actions/export/import: DelegationTabComponentGenerator::
        // adaptDelegationToCustomFeature() flattens the delegation's
        // operations.list.backend.{bulk_actions,export,import} straight
        // into $backendFeatures['list'] alongside 'enabled'/'endpoint' — no
        // extra nesting. Export needs no permission prop at all (reuses
        // whatever already gates the list itself, matching how standalone
        // export reuses .list rather than a dedicated permission — see
        // CrudListPanel.vue's own no-exportPermission-prop design).
        $listBackend = $backendFeatures['list'] ?? [];
        $enableExport = !empty($listBackend['export']) ? 'true' : 'false';
        $enableBulkActions = !empty($listBackend['bulk_actions']) ? 'true' : 'false';
        $enableImport = !empty($listBackend['import']) ? 'true' : 'false';
        $bulkActionsLiteral = $this->generateBulkActionsLiteral($listBackend['bulk_actions'] ?? []);
        $bulkActionPermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'bulkAction', []
        );
        $importPermission = DelegationConfigNormalizer::resolveOperationPermission(
            $relatedModuleName, 'import', []
        );

        // Generate component imports for related module forms
        $componentImports = '';
        if (!empty($relatedModuleName)) {
            $importSegment = PathManager::resolveFrontendImportSegment($relatedModuleName);
            $imports = [];

            // Skip related-module component imports when module can't be resolved.
            // Prevents broken paths like "modules//X/..." that crash the build.
            if (empty($importSegment)) {
                // Reported through PathManager, not the Log facade — see the same
                // switch in BaseComponentGenerator. Delegation generation runs inside
                // FrontendPipeline, which must work with no Laravel container at all,
                // and a facade call here turned an unresolvable related module from a
                // skipped import into a hard "A facade root has not been set" failure.
                PathManager::reportIssue(
                    "CustomFeatureTabComponentGenerator: related module '{$relatedModuleName}' not "
                    . "resolvable for delegation on {$this->moduleName}; skipping component imports"
                );
                $componentImports = "// Related module '{$relatedModuleName}' not found — component imports skipped";
                $relatedModuleName = ''; // neutralize downstream usage in template
            } else {

            // All four resolve to the related module's own NATIVE components —
            // the same ones its own standalone list page uses. There is no
            // longer a separate delegation-specific Create/Edit form (the
            // now-removed RelatedModuleFormGenerator used to overwrite these
            // exact same files at this exact same path — a confirmed, live
            // collision bug) or a separate ViewComponent (unified onto the
            // native ViewModal, which now supports an optional parentUuid
            // prop for exactly this context).
            if ($hasCreate) {
                $imports[] = "import {$relatedModuleName}CreateForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}CreateForm.vue\";";
            }
            if ($hasEdit) {
                $imports[] = "import {$relatedModuleName}EditForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}EditForm.vue\";";
            }
            if ($hasView) {
                $imports[] = "import {$relatedModuleName}ViewModal from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}ViewModal.vue\";";
            }
            if ($hasDelete) {
                $imports[] = "import {$relatedModuleName}DeleteForm from \"@/pages/modules/{$importSegment}/Components/{$relatedModuleName}DeleteForm.vue\";";
            }

            $componentImports = implode("\n", $imports);
            }
        }
        
        // Get filterKey for passing parent ID in defaults
        $filterKey = $customFeature['filterKey'] ?? 'parent_id';
        
        // Get hiddens and defaults from frontend config
        $frontendCreate = $customFeature['features']['frontend']['create'] ?? [];
        $frontendEdit = $customFeature['features']['frontend']['edit'] ?? [];
        
        $createHiddens = $frontendCreate['hiddens'] ?? [];
        $createDefaults = $frontendCreate['defaults'] ?? [];
        $editHiddens = $frontendEdit['hiddens'] ?? [];
        $editDefaults = $frontendEdit['defaults'] ?? [];
        
        // Ensure filterKey is always hidden and has default value
        if (!isset($createHiddens[$filterKey])) {
            $createHiddens[$filterKey] = true;
        }
        // The FK default must be the parent's `id`, NOT its uuid. filterKey is an
        // integer foreign key (item_id), while `uuid` here is the route's uuid
        // string — sending it produced a 422 "The item id field must be an
        // integer" on every create from a delegation tab. `parentIdField`
        // (default 'id') names the parent attribute to send, and the parent
        // record arrives on the tab as props.data.
        if (!isset($createDefaults[$filterKey])) {
            $createDefaults[$filterKey] = 'parentId';
        }
        if (!isset($editHiddens[$filterKey])) {
            $editHiddens[$filterKey] = true;
        }
        if (!isset($editDefaults[$filterKey])) {
            $editDefaults[$filterKey] = 'parentId';
        }
        
        // Convert hiddens to JSON for template replacement
        $createHiddensJson = json_encode($createHiddens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $editHiddensJson = json_encode($editHiddens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Convert defaults to JavaScript object literal format
        $createDefaultsJs = $this->convertDefaultsToJs($createDefaults);
        $editDefaultsJs = $this->convertDefaultsToJs($editDefaults);
        
        $content = $this->replacePlaceholders($content, [
            '[[FeatureName]]' => $featureName,
            '[[featureName]]' => strtolower($featureName),
            // shelui-engine fork: the PARENT module's own route-param name --
            // 'uuid' when ModuleConfigContract::hasUuid(), else 'id'. Feeds
            // tab_action.stub's `uuid` computed(), which used to hardcode
            // `route.params.uuid` unconditionally -- for a has_uuid: false
            // parent module the parent details route now registers an `:id`
            // segment (FrontendRoutesGenerator::$idParam), so that literal
            // read undefined and every request this tab builds off `${uuid.value}`
            // (list/create/edit/delete/view endpoints above) 404'd/hit
            // ".../undefined/...". See idParam()'s docblock, BaseComponentGenerator.
            '[[idParam]]' => $this->idParam(),
            '[[RelatedModule]]' => $relatedModuleName,
            '[[relatedModule]]' => strtolower($relatedModuleName),
            '[[columns]]' => $columns,
            '[[primaryKey]]' => $primaryKey,
            '[[primaryCellContent]]' => $primaryCellContent,
            '[[customCellRenderers]]' => $customCellRenderers,
            '[[hasCreate]]' => $hasCreate ? 'true' : 'false',
            '[[hasEdit]]' => $hasEdit ? 'true' : 'false',
            '[[hasView]]' => $hasView ? 'true' : 'false',
            '[[hasDelete]]' => $hasDelete ? 'true' : 'false',
            // The component props must never NAME a component whose import wasn't emitted: a
            // read-only delegation (create/edit/delete off) used to render
            // `:create-component="false ? XCreateForm : null"`, which still references the
            // identifier -- vue-tsc fails with "Property 'XCreateForm' does not exist" even though
            // the branch can never run. Emit `null` outright when the operation is off (or the
            // related module couldn't be resolved, so no import exists either).
            '[[createComponent]]' => ($hasCreate && $relatedModuleName !== '') ? "{$relatedModuleName}CreateForm" : 'null',
            '[[editComponent]]' => ($hasEdit && $relatedModuleName !== '') ? "{$relatedModuleName}EditForm" : 'null',
            '[[deleteComponent]]' => ($hasDelete && $relatedModuleName !== '') ? "{$relatedModuleName}DeleteForm" : 'null',
            '[[viewComponent]]' => ($hasView && $relatedModuleName !== '') ? "{$relatedModuleName}ViewModal" : 'null',
            '[[apiEndpointPath]]' => $endpointPath,
            '[[createEndpointPath]]' => $createEndpointPath,
            '[[editEndpointPath]]' => $editEndpointPath,
            '[[deleteEndpointPath]]' => $deleteEndpointPath,
            '[[deleteCheckEndpointPath]]' => $deleteCheckEndpointPath,
            '[[viewEndpointPath]]' => $viewEndpointPath,
            '[[createPermission]]' => "'{$createPermission}'",
            '[[editPermission]]' => "'{$editPermission}'",
            '[[viewPermission]]' => "'{$viewPermission}'",
            '[[deletePermission]]' => "'{$deletePermission}'",
            '[[componentImports]]' => $componentImports,
            '[[parentIdField]]' => $customFeature['parentIdField'] ?? 'id',
            '[[filterKey]]' => $filterKey,
            '[[createHiddens]]' => $createHiddensJson,
            '[[createDefaults]]' => $createDefaultsJs,
            '[[editHiddens]]' => $editHiddensJson,
            '[[editDefaults]]' => $editDefaultsJs,
            '[[enableExport]]' => $enableExport,
            '[[enableBulkActions]]' => $enableBulkActions,
            '[[enableImport]]' => $enableImport,
            '[[bulkActionsLiteral]]' => $bulkActionsLiteral,
            '[[bulkActionPermission]]' => "'{$bulkActionPermission}'",
            '[[importPermission]]' => "'{$importPermission}'",
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}{$featureName}Tab.vue";
        
        return $this->writeFile($filePath, $content);
    }
    
    /**
     * Convert defaults array to JavaScript object literal format
     * Handles JavaScript expressions like "props.data.uuid" as strings
     */
    protected function convertDefaultsToJs(array $defaults): string
    {
        $lines = [];
        foreach ($defaults as $key => $value) {
            if (is_string($value) && (strpos($value, 'props.') === 0 || strpos($value, 'data.') === 0 || preg_match('/^[a-zA-Z_$][a-zA-Z0-9_$]*(\.[a-zA-Z_$][a-zA-Z0-9_$]*)*$/', $value))) {
                // JavaScript expression (props.data.uuid, uuid, etc.) - use as-is
                $lines[] = "\t\t\t{$key}: {$value},";
            } elseif (is_bool($value)) {
                $lines[] = "\t\t\t{$key}: " . ($value ? 'true' : 'false') . ",";
            } elseif (is_null($value)) {
                $lines[] = "\t\t\t{$key}: null,";
            } elseif (is_numeric($value)) {
                $lines[] = "\t\t\t{$key}: {$value},";
            } else {
                // String value - quote it
                $lines[] = "\t\t\t{$key}: " . json_encode($value) . ",";
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Read the related module's persisted module.json.
     *
     * The delegation only names its related module; the columns to render live
     * in that module's own config. Resolved through the registry so a nested
     * module (System/Inventory/ItemImages) is found at its real path.
     */
    protected function loadRelatedModuleConfig(array $customFeature): array
    {
        $related = $customFeature['relatedModule'] ?? null;
        $name = is_array($related) ? ($related['name'] ?? '') : (string) $related;
        if ($name === '') {
            return [];
        }

        $entry = PathManager::findModuleInRegistry($name);

        // Prefer a config the registry already carries over re-reading it from disk.
        // The filesystem lookup below resolves a BACKEND path, which does not exist at
        // all when only the frontend is being generated (bin/gen-frontend building a
        // prototype): the read failed, this returned [], and every delegation tab
        // rendered `const columns = []` — a tab that paginated correctly over real rows
        // while showing no headers and no cells. `$entry['config'] ?? $entry` is the
        // same defensive dual-shape read buildInlineInjectArray() already uses, since
        // registries in the wild store the config both nested and flat.
        $registryConfig = $entry['config'] ?? $entry;
        if (is_array($registryConfig) && !empty($registryConfig['features'])) {
            return $registryConfig;
        }

        $group = PathManager::normalizeGroupName($entry['module_type'] ?? $entry['type'] ?? 'Core');
        $subGroup = $entry['group_name'] ?? null;

        $original = PathManager::getModuleSubGroup();
        PathManager::setModuleSubGroup($subGroup);
        $path = PathManager::getBackendModulePath($group, $name) . '/module.json';
        PathManager::setModuleSubGroup($original);

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

}

