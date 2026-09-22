<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Validation\ValidationGenerator;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

abstract class BaseServiceGenerator extends BaseGenerator
{
    protected function generateFilterableFields(): string
    {
        // "id", "uuid" and "created_at" are always backend-filterable, regardless
        // of config (2026-08-05) — every generated table carries all three via
        // the standard migration columns, same as id/uuid always being
        // sortable/present. All three also get a frontend filter control by
        // default now — see generateFilterFields()'s matching appends.
        //
        // shelui-engine fork: "uuid" is not universal -- a has_uuid: false module has
        // no such column, and offering it as a filter 500s the list endpoint
        // (`where uuid = ...` against a nonexistent column). Gated the same way every
        // other has_uuid decision in this codebase is (ModuleConfigContract).
        // "created_at" has the identical latent problem for has_timestamps: false /
        // custom timestamp column names -- not fixed here, out of scope for this pass
        // (see FORK.md), left as-is.
        $systemFields = ['id'];
        if (ModuleConfigContract::hasUuid($this->config)) {
            $systemFields[] = 'uuid';
        }
        $systemFields[] = 'created_at';
        $fields = $this->appendSystemFields(
            $this->collectConfiguredFilterableFields(),
            $systemFields
        );

        return $this->fieldsToArrayLiteral($fields);
    }

    protected function generateSortableFields(): string
    {
        // Use sortableFields from list configuration
        if (isset($this->config['features']['backend']['list']['sortableFields'])) {
            $sortableFields = $this->config['features']['backend']['list']['sortableFields'];

            // Handle comma-separated string
            if (is_string($sortableFields)) {
                $fields = array_map('trim', explode(',', $sortableFields));
            } elseif (is_array($sortableFields)) {
                // Handle array
                $fields = array_values($sortableFields);
            } else {
                $fields = [];
            }
        } else {
            // Fallback to filterableFields if sortableFields not specified
            $fields = $this->collectConfiguredFilterableFields();
        }

        // Secret-like columns never become sortable, even when a developer
        // hand-authored sortableFields explicitly names one (e.g. Users'
        // `password` before this fix) — see withoutSensitiveColumns()'s
        // docblock for the live symptom (a `begins`-filter prefix oracle).
        $fields = $this->withoutSensitiveColumns($fields);

        // "id" is always sortable — it's the standard first, pinned column every
        // generated list gets. "uuid" is deliberately NOT added here: per the
        // spec it's filterable-only (see generateFilterableFields()) and never a
        // sortable/visible column.
        $fields = $this->appendSystemFields($fields, ['id']);

        return $this->fieldsToArrayLiteral($fields);
    }

    /**
     * Extract the raw, pre-system-field filterable field keys: feature-specific
     * filterFields if present, else filterableFields (array or comma-separated
     * string), else empty. Shared by generateFilterableFields() and
     * generateSortableFields()'s fallback branch — neither "id" nor "uuid" is
     * added here; callers append whichever system fields they need.
     *
     * @return string[]
     */
    private function collectConfiguredFilterableFields(): array
    {
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];

        if (!empty($filterFields)) {
            $fields = [];
            foreach ($filterFields as $field) {
                $key = $field['key'] ?? '';
                if ($key !== '') {
                    $fields[] = $key;
                }
            }
            return $this->withoutSensitiveColumns($fields);
        }

        if (isset($this->config['features']['backend']['list']['filterableFields'])) {
            $filterableFields = $this->config['features']['backend']['list']['filterableFields'];
            if (is_array($filterableFields)) {
                return $this->withoutSensitiveColumns(array_map(fn($field) => trim($field), $filterableFields));
            }
            return $this->withoutSensitiveColumns(array_map('trim', explode(',', $filterableFields)));
        }

        return [];
    }

    /**
     * Drop any key that names a secret-like column (ModuleConfigContract::
     * isSensitive()) from a field-key list. Applies even to a hand-authored
     * `filterFields`/`filterableFields`/`sortableFields` config, not just an
     * introspected one — SYSTEM_SHELL's Users module listed `password` as
     * both filterable and sortable, and the list filter's `begins` operator
     * (`LIKE 'value%'`) turned `Users.list` into a character-by-character
     * prefix oracle against the password hash for anyone holding that one
     * permission.
     *
     * @param string[] $keys
     * @return string[]
     */
    private function withoutSensitiveColumns(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn ($key): bool => !is_string($key) || !ModuleConfigContract::isSensitive($this->config, $key),
        ));
    }

    /**
     * Append any of $systemFields not already present in $fields, preserving order.
     *
     * @param string[] $fields
     * @param string[] $systemFields
     * @return string[]
     */
    private function appendSystemFields(array $fields, array $systemFields): array
    {
        foreach ($systemFields as $systemField) {
            if (!in_array($systemField, $fields, true)) {
                $fields[] = $systemField;
            }
        }
        return $fields;
    }

    /**
     * Render a plain string field-key list as a PHP array literal, e.g. ['name', 'id'].
     *
     * @param string[] $fields
     */
    private function fieldsToArrayLiteral(array $fields): string
    {
        if (empty($fields)) {
            return '[]';
        }
        return '[' . implode(', ', array_map(fn($field) => "'" . trim((string) $field) . "'", $fields)) . ']';
    }

    protected function generateEagerLoadRelationships($feature): string
    {
        // 'creator'/'updater' are only real relationships when the model
        // actually has created_by_id/updated_by_id columns -- appending them
        // unconditionally threw a RelationNotFoundException for every module
        // without has_creator_updater. ModuleConfigContract::hasCreatorUpdater()
        // is the single sanctioned place to read this fact (see its own
        // docblock) rather than re-deriving it here.
        $creatorUpdater = ModuleConfigContract::hasCreatorUpdater($this->config)
            ? ["'creator'", "'updater'"]
            : [];

        // Check if custom configuration exists
        if (isset($this->config['features']['backend'][$feature]['eagerLoadRelationships'])) {
            $eagerLoadConfig = $this->config['features']['backend'][$feature]['eagerLoadRelationships'];

            // Handle comma-separated string
            if (is_string($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', explode(',', $eagerLoadConfig)),
                    fn($rel) => !empty($rel)
                );
                $relationships = array_merge(
                    array_map(fn($rel) => "'" . $this->correctEagerLoadRelationshipName($rel) . "'", $customRelationships),
                    $creatorUpdater
                );
                return empty($relationships) ? '[]' : '[' . implode(', ', $relationships) . ']';
            }

            // Handle array format
            if (is_array($eagerLoadConfig)) {
                $customRelationships = array_filter(
                    array_map('trim', $eagerLoadConfig),
                    fn($rel) => !empty($rel)
                );
                $relationships = array_merge(
                    array_map(fn($rel) => "'" . $this->correctEagerLoadRelationshipName($rel) . "'", $customRelationships),
                    $creatorUpdater
                );
                return empty($relationships) ? '[]' : '[' . implode(', ', $relationships) . ']';
            }
        }

        // No feature-specific eagerLoadRelationships configured at all --
        // still auto-eager-load every foreignId column's own relation (not
        // just creator/updater), same signal resolveColumnRelationship()
        // uses on the Frontend/MobileApp side. Without this, a feature that
        // never got the same relation-config-seeding List's builder step
        // does (confirmed missing for `view` on a real module: `list`'s
        // eagerLoadRelationships was populated, `view`'s was simply never
        // set) returns raw FK ids with no relation data at all -- the
        // frontend/mobile FK-name/status-color rendering has nothing to
        // read even when it correctly asks for `location?.name`.
        $fkRelationships = [];
        foreach ($this->config['columns'] ?? [] as $col) {
            if (($col['type'] ?? null) === 'foreignId' && !empty($col['relatedModule'])) {
                $fkRelationships[] = "'" . $this->deriveRelationshipMethodName($col['name'] ?? '') . "'";
            }
        }

        $relationships = array_merge($fkRelationships, $creatorUpdater);
        return empty($relationships) ? '[]' : '[' . implode(', ', $relationships) . ']';
    }

    /**
     * Bug (found 2026-08-15 via the retail-ERP demo fixture): V1's frontend
     * (Section03_BackendFeatures.vue's initEagerLoadRelationships()/
     * initViewFields()) sends a relation name built by snake-casing the
     * FK's *related module name* and then chopping exactly one trailing
     * character off it — "Statuses" -> "statuses" -> "statuse",
     * "ItemCategories" -> "item_categories" -> "item_categorie",
     * "PaymentTerms" -> "payment_terms" -> "payment_term" — instead of the
     * real Eloquent relation method name the Model actually generates
     * (ModelGenerator::deriveRelationshipMethodName() — camelCase, `_id`
     * stripped from the COLUMN name: status_id -> status, payment_terms_id
     * -> paymentTerms). A ListService/ViewService built with the wrong name
     * throws `Call to undefined relationship [...]` the moment it eager-
     * loads. Rather than trust the frontend's raw string, re-derive the
     * correct name from `$config['columns']` here whenever this looks like
     * that exact chopped-relatedModule shape; anything else (a
     * hand-authored custom relation, e.g. a manually declared
     * belongsToMany) passes through unchanged.
     *
     * Second bug (found 2026-08-17, same fixture, a different column):
     * `toRelationName()` doesn't always garble via the relatedModule path
     * above — for a column like `default_price_list_id` it instead derives
     * a name straight from the COLUMN itself but leaves it snake_case
     * ("default_price_list") rather than camelCase ("defaultPriceList").
     * That shape wasn't one of the two hardcoded strings this method used
     * to check, so it passed through unchanged and still broke eager
     * loading. Generalized below: any relation name whose camelCase form
     * equals the correct derived name is corrected, not just the two
     * specific relatedModule-derived shapes already known.
     */
    protected function correctEagerLoadRelationshipName(string $relationName): string
    {
        foreach (($this->config['columns'] ?? []) as $col) {
            $colName = $col['name'] ?? '';
            $relatedModule = $col['relatedModule'] ?? '';
            if (($col['type'] ?? '') !== 'foreignId' || !str_ends_with($colName, '_id') || $relatedModule === '') {
                continue;
            }
            $correctName = lcfirst(Str::camel(substr($colName, 0, -3)));
            $relatedSnake = Str::snake($relatedModule);
            // Confirmed-live broken shapes: the related module's own
            // snake-cased name verbatim (e.g. "units_of_measure" for
            // relatedModule "UnitsOfMeasure"), that same string with
            // exactly one trailing character chopped (e.g. "statuse" for
            // "Statuses"), the theoretically-correct name itself (in case
            // a future frontend fix sends it directly), and — generically —
            // any snake_case (or other-cased) rendering of the correct
            // column-derived name, since `Str::camel()` normalizes case
            // differences away regardless of which broken path produced it.
            if (
                in_array($relationName, [$correctName, $relatedSnake, substr($relatedSnake, 0, -1)], true)
                || Str::camel($relationName) === $correctName
            ) {
                return $correctName;
            }
        }

        return $relationName;
    }

    protected function generateFilterableRelationships(): string
    {
        // Use filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];
        
        $filterableRelationships = [];

        // Extract relationships from filterFields that are foreign keys
        foreach ($filterFields as $field) {
            $fieldName = $field['key'] ?? '';
            if ($this->isForeignKey($fieldName, $field)) {
                $relationshipName = str_replace('_id', '', $fieldName);
                $filterableRelationships[$relationshipName] = ['id', 'name'];
            }
        }
        
        // Also check filterableRelationships if explicitly configured
        if (isset($this->config['features']['backend']['list']['filterableRelationships'])) {
            $explicitRelationships = $this->config['features']['backend']['list']['filterableRelationships'];
            if (is_array($explicitRelationships) && !empty($explicitRelationships)) {
                foreach ($explicitRelationships as $rel => $fields) {
                    $filterableRelationships[$rel] = is_array($fields) ? $fields : ['id', 'name'];
                }
            }
        }

        if (empty($filterableRelationships)) {
            return '[]';
        }

        $relationshipConfigs = [];
        foreach ($filterableRelationships as $relationship => $fields) {
            $fieldStrings = array_map(fn($field) => "'{$field}'", $fields);
            $relationshipConfigs[] = "'{$relationship}' => [" . implode(', ', $fieldStrings) . "]";
        }

        return '[' . implode(', ', $relationshipConfigs) . ']';
    }

    protected function generateFilterFields(): string
    {
        // Use feature-specific filterFields from list configuration
        $filterFields = $this->config['features']['backend']['list']['filterFields'] ?? [];

        // A hand-authored filterFields entry naming a secret-like column
        // (e.g. Users' `password`) never becomes a filter control — same
        // rationale as withoutSensitiveColumns() above, applied by 'key'
        // since these are already-built filter-field entries, not bare names.
        $filterFields = array_values(array_filter(
            $filterFields,
            fn ($field): bool => !is_array($field) || !isset($field['key']) || !ModuleConfigContract::isSensitive($this->config, $field['key']),
        ));

        // Fallback: derive type-aware filters from filterableFields so the DataTableFilter
        // has fields to render. Without this, introspected modules emit an empty array and
        // show no filter UI at all.
        //
        // Bug (found + fixed 2026-08-03): every field built here used to be
        // hardcoded 'type' => 'text', regardless of the column's real type
        // — an FK or enum column (e.g. an Order's `status`) rendered as a
        // plain text box running a LIKE search against an integer/enum
        // column that could never meaningfully match. Now looks up each
        // field's real column definition and uses getFilterFieldType()/
        // buildFilterFieldOptions() (see their own docblocks for the full
        // writeup — both methods already existed with the right idea, but
        // had zero call sites anywhere in the codebase, so their own latent
        // bugs were never caught either).
        if (empty($filterFields)) {
            $filterable = $this->config['features']['backend']['list']['filterableFields'] ?? [];
            if (is_string($filterable)) {
                $filterable = array_filter(array_map('trim', explode(',', $filterable)));
            }
            $filterable = $this->withoutSensitiveColumns(array_values($filterable));

            $columnsByName = [];
            foreach (($this->config['columns'] ?? []) as $col) {
                if (!empty($col['name'])) {
                    $columnsByName[$col['name']] = $col;
                }
            }

            foreach ($filterable as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $cleanKey = preg_replace(['/_id$/', '/_at$/'], '', $name);
                $column = $columnsByName[$name] ?? [];
                // config['columns'] entries (IntrospectionToConfig::
                // buildColumn()'s output) hold the normalized type directly
                // in 'type' — see isForeignKey()'s docblock for the full
                // writeup on why this isn't 'normalized_type'.
                $normalizedType = $column['type'] ?? '';

                $type = $this->getFilterFieldType($name, $normalizedType, $column);

                $entry = [
                    'key'   => $name,
                    'label' => ucwords(str_replace('_', ' ', $cleanKey)),
                    'type'  => $type,
                ];

                if ($type === 'select') {
                    $entry['options'] = $this->buildFilterFieldOptions($name, $normalizedType, $column);
                } elseif ($type === 'select_paginated') {
                    $paginatedConfig = $this->buildSelectPaginatedFilterFieldConfig($column);
                    if ($paginatedConfig === []) {
                        // isForeignKey()'s `_id`-suffix fallback also matches
                        // file/media reference columns (e.g. `image_media_id`)
                        // that aren't a real relation the module registry can
                        // resolve -- buildSelectPaginatedFilterFieldConfig()
                        // returns [] for exactly this case (no relatedModule).
                        // Emitting 'select_paginated' with no api_endpoint
                        // produces a filter that fires every search against
                        // the bare API base URL and always fails (confirmed
                        // live 2026-08-08: `GET /api?page=1&per_page=20`,
                        // net::ERR_FAILED, on a freshly generated ItemImages
                        // module's `image_media_id` filter). Fall back to a
                        // plain numeric filter instead -- it's still an *_id
                        // column.
                        $entry['type'] = 'number';
                    } else {
                        $entry = array_merge($entry, $paginatedConfig);
                    }
                }

                $filterFields[] = $entry;
            }
        }

        // "id", "uuid" and "created_at" all get a frontend filter control by
        // default (2026-08-05) — every generated table carries all three via
        // the standard migration columns, matching generateFilterableFields()'s
        // backend allow-list. "created_at" renders as a 'date' filter
        // (DataTableFilter.vue's date picker), even though the underlying
        // column is a full datetime — ListServiceTrait::applyFilter() (in
        // SYSTEM_SHELL) expands a bare "YYYY-MM-DD" value into a whole-day
        // range instead of an exact-instant match, since the two would
        // otherwise never be equal.
        $filterFields = $this->appendDefaultFilterField($filterFields, 'id', 'ID', 'text');
        // shelui-engine fork: gated on has_uuid -- see generateFilterableFields()'s matching fix.
        if (ModuleConfigContract::hasUuid($this->config)) {
            $filterFields = $this->appendDefaultFilterField($filterFields, 'uuid', 'UUID', 'text');
        }
        $filterFields = $this->appendDefaultFilterField($filterFields, 'created_at', 'Created At', 'date');

        if (empty($filterFields)) {
            return '[]'; // Return empty if no filter fields configured
        }

        $fields = [];
        foreach ($filterFields as $field) {
            $fields[] = $this->formatFilterField($field);
        }

        return '[' . implode(",\n\t\t\t", $fields) . ']';
    }

    /**
     * Append a default filter field entry unless a field with the same `key`
     * is already present (either hand-authored in
     * `features.backend.list.filterFields`, or already derived from
     * `filterableFields` above) — never overrides a caller's own definition.
     *
     * @param array<int, array<string, mixed>> $filterFields
     * @return array<int, array<string, mixed>>
     */
    private function appendDefaultFilterField(array $filterFields, string $key, string $label, string $type): array
    {
        foreach ($filterFields as $field) {
            if (($field['key'] ?? '') === $key) {
                return $filterFields;
            }
        }
        $filterFields[] = ['key' => $key, 'label' => $label, 'type' => $type];
        return $filterFields;
    }

    protected function formatFilterField(array $field): string
    {
        $formattedField = $field;

        // Handle custom_select type
        if ($field['type'] === 'custom_select') {
            if (isset($field['custom_keys']) && is_array($field['custom_keys'])) {
                $formattedField['custom_keys'] = $field['custom_keys'];
            }
            if (isset($field['custom_options']) && is_array($field['custom_options'])) {
                $formattedField['custom_options'] = $field['custom_options'];
            }
        }

        // Handle module_select type
        if ($field['type'] === 'module_select') {
            if (isset($field['columns']) && is_array($field['columns'])) {
                $formattedField['columns'] = $field['columns'];
            }
        }

        return $this->arrayToString($formattedField);
    }

    protected function generateFieldLabel(string $fieldName): string
    {
        // Convert snake_case to Title Case
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        $label = str_replace(' Id', '', $label);
        $label = str_replace(' At', '', $label);
        return $label;
    }

    /**
     * Bug (found + fixed 2026-08-03): this method existed, correctly
     * signatured, and was never called anywhere — `generateFilterFields()`'s
     * fallback (below) hardcoded every introspected filter field to
     * `'type' => 'text'` regardless of the column's real type, so an FK or
     * enum column (e.g. an Order's `status`) rendered as a plain text box
     * running a LIKE search against an integer/enum column that could never
     * meaningfully match. Confirmed via `grep -rn getFilterFieldType src/
     * tests/` finding only this definition, zero call sites.
     *
     * Wiring it up alone wasn't enough — it had its own latent bug, never
     * caught because it never ran against real data: `'bigint'` here never
     * matches the string `config['columns']` entries actually carry for a
     * bigint column, `'bigInteger'` (camelCase, from
     * `SchemaIntrospector::normalizeType()`) — fixed. Also had no `enum`
     * branch at all — an enum column fell through to the default `'text'`
     * case, i.e. the exact bug this method exists to prevent, for the exact
     * column type (`status`-style enums) most likely to need it. Added,
     * returning `'select'`. `isForeignKey()`'s own check
     * (`$field['type'] === 'foreignId'`) was already correct for the only
     * shape it's ever called with — see its own docblock for why a first
     * attempt at "fixing" it (checking `is_fk`/`normalized_type` instead)
     * was itself wrong, caught by this same live-verification pass.
     *
     * `'select'`/`'select_paginated'` need `DataTableFilter.vue`'s
     * `field.options` (an explicit `{name, id}[]` list) or `select_paginated`
     * (which loads its own options live) to actually render a dropdown —
     * see `buildFilterFieldOptions()`, which the fallback loop below calls
     * alongside this method.
     */
    protected function getFilterFieldType(string $fieldName, string $fieldType, array $field): string
    {
        if ($this->isForeignKey($fieldName, $field)) {
            return 'select_paginated';
        }
        if (!empty($field['enum_values'])) {
            return 'select';
        }
        if ($fieldType === 'boolean' || $fieldName === 'is_default' || $fieldName === 'is_active') {
            return 'select';
        }
        if (in_array($fieldType, ['date', 'datetime', 'timestamp'])) {
            return 'date';
        }
        if (in_array($fieldType, ['integer', 'bigInteger', 'decimal'])) {
            return 'number';
        }
        return 'text';
    }

    /**
     * Options for a `'select'`-type filter field — required for
     * `DataTableFilter.vue` to actually render a dropdown (it gates the
     * `<select>` widget on `field.options` being present, not just
     * `field.type === 'select'`).
     *
     * @return array<int, array{name: string, id: mixed}>
     */
    protected function buildFilterFieldOptions(string $fieldName, string $fieldType, array $field): array
    {
        if (!empty($field['enum_values']) && is_array($field['enum_values'])) {
            return array_map(
                static fn (string $value): array => ['name' => ucwords(str_replace(['_', '-'], ' ', $value)), 'id' => $value],
                $field['enum_values']
            );
        }
        if ($fieldType === 'boolean' || $fieldName === 'is_default' || $fieldName === 'is_active') {
            return [
                ['name' => 'Yes', 'id' => 1],
                ['name' => 'No', 'id' => 0],
            ];
        }
        return [];
    }

    /**
     * Config for a `'select_paginated'`-type filter field. A prior version of
     * this file's docblock claimed select_paginated "needs none of this; it
     * loads its own options live via ApiSelect2" — wrong, confirmed live:
     * `DataTableFilter.vue` binds `:api-url="field.api_endpoint || ''"`, so a
     * field with no `api_endpoint` fires every search against the bare API
     * base URL (`GET /api?page=1&per_page=20`), which always fails. The
     * fallback loop in `generateFilterFields()` derived the correct `type`
     * for an FK column but never built this config, so every module relying
     * on the introspection fallback (i.e. no hand-authored
     * `features.backend.list.filterFields` in module.json) shipped a
     * silently-broken FK filter dropdown — found live on SYSTEM_SHELL's
     * `Locations` module (`location_type_id`/`parent_id`/`status_id`).
     *
     * Mirrors the shape every hand-authored `select_paginated` filterField in
     * this codebase already uses (e.g. `LocationTypesListService`'s
     * `location_type_id`/`parent_id`/`status_id` entries): `api_endpoint`
     * points at the FK target's own `/select/{Module}` route (same
     * `SelectController` every `ApiSelect2Field` already calls), 'name'/'id'
     * as the default option label/value.
     *
     * @return array<string, mixed>
     */
    protected function buildSelectPaginatedFilterFieldConfig(array $field): array
    {
        $relatedModule = $field['relatedModule'] ?? '';
        if ($relatedModule === '') {
            return [];
        }

        return [
            'api_endpoint'    => "/select/{$relatedModule}",
            'search_param'    => 'search',
            'id_search_param' => 'id',
            'per_page'        => 20,
            'multiple'        => true,
            'paginate'        => true,
            'option_label'    => 'name',
            'option_value'    => 'id',
        ];
    }

    protected function isForeignKey(string $fieldName, array $field): bool
    {
        // $field here is always one of $this->config['columns'] — i.e.
        // IntrospectionToConfig::buildColumn()'s OUTPUT shape, not
        // SchemaIntrospector::columns()' raw shape. Those are two different
        // contracts: buildColumn() collapses the raw 'type' (DB-native,
        // e.g. 'bigint') and 'normalized_type' (e.g. 'foreignId') down to a
        // single 'type' key holding the NORMALIZED value — there is no
        // separate 'normalized_type' or 'is_fk' key at this layer.
        // Confirmed via live generation (not assumed): a real FK column's
        // config['columns'] entry has 'type' => 'foreignId'. Getting this
        // backwards (checking for a raw-shape-only key that never survives
        // into config['columns']) was a bug introduced while first fixing
        // this method, caught by the same live-verification pass that
        // caught getFilterFieldType()'s other issues below.
        if (($field['type'] ?? '') === 'foreignId') {
            return true;
        }
        if (str_ends_with($fieldName, '_id')) {
            return true;
        }
        return false;
    }

    protected function getRelatedTableName(string $fieldName): string
    {
        if (str_ends_with($fieldName, '_id')) {
            $relatedTable = str_replace('_id', '', $fieldName);
            return Str::plural($relatedTable);
        }
        return Str::plural($fieldName);
    }

    protected function generateValidationRules(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];

        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }

        // Called once, up front, so an invalid json_rules declaration fails
        // loudly here regardless of which field is iterated first.
        $jsonRules = ModuleConfigContract::jsonRules($this->config);

        // Columns marked via IntrospectionToConfig's file_columns meta (threaded
        // to the config's top level -- see IntrospectionToConfig::build()) need a
        // 'file' validation rule instead of whatever FK/integer rule would
        // otherwise apply to their (typically unsignedBigInteger *_media_id)
        // column type. This is the single source of truth for that override --
        // checked here regardless of whatever rule string the field entry
        // itself already carries, so it applies whether that entry came from
        // IntrospectionToConfig::buildBackendFields() or a hand-authored config.
        $fileColumns = $this->config['file_columns'] ?? [];

        // Enum columns: IntrospectionToConfig::buildBackendFields()
        // (src/Schema/IntrospectionToConfig.php) builds each field's `rules`
        // string purely from its normalized DB type -- it has no enum branch,
        // so an enum column's field entry here never carries an `in:`/allowed
        // -values constraint, even though its allowed values ARE known (see
        // IntrospectionToConfig::buildColumn(), which threads `enum_values`
        // onto the top-level $config['columns'] entry for exactly this
        // column). Cross-reference that here by field name -- same pattern as
        // the $fileColumns override above -- so the constraint applies
        // whether the field's own `rules` string came from introspection or a
        // hand-authored config.
        $enumValuesByField = [];
        // Same cross-reference for foreignId columns' related module (see
        // the exists:-rebuild block below, right after the unique: rebuild).
        // V1's frontend (generateColumnsValidations() in generator.ts)
        // computes each field's `exists:{table},id` rule once, the first
        // time its Backend Features step mounts -- if a column's
        // `relatedModule` is set or changed AFTER that (e.g. the user
        // returns to Table Columns and only then picks the FK's related
        // module), the stale, already-baked rule string survives untouched,
        // producing garbage table names like `exists:itemcategories,id`
        // (mashed together, no `Str::plural`+`Str::snake`) instead of the
        // real `item_categories`. Confirmed live via the retail-ERP demo
        // fixture: this table name doesn't exist, so `exists:` always
        // rejects a legitimately valid ID and any generated PHPUnit
        // fixture/factory built from it (PhpUnitTestGenerator::
        // resolveCrossModuleFkLiteral()) can never resolve the real target
        // module either. Rebuilt here from the authoritative
        // `relatedModule` + naming convention (Str::snake(Str::plural(...)))
        // instead of trusting whatever table name the frontend embedded --
        // correct regardless of when/whether the frontend's own field
        // recomputed.
        $relatedModuleByField = [];
        foreach (($this->config['columns'] ?? []) as $col) {
            $colName = $col['name'] ?? '';
            if ($colName !== '' && !empty($col['enum_values']) && is_array($col['enum_values'])) {
                $enumValuesByField[$colName] = $col['enum_values'];
            }
            if ($colName !== '' && ($col['type'] ?? '') === 'foreignId' && !empty($col['relatedModule'])) {
                $relatedModuleByField[$colName] = $col['relatedModule'];
            }
        }

        $rules = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';

            if ($fieldName !== '' && in_array($fieldName, $fileColumns, true)) {
                // Edit always allows an optional re-upload (see
                // BaseServiceGenerator::generateFileColumnUploads()'s edit
                // branch, which keeps the model's existing media_id untouched
                // when no new file is sent) -- matches the hand-written
                // MobileReleasesEditService reference pattern, where every
                // file field is optional on edit regardless of the column's
                // own DB nullability. Create keeps 'required' unless the
                // column's own generated rule was already 'nullable'.
                $isRequired = !$edit && !str_contains($field['rules'] ?? '', 'nullable');
                $ruleArray = [$isRequired ? 'required' : 'nullable', 'file'];
                $ruleStrings = array_map(fn($rule) => "\"{$rule}\"", $ruleArray);
                $rules[] = "'{$fieldName}' => [" . implode(', ', $ruleStrings) . ']';
                continue;
            }

            $fieldRules = $field['rules'] ?? '';

            if (!empty($fieldName) && !empty($fieldRules)) {
                // Split rules string into array
                // Laravel validation rules use pipe (|) as separator
                // Split on pipe and trim each rule
                $ruleArray = array_map('trim', explode('|', $fieldRules));
                $ruleArray = array_filter($ruleArray, fn($rule) => !empty($rule));

                // Edit-service unique rules must exclude the record being edited
                // (unique:table,column,{$model->id}) or saving an unchanged record
                // trips over its own existing value. Config authors mark this with
                // a "unique:table,column,__ID__"-style rule; rebuild it here via the
                // same logic ValidationGenerator::processUniqueRule() already
                // implements. Create services never get this — there's no existing
                // record to exclude — so this only runs when $edit is true.
                if ($edit) {
                    $ruleArray = array_map(
                        fn($rule) => str_starts_with($rule, 'unique:')
                            ? ValidationGenerator::processUniqueRule($rule, $fieldName, true)
                            : $rule,
                        $ruleArray
                    );
                }

                $relatedModule = $relatedModuleByField[$fieldName] ?? null;
                if ($relatedModule !== null) {
                    // Prefer the related module's REAL, configured table_name
                    // (see PathManager::resolveTableNameForModule()'s own
                    // docblock) — the naming-convention guess only when the
                    // registry has no entry for it at all.
                    $correctTable = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveTableNameForModule($relatedModule)
                        ?? Str::snake(Str::plural($relatedModule));
                    $ruleArray = array_map(
                        fn($rule) => preg_match('/^exists:[^,]+,(.+)$/', $rule, $m)
                            ? "exists:{$correctTable},{$m[1]}"
                            : $rule,
                        $ruleArray
                    );
                }

                $ruleStrings = array_map(fn($rule) => "\"{$rule}\"", $ruleArray);

                // Append the allowed-values constraint as its own array entry,
                // NOT folded into $ruleArray/$ruleStrings above -- those two
                // are string rules ("required", "max:255", ...), each wrapped
                // in double quotes as a Laravel pipe-style rule literal. A
                // flat "in:a,b,c" string in that same style can't safely
                // represent a value containing a comma or pipe (both are
                // syntactically significant to Laravel's colon-rule parser),
                // so \Illuminate\Validation\Rule::in() is used instead --
                // fully qualified so no `use` import needs adding to the
                // Service stub templates. Composes for free with
                // required/nullable: Laravel already skips all other rules,
                // Rule::in() included, when a nullable field's value is null.
                // var_export(), not addslashes(), escapes each value for its
                // single-quoted PHP-array-literal context here -- addslashes()
                // also escapes `"`, which a single-quoted PHP string doesn't
                // recognize as an escape, so the backslash would survive
                // literally in the value (same reasoning as
                // FactoryGenerator's enum branch, which sets this precedent).
                $enumValues = $enumValuesByField[$fieldName] ?? null;
                if (is_array($enumValues) && !empty($enumValues)) {
                    $literalValues = implode(', ', array_map(
                        static fn($v) => var_export((string) $v, true),
                        $enumValues
                    ));
                    $ruleStrings[] = "\\Illuminate\\Validation\\Rule::in([{$literalValues}])";
                }

                // A location-bearing module's own location_id write must
                // reject a value the acting user cannot reach (plan 039).
                // class_exists()-guarded spread, not a plain array entry, so
                // an app whose BACKEND predates App\Project\_Src\Rules\
                // AccessibleLocation (older shell, no composer update yet)
                // gets byte-identical rules to before this feature existed --
                // same safety convention as applyRecordScope()'s method_exists()
                // guard (plan 031).
                if ($fieldName === 'location_id' && ModuleConfigContract::isLocationBearing($this->config)) {
                    $ruleStrings[] = "...(class_exists('App\\Project\\_Src\\Rules\\AccessibleLocation') ? [new \\App\\Project\\_Src\\Rules\\AccessibleLocation()] : [])";
                }

                $rulesArrayStr = '[' . implode(', ', $ruleStrings) . ']';
                $rules[] = "'{$fieldName}' => {$rulesArrayStr}";

                // json_rules (plan 035): a json column's declared nested
                // shape becomes one extra top-level rule entry per path,
                // e.g. 'params.windows.*.start' => [...] -- Laravel resolves
                // these against the same $data array the column's own
                // 'params' => [...] rule validates, no special wiring
                // needed. Single-quoted var_export() output (never double
                // quotes) so a literal '$' in a rule argument never
                // interpolates.
                if (isset($jsonRules[$fieldName])) {
                    foreach ($jsonRules[$fieldName]['rules'] as $path => $pathRules) {
                        $key = var_export("{$fieldName}.{$path}", true);
                        $value = '[' . implode(', ', array_map(static fn ($r) => var_export($r, true), $pathRules)) . ']';
                        $rules[] = "{$key} => {$value}";
                    }
                }
            }
        }

        return '[' . implode(",\n            ", $rules) . ']';
    }

    /**
     * Emit inline PHP, injected into beforeCreate()/beforeUpdate() ahead of any
     * other before-save processing, that converts each file_columns-marked
     * field's raw UploadedFile into a Media row and stores the returned int id
     * back into $validData under the SAME column key.
     *
     * Wire-key contract: BaseComponentGenerator::generateSubmitCall() (the
     * frontend generator) sends the raw File under a FormData key equal to the
     * column's own name (e.g. 'image_media_id') -- fileRefName() only names the
     * local Vue `ref`, never the wire key (see its docblock). Laravel's
     * Request::all() -- what every generated Controller passes as $data --
     * merges $this->files->all() into that same array under that same key, so
     * by the time $data reaches validator()->validate() with the 'file' rule
     * generateValidationRules() emits for these columns, $validData[$column]
     * already holds the UploadedFile instance directly. No separate $params
     * plumbing is needed the way the hand-written MobileReleasesCreateService
     * uses (its 'apk_file'/'ota_file' $params keys predate this generator
     * feature and don't reflect its wire contract).
     *
     * Edit ($isEdit = true) mirrors the hand-written MobileReleasesEditService
     * reference pattern: only convert + overwrite when a new file was actually
     * supplied; otherwise unset the key so $model->update() never touches that
     * column, leaving its existing media_id in place. (MobileReleasesEditService
     * additionally deletes the old media row once the new one is saved --
     * deliberately NOT replicated here; see the generator-engine task notes for
     * this feature.)
     */
    protected function generateFileColumnUploads(bool $isEdit): string
    {
        $fileColumns = $this->config['file_columns'] ?? [];
        if (empty($fileColumns)) {
            return '';
        }

        $featureKey = $isEdit ? 'edit' : 'create';
        $configuredFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        $configuredNames = array_map(fn($f) => $f['field'] ?? '', $configuredFields);

        $lines = [];
        foreach ($fileColumns as $column) {
            if (!is_string($column) || $column === '' || !in_array($column, $configuredNames, true)) {
                // Not one of this feature's own fields -- e.g. a stray/typo'd
                // --file-columns entry, or one meant for a different module.
                continue;
            }

            $lines[] = "if ((\$validData['{$column}'] ?? null) instanceof \\Illuminate\\Http\\UploadedFile) {";
            $lines[] = "    \$validData['{$column}'] = \\App\\Project\\Modules\\Core\\Media\\Services\\MediaService::createFile(\$validData['{$column}'], Auth::id());";
            if ($isEdit) {
                $lines[] = "} else {";
                $lines[] = "    unset(\$validData['{$column}']);";
            }
            $lines[] = "}";
        }

        return empty($lines) ? '' : implode("\n        ", $lines);
    }

    protected function generateValidationMessages(bool $edit = false): string
    {
        // Use feature-specific fields (create or edit)
        $featureKey = $edit ? 'edit' : 'create';
        $featureFields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];
        
        if (empty($featureFields)) {
            return '[]'; // Return empty if no fields configured
        }
        
        $messages = [];
        foreach ($featureFields as $field) {
            $fieldName = $field['field'] ?? '';
            $fieldMessages = $field['messages'] ?? [];
            
            if (!empty($fieldName) && !empty($fieldMessages)) {
                foreach ($fieldMessages as $message) {
                    $ruleKey = $message['ruleKey'] ?? '';
                    $messageText = $message['message'] ?? '';
                    
                    if (!empty($ruleKey) && !empty($messageText)) {
                        $messages[] = "'{$fieldName}.{$ruleKey}' => '{$messageText}'";
            }
        }
            }
        }
        
        return '[' . implode(",\n            ", $messages) . ']';
    }

    protected function generateSplashData(string $feature): string
    {
        // Get splash configuration from new path: features.backend
        $backendConfig = $this->config['features']['backend'][$feature] ?? [];
        $splashConfig = $backendConfig['splashData'] ?? [];

        $splashData = [];

        foreach ($splashConfig as $source) {
            $key = $source['key'];
            $type = $source['type'] ?? 'model';
            $paginate = $source['paginate'] ?? true; // Default to paginated
            $module = $source['module'] ?? '';
            $moduleGroup = $source['moduleGroup'] ?? 'Core';

            if ($type === 'custom') {
                // Custom static data - no pagination needed
                $dataArray = $this->formatCustomDataArray($source['data'] ?? []);
                $splashData[] = "'{$key}' => {$dataArray}";
            } else {
                // Model-based data
                if (empty($module)) {
                    continue;
                }
                
                // Check if this should be paginated
                if ($paginate) {
                    // Return API endpoint info instead of static data
                    // Frontend will call the endpoint directly
                    // Convert key to kebab-case for API endpoint (route pattern requires [a-z-]+)
                    // Str::kebab() only handles PascalCase, so convert snake_case manually
                    $endpointKey = str_replace('_', '-', $key);
                    $splashData[] = "'{$key}' => ['type' => 'api', 'endpoint' => '/select/{$endpointKey}']";
                } else {
                    // Static data (non-paginated)
                    $columns = $source['columns'] ?? ['id', 'name'];
                $columnsStr = is_array($columns) ? implode("', '", $columns) : $columns;
                $splashData[] = "'{$key}' => {$module}Model::select('{$columnsStr}')->get()->toArray()";
                }
            }
        }

        return implode(",\n            ", $splashData);
    }

    protected function formatCustomDataArray(array $data): string
    {
        if (empty($data)) {
            return '[]';
        }

        $formatted = [];
        foreach ($data as $item) {
            $itemStr = [];
            foreach ((array) $item as $k => $v) {
                $itemStr[] = var_export((string) $k, true) . ' => ' . $this->customDataLiteral($v);
            }
            $formatted[] = '[' . implode(', ', $itemStr) . ']';
        }

        return '[' . implode(', ', $formatted) . ']';
    }

    /**
     * A PHP literal for one value of a `type: custom` splash source. Strings go through var_export() so a
     * quote or a backslash in the data cannot break out of the literal -- `Washer 'M8'` used to be emitted
     * as `'Washer 'M8''`, a parse error that took the whole create/edit splash route down. A nested list
     * or object becomes a real PHP array (an object used to be json_encode()d, which is not PHP).
     */
    private function customDataLiteral(mixed $value): string
    {
        if (is_array($value)) {
            $pairs = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $pairs[] = ($isList ? '' : var_export((string) $key, true) . ' => ') . $this->customDataLiteral($item);
            }

            return '[' . implode(', ', $pairs) . ']';
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }

    protected function arrayToString(array $array): string
    {
        if (empty($array)) {
            return '[]';
        }

        $lines = ["["];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Numeric array
                    $valueStrings = [];
                    foreach ($value as $rule) {
                        if (is_array($rule)) {
                            $valueStrings[] = $this->arrayToString($rule);
                        } else {
                            $valueStrings[] = '"' . addslashes($rule) . '"';
                        }
                    }
                    $valueString = '[' . implode(', ', $valueStrings) . ']';
                } else {
                    // Associative array
                    $valueString = $this->arrayToString($value);
                }
            } else {
                $valueString = '"' . addslashes($value) . '"';
            }
            $lines[] = "\t\t\t\t'{$key}' => {$valueString},";
        }
        $lines[] = "\t\t\t]";

        return implode("\n", $lines);
    }

    /**
     * Emit PHP code to invoke all processors matching (operation, stage) for the current module.
     * Target processor services must expose static methods matching the camelCase of the stage:
     *   public static function beforeSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterSave(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function beforeDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *   public static function afterDelete(array $data, ?Model $model, array $fields = [], array $config = []): array
     *
     * Generated code assigns the return value back to $validData so processors can transform data.
     *
     * @param string $op Operation: 'create', 'edit', 'delete'
     * @param string $stage Stage: 'before_save', 'after_save', 'before_delete', 'after_delete'
     * @return string Indented code block suitable for stub placeholder
     */
    protected function generateProcessorCalls(string $op, string $stage): string
    {
        $processors = $this->config['processors'] ?? [];
        $lines = [];

        foreach ($processors as $p) {
            if (!in_array($op, $p['operations'] ?? [], true)) {
                continue;
            }
            if (($p['stage'] ?? '') !== $stage) {
                continue;
            }

            $service = $p['service'] ?? null;
            $module = $p['module'] ?? null;

            if (!$service || !$module) {
                continue;
            }

            $method = Str::camel($stage); // before_save -> beforeSave
            // Resolve full namespace from DB (module_type + group) instead of trusting
            // $p['moduleGroup'] — the UI stores this inconsistently (sometimes the sub-group
            // "Accounting", sometimes the module_type "System"). DB lookup is authoritative.
            $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
            $namespace = "{$ns}\\Services\\{$service}";
            $fields = json_encode($p['fields'] ?? []);
            $config = json_encode($p['config'] ?? new \stdClass());

            // Method signature target must accept (array $data, ?Model $model, array $fields, array $config): array
            // Generated call passes data, model (null or variable), fields array, config array.
            //
            // $model is passed wherever the owning stub actually has one in scope. The ONLY
            // injection point that genuinely has no model is beforeCreate() -- the row does not
            // exist yet (see create/service.stub, whose signature is `beforeCreate($validData)`).
            // beforeUpdate($validData, array $validParams, {Module}Model $model) does have one
            // (edit/service.stub), and until 2026-08-25 this line passed a literal `null` there
            // anyway, so an edit-time before_save processor could not see the stored row at all.
            // That made the single most useful thing such a processor does -- compare submitted
            // data against what is currently persisted (guards, locks, derived values that must
            // not be re-derived on edit) -- impossible without a caller-side backtrace hack.
            //
            // Note this makes `$model === null` at before_save a RELIABLE "this is a create"
            // signal, which it previously was not: null meant "create, or edit, indistinguishably".
            $modelArg = ($op === 'create' && $stage === 'before_save') ? 'null' : '$model';

            // Bug (found 2026-08-15/16 running the retail-ERP demo fixture's own generated
            // PHPUnit suite live): this hardcoded `$validData` regardless of which local
            // variable is actually in scope at the injection point — correct for
            // beforeCreate/afterCreate/beforeUpdate (all declared `$validData` in their
            // owning stub), but `afterUpdate`/`beforeDelete`/`afterDelete` are declared
            // `$data` (see edit/service.stub, delete/service.stub) -- an
            // "Undefined variable $validData" fatal on every edit with an after_save
            // processor, confirmed live via ExpensesEditServiceTest. The local variable
            // name is a function of (op, stage), not a generator-wide constant.
            $var = ($op === 'delete' || ($op === 'edit' && $stage === 'after_save')) ? 'data' : 'validData';

            $lines[] = "// Processor: {$module}\\{$service}::{$method}";
            $lines[] = "\${$var} = \\{$namespace}::{$method}(\${$var}, {$modelArg}, json_decode('{$fields}', true), json_decode('{$config}', true));";
        }

        return empty($lines) ? '' : implode("\n        ", $lines);
    }

    // ─── Inline Items helpers ─────────────────────────────────────────────────

    /**
     * Resolve the PHP namespace for a child module used in inline-items generation.
     *
     * Bug (fixed 2026-08-03): this used to force any $childGroup other than
     * exactly 'Core' or 'System' to nest under `App\Project\Modules\System\
     * {group}\...`. That was replaced with hand-assembling from the config's
     * own `child_group`/`child_group_name` strings -- but confirmed via a
     * real generate-and-inspect pass (the retail-ERP demo fixture, Items ->
     * ItemImages) that this still produces a wrong, truncated namespace:
     * V1's own wizard (Section11_InlineItems.vue) only ever populates
     * `child_group` with the module's *group* (e.g. "Demo"), never
     * `child_group_name`, so the module's `module_type` segment ("System")
     * is silently dropped -- `Modules\Demo\ItemImages` instead of the real
     * `Modules\System\Demo\ItemImages`. Every CreateService/EditService/
     * ViewService/DeleteService call touching an inline_items child would
     * fatal with a "class not found" error.
     *
     * Fixed by routing through the same authoritative registry lookup
     * `generateProcessorCalls()` already uses for the identical problem
     * (`PathManager::resolveBackendModuleNamespace()`) instead of trusting
     * hand-typed group strings the UI has no reliable way to populate
     * correctly. Only `$childModule` is needed -- the registry (or
     * `default_modules.json`, or the project's own persisted registry
     * files) is the source of truth for a module's real namespace, not the
     * inline_items config.
     */
    protected function buildChildNamespace(string $childModule): string
    {
        return \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($childModule);
    }

    /**
     * Generate the extract block placed before validateData() in execute().
     * Pulls each inline-items key out of $data into $inlineData before validation
     * so the parent validator never sees the child-record arrays -- then validates
     * the rows themselves.
     *
     * The rows used to go from the request straight into `{Child}Model::create(array_merge($row, [...]))`.
     * BaseModel is `guarded = []` (mass-assignment protection is the service layer's job), so a client
     * could set ANY column of the child model -- its id, its timestamps, a status or approval flag -- and
     * a row missing a `required` inline field reached the database and came back as a 500. Now every row
     * is checked against the inline `fields` declaration and reduced to the keys it declares (plus `uuid`,
     * which the Edit sync matches on); Arr::only() does the reducing so it does not depend on the app
     * enabling Laravel's excludeUnvalidatedArrayKeys.
     */
    protected function generateInlineItemsExtract(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $lines = [];
        $rules = [];
        $reduce = [];
        foreach ($inlineItems as $item) {
            $key = $item['key'];
            $lines[] = "\$inlineData['{$key}'] = \$data['{$key}'] ?? [];";
            $lines[] = "unset(\$data['{$key}']);";

            $rules[] = "'{$key}' => ['array']";
            $rules[] = "'{$key}.*' => ['array']";
            $rules[] = "'{$key}.*.uuid' => ['nullable', 'string']";
            $declared = ['uuid'];
            foreach ($item['fields'] ?? [] as $field) {
                $fieldKey = $field['key'] ?? null;
                if (!is_string($fieldKey) || $fieldKey === '') {
                    continue;
                }
                $declared[] = $fieldKey;
                $rules[] = "'{$key}.*.{$fieldKey}' => " . $this->inlineFieldRuleLiteral($field);
            }
            $only = implode(', ', array_map(fn (string $k) => "'{$k}'", array_values(array_unique($declared))));
            $reduce[] = "\$inlineData['{$key}'] = array_map(fn (\$row) => \\Illuminate\\Support\\Arr::only(\$row, [{$only}]), \$inlineData['{$key}']);";
        }

        $lines[] = "validator(\$inlineData, [\n                " . implode(",\n                ", $rules) . ",\n            ])->validate();";
        foreach ($reduce as $line) {
            $lines[] = $line;
        }

        return implode("\n            ", $lines);
    }

    /**
     * The validation rules of ONE inline field, as a PHP list literal, from its declaration: `required`
     * (or `nullable`) plus a type rule where the field's type says something reliable. A select's value can
     * be a string or an id depending on where its options come from, so it gets no type rule.
     */
    protected function inlineFieldRuleLiteral(array $field): string
    {
        $rules = [($field['required'] ?? false) ? 'required' : 'nullable'];

        $type = (string) ($field['type'] ?? 'input');
        $fieldType = (string) ($field['field_type'] ?? '');

        if (in_array($type, ['number', 'decimal'], true) || $fieldType === 'number') {
            $rules[] = 'numeric';
        } elseif (in_array($type, ['checkbox', 'switch', 'boolean'], true)) {
            $rules[] = 'boolean';
        } elseif (in_array($type, ['date', 'datepicker'], true) || $fieldType === 'date') {
            $rules[] = 'date';
        } elseif ($type === 'api-select') {
            $rules[] = 'integer';
        } elseif (!in_array($type, ['select'], true)) {
            $rules[] = 'string';
        }

        return '[' . implode(', ', array_map(fn (string $r) => "'{$r}'", $rules)) . ']';
    }

    /**
     * Build the array_merge() injection block for a single inline item:
     * parent_fk + any inject_from_parent fields + (opt-in) a creator/updater
     * audit column.
     *
     * Bug (found + fixed 2026-08-02): this never set created_by_id/
     * updated_by_id on the child rows it creates/updates, even though the
     * project's own standard convention (see ModuleConfigContract::
     * hasCreatorUpdater()) is that every module has these columns and they
     * are NOT NULL. Confirmed via a live `OrdersCreateService::execute()`
     * call against a real DB: creating an Order with nested order_items
     * fatal-errored with "Field 'created_by_id' doesn't have a default
     * value" -- this is the mainline case (has_creator_updater defaults to
     * true project-wide), not an edge case, so every real inline_items
     * child using the standard convention was broken until now.
     *
     * Bug (found + fixed again 2026-08-21): the opt-in `child_has_creator_updater`
     * flag introduced by the 2026-08-02 fix above is easy to simply forget to
     * set -- inline_items is hand-authored on the PARENT's config, and nothing
     * forces the author to go check the CHILD module's own has_creator_updater
     * setting. Confirmed live: a real PurchaseOrders->PurchaseOrderItems
     * inline_items entry never set the flag despite PurchaseOrderItems having
     * has_creator_updater: true, fatal-erroring on every real submit with zero
     * PHPUnit coverage (the generated contract test never exercises the
     * inline_items save path with real child rows).
     *
     * The child module's own schema IS now visible from here, via the same
     * module registry ModelGenerator::generateInverseHasManyRelationships()
     * already reads (PathManager::findModuleInRegistry() / registry entries
     * carry a 'has_creator_updater' key as of this fix -- see each consuming
     * app's registry-population call sites, e.g. MakeModulesFromDb.php /
     * ModuleScaffolder::buildModuleRegistryFromFs()). So `child_has_creator_updater`
     * is now only a manual OVERRIDE (explicit true/false wins outright); when
     * absent, the child module's own real registry value is used; only when
     * the registry has no entry for the child at all does this fall back to
     * false, preserving prior behavior for a registry that predates this fix.
     *
     * @param string|null $auditField 'created_by_id' or 'updated_by_id', or
     *                                 null to omit any audit column (used by
     *                                 EditServiceGenerator's create vs.
     *                                 update-via-updateOrCreate branches,
     *                                 which need different columns -- see
     *                                 generateInlineItemsSync()).
     */
    protected function buildInlineInjectArray(array $item, ?string $auditField = null): string
    {
        $pairs   = [];
        $pairs[] = "'{$item['parent_fk']}' => \$model->id";

        foreach ($item['inject_from_parent'] ?? [] as $mapping) {
            $childField  = $mapping['child_field'];
            $parentField = $mapping['parent_field'];
            $pairs[] = "'{$childField}' => \$model->{$parentField}";
        }

        if ($auditField !== null && $this->childHasCreatorUpdater($item)) {
            $pairs[] = "'{$auditField}' => Auth::id()";
        }

        return "[\n                " . implode(",\n                ", $pairs) . ",\n            ]";
    }

    /**
     * Resolve whether an inline_items child module has creator/updater audit
     * columns -- an explicit `child_has_creator_updater` on the item config
     * always wins; otherwise derived from the child module's own real,
     * already-known registry entry. See buildInlineInjectArray()'s docblock.
     */
    protected function childHasCreatorUpdater(array $item): bool
    {
        if (array_key_exists('child_has_creator_updater', $item)) {
            return (bool) $item['child_has_creator_updater'];
        }

        $childModule = $item['child_module'] ?? null;
        if ($childModule === null) {
            return false;
        }

        $entry = \Blutrixx\GeneratorEngine\Generators\PathManager::findModuleInRegistry($childModule);
        if ($entry === null) {
            return false;
        }

        $childConfig = $entry['config'] ?? $entry;

        return (bool) ($childConfig['has_creator_updater'] ?? false);
    }

    protected function generateCustomFieldProcessing(string $featureKey, string $stage = 'before'): string
    {
        $fields = $this->config['features']['backend'][$featureKey]['fields'] ?? [];

        $lines = [];
        foreach ($fields as $field) {
            $fieldName = $field['field'] ?? '';
            $service = $field['processing_service'] ?? null;
            $module = $field['processing_module'] ?? null;
            $fieldStage = $field['processing_stage'] ?? 'before';

            if ($service && $module && $fieldStage === $stage) {
                // Resolve full namespace from DB (module_type + group). The config
                // field 'processing_module_group' cannot be trusted as the top-level group —
                // DB lookup via PathManager is authoritative.
                $ns = \Blutrixx\GeneratorEngine\Generators\PathManager::resolveBackendModuleNamespace($module);
                $namespace = "{$ns}\\Services\\{$service}";
                $rules = $field['rules'] ?? '';
                $isArray = str_contains($rules, 'array');

                // Bug (found 2026-08-15 via the retail-ERP demo fixture):
                // the processing_service's return value was never assigned
                // back to $validData — the hook fired but any
                // transformation it made was silently discarded, so
                // processing_service could never actually change what got
                // saved (only side effects, e.g. logging, ever "worked").
                // Assign the return value back, same as the analogous
                // processors[] call (generateProcessorCalls() a few hundred
                // lines up) already correctly does.
                $lines[] = "// Processing {$fieldName} using {$service}";
                $lines[] = "if (isset(\$validData['{$fieldName}'])) {";
                if ($isArray) {
                    $lines[] = "    \$validData['{$fieldName}'] = array_map(";
                    $lines[] = "        fn(\$item) => \\{$namespace}::process(\$item),";
                    $lines[] = "        \$validData['{$fieldName}']";
                    $lines[] = "    );";
                } else {
                    $lines[] = "    \$validData['{$fieldName}'] = \\{$namespace}::process(\$validData['{$fieldName}']);";
                }
                $lines[] = "}";
            }
        }

        return empty($lines) ? '// No custom field processing' : implode("\n        ", $lines);
    }
}

