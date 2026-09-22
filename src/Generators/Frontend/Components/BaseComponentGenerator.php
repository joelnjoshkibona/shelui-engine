<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\BulkActionConfigNormalizer;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

abstract class BaseComponentGenerator extends BaseGenerator
{
    protected function getModulePath(): string
    {
        return PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
    }

    /**
     * shelui-engine fork: this module's own record-identifier route-param/
     * prop name -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'.
     * `features.frontend.view.idParam` (a pre-fork escape hatch already used
     * by FrontendRoutesGenerator/ViewLayoutGenerator/ViewHistoryGenerator)
     * still always wins when explicitly set; this is only the smart default
     * it falls back to instead of the literal 'uuid' every one of those
     * previously hardcoded independently. The single shared resolution
     * point every Frontend component generator (they all extend this class)
     * should call instead of recomputing its own copy -- mirrors
     * ModuleConfigContract's own "one place, not N independently-derived
     * copies" rationale, and FrontendRoutesGenerator::$idParam (the
     * identical constructor-time property on the one Frontend generator
     * that does NOT extend this class).
     */
    protected function idParam(): string
    {
        return $this->config['features']['frontend']['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');
    }

    /**
     * shelui-engine fork: the RELATED module's own record-identifier prop
     * name for a `RelatedRecordLink` emitted by this module's FK cell
     * renderer (see generateCustomCellRenderersFromListFields() below) --
     * the twin of idParam() above, but resolved for a DIFFERENT module than
     * $this->config, via PathManager::findModuleInRegistry() (the same
     * registry-lookup shape BaseServiceGenerator::resolveChildAuditColumn()
     * already established for a delegation child's own audit columns).
     * Defaults to 'uuid' -- not idParam()'s hasUuid()-driven default -- when
     * the related module can't be resolved (not yet generated/registered,
     * or an empty/self-referential $relatedModule), because 'uuid' is the
     * literal this replaces and RelatedRecordLink already degrades to inert
     * text for an unregistered target regardless of which prop name it
     * receives.
     */
    protected function resolveRelatedIdParam(string $relatedModule): string
    {
        if ($relatedModule === '') {
            return 'uuid';
        }

        $entry = PathManager::findModuleInRegistry($relatedModule);
        if ($entry === null) {
            return 'uuid';
        }

        $relatedConfig = $entry['config'] ?? $entry;

        return $relatedConfig['features']['frontend']['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($relatedConfig) ? 'uuid' : 'id');
    }

    /**
     * Generate a human-readable label from a field name
     * Converts snake_case to Title Case, removes " Id" and " At" suffixes
     * 
     * @param string $fieldName The field name (e.g., "first_name", "country_id", "created_at")
     * @return string The formatted label (e.g., "First Name", "Country", "Created")
     */
    protected function generateFieldLabel(string $fieldName): string
    {
        // Remove common suffixes first
        $fieldName = preg_replace('/_id$/', '', $fieldName); // Remove "_id" suffix
        $fieldName = preg_replace('/_at$/', '', $fieldName); // Remove "_at" suffix
        
        // Convert snake_case to Title Case
        $label = str_replace('_', ' ', $fieldName);
        $label = ucwords($label);
        
        // Remove any remaining " Id" or " At" (in case they weren't at the end)
        $label = str_replace(' Id', '', $label);
        $label = str_replace(' At', '', $label);
        
        // Trim any extra spaces
        $label = trim($label);
        
        return $label;
    }

    /**
     * Format a placeholder string, removing underscores and formatting field names
     * If placeholder contains underscores or field-like patterns, format them
     * 
     * @param string $placeholder The placeholder text (e.g., "Enter first_name", "Enter country_id")
     * @param string|null $fieldKey Optional field key to use for formatting if placeholder is generic
     * @return string The formatted placeholder (e.g., "Enter First Name", "Enter Country")
     */
    protected function formatPlaceholder(string $placeholder, ?string $fieldKey = null): string
    {
        // If placeholder contains underscores, find and replace all field-like patterns
        if (strpos($placeholder, '_') !== false) {
            // Find all words with underscores (field-like patterns)
            // Match patterns like: first_name, country_id, created_at, etc.
            $placeholder = preg_replace_callback('/\b([a-z][a-z0-9_]*[a-z0-9])\b/i', function($matches) {
                $word = $matches[1];
                // Only format if it contains underscore (looks like a field name)
                if (strpos($word, '_') !== false) {
                    return $this->generateFieldLabel($word);
                }
                return $word;
            }, $placeholder);
        } elseif ($fieldKey && strpos($placeholder, $fieldKey) !== false) {
            // If placeholder contains the field key but no underscores, check if field key has underscores
            if (strpos($fieldKey, '_') !== false) {
                $formattedKey = $this->generateFieldLabel($fieldKey);
                $placeholder = str_replace($fieldKey, $formattedKey, $placeholder);
            }
        }
        
        return $placeholder;
    }

    protected function generateColumnsFromListFields(array $fields, ?string $primaryKey = null): string
    {
        $columns = [];
        $moduleRoute = Str::kebab($this->moduleName);

        // Get primary field from parameter, config, or use first field
        if ($primaryKey === null) {
            $listConfig = $this->config['features']['frontend']['list'] ?? [];
            $primaryFieldKey = $listConfig['primaryField'] ?? '';

            // If no explicit primaryField, use first field
            if (empty($primaryFieldKey) && !empty($fields)) {
                $firstField = $fields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? '';
            }
            $primaryKey = $primaryFieldKey;
        }

        // No dedicated ID column is emitted (fixed 2026-07-26): a raw internal
        // "id"/"uuid" integer/string is meaningless to end users and every
        // hand-built reference list (Users, LocationTypes, Locations) omits
        // one. "id" (and, for uuid-keyed modules, "uuid") remains backend
        // sortable/filterable via BaseServiceGenerator::generateFilterableFields()
        // / generateSortableFields() / generateFilterFields() and via the
        // crud.e2e.stub's `?sort=id&order=desc` navigation — none of that
        // depends on a visible frontend column, so removing this entry is safe
        // (see the e2e specs' own `?sort=id&order=desc` navigation, e.g.
        // create.e2e.stub/fixtures.e2e.stub).

        // Emit ReportColumn-shaped objects (see @/components/report-table): the
        // header text is `label` (NOT `title`) and width is in pixels. The report
        // table handles horizontal scroll + column visibility itself, so no
        // responsive `hidden lg:table-cell` classes are needed.
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';
            $sortable = $field['sortable'] ?? true;
            $i18nKey = "{$moduleRoute}.col_{$key}";

            if ($key === $primaryKey) {
                // Primary column pinned left (fixed: true) so it stays visible
                // while scrolling -- keeps its explicit width (unlike every
                // other column below, 2026-08-16): ReportTable.vue's sticky-
                // offset math and its own `width` docblock both call this out
                // as required specifically for fixed columns.
                $columns[] = "{ key: \"{$key}\", label: t('{$i18nKey}'), sortable: true, fixed: true, width: 150 }";
            } else {
                $sortableStr = $sortable ? 'true' : 'false';
                // Relation (FK) columns stay visible by default, same as every
                // other column — Locations' list shows location_type_id and
                // parent_id, and Users' list shows status_id, both un-hidden.
                // Hiding them here previously stopped RelatedRecordLink (which
                // lives inside these columns) from ever rendering.
                //
                // Optional opt-out: a field may set 'defaultVisible' => false
                // in features.frontend.list.fields[] to start hidden behind
                // ReportTable.vue's existing "View" column-visibility toggle
                // (already ships project-wide — this just wires the generator
                // up to it). Omitted entirely unless explicitly false, so
                // every already-generated file's output is unchanged byte-
                // for-byte — matches ReportColumn.defaultVisible's own "omit
                // means true" contract, no new default to document twice.
                // Never applied to the primary/fixed column above: ReportTable
                // excludes fixed columns from configurableColumns entirely
                // (they're always shown, pinned), so the flag would be a
                // silent no-op there.
                // No fixed width (2026-08-16): unlike the primary/fixed column
                // above, a non-pinned column has no sticky-offset math that
                // needs one -- ReportTable.vue's own `width?: number` docblock
                // says as much ("Required for fixed columns; helps layout for
                // all columns", not required for the rest), and only sets an
                // explicit CSS width when `col.width` is truthy, falling back
                // to natural/content-based sizing otherwise. Forcing every
                // column to the same 150px regardless of its actual content
                // (a short "Status" badge vs. a long free-text "Notes" field)
                // produced cramped, wrapping cells on every generated list.
                $defaultVisibleStr = ($field['defaultVisible'] ?? true) === false ? ', defaultVisible: false' : '';
                $columns[] = "{ key: \"{$key}\", label: t('{$i18nKey}'), sortable: {$sortableStr}{$defaultVisibleStr} }";
            }
        }

        // Add the actions column (View/Edit/Delete buttons) so it lines up with the
        // <template #cell-actions="{ row }"> slot emitted in list/page.stub. Skip it
        // if a field named "actions" was already supplied to avoid a duplicate key.
        $hasActionsColumn = false;
        foreach ($fields as $field) {
            $fieldKey = $field['key'] ?? $field['field'] ?? '';
            if ($fieldKey === 'actions') {
                $hasActionsColumn = true;
                break;
            }
        }
        if (!$hasActionsColumn) {
            $columns[] = "{ key: \"actions\", label: t('common.actions'), width: 120, align: 'right' }";
        }

        return implode(",\n\t", $columns);
    }

    public function getPrimaryListField(array $fields, ?string $primaryKey = null) {
        // Get primary field from parameter, config, or use first field
        if ($primaryKey === null) {
            $listConfig = $this->config['features']['frontend']['list'] ?? [];
            $primaryFieldKey = $listConfig['primaryField'] ?? '';

            // If no explicit primaryField, use first field
            if (empty($primaryFieldKey) && !empty($fields)) {
                $firstField = $fields[0];
                $primaryFieldKey = $firstField['key'] ?? $firstField['field'] ?? '';
            }
            $primaryKey = $primaryFieldKey;
        }

        return $primaryKey;
    }

    // $slotProp controls the destructured slot-prop name emitted for the
    // primary-field accessor and the mobile-sub field accessors. Defaults to
    // (and, as of 2026-08-05, every caller passes) 'row' — ListPageGenerator
    // (list/page.stub) and CustomFeatureTabComponentGenerator
    // (custom/tab_action.stub) both wrap <CrudListPanel> -> <ListTable> ->
    // <ReportTable>, which only ever exposes `:row="row"`.
    protected function generatePrimaryCellContentFromListFields(array $fields, ?string $primaryKey = null, string $slotProp = 'row'): string
    {
        $content = [];
        $primaryKey = $this->getPrimaryListField($fields, $primaryKey);
        $moduleRoute = Str::kebab($this->moduleName);

        // First item is the primary field itself — use data path if set (e.g. FK: customer?.name)
        $primaryField = null;
        foreach ($fields as $f) {
            if (($f['key'] ?? $f['field'] ?? '') === $primaryKey) {
                $primaryField = $f;
                break;
            }
        }
        $primaryDisplayPath = ($primaryField['data'] ?? null) ?: $primaryKey;
        $content[] = "<span class=\"font-bolder\">{{ {$slotProp}.{$primaryDisplayPath} }}</span>";

        // Generate responsive content for other fields marked with showOnMobileSub
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';

            // Skip primary field itself
            if ($key === $primaryKey) {
                continue;
            }

            // Only include if showOnMobileSub is true
            if (!($field['showOnMobileSub'] ?? false)) {
                continue;
            }

            $data = $field['data'] ?? null;

            // Use data path if available, otherwise use key
            $dataPath = $data ?? $key;

            // Handle different field types
            $content[] = "<div class=\"text-xs text-muted-foreground lg:hidden\">
\t\t\t\t{{ \$t('{$moduleRoute}.col_{$key}') }}: {{ {$slotProp}.{$dataPath} || 'N/A' }}
\t\t\t</div>";
        }

        return implode("\n\t\t\t", $content);
    }

    // $slotProp controls the destructured slot-prop name emitted in each
    // generated `<template #cell-XXX="{ ... }">` block. Defaults to (and,
    // as of 2026-08-05, every caller passes) 'row' — see
    // generatePrimaryCellContentFromListFields()'s docblock above for why.
    //
    // $includePrimaryKey: CustomFeatureTabComponentGenerator (tab_action.stub)
    // already hand-emits its own `<template #cell-{primaryKey}>` block built
    // from generatePrimaryCellContentFromListFields() — a SECOND renderer for
    // the same slot name from this method would just be a broken duplicate
    // Vue template, so that caller leaves this false (the original,
    // unconditional "always skip the primary key" behaviour). ListPageGenerator
    // (page.stub) has no such block at all: its primary column is just a
    // normal <ReportColumn> like any other, pinned via `fixed: true`. When the
    // primary field happens to be a plain scalar (the overwhelming majority of
    // modules — e.g. Roles.name) that's fine, since ReportTable.vue's default
    // `row[col.key]` fallback already renders it correctly and skipping is a
    // harmless no-op either way. But when the primary field is itself an FK
    // (or badge/boolean) — the case for a junction/assignment table with no
    // natural name/title column of its own, e.g. UserLocations.user_id, the
    // first column IntrospectionToConfig::detectPrimaryFieldFromColumns()
    // falls back to — skipping left that pinned, leftmost, "identifies this
    // row" column with NO renderer and NO slot override, so it fell through to
    // ReportTable's raw `row['user_id']` fallback: a bare numeric FK id shown
    // where every other module shows a name. ListPageGenerator passes true so
    // this method evaluates the primary field through the exact same
    // badge/boolean/isFk branches every other field already gets; a plain
    // scalar primary field still produces no renderer (byte-identical output
    // to before this parameter existed), since it hits neither branch below.
    protected function generateCustomCellRenderersFromListFields(array $fields, $primaryKey, string $slotProp = 'row', bool $includePrimaryKey = false): string
    {
        $renderers = [];

        $primaryKey = $this->getPrimaryListField($fields, $primaryKey);

        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['field'] ?? '';

            // Skip primary field itself — unless the caller opted in above.
            if ($key === $primaryKey && !$includePrimaryKey) {
                continue;
            }

            $type = $field['type'] ?? 'text';
            $data = $field['data'] ?? null;
            $dataPath = $data ?? $key;
            $enumValues = $field['enum_values'] ?? null;

            // Generate custom cell renderer for badge, status and boolean types.
            // 'status' (declared in generator.ts's list-field type union
            // alongside 'text'/'badge' but never actually checked here before)
            // used to fall all the way through to the default plain-text
            // renderer below -- a status-typed relationship column (e.g.
            // Expenses.status_id => data: "status?.name", type: "status")
            // rendered as bare text, no badge at all. Folded into the same
            // branch as 'badge' since every renderer below already handles
            // both the relationship and non-relationship shape generically.
            if ($type === 'badge' || $type === 'status' || $type === 'boolean') {
                // Check if it's a relationship field (has dot notation)
                $isRelationship = strpos($dataPath, '.') !== false;

                // Enum column (IntrospectionToConfig::buildFrontendListFields()
                // sets type => 'badge' + enum_values => [{value,label}, ...] for
                // these). Never a relationship -- enum columns aren't FKs -- so
                // this is checked before the isRelationship branch below, which
                // exists only for the (currently introspection-unused) generic
                // relationship-badge case such as status.name.
                if (!$isRelationship && is_array($enumValues) && !empty($enumValues)) {
                    // Consistent single badge style rather than the
                    // Active/Inactive-style two-tone :class ternary used
                    // below -- an enum can have any number of values (e.g.
                    // ItemPrices.price_tier: standard|premium|wholesale), so
                    // that binary green/gray mapping doesn't generalise.
                    // Per-value colour mapping would be a nice bonus but
                    // needs a colour-assignment concept that doesn't exist
                    // anywhere in this generator yet -- deliberately not
                    // half-implemented here.
                    $mapEntries = [];
                    foreach ($enumValues as $enumValue) {
                        $rawValue = addslashes((string) ($enumValue['value'] ?? ''));
                        $label    = addslashes((string) ($enumValue['label'] ?? ''));
                        $mapEntries[] = "'{$rawValue}': '{$label}'";
                    }
                    $mapLiteral = '{ ' . implode(', ', $mapEntries) . ' }';

                    $renderer = "\t\t<!-- Custom cell renderer for enum badge column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800\">\n";
                    $renderer .= "\t\t\t\t{{ ({$mapLiteral})[{$slotProp}.{$key}] ?? {$slotProp}.{$key} }}\n";
                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";

                    $renderers[] = $renderer;
                    continue;
                }

                if ($isRelationship) {
                    // Relationship badge (e.g., status.name)
                    $parts = explode('.', $dataPath);
                    $relationship = $parts[0];
                    $fieldName = $parts[1] ?? 'name';

                    // 'row', not 'item': this renderer is spliced into
                    // <Module>ListPage.vue, whose <ListTable> wraps
                    // ReportTable.vue — confirmed via
                    // `<slot :name="`cell-${col.key}`" :row="row" ...>` in
                    // ReportTable.vue, which never provides an `item` prop
                    // at all. The sibling isFk branch below already used
                    // 'row' correctly; this badge/boolean branch predates
                    // it and was never updated to match, so 'item' was
                    // always undefined here. Confirmed live: a generated
                    // ItemsListPage.vue crashed the entire list — "Cannot
                    // read properties of undefined (reading 'is_active')"
                    // — the instant a real row existed to render, since
                    // is_active (a boolean column) is visible by default.
                    // The related record itself may carry its own `color`
                    // (e.g. StatusesModel.color, hex string) -- when present,
                    // use it verbatim via inline style instead of the binary
                    // Active/green vs. everything-else/gray class ternary,
                    // same convention as StatusBadge.vue's `color` prop and
                    // mobile's ListPageBareCards.vue getBadgeColor(). Falls
                    // back to the ternary unchanged when the relationship has
                    // no `color` field (e.g. a plain FK badge like vendor.name).
                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"!{$slotProp}.{$relationship}.color ? ({$slotProp}.{$relationship}.{$fieldName} === 'Active' || {$slotProp}.{$relationship}.{$fieldName} === 1 || {$slotProp}.{$relationship}.{$fieldName} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') : ''\"\n";
                    $renderer .= "\t\t\t\t:style=\"{$slotProp}.{$relationship}.color ? { backgroundColor: {$slotProp}.{$relationship}.color + '1a', color: {$slotProp}.{$relationship}.color } : {}\">\n";
                    $renderer .= "\t\t\t\t{{ {$slotProp}.{$dataPath} || 'N/A' }}\n";
                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                } else {
                    // Direct field badge (e.g., is_active) — see the
                    // 'row' vs 'item' rationale in the isRelationship
                    // branch's comment above; identical fix, same root
                    // cause.
                    $renderer = "\t\t<!-- Custom cell renderer for badge/boolean column -->\n";
                    $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                    $renderer .= "\t\t\t<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium\"\n";
                    $renderer .= "\t\t\t\t:class=\"{$slotProp}.{$key} === 'Active' || {$slotProp}.{$key} === 1 || {$slotProp}.{$key} === true ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'\">\n";

                    // For boolean, show Yes/No or Active/Inactive
                    if ($type === 'boolean') {
                        $renderer .= "\t\t\t\t{{ {$slotProp}.{$key} ? 'Yes' : 'No' }}\n";
                    } else {
                        $renderer .= "\t\t\t\t{{ {$slotProp}.{$key} || 'N/A' }}\n";
                    }

                    $renderer .= "\t\t\t</span>\n";
                    $renderer .= "\t\t</template>";
                }

                $renderers[] = $renderer;
            } elseif ($type === 'date' || $type === 'datetime') {
                // Ported-from-Boot-Box gap: list pages there hand-format
                // every date/datetime column via formatDateTime() (see e.g.
                // BroadcastsListPage.vue's `created_at`/`scheduled_at`
                // columns) -- this generator had no equivalent branch at
                // all before, so a date column fell through to the plain
                // {{ row.field }} default below and rendered a raw
                // ISO-8601 string. formatDateTime() is SYSTEM_SHELL's own
                // helpers.ts export (imported via [[customCellImports]] in
                // page.stub, populated by ListPageGenerator when this
                // branch fires) -- not a Boot Box file port, since the
                // generated page already imports from '@/helpers' for
                // everything else.
                $renderer = "\t\t<!-- Custom cell renderer for date/datetime column -->\n";
                $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                $renderer .= "\t\t\t{{ formatDateTime({$slotProp}.{$dataPath}) ?? 'N/A' }}\n";
                $renderer .= "\t\t</template>";

                $renderers[] = $renderer;
            } elseif ($field['isFk'] ?? false) {
                // Custom cell renderer for FK columns: wrap the display value in
                // RelatedRecordLink so it becomes a clickable link to the related
                // record's own view. RelatedRecordLink itself degrades to plain
                // inert text if relatedModule isn't registered or the user lacks
                // permission, so it's always safe to emit here even for
                // not-yet-registered targets.
                //
                // relationAccessor is the FK column key with its trailing "_id"
                // stripped (e.g. "location_type_id" -> "location_type"). This is
                // snake_case regardless of what the belongsTo() relation METHOD is
                // actually named (see ModelGenerator::deriveRelationshipMethodName(),
                // which names it camelCase, e.g. "locationType") — because Laravel's
                // base Model has `public static $snakeAttributes = true` by default,
                // and HasAttributes::relationsToArray() unconditionally runs
                // `$key = Str::snake($key)` on every loaded relation's array key
                // before it reaches toArray()/JSON, REGARDLESS of what string the
                // relation was accessed/loaded under. Confirmed empirically:
                // ->load('locationType') on a model whose real method is
                // locationType() still produces a "location_type" key in toArray()
                // — never "locationType". (An earlier version of this file briefly
                // "fixed" this to camelCase based on a flawed test that only ever
                // exercised an already-snake_case relation name, which can't
                // distinguish "Eloquent preserves the loaded key" from "Eloquent
                // snake_cases the key" — both hypotheses agree on that input. Real
                // root cause of the RelationNotFoundException that prompted that
                // change: the freshly-regenerated ListService/ViewService assumed a
                // camelCase relation METHOD name (matching deriveRelationshipMethodName()),
                // but a model_hand_maintained Model had kept an old snake_case
                // method name from before that convention existed — a method-name
                // mismatch between Service and Model, unrelated to this renderer,
                // which was correct all along and reverted back to this here.)
                //
                // Display field defaults to 'name' but is overridden by
                // 'displayField' when the caller (IntrospectionToConfig::
                // buildFrontendListFields(), via its $foreignPrimaryFields
                // map) already resolved the FK target's real display column
                // -- e.g. an `orders` table displayed by `order_number`, not
                // `name`. A hand-authored module.json field that never sets
                // 'displayField' still falls back to 'name', unchanged from
                // before this existed.
                $relationAccessor = preg_replace('/_id$/', '', $key);
                $relatedModule = $field['relatedModule'] ?? '';
                $displayField = $field['displayField'] ?? 'name';
                $relatedIdParam = $this->resolveRelatedIdParam($relatedModule);

                $renderer = "\t\t<!-- Custom cell renderer for FK column -->\n";
                $renderer .= "\t\t<template #cell-{$key}=\"{ {$slotProp} }\">\n";
                $renderer .= "\t\t\t<RelatedRecordLink module=\"{$relatedModule}\" :uuid=\"{$slotProp}.{$relationAccessor}?.{$relatedIdParam}\">\n";
                $renderer .= "\t\t\t\t{{ {$slotProp}.{$relationAccessor}?.{$displayField} || 'N/A' }}\n";
                $renderer .= "\t\t\t</RelatedRecordLink>\n";
                $renderer .= "\t\t</template>";

                $renderers[] = $renderer;
            }
        }

        return !empty($renderers) ? implode("\n\n", $renderers) . "\n" : '';
    }

    // Form generation helper methods (shared across form generators)
    protected function mapNewFormFieldsToLegacy(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $key = $field['field'] ?? $field['key'] ?? 'name';
            $label = $field['label'] ?? $this->generateFieldLabel($key);
            $placeholder = $field['placeholder'] ?? ("Enter {$label}");
            // Format placeholder to remove underscores if present
            $placeholder = $this->formatPlaceholder($placeholder, $key);
            $required = $field['required'] ?? false;
            $fieldType = $field['field_type'] ?? 'input';

            // Build mapped field with all original properties preserved
            $mappedField = [
                'key' => $key,
                'name' => $key,
                'type' => $fieldType,  // Use field_type directly instead of mapping
                // The raw semantic type ('number', 'boolean', ...), kept under
                // its own key: 'type' above is a component/stub selector
                // (generateField()/resolveFieldType() key off it, e.g.
                // 'select', 'checkbox') and must not be overloaded to also
                // carry the default-value type — 'number-input' !== 'number'.
                'dataType' => $field['type'] ?? $fieldType,
                'label' => $label,
                'placeholder' => $placeholder,
                'required' => $required,
                // Real, persisted config uses snake_case `splash_key` (confirmed against
                // docs/modules/actions.md and every real project's own module.json) --
                // `splashKey` was the only key ever read here, which meant it was silently
                // always null for every hand-authored actions[].fields entry, and the
                // Str::plural($key) fallback below fired unconditionally instead. Confirmed
                // live 2026-08-20: PurchaseOrders.recordPayment's account_id field crashed
                // with an undefined `splash` reference as a direct result.
                'splashKey' => $field['splashKey'] ?? $field['splash_key'] ?? null,
            ];

            // Determine 'options': preserve a real inline options array from config
            // as-is (e.g. enum columns carry their choices here); otherwise fall
            // back to the splash key naming convention. Note splashKey can be an
            // empty string (not null) for static/inline-options fields, so `?:`
            // (falsy check) is used here rather than `??` (null-only check) --
            // using `??` would keep the empty string and short-circuit the
            // Str::plural($key) fallback.
            if (isset($field['options']) && is_array($field['options'])) {
                $mappedField['options'] = $field['options'];
            } else {
                $mappedField['options'] = !empty($mappedField['splashKey']) ? $mappedField['splashKey'] : Str::plural($key);
            }

            // Preserve all additional properties from the original field
            // This includes: api_url, option_label, option_value, decimals, fields, primaryField, etc.
            foreach ($field as $prop => $value) {
                if (!isset($mappedField[$prop]) && $prop !== 'field' && $prop !== 'field_type') {
                    $mappedField[$prop] = $value;
                }
            }

            $mapped[] = $mappedField;
        }
        return $mapped;
    }

    /**
     * A JS array-literal `BulkAction[]` for `<CrudListPanel :bulk-actions="...">`,
     * shared by ListPageGenerator (standalone list pages) and
     * CustomFeatureTabComponentGenerator (delegation tabs) so both stay
     * wired identically — the same principle that made CrudListPanel itself
     * one shared component instead of two divergent ones. Normalizer-backed
     * (BulkActionConfigNormalizer::normalizeAll()) rather than hand-rolling
     * defaults, so this can't drift from BulkActionServiceGenerator's/
     * ListServiceGenerator's own key/label/empty-key handling.
     */
    protected function generateBulkActionsLiteral(array $bulkActions): string
    {
        $bulkActions = BulkActionConfigNormalizer::normalizeAll($bulkActions);
        if (empty($bulkActions)) {
            return '[]';
        }

        $entries = [];
        foreach ($bulkActions as $action) {
            $parts = ["key: '" . addslashes($action['key']) . "'"];
            $parts[] = "label: '" . addslashes($action['label']) . "'";
            if (!empty($action['icon'])) {
                $parts[] = "icon: '" . addslashes($action['icon']) . "'";
            }
            if (!empty($action['requiresPermission'])) {
                $parts[] = "requiresPermission: '" . addslashes($action['requiresPermission']) . "'";
            }
            if (!empty($action['confirmMessage'])) {
                $parts[] = "confirmMessage: '" . addslashes($action['confirmMessage']) . "'";
            }
            if (!empty($action['variant'])) {
                $parts[] = "variant: '" . addslashes($action['variant']) . "'";
            }
            $entries[] = "\n\t{ " . implode(', ', $parts) . " }";
        }

        return '[' . implode(',', $entries) . "\n]";
    }

    protected function generateFormSections(array $config, string $footerHtml = ''): string
    {
        $sections = $config['sections'] ?? [];

        if (empty($sections)) {
            // Fallback to old structure for backward compatibility
            $fields = $config['fields'] ?? [];
            return $this->generateDefaultFormSection($fields, $footerHtml);
        }

        $sectionContent = [];
        $lastIndex = count($sections) - 1;
        foreach ($sections as $index => $section) {
            $footer = ($index === $lastIndex) ? $footerHtml : '';
            $sectionContent[] = $this->generateFormSection($section, $section['fields'] ?? [], $footer);
        }

        return implode("\n\n\t\t", $sectionContent);
    }

    protected function generateFormFooter(string $formType = 'create', bool $hasDrafts = true, bool $isWizard = false, bool $requiresConfirmation = false): string
    {
        $moduleRoute = Str::kebab($this->moduleName);
        $moduleSlug = strtolower($this->moduleName);
        // Only meaningful once currentStep === wizardSteps.length - 1 (the
        // confirm step itself, since it's always the last one appended by
        // generateWizardSteps()) -- harmless on every earlier step, where
        // the submit button isn't rendered at all (v-else on Next).
        $submitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';

        $saveDraftButton = $hasDrafts
            ? "\t\t\t\t<Button type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-save-draft\" @click=\"handleSaveDraftClick\" :disabled=\"isSubmitting || isSavingDraft\">\n"
            . "\t\t\t\t\t<component :is=\"icons['Loader2Icon']\" v-if=\"isSavingDraft\" class=\"h-3.5 w-3.5 mr-1.5 animate-spin\" />\n"
            . "\t\t\t\t\tSave Draft\n"
            . "\t\t\t\t</Button>\n"
            : '';

        // Wizard mode: Back appears once past step 0, and the primary action
        // is Next on every step but the last, where it's the SAME real
        // Submit button as a non-wizard form (identical backend/API call --
        // final-submit-only for this pass, see BaseComponentGenerator's
        // generateWizardSteps() docblock). Placed adjacent to each other at
        // the end of the button group, matching CreatePaygSalePage.vue's
        // real precedent (Back/Next pair, Cancel kept separate/available on
        // every step).
        $backButton = $isWizard
            ? "\t\t\t\t<Button v-if=\"currentStep > 0\" type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-wizard-back\" @click=\"goBack\" :disabled=\"isSubmitting\">\n"
            . "\t\t\t\t\t{{ \$t('common.back') }}\n"
            . "\t\t\t\t</Button>\n"
            : '';
        $nextButton = $isWizard
            ? "\t\t\t\t<Button v-if=\"currentStep < wizardSteps.length - 1\" type=\"button\" size=\"sm\" data-testid=\"{$moduleSlug}-wizard-next\" @click=\"goNext\" :disabled=\"isSubmitting\">\n"
            . "\t\t\t\t\t{{ \$t('common.next') }}\n"
            . "\t\t\t\t</Button>\n"
            : '';
        $submitVIf = $isWizard ? ' v-else' : '';

        if ($formType === 'edit') {
            // shelui-engine fork: this module's own record-identifier prop --
            // 'uuid' when ModuleConfigContract::hasUuid(), else 'id' (see
            // idParam()'s docblock above). The "open full page" link reads
            // the loaded record's own id back out of the EditForm's props
            // (declared in features/edit/form.stub, which resolves the SAME
            // prop name), so it must be the resolved idParam here too --
            // this previously hardcoded the literal `uuid` JS identifier
            // regardless of what the stub actually declared/exposed.
            $idParam = $this->idParam();

            return "<div class=\"flex items-center justify-between px-4 py-3 border-t shrink-0\">\n"
                 . "\t\t\t<router-link v-if=\"modal\" :to=\"`/{$moduleRoute}/\${{$idParam}}/edit`\">\n"
                 . "\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"sm\" class=\"text-muted-foreground\">\n"
                 . "\t\t\t\t\t<component :is=\"icons['ExternalLinkIcon']\" class=\"h-3.5 w-3.5 mr-1.5\" />\n"
                 . "\t\t\t\t\t{{ \$t('entity.open_full') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t</router-link>\n"
                 . "\t\t\t<div v-else />\n"
                 . "\t\t\t<div class=\"flex gap-3\">\n"
                 . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-cancel\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
                 . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . $saveDraftButton
                 . $backButton
                 . $nextButton
                 . "\t\t\t\t<Button type=\"submit\"{$submitVIf} size=\"sm\" data-testid=\"{$moduleSlug}-submit\" :disabled=\"{$submitDisabled}\">\n"
                 . "\t\t\t\t\t<component :is=\"icons['Loader2Icon']\" v-if=\"isSubmitting\" class=\"h-3.5 w-3.5 mr-1.5 animate-spin\" />\n"
                 . "\t\t\t\t\t{{ isSubmitting ? \$t('{$moduleRoute}.saving') : \$t('{$moduleRoute}.save_changes') }}\n"
                 . "\t\t\t\t</Button>\n"
                 . "\t\t\t</div>\n"
                 . "\t\t</div>";
        }

        // create footer
        return "<div class=\"flex items-center justify-between px-4 py-3 border-t shrink-0\">\n"
             . "\t\t\t<router-link v-if=\"modal\" to=\"/{$moduleRoute}/create\">\n"
             . "\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"sm\" class=\"text-muted-foreground\">\n"
             . "\t\t\t\t\t<component :is=\"icons['ExternalLinkIcon']\" class=\"h-3.5 w-3.5 mr-1.5\" />\n"
             . "\t\t\t\t\t{{ \$t('entity.open_full') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t</router-link>\n"
             . "\t\t\t<div v-else />\n"
             . "\t\t\t<div class=\"flex gap-3\">\n"
             . "\t\t\t\t<Button v-if=\"modal\" type=\"button\" variant=\"outline\" size=\"sm\" data-testid=\"{$moduleSlug}-cancel\" @click=\"cancel()\" :disabled=\"isSubmitting\">\n"
             . "\t\t\t\t\t{{ \$t('common.cancel') }}\n"
             . "\t\t\t\t</Button>\n"
             . $saveDraftButton
             . $backButton
             . $nextButton
             . "\t\t\t\t<Button type=\"submit\"{$submitVIf} size=\"sm\" data-testid=\"{$moduleSlug}-submit\" :disabled=\"{$submitDisabled}\">\n"
             . "\t\t\t\t\t<component :is=\"icons['Loader2Icon']\" v-if=\"isSubmitting\" class=\"h-3.5 w-3.5 mr-1.5 animate-spin\" />\n"
             . "\t\t\t\t\t{{ isSubmitting ? \$t('common.creating') : \$t('common.create') }}\n"
             . "\t\t\t\t</Button>\n"
             . "\t\t\t</div>\n"
             . "\t\t</div>";
    }

    protected function generateDefaultFormSection(array $fields, string $footerHtml = ''): string
    {
        $fieldsContent = $this->generateFieldsGrid($fields);
        $footer = !empty($footerHtml) ? "\n\t\t{$footerHtml}" : '';

        // Modal mode renders as a plain (non-flex, non-scrolling) block --
        // scroll ownership belongs to the single wrapper form.stub puts
        // around ALL sections + the inline_items block together, not to
        // any one section individually. See generateFormSection()'s
        // docblock-length comment for the full story.
        return "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : ''\">
			<div v-if=\"!modal\" class=\"px-4 py-3 border-b shrink-0\">
				<span class=\"text-sm font-semibold\">Main Details</span>
			</div>
			<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4\">
            {$fieldsContent}
			</div>{$footer}
		</div>";
    }

    /**
     * Bug (fixed 2026-08-17): this used to make EACH section its own
     * `flex flex-col flex-1 min-h-0` box with an internally
     * `overflow-y-auto` fields grid, so it could self-scroll when rendered
     * inside a modal (AppDialog). That works only as long as a section is
     * the SOLE flex child of <form> -- as soon as generateInlineItemsBlock()
     * splices an inline_items <Card> in as a later sibling (or there's more
     * than one section), flexbox's default `min-height: auto` lets that
     * sibling keep its full natural height while the section -- the only
     * child willing to shrink, because it opted into min-h-0 -- absorbed ALL
     * of the squeeze and collapsed into a tiny scrollbox, leaving the Items
     * card sitting untouched below it. Confirmed live 2026-08-17 on
     * Expenses' Create modal (has inline_items). Fix: sections render as
     * plain non-scrolling blocks; form.stub now wraps every section plus
     * the inline_items block together in ONE `flex-1 min-h-0
     * overflow-y-auto` region (mirrors AppDialog.vue's own
     * header/body-scroll/footer split), so the whole body scrolls as a
     * single unit and only the footer stays pinned.
     */
    protected function generateFormSection(array $section, array $fields, string $footerHtml = ''): string
    {
        $fieldsContent = $this->generateFieldsGrid($fields);
        $title = $section['title'] ?? 'Main Details';
        $footer = !empty($footerHtml) ? "\n\t\t{$footerHtml}" : '';

        return "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : ''\">
			<div v-if=\"!modal\" class=\"px-4 py-3 border-b shrink-0\">
				<span class=\"text-sm font-semibold\">{$title}</span>
			</div>
			<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4\">
{$fieldsContent}
			</div>{$footer}
		</div>";
    }

    /**
     * Wizard steps as the generators read them: every step has an `id` and a `label`.
     *
     * The documented shape is `{ title, field_keys }`; the generators read `id` and `label`. A step
     * written the documented way therefore rendered a stepper of blank titles (and, on the review
     * step, empty section headings) with no warning -- found by the super-suite fixture's create
     * wizard. `title` is accepted as the label, and a missing `id` is derived from it.
     *
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeWizardSteps(array $steps): array
    {
        $normalized = [];
        foreach (array_values($steps) as $i => $step) {
            if (!is_array($step)) {
                continue;
            }
            $label = (string) ($step['label'] ?? $step['title'] ?? '');
            $step['label'] = $label;
            $step['id'] = (string) (($step['id'] ?? '') !== '' ? $step['id'] : (Str::slug($label) ?: 'step-' . ($i + 1)));
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * Multi-step "wizard" presentation of a create/edit form's fields --
     * sibling to generateFormSection(), NOT a fork of the generation
     * pipeline (mirrors the modal-vs-page pattern: one generated component,
     * conditional internal rendering, not a parallel set of generators).
     * Called INSTEAD OF generateFormSection() when wizard.enabled is true;
     * the underlying form = ref({...}) (generateFormFields()) and the final
     * submit call (generateSubmitCall()) are UNTOUCHED -- final-submit-only
     * for this pass (see plan doc), steps only gate which fields are
     * visible, one real submit at the end, identical backend/API shape to
     * a non-wizard form.
     *
     * A step's field_keys can reference either a plain field (rendered into
     * that step's own fields grid) or an inline_items[].key (rendered as
     * that item's existing wrapper-component block instead --
     * generateInlineItemsBlock(), already built/proven this package). Any
     * inline_items NOT claimed by a step (an author forgot to assign one)
     * must still render somewhere, not silently vanish -- the caller
     * (CreateFormGenerator/EditFormGenerator) is responsible for passing
     * whatever this method reports as "claimed" back into its own
     * [[inlineItemsBlock]] exclusion set. Returns an indexed 3-tuple
     * (matches this class's existing buildSplashBlocks()/buildDraftBlocks()
     * tuple-return convention) rather than a by-reference out-param.
     *
     * $confirmStepConfig is resolved by the CALLER (default true for wizard
     * mode unless explicitly disabled -- see CreateFormGenerator et al) and
     * passed in already-resolved; pass `['enabled' => false]` to omit the
     * confirm step entirely.
     *
     * @return array{0: string, 1: string[], 2: bool} [$markup, $claimedInlineItemKeys, $hasFkFieldLabels]
     *         $hasFkFieldLabels: true when at least one FK select field's
     *         @selected-object handler got wired -- the caller must declare
     *         `const fieldLabels = ref<Record<string,string>>({})` only when
     *         this is true, since tsconfig's noUnusedLocals would otherwise
     *         flag it on a confirm-enabled wizard with zero FK fields.
     */
    protected function generateWizardSteps(array $wizardConfig, array $fields, array $inlineItems, array $confirmStepConfig = []): array
    {
        $steps = $this->normalizeWizardSteps($wizardConfig['steps'] ?? []);
        if (empty($steps)) {
            return [$this->generateFormSection(['title' => 'Main Details'], $fields), [], false];
        }

        $fieldsByKey = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            if ($key !== '') {
                $fieldsByKey[$key] = $field;
            }
        }

        $inlineItemsByKey = [];
        foreach ($inlineItems as $item) {
            $inlineItemsByKey[$item['key']] = $item;
        }

        // A wizard PARTITIONS the form: a field named by no step is not rendered at all. That is easy to
        // do by accident and silent -- when the field is required the create then 422s on a field the
        // user was never shown (found by the super-suite fixture, where `status_id` was in no step).
        $stepped = [];
        foreach ($steps as $step) {
            foreach (($step['field_keys'] ?? []) as $fieldKey) {
                $stepped[$fieldKey] = true;
            }
        }
        $unplaced = array_values(array_diff(array_keys($fieldsByKey), array_keys($stepped)));
        if ($unplaced !== []) {
            PathManager::reportIssue(
                "Wizard on {$this->moduleName}: field(s) " . implode(', ', $unplaced)
                . ' are named by no step and will not be rendered. Add them to a step\'s field_keys'
                . ' (a required one left out makes every submit fail validation).',
                'warning'
            );
        }

        // Only api-select(-inline) fields need label tracking (a plain
        // select's `form.key` is already a bare id; see generateField()'s
        // own [[fieldLabelCapture]] docblock) -- and only when there's an
        // actual confirm-step summary to feed it.
        $trackFieldLabels = ($confirmStepConfig['enabled'] ?? false) === true;

        $claimedInlineItemKeys = [];
        $stepBlocks = [];
        $summarySections = [];
        $hasFkFieldLabels = false;
        foreach (array_values($steps) as $index => $step) {
            $stepPlainFields = [];
            $stepInlineBlocks = [];
            $summaryLines = [];
            foreach (($step['field_keys'] ?? []) as $fieldKey) {
                if (isset($inlineItemsByKey[$fieldKey])) {
                    $item = $inlineItemsByKey[$fieldKey];
                    $stepInlineBlocks[] = $this->generateInlineItemsBlock([$item]);
                    $claimedInlineItemKeys[] = $fieldKey;
                    $itemLabel = htmlspecialchars($item['label'] ?? $fieldKey, ENT_QUOTES);
                    // Generically knowable without any per-domain logic: an
                    // inline_items block is always a plain array on `form`.
                    $summaryLines[] = "\t\t\t\t\t<div><span class=\"text-muted-foreground\">{$itemLabel}:</span> {{ form.{$fieldKey}.length }} item(s)</div>";
                } elseif (isset($fieldsByKey[$fieldKey])) {
                    $field = $fieldsByKey[$fieldKey];
                    $stepPlainFields[] = $field;
                    $fieldKeyEsc = $fieldKey;
                    $fieldLabel = htmlspecialchars($field['label'] ?? $fieldKey, ENT_QUOTES);
                    // An FK select's `form.key` is a bare id -- prefer the
                    // display label generateField()'s @selected-object
                    // handler captured, falling back to the raw value for
                    // every other field type (text/number/date/...).
                    $isFkSelect = $this->isApiBackedSelect($field);
                    if ($isFkSelect && $trackFieldLabels) {
                        $hasFkFieldLabels = true;
                    }
                    $valueExpr = $isFkSelect
                        ? "fieldLabels.{$fieldKeyEsc} || form.{$fieldKeyEsc} || '—'"
                        : "form.{$fieldKeyEsc} || '—'";
                    $summaryLines[] = "\t\t\t\t\t<div><span class=\"text-muted-foreground\">{$fieldLabel}:</span> {{ {$valueExpr} }}</div>";
                }
            }

            $fieldsGridBlock = !empty($stepPlainFields)
                ? "\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4\">\n"
                    . $this->generateFieldsGrid($stepPlainFields, $trackFieldLabels) . "\n"
                    . "\t\t\t\t</div>\n"
                : '';
            $inlineBlock = !empty($stepInlineBlocks) ? implode("\n", $stepInlineBlocks) . "\n" : '';

            $stepBlocks[] = "\t\t\t<div v-if=\"currentStep === {$index}\">\n{$fieldsGridBlock}{$inlineBlock}\t\t\t</div>";

            if (!empty($summaryLines)) {
                $stepLabel = htmlspecialchars($step['label'] ?? '', ENT_QUOTES);
                $summarySections[] = "\t\t\t\t<div>\n\t\t\t\t\t<h4 class=\"text-sm font-semibold mb-2\">{$stepLabel}</h4>\n\t\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-2 gap-2 text-sm\">\n"
                    . implode("\n", $summaryLines) . "\n\t\t\t\t\t</div>\n\t\t\t\t</div>";
            }
        }

        if (($confirmStepConfig['enabled'] ?? false) === true) {
            $stepBlocks[] = $this->generateWizardConfirmStep($confirmStepConfig, count($steps), $summarySections);
        }

        // Bug (found live 2026-08-18, PurchaseOrders' modal create form): the
        // outer wrapper only carried `p-4` bundled with the `!modal` border
        // chrome, and a step's OWN fields grid (unlike generateFormSection()'s
        // equivalent grid, which bakes `p-4` in unconditionally) had no
        // padding of its own -- so modal mode (where the outer wrapper's class
        // resolves to '') rendered the Stepper and every step's fields flush
        // against the dialog edge. Fixed to match the already-working
        // convention generateFormSection()/generateInlineItemsBlock() use:
        // the outer wrapper stays chrome-only (border is genuinely
        // modal-conditional), padding is unconditional and lives on the
        // content itself -- the Stepper gets its own explicit `p-4 pb-0`
        // wrapper, each step's fields grid now carries `p-4` above.
        $markup = "<div :class=\"!modal ? 'rounded-md border overflow-hidden' : ''\">\n"
            . "\t\t\t<div class=\"p-4 pb-0\">\n"
            . "\t\t\t\t<Stepper :steps=\"wizardSteps\" :current-step=\"currentStep\" :completed-steps=\"completedSteps\" @step-click=\"goToStep\" />\n"
            . "\t\t\t</div>\n"
            . implode("\n", $stepBlocks)
            . "\n\t\t</div>";

        return [$markup, $claimedInlineItemKeys, $hasFkFieldLabels];
    }

    /**
     * The optional trailing "Review & Confirm" step -- `wizard.confirm_step:
     * {enabled, label?, confirmation_text?}`. Auto-summarizes every earlier
     * step's plain fields (`{label}: {{ form.key }}`) and inline_items
     * blocks (`{label}: N item(s)`, the only thing genuinely knowable about
     * an inline_items array without per-domain logic) as read-only text,
     * then gates the final submit behind a checkbox
     * (`confirmed`, declared in generateWizardStateBlock()).
     *
     * A step with EMPTY field_keys (a hand-built custom step, e.g. Receive
     * PO's "Items Received") contributes nothing to the auto-summary --
     * the HTML comment below is a stable extension point a hand-edit (in a
     * writeFileOnce()-protected action Form.vue) fills in with that step's
     * own summary. For Create/Edit forms, which fully regenerate and have
     * no such custom steps by default, the comment is inert.
     */
    protected function generateWizardConfirmStep(array $confirmConfig, int $index, array $summarySections): string
    {
        $summaryBlock = !empty($summarySections) ? implode("\n", $summarySections) . "\n" : '';

        return "\t\t\t<div v-if=\"currentStep === {$index}\" class=\"p-4 space-y-4\">\n"
            . "\t\t\t\t<div class=\"space-y-4\">\n"
            . $summaryBlock
            . "\t\t\t\t\t<!-- Custom step summaries -- add one <div> per hand-built step here. -->\n"
            . "\t\t\t\t</div>\n"
            . $this->generateConfirmCheckbox($confirmConfig, 'pt-3 border-t')
            . "\n\t\t\t</div>";
    }

    /**
     * The `<CheckboxField>` that gates a form's submit behind `confirmed`
     * (declared alongside `isSubmitting`/`errors` whenever confirmation is
     * required -- see CreateFormGenerator/EditFormGenerator/
     * ActionComponentGenerator's own `$requiresConfirmation` resolution).
     * Shared by the wizard confirm step and generateFlatConfirmBlock() so
     * both variants render byte-identical checkbox markup.
     */
    protected function generateConfirmCheckbox(array $confirmConfig, string $extraClass = ''): string
    {
        $confirmationText = htmlspecialchars($confirmConfig['confirmation_text'] ?? 'I confirm this information is correct.', ENT_QUOTES);
        $classAttr = $extraClass !== '' ? " class=\"{$extraClass}\"" : '';

        return "\t\t\t\t<CheckboxField id=\"wizard-confirm\" label=\"{$confirmationText}\" v-model=\"confirmed\"{$classAttr} />";
    }

    /**
     * Non-wizard equivalent of generateWizardConfirmStep() -- a single
     * checkbox appended after a flat form's fields, no Stepper/steps
     * involved (the form already shows everything on one screen, so there's
     * nothing to "summarize"). Defaults OFF for flat forms (opt-in via
     * confirm_step.enabled: true) -- unlike wizard mode, most flat CRUD is
     * short/low-stakes and a checkbox on every 2-field edit would just
     * become something people stop reading (see [[confirmCheckboxBlock]]'s
     * callers for the exact default-resolution rule).
     */
    protected function generateFlatConfirmBlock(array $confirmConfig): string
    {
        if (($confirmConfig['enabled'] ?? false) !== true) {
            return '';
        }

        return "\t\t<div class=\"px-4 pb-2\">\n"
            . $this->generateConfirmCheckbox($confirmConfig)
            . "\n\t\t</div>";
    }

    /**
     * Script-level `wizardSteps`/`currentStep`/`completedSteps`/goNext/
     * goBack/goToStep state -- the [[wizardStateBlock]] stub placeholder,
     * sibling to [[draftSetupBlock]] (goNext() reuses the SAME saveDraft()
     * already generated for every form when drafts are enabled -- an
     * immediate save on step-complete, on top of the existing debounced
     * watch(form, ..., {deep:true}) autosave that already fires on every
     * keystroke regardless of wizard mode; see buildEditDraftBlocks()/
     * buildCreateDraftBlocks()'s draftWatchBlock. No new backend endpoint,
     * no new composable -- this is the ONLY place wizard mode touches the
     * draft mechanism, and it's a second call to something already
     * imported/wired into every generated form).
     *
     * Deliberately does NOT block goNext() on per-step field validation --
     * steps gate visibility, not correctness; the full validated submit at
     * the end (generateSubmitCall(), untouched) is exactly the same
     * required/rules enforcement a non-wizard form already has. goToStep()
     * allows free navigation to any step (matches Stepper.vue's own
     * strictNavigation-false default) rather than a hard forward-progress
     * gate, since nothing has been persisted to the real record yet.
     */
    protected function generateWizardStateBlock(array $wizardConfig, bool $hasDrafts, array $confirmStepConfig = [], bool $hasFkFieldLabels = false): string
    {
        $steps = $this->normalizeWizardSteps($wizardConfig['steps'] ?? []);
        if (empty($steps)) {
            return '';
        }

        $stepEntries = [];
        foreach ($steps as $step) {
            $id = addslashes($step['id'] ?? '');
            $label = addslashes($step['label'] ?? '');
            $description = !empty($step['description'])
                ? ", description: '" . addslashes($step['description']) . "'"
                : '';
            $icon = !empty($step['icon'])
                ? ", icon: icons['" . addslashes($step['icon']) . "']"
                : '';
            $stepEntries[] = "\t{ id: '{$id}', label: '{$label}'{$description}{$icon} }";
        }

        $hasConfirmStep = ($confirmStepConfig['enabled'] ?? false) === true;
        if ($hasConfirmStep) {
            $confirmLabel = addslashes($confirmStepConfig['label'] ?? 'Review & Confirm');
            $stepEntries[] = "\t{ id: 'confirm', label: '{$confirmLabel}' }";
        }
        $stepsLiteral = "[\n" . implode(",\n", $stepEntries) . "\n]";

        $draftSaveOnNext = $hasDrafts ? "\n\tsaveDraft(form.value)" : '';
        // Declared even when confirm_step is disabled would be an unused
        // var TS lint would flag -- only declare it when the checkbox that
        // reads/writes it actually gets generated (generateWizardConfirmStep()).
        $confirmedState = $hasConfirmStep ? "const confirmed = ref(false)\n" : '';
        // Same reasoning: only declare fieldLabels when at least one FK
        // select field's summary line actually reads from it (see
        // generateWizardSteps()'s own $hasFkFieldLabels tracking).
        $fieldLabelsState = $hasFkFieldLabels ? "const fieldLabels = ref<Record<string, string>>({})\n" : '';

        return <<<TS
// Wizard mode: multi-step presentation of the fields above. See
// generateWizardSteps()'s docblock for why this is final-submit-only.
const wizardSteps = {$stepsLiteral}
const currentStep = ref(0)
const completedSteps = ref<number[]>([])
{$confirmedState}{$fieldLabelsState}
const goNext = () => {
\tif (!completedSteps.value.includes(currentStep.value)) {
\t\tcompletedSteps.value.push(currentStep.value)
\t}
\tif (currentStep.value < wizardSteps.length - 1) {
\t\tcurrentStep.value++
\t}{$draftSaveOnNext}
}

const goBack = () => {
\tif (currentStep.value > 0) {
\t\tcurrentStep.value--
\t}
}

const goToStep = (index: number) => {
\tcurrentStep.value = index
}
TS;
    }

    /**
     * Edit-only counterpart to generateField()'s @selected-object capture:
     * that only fires on a LIVE user pick, so a value that arrives already
     * populated from the loaded record (the normal edit case) never
     * triggers it -- confirmed live on PurchaseOrders' Edit wizard, whose
     * confirm-step summary kept showing raw ids for vendor_id/location_id/
     * status_id despite the picker fix, since the record loads with those
     * fields already set and the user never re-opens the dropdown.
     *
     * Seeds `fieldLabels` from the SAME eager-loaded relation data the View
     * response already carries (every FK column's belongsTo() relation is
     * eager-loaded and returned as its own key by
     * BaseServiceGenerator::generateEagerLoadRelationships() -- e.g.
     * `response.data.vendor.name` -- no ViewService generator change
     * needed; relation-name derivation mirrors ViewServiceGenerator::
     * extractInlineItemFkFields() exactly: strip a trailing `_id`, camelCase).
     */
    protected function generateFieldLabelsSeedBlock(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $isFkSelect = ($field['type'] ?? '') === 'select' && !empty($field['splashKey']);
            if (!$isFkSelect) {
                continue;
            }
            $key = $field['key'] ?? $field['name'] ?? '';
            if ($key === '') {
                continue;
            }
            $base = str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;
            $relation = lcfirst(Str::camel($base));
            $optionLabelProp = $field['option_label'] ?? 'name';
            $lines[] = "\t\tif (response.data.{$relation}) fieldLabels.value['{$key}'] = response.data.{$relation}.{$optionLabelProp} ?? ''";
        }

        return !empty($lines) ? implode("\n", $lines) : '';
    }

    /**
     * $trackFieldLabels: true only for a wizard step's field grid when that
     * wizard has a confirm_step summary to feed -- an FK select field's
     * `form.key` is a bare id (e.g. `10`), meaningless in a review screen,
     * so the ApiSelect2Field variants also capture the chosen option's
     * display label into `fieldLabels` (see generateWizardConfirmStep()'s
     * summary lines, which prefer `fieldLabels.key` over `form.key`).
     * False everywhere else -- every non-confirm caller keeps byte-identical
     * output.
     */
    protected function generateFieldsGrid(array $fields, bool $trackFieldLabels = false): string
    {
        $fieldContent = [];

        foreach ($fields as $field) {
            $fieldContent[] = $this->generateField($field, $trackFieldLabels);
        }

        return implode("\n", $fieldContent);
    }


    protected function arrayToJsObjectString(array $array): string
    {
        // Recursively convert array to JS object string
        $json = json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Remove quotes around keys to make it look more like JS object (optional but cleaner)
        // For simplicity, we'll just use the JSON string which is valid JS
        // But we need to handle function strings if any (e.g. render functions)
        // For now, assuming simple data structures.
        // We need to escape single quotes if we use them in the template attribute.
        // json_encode never escapes an apostrophe inside a string value (only
        // double quotes are special in JSON), so any data value containing one
        // (e.g. an enum option like "o'brien") must have it escaped BEFORE the
        // structural double-quotes are swapped for single-quotes below --
        // otherwise the apostrophe would prematurely terminate the resulting
        // JS single-quoted string and break the generated Vue attribute.
        $json = str_replace("'", "\\'", $json);
        return str_replace('"', "'", $json);
    }

    /**
     * Build the target-map JS object literal a morph-select field's
     * MorphSelectField.vue needs: alias => { apiUrl, optionLabel }. Not the
     * same shape arrayToJsObjectString() produces (that's a flat list of
     * objects; this is a map keyed by an arbitrary string), so it gets its
     * own small builder rather than trying to bend that one to fit.
     *
     * apiUrl is derived from targets[].module the same way every other
     * generated field derives its own module route — Str::kebab() — so a
     * Suppliers target resolves to /select/suppliers, matching
     * SelectController's Str::studly()-based module-name resolution on the
     * backend (it accepts either case; kebab is this file's own convention
     * for URLs built from a module name elsewhere, e.g. $moduleRoute).
     */
    protected function generateMorphTargetMapLiteral(array $targets): string
    {
        if (empty($targets)) {
            return '{}';
        }

        $entries = [];
        foreach ($targets as $target) {
            $alias       = addslashes($target['alias'] ?? '');
            // A morph target's `module` is "Group/Name" for a module inside a domain group
            // (`Suite/SuiteSuppliers`). Str::kebab() keeps the slash and inserts a hyphen after
            // it -- `/select/suite/-suite-suppliers`, a 404 -- and the endpoint is addressed by
            // the module name alone.
            $moduleName  = (string) ($target['module'] ?? '');
            $moduleSlug  = Str::kebab(str_contains($moduleName, '/') ? substr($moduleName, (int) strrpos($moduleName, '/') + 1) : $moduleName);
            $optionLabel = addslashes($target['option_label'] ?? 'name');
            $entries[] = "\t\t\t\t'{$alias}': { apiUrl: '/select/{$moduleSlug}', optionLabel: '{$optionLabel}' }";
        }

        return "{\n" . implode(",\n", $entries) . "\n\t\t\t}";
    }

    /**
     * Process inline-items fields to ensure all properties are properly included
     * This ensures readonly and other properties are preserved
     */
    protected function processInlineItemsFields(array $fields): array
    {
        $processedFields = [];
        
        foreach ($fields as $field) {
            $processedField = [
                'key' => $field['key'] ?? '',
                'label' => $field['label'] ?? '',
                'type' => $field['type'] ?? 'input',
            ];
            
            // Include optional properties if they exist
            if (isset($field['placeholder'])) {
                $processedField['placeholder'] = $field['placeholder'];
            }
            if (isset($field['required'])) {
                $processedField['required'] = (bool)$field['required'];
            }
            if (isset($field['disabled'])) {
                $processedField['disabled'] = (bool)$field['disabled'];
            }
            if (isset($field['readonly'])) {
                $processedField['readonly'] = (bool)$field['readonly'];
            }
            if (isset($field['decimals'])) {
                $processedField['decimals'] = (int)$field['decimals'];
            }
            if (isset($field['default'])) {
                $processedField['default'] = $field['default'];
            }
            if (isset($field['inputType'])) {
                $processedField['inputType'] = $field['inputType'];
            }
            if (isset($field['options'])) {
                $processedField['options'] = $field['options'];
            }
            if (isset($field['optionLabel'])) {
                $processedField['optionLabel'] = $field['optionLabel'];
            }
            if (isset($field['optionValue'])) {
                $processedField['optionValue'] = $field['optionValue'];
            }
            if (isset($field['splashKey'])) {
                $processedField['splashKey'] = $field['splashKey'];
            }
            if (isset($field['apiUrl'])) {
                $processedField['apiUrl'] = $field['apiUrl'];
            }
            if (isset($field['showInTable'])) {
                $processedField['showInTable'] = (bool)$field['showInTable'];
            }
            if (isset($field['tableWidth'])) {
                $processedField['tableWidth'] = $field['tableWidth'];
            }
            
            $processedFields[] = $processedField;
        }

        return $processedFields;
    }

    /**
     * Component name for a module's inline-items wrapper -- shared by both
     * inline-items mechanisms this generator has (see
     * writeInlineItemsWrapperComponent()'s docblock), so a field-type field
     * and a top-level `inline_items` block never derive the name differently
     * for the same key.
     */
    protected function inlineItemsWrapperComponentName(string $key): string
    {
        return "{$this->moduleName}" . Str::studly($key) . 'InlineItems';
    }

    /**
     * Emit `{Module}{Key}InlineItems.vue` -- a hand-edit-protected wrapper
     * that renders the whole add/edit/view/delete UI itself, written once via
     * writeFileOnce()'s truly unconditional skip-if-exists (never
     * writeFile() or writeFileAlways()). Every setting (fields, titles, modal
     * size/columns, can_*, variant, totals) is rendered into the file at
     * generation time; the wrapper's only interface to the parent form is its
     * v-model and a `totals-change` emit, so the call site must pass nothing
     * else (the wrapper has several root nodes, so Vue cannot fall attributes
     * through and warns about each one). A module with dependent inline-item
     * fields (e.g. Order Items: disable a field based on another, recompute
     * totals) hand-edits exactly one file that regeneration never touches again.
     *
     * Bug (found + fixed 2026-08-02): this used to call writeFile(), whose
     * skip-if-exists is gated on `!$this->force` -- correct for every
     * regularly-regenerated output, but backwards for a file whose entire
     * purpose is to survive regeneration. Any `--force` run (the normal case
     * for picking up an unrelated schema change) silently overwrote a
     * developer's hand-added hooks back to the template. Confirmed via a
     * live `make:module --force` run against a real scratch module. See
     * writeFileOnce()'s own docblock on BaseGenerator for the full writeup.
     *
     * Shared by BOTH of this generator's inline-items mechanisms: a single
     * `field_type: 'inline-items'` entry inside a normal fields[] list (see
     * generateField()), and a full `Card`-wrapped block declared via the
     * top-level `inline_items` config key (see generateInlineItemsBlock()) --
     * the latter is this package's documented parent-child pattern (e.g.
     * Order Items, with its own backend save/sync/load generation), and
     * exactly the scenario this wrapper was built for. Each caller builds
     * its own $fieldsJs (their field shapes differ: camelCase vs this
     * mechanism's snake_case config keys), this method only owns the
     * template render + write-once file I/O both share.
     *
     * @param string $fieldsJs  Pre-built `[{ key: '...', ... }, ...]` JS array literal.
     * @return string  The wrapper component's name (e.g. "OrdersOrderItemsInlineItems"),
     *                 for the caller to splice into its own markup as the tag to render.
     */
    /**
     * $normalizedFields: array of fields already shaped like
     * processInlineItemsFields()'s own output ({key, label, type (the
     * WIDGET selector -- 'input'/'textarea'/'select'/'api-select'/'date'/
     * 'checkbox'/'number-input'), required?, disabled?, readonly?,
     * decimals?, default?, inputType?, options?, optionLabel?,
     * optionValue?, apiUrl?, tableWidth?, showInTable?, colSpan?}) --
     * normalizeInlineItemConfigField() is the adapter for the OTHER caller's
     * snake_case inline_items[].fields[] config shape.
     *
     * $itemConfig carries the surrounding inline_items[] entry's own keys
     * (label, primary_field, modal_size, modal_columns, add_button_text,
     * add_modal_title, edit_modal_title, view_modal_title, empty_message,
     * delete_message, can_add/edit/view/delete, variant, totals) -- every
     * key is optional, a caller with none of them (the field_type:
     * 'inline-items' case) still gets a fully working component from
     * sensible generated defaults.
     *
     * Emits fully concrete, directly-editable markup (real rows, a real
     * Add/Edit AppDialog with one real field component per configured
     * field, a real View AppDialog, a real Delete confirm AppDialog) --
     * deliberately NOT a runtime JSON-config-driven generic component. A
     * developer restyling one module's inline items (row layout, which
     * fields show in the table, modal wording, etc.) edits real markup in
     * this one file; nothing else reads or depends on its internal shape.
     */
    protected function writeInlineItemsWrapperComponent(string $key, array $normalizedFields, array $itemConfig = []): string
    {
        $componentName = $this->inlineItemsWrapperComponentName($key);

        $label        = $itemConfig['label'] ?? ucwords(str_replace('_', ' ', $key));
        $primaryField = $itemConfig['primary_field'] ?? ($normalizedFields[0]['key'] ?? 'name');
        $modalSize    = $itemConfig['modal_size'] ?? 'md';
        $modalColumns = (int) ($itemConfig['modal_columns'] ?? 1);
        $modalColumnsClass = $modalColumns >= 2 ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1';

        $addButtonText  = addslashes($itemConfig['add_button_text'] ?? 'Add Item');
        $addModalTitle  = addslashes($itemConfig['add_modal_title'] ?? "Add {$label}");
        $editModalTitle = addslashes($itemConfig['edit_modal_title'] ?? "Edit {$label}");
        $viewModalTitle = addslashes($itemConfig['view_modal_title'] ?? "{$label} Details");
        $emptyMessage   = addslashes($itemConfig['empty_message'] ?? 'No items added');
        $deleteMessage  = addslashes($itemConfig['delete_message'] ?? "This will remove this {$label} entry.");

        $canAddBool    = ($itemConfig['can_add'] ?? true) !== false;
        $canEditBool   = ($itemConfig['can_edit'] ?? true) !== false;
        $canViewBool   = ($itemConfig['can_view'] ?? true) !== false;
        $canDeleteBool = ($itemConfig['can_delete'] ?? true) !== false;

        $variant = $itemConfig['variant'] ?? 'card';
        $rowsMarkup = $variant === 'table'
            ? $this->buildInlineItemsRowsMarkupTable($normalizedFields, $itemConfig, $canViewBool, $canEditBool, $canDeleteBool)
            : $this->buildInlineItemsRowsMarkupCard($normalizedFields, $primaryField, $canViewBool, $canEditBool, $canDeleteBool);

        $stub = $this->getTemplateContent('fields/inline-items-wrapper', 'frontend');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]'       => $componentName,
            '[[ModuleName]]'          => $this->moduleName,
            '[[label]]'               => $label,
            '[[primaryField]]'        => $primaryField,
            '[[modalSize]]'           => $modalSize,
            '[[modalColumnsClass]]'   => $modalColumnsClass,
            '[[addButtonText]]'       => $addButtonText,
            '[[addModalTitle]]'       => $addModalTitle,
            '[[editModalTitle]]'      => $editModalTitle,
            '[[viewModalTitle]]'      => $viewModalTitle,
            '[[emptyMessage]]'        => $emptyMessage,
            '[[deleteMessage]]'       => $deleteMessage,
            '[[canAdd]]'              => $canAddBool ? 'true' : 'false',
            '[[rowsMarkup]]'          => $rowsMarkup,
            '[[modalFieldsMarkup]]'   => implode("\n", array_map(fn (array $f) => $this->generateInlineItemModalField($f), $normalizedFields)),
            '[[viewFieldsMarkup]]'    => implode("\n", array_map(fn (array $f) => $this->generateInlineItemViewField($f), $normalizedFields)),
            '[[fieldImports]]'        => $this->buildInlineItemFieldImports($normalizedFields),
            '[[defaultsLiteral]]'     => $this->buildInlineItemDefaultsLiteral($normalizedFields),
            '[[requiredKeysLiteral]]' => $this->buildInlineItemRequiredKeysLiteral($normalizedFields),
            '[[totalsBlock]]'         => $this->buildInlineItemsTotalsScriptBlock($itemConfig['totals'] ?? []),
        ]);

        $path = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    /**
     * Adapter for generateInlineItemsBlock()'s snake_case inline_items[].
     * fields[] config shape into the same normalized shape
     * processInlineItemsFields() already produces for the OTHER caller
     * (field_type: 'inline-items') -- so every downstream builder below
     * only ever deals with one field shape. Mirrors buildInlineItemFieldsJs()'s
     * own type-resolution (INLINE_ITEM_TYPE_TO_WIDGET) exactly, since that
     * method's JS-literal output is what this replaces as this class's
     * only consumer of that mapping.
     */
    protected function normalizeInlineItemConfigField(array $field): array
    {
        $configuredType = $field['type'] ?? 'text';
        $widgetType = $field['field_type'] ?? (self::INLINE_ITEM_TYPE_TO_WIDGET[$configuredType] ?? $configuredType);
        $apiUrl = $field['api_url'] ?? null;

        // `splash_key` / `api_url` on a select with no literal `options` is an API-backed picker,
        // resolved against the generic select endpoint: `api_url` if given, else
        // `/select/{StudlySplashKey}`. This was the runtime rule of the old shared inline-items
        // component (`field.apiUrl || '/select/' + pascalCase(field.splashKey)`); the v3.5.18
        // rewrite to concrete markup dropped it, so such a field fell through to generateField()'s
        // own default -- `:options="splash.<plural key>"` -- naming a `splash` object the wrapper
        // never receives (a vue-tsc error, and a render-time crash when the dialog opens). Found by
        // the super-suite fixture's `status_key` field. Resolved HERE, at generation time, so the
        // rest of the pipeline only ever sees an ordinary api-select.
        $splashKey = $field['splash_key'] ?? $field['splashKey'] ?? null;
        $hasLiteralOptions = !empty($field['options']) && is_array($field['options']);
        if (
            in_array($widgetType, ['select', 'api-select'], true)
            && !$hasLiteralOptions
            && (!empty($splashKey) || !empty($apiUrl))
        ) {
            $widgetType = 'api-select';
            $apiUrl = !empty($apiUrl) ? $apiUrl : '/select/' . Str::studly((string) $splashKey);
        }

        return [
            'key'         => $field['key'] ?? '',
            'label'       => $field['label'] ?? ucwords(str_replace('_', ' ', $field['key'] ?? '')),
            'type'        => $widgetType,
            'required'    => !empty($field['required']),
            'readonly'    => !empty($field['readonly']),
            'disabled'    => !empty($field['disabled']),
            'apiUrl'      => $apiUrl,
            'decimals'    => $field['decimals'] ?? 0,
            'tableWidth'  => $field['table_width'] ?? null,
            'showInTable' => $field['show_in_table'] ?? true,
            'colSpan'     => $field['col_span'] ?? 1,
            'placeholder' => $field['placeholder'] ?? null,
            'inputType'   => $field['input_type'] ?? null,
            'optionLabel' => $field['option_label'] ?? 'name',
            'optionValue' => $field['option_value'] ?? 'id',
            'options'     => $field['options'] ?? null,
            'default'     => $field['default'] ?? null,
        ];
    }

    /**
     * One field's concrete Add/Edit-modal markup, reusing generateField()'s
     * own field-type dispatch (same stub per widget type, same component,
     * same :error/:required/:disabled conventions every other generated
     * form already uses) rather than inventing a parallel one. Two
     * adjustments for this simpler, no-parent-props context: the model
     * root is this component's own local `draft` object, not a `form` prop
     * threaded through `props.hiddens`/`isFieldDisabled()` (inline-item
     * fields have no per-field hide/disable-from-parent concept -- every
     * configured field always renders and is only disabled via its own
     * `disabled` config key, already carried through as [[fieldDisabled]]).
     */
    protected function generateInlineItemModalField(array $field): string
    {
        $key = $field['key'];
        $widgetType = $field['type'] ?? 'input';

        // generateField()'s own dispatch keys the `type="..."` HTML attribute
        // off field_type, not a separate semantic-type key (see its final
        // `in_array($fieldType, ['input','email','password',...])` branch) --
        // an inputType override (e.g. 'email') only takes effect by BEING
        // the field_type passed in, for a plain 'input' widget.
        $effectiveFieldType = ($widgetType === 'input' && !empty($field['inputType']))
            ? $field['inputType']
            : $widgetType;

        $mapped = [
            'key'          => $key,
            'label'        => $field['label'] ?? $key,
            'field_type'   => $effectiveFieldType,
            'required'     => $field['required'] ?? false,
            'disabled'     => $field['disabled'] ?? false,
            'placeholder'  => $field['placeholder'] ?? '',
            'options'      => $field['options'] ?? null,
            'option_label' => $field['optionLabel'] ?? 'name',
            'option_value' => $field['optionValue'] ?? 'id',
            'api_url'      => $field['apiUrl'] ?? '',
            'decimals'     => $field['decimals'] ?? 0,
        ];

        $markup = $this->generateField($mapped);
        $markup = str_replace("form.{$key}", "draft.{$key}", $markup);
        // Collapse the always-true "not explicitly hidden" v-if generateField()
        // emits for every field by default -- there is no props.hiddens here.
        //
        // A field wrapped in `<template v-if="...">` (every plain input, textarea, select) must lose the
        // WRAPPER, not just the directive: stripping only the attribute left a bare `<template>`, which
        // Vue renders as a real (display:none) <template> element -- so the field was in the DOM and
        // never visible. Every text input in an inline-items or item-picker modal was invisible that
        // way; a `number` field escaped only because its stub puts the v-if on the component itself.
        $hiddenGuard = 'v-if="!props\.hiddens\?\.\[\'' . preg_quote($key, '/') . '\'\]"';
        $unwrapped = preg_replace('/^\s*<template\s+' . $hiddenGuard . '\s*>\s*(.*?)\s*<\/template>\s*$/s', '$1', $markup);
        if (is_string($unwrapped)) {
            $markup = $unwrapped;
        }
        $markup = preg_replace('/\s+' . $hiddenGuard . '/', '', $markup) ?? $markup;
        $markup = preg_replace('/\bisFieldDisabled\(\'' . preg_quote($key, '/') . '\'\)/', 'false', $markup) ?? $markup;

        return $markup;
    }

    /**
     * One field's read-only View-modal markup. A select/api-select field
     * with a STATIC options list resolves the stored value to its display
     * label; one backed by a live api_url (no local list to resolve
     * against here) shows the stored raw value as-is -- a real, honest v1
     * limitation rather than a guessed resolution.
     */
    protected function generateInlineItemViewField(array $field): string
    {
        $key   = $field['key'];
        $label = addslashes($field['label'] ?? $key);
        $type  = $field['type'] ?? 'input';

        if (in_array($type, ['select', 'api-select'], true) && !empty($field['options']) && is_array($field['options'])) {
            $optionLabel = $field['optionLabel'] ?? 'name';
            $optionValue = $field['optionValue'] ?? 'id';
            $optionsJs   = $this->arrayToJsObjectString($field['options']);
            $valueExpr   = "({$optionsJs}.find((o: any) => o.{$optionValue} === draft.{$key})?.{$optionLabel} ?? draft.{$key})";
        } elseif ($type === 'checkbox') {
            $valueExpr = "(draft.{$key} ? 'Yes' : 'No')";
        } else {
            $valueExpr = "draft.{$key}";
        }

        return <<<VUE
			<div>
				<p class="text-xs text-muted-foreground">{$label}</p>
				<p class="text-sm font-medium">{{ {$valueExpr} }}</p>
			</div>
VUE;
    }

    /**
     * One de-duplicated import line per form-field component the modal's markup actually uses.
     *
     * Read off the markup generateInlineItemModalField() emits rather than off a widget-name table: the
     * table drifted from the field stubs. A `number` field renders <NumberInputField> (generateField()
     * aliases it to the `number-input` stub) but the table only knew `number-input`, so the wrapper
     * imported InputField and the Quantity field never appeared in the picker's modal -- an unresolved
     * component renders nothing, and Vue says nothing about it in a production build. `date`/`time`
     * had the same problem in reverse (imported DateField, rendered InputField).
     */
    protected function buildInlineItemFieldImports(array $fields): string
    {
        $seen  = [];
        $lines = [];
        foreach ($fields as $field) {
            if (!preg_match_all('/<([A-Z][A-Za-z0-9]*Field)\b/', $this->generateInlineItemModalField($field), $matches)) {
                continue;
            }
            foreach ($matches[1] as $component) {
                if (isset($seen[$component])) {
                    continue;
                }
                $seen[$component] = true;
                $lines[] = "import {$component} from '@/components/form-fields/{$component}.vue'";
            }
        }

        return implode("\n", $lines);
    }

    /** `defaultDraft()`'s return literal -- each field's own `default`, or a type-appropriate blank. */
    protected function buildInlineItemDefaultsLiteral(array $fields): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $key = $field['key'];

            if (array_key_exists('default', $field) && $field['default'] !== null) {
                $default = $field['default'];
                if (is_bool($default)) {
                    $parts[] = "\t\t{$key}: " . ($default ? 'true' : 'false') . ',';
                } elseif (is_numeric($default)) {
                    $parts[] = "\t\t{$key}: {$default},";
                } else {
                    $parts[] = "\t\t{$key}: '" . addslashes((string) $default) . "',";
                }
                continue;
            }

            $blank = match ($field['type'] ?? 'input') {
                'checkbox'     => 'false',
                'number-input' => '0',
                default        => "''",
            };
            $parts[] = "\t\t{$key}: {$blank},";
        }

        return "{\n" . implode("\n", $parts) . "\n\t}";
    }

    protected function buildInlineItemRequiredKeysLiteral(array $fields): string
    {
        $required = array_values(array_filter(array_map(
            static fn (array $f): ?string => !empty($f['required']) ? "'{$f['key']}'" : null,
            $fields
        )));

        return '[' . implode(', ', $required) . ']';
    }

    protected function buildInlineItemRowActionButtons(bool $canView, bool $canEdit, bool $canDelete): string
    {
        $lines = [];
        if ($canView) {
            $lines[] = "\t\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"icon\" @click=\"openView(index)\"><Eye class=\"size-4\" /></Button>";
        }
        if ($canEdit) {
            $lines[] = "\t\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"icon\" @click=\"openEdit(index)\"><Pencil class=\"size-4\" /></Button>";
        }
        if ($canDelete) {
            $lines[] = "\t\t\t\t\t<Button type=\"button\" variant=\"ghost\" size=\"icon\" @click=\"openDelete(index)\"><Trash2 class=\"size-4\" /></Button>";
        }

        return implode("\n", $lines);
    }

    /**
     * Card variant (default): one bordered row per item, primary field bold
     * on its own line, every OTHER configured field rendered as a
     * "Label: {{ value }}" chip on the line below, view/edit/delete
     * icon-buttons on the right per the can_view/can_edit/can_delete gates.
     */
    protected function buildInlineItemsRowsMarkupCard(array $fields, string $primaryField, bool $canView, bool $canEdit, bool $canDelete): string
    {
        $secondaryFields = array_values(array_filter($fields, static fn (array $f): bool => $f['key'] !== $primaryField));

        $chipParts = [];
        foreach ($secondaryFields as $field) {
            $chipParts[] = "{$field['label']}: {{ item.{$field['key']} }}";
        }
        $chipsLine = implode(' &middot; ', $chipParts);
        $chipsMarkup = $chipsLine !== ''
            ? "\t\t\t\t<p class=\"text-xs text-muted-foreground truncate\">{$chipsLine}</p>\n"
            : '';

        $actionButtons = $this->buildInlineItemRowActionButtons($canView, $canEdit, $canDelete);

        return <<<VUE
	<div v-else class="divide-y rounded-md border mt-2">
		<div v-for="(item, index) in modelValue" :key="index" class="flex items-center justify-between gap-3 p-3">
			<div class="min-w-0 flex-1">
				<p class="text-sm font-medium truncate">{{ item.{$primaryField} }}</p>
{$chipsMarkup}			</div>
			<div class="flex shrink-0 items-center gap-1">
{$actionButtons}
			</div>
		</div>
	</div>
VUE;
    }

    /**
     * Table variant (`inline_items[].variant: 'table'`): a real `<table>`,
     * one `<th>`/`<td>` per field whose `show_in_table` isn't explicitly
     * false, `table_width` applied as a column width style, a `<tfoot>`
     * totals row when `totals` is configured (matching generateInlineItemsBlock()'s
     * existing @totals-change wiring on the PARENT side, unchanged).
     */
    protected function buildInlineItemsRowsMarkupTable(array $fields, array $itemConfig, bool $canView, bool $canEdit, bool $canDelete): string
    {
        $tableFields = array_values(array_filter($fields, static fn (array $f): bool => ($f['showInTable'] ?? true) !== false));

        $headers = [];
        $cells   = [];
        foreach ($tableFields as $field) {
            $style = !empty($field['tableWidth']) ? " style=\"width: {$field['tableWidth']}\"" : '';
            $headers[] = "\t\t\t\t<th class=\"px-3 py-2 text-left font-medium\"{$style}>{$field['label']}</th>";
            $cells[]   = "\t\t\t\t<td class=\"px-3 py-2\">{{ item.{$field['key']} }}</td>";
        }

        $totals = $itemConfig['totals'] ?? [];
        $footerRow = '';
        if (!empty($totals)) {
            $footerCells = [];
            foreach ($tableFields as $field) {
                $match = null;
                foreach ($totals as $total) {
                    if (($total['field'] ?? null) === $field['key']) {
                        $match = $total;
                        break;
                    }
                }
                $footerCells[] = $match !== null
                    ? "\t\t\t\t<td class=\"px-3 py-2 font-medium\">{{ totals.{$field['key']} }}</td>"
                    : "\t\t\t\t<td class=\"px-3 py-2\"></td>";
            }
            $footerRow = "\n\t\t<tfoot>\n\t\t\t<tr class=\"border-t font-medium\">\n" . implode("\n", $footerCells) . "\n\t\t\t\t<td></td>\n\t\t\t</tr>\n\t\t</tfoot>";
        }

        $headersText = implode("\n", $headers);
        $cellsText   = implode("\n", $cells);
        $actionButtons = $this->buildInlineItemRowActionButtons($canView, $canEdit, $canDelete);

        return <<<VUE
	<table v-else class="w-full text-sm mt-2">
		<thead>
			<tr class="border-b">
{$headersText}
				<th class="px-3 py-2 text-right font-medium">Actions</th>
			</tr>
		</thead>
		<tbody>
			<tr v-for="(item, index) in modelValue" :key="index" class="border-b">
{$cellsText}
				<td class="px-3 py-2 text-right">
					<div class="flex justify-end gap-1">
{$actionButtons}
					</div>
				</td>
			</tr>
		</tbody>{$footerRow}
	</table>
VUE;
    }

    /**
     * `totals`/`sync_to`'s CHILD-side half: a computed summing each
     * configured field across every row, emitted via @totals-change so
     * generateInlineItemsBlock()'s existing PARENT-side wiring (`form.
     * {syncField} = totals.{field}`, unchanged, un-touched by this
     * redesign) keeps working exactly as before.
     */
    protected function buildInlineItemsTotalsScriptBlock(array $totals): string
    {
        if (empty($totals)) {
            return '';
        }

        $this->assertInlineItemTotalsShape($totals);

        $sumLines = [];
        foreach ($totals as $total) {
            $field = $total['field'];
            $sumLines[] = "\t{$field}: modelValue.value.reduce((sum, item) => sum + (Number(item.{$field}) || 0), 0),";
        }
        $sumLinesText = implode("\n", $sumLines);

        return <<<JS


const totals = computed(() => ({
{$sumLinesText}
}))

watch(totals, (value) => emit('totals-change', value), { immediate: true })
JS;
    }

    /**
     * Component name for a module's item-picker wrapper -- mirrors
     * inlineItemsWrapperComponentName() exactly (same {Module}{StudlyKey}
     * naming convention), just its own suffix so an item-picker field and
     * an inline-items field on the same module never collide on name.
     */
    protected function itemPickerWrapperComponentName(string $key): string
    {
        return "{$this->moduleName}" . Str::studly($key) . 'ItemPicker';
    }

    /**
     * Emit `{Module}{Key}ItemPicker.vue` -- like writeInlineItemsWrapperComponent(),
     * fully concrete markup instead of a runtime JSON-config-driven generic
     * component (the old <ItemPickerComponent :available-items="..." ...>
     * this replaces), written once via writeFileOnce() so hand-edits survive
     * every future --force regenerate.
     *
     * item-picker picks EXISTING records from a splash-loaded catalog (an
     * `availableItems` config value names the splash key, e.g. "products" --
     * see generateSplashData()'s own item-picker branch, unchanged by this)
     * and configures each one via the SAME per-field Add/Edit-modal markup
     * inline-items already generates (configFields carries the identical
     * {key,label,type,...} shape as inline_items[].fields[], processed
     * through the same processInlineItemsFields() normalizer) -- so this
     * reuses generateInlineItemModalField()/buildInlineItemFieldImports()/
     * buildInlineItemDefaultsLiteral()/buildInlineItemRequiredKeysLiteral()/
     * buildInlineItemsRowsMarkupCard() directly rather than duplicating that
     * dispatch. Only the "browse available items" list and the optional
     * summary banner are genuinely new markup this method owns.
     *
     * `availableItems` is threaded through as a real PROP on the generated
     * component (`:available-items="{name}"`, wired at the embed site in
     * generateField()'s own item-picker branch) rather than assumed to be in
     * scope, since this is now a separate child component, not an inline
     * template snippet in the parent form's own scope.
     */
    protected function writeItemPickerWrapperComponent(array $field, string $key): string
    {
        $componentName = $this->itemPickerWrapperComponentName($key);

        $configFields  = $this->processInlineItemsFields($field['configFields'] ?? []);
        $browseFields  = $this->processInlineItemsFields($field['availableItemsFields'] ?? []);
        $primaryField  = $field['primaryField'] ?? ($configFields[0]['key'] ?? 'name');
        $label         = $field['label'] ?? ucwords(str_replace('_', ' ', $key));

        $addButtonText = addslashes($field['addButtonText'] ?? 'Add');
        $addModalTitle = addslashes($field['addModalTitle'] ?? "Configure {$label}");
        $editModalTitle = addslashes($field['editModalTitle'] ?? "Edit {$label}");
        $emptyMessage  = addslashes($field['emptyMessage'] ?? 'No items available');
        $selectedEmptyMessage = addslashes($field['selectedEmptyMessage'] ?? 'No items selected yet');
        $deleteMessage = addslashes($field['deleteMessage'] ?? "This will remove this {$label} entry.");

        $canEditSelected   = ($field['canEditSelected'] ?? true) !== false;
        $canDeleteSelected = ($field['canDeleteSelected'] ?? true) !== false;

        $rowsMarkup = $this->buildInlineItemsRowsMarkupCard($configFields, $primaryField, false, $canEditSelected, $canDeleteSelected);
        $availableListMarkup = $this->buildItemPickerAvailableListMarkup($browseFields, $primaryField, $addButtonText, $emptyMessage);
        $summaryBlock = $this->buildItemPickerSummaryBlock($field['summaryFields'] ?? []);

        $stub = $this->getTemplateContent('fields/item-picker-wrapper', 'frontend');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]'        => $componentName,
            '[[ModuleName]]'           => $this->moduleName,
            '[[label]]'                => $label,
            '[[primaryField]]'         => $primaryField,
            '[[addButtonText]]'        => $addButtonText,
            '[[addModalTitle]]'        => $addModalTitle,
            '[[editModalTitle]]'       => $editModalTitle,
            '[[emptyMessage]]'         => $emptyMessage,
            '[[selectedEmptyMessage]]' => $selectedEmptyMessage,
            '[[deleteMessage]]'        => $deleteMessage,
            '[[availableListMarkup]]'  => $availableListMarkup,
            '[[rowsMarkup]]'           => $rowsMarkup,
            '[[modalFieldsMarkup]]'    => implode("\n", array_map(fn (array $f) => $this->generateInlineItemModalField($f), $configFields)),
            '[[fieldImports]]'         => $this->buildInlineItemFieldImports($configFields),
            '[[defaultsLiteral]]'      => $this->buildInlineItemDefaultsLiteral($configFields),
            '[[requiredKeysLiteral]]'  => $this->buildInlineItemRequiredKeysLiteral($configFields),
            '[[summaryBlock]]'         => $summaryBlock,
        ]);

        $path = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    /**
     * The "browse available items" section: a client-side search box (the
     * catalog is already fully splash-loaded, no server round-trip needed)
     * over `availableItems`, one row per item showing its configured browse
     * columns (or just the primary field when none are configured), an Add
     * button per row that opens the shared config modal pre-seeded from
     * that item.
     */
    protected function buildItemPickerAvailableListMarkup(array $browseFields, string $primaryField, string $addButtonText, string $emptyMessage): string
    {
        $columns = array_values(array_filter($browseFields, static fn (array $f): bool => ($f['showInTable'] ?? true) !== false));

        if (empty($columns)) {
            $cellsMarkup = "\t\t\t\t<p class=\"text-sm font-medium truncate\">{{ item.{$primaryField} }}</p>";
        } else {
            $lines = [];
            foreach ($columns as $field) {
                $lines[] = "\t\t\t\t<span class=\"text-xs text-muted-foreground\">{$field['label']}: <span class=\"text-foreground\">{{ item.{$field['key']} }}</span></span>";
            }
            $cellsMarkup = implode("\n", $lines);
        }

        return <<<VUE
	<div class="space-y-2">
		<Input v-model="availableSearch" placeholder="Search..." class="max-w-sm" />
		<div class="divide-y rounded-md border">
			<div v-for="(item, index) in filteredAvailableItems" :key="index" class="flex items-center justify-between gap-3 p-3">
				<div class="min-w-0 flex-1 space-y-0.5">
{$cellsMarkup}
				</div>
				<Button type="button" variant="outline" size="sm" @click="openAddFrom(item)">
					<Plus class="size-4" />
					{$addButtonText}
				</Button>
			</div>
			<div v-if="filteredAvailableItems.length === 0" class="p-4 text-center text-sm text-muted-foreground">
				{$emptyMessage}
			</div>
		</div>
	</div>
VUE;
    }

    /**
     * Summary banner: count/sum/average per configured summaryFields entry.
     * `type: 'custom'` (a runtime format() callback in the old config-driven
     * component) has no generation-time equivalent -- skipped with a
     * generated comment naming it, a real v1 limitation rather than a
     * guessed resolution, matching generateInlineItemViewField()'s own
     * api_url-without-local-list precedent.
     */
    protected function buildItemPickerSummaryBlock(array $summaryFields): string
    {
        if (empty($summaryFields)) {
            return '';
        }

        $statMarkup = [];
        $skippedCustom = [];
        foreach ($summaryFields as $summary) {
            $summaryKey = $summary['key'] ?? '';
            $summaryLabel = addslashes($summary['label'] ?? $summaryKey);
            $type = $summary['type'] ?? 'count';

            $valueExpr = match ($type) {
                'count'   => 'modelValue.length',
                'sum'     => "modelValue.reduce((sum, item) => sum + (Number(item.{$summary['field']}) || 0), 0)",
                'average' => "(modelValue.length > 0 ? modelValue.reduce((sum, item) => sum + (Number(item.{$summary['field']}) || 0), 0) / modelValue.length : 0)",
                default   => null,
            };

            if ($valueExpr === null) {
                $skippedCustom[] = $summaryKey;
                continue;
            }

            $statMarkup[] = <<<VUE
				<div class="flex flex-col">
					<span class="text-xs text-muted-foreground">{$summaryLabel}</span>
					<span class="text-lg font-bold">{{ {$valueExpr} }}</span>
				</div>
VUE;
        }

        $skippedComment = !empty($skippedCustom)
            ? "\t<!-- Skipped: " . implode(', ', $skippedCustom) . " -- 'custom' summary fields need a hand-written format() callback with no generation-time equivalent; add the markup here by hand. -->\n"
            : '';

        if (empty($statMarkup)) {
            return $skippedComment;
        }

        $statsText = implode("\n", $statMarkup);

        return <<<VUE
{$skippedComment}	<Card v-if="modelValue.length > 0" class="p-4">
		<div class="flex flex-wrap items-center gap-4 md:gap-6">
{$statsText}
		</div>
	</Card>
VUE;
    }

    /**
     * Resolve the module name for a FK select field's "Add New" quick-create
     * affordance (`fields/api-select-inline.stub`) — the single source of
     * truth for whether a field gets it at all, so both call sites in
     * generateField() (the templateType-selection block that picks the stub,
     * and the replacements block that sets [[createFormModule]]) stay in
     * sync by construction rather than duplicating this logic. Returns null
     * (never a guess) unless eligible:
     *
     *   0. `$field['inline_create']` isn't explicitly `false` — the opt-out.
     *   1. `$field['create_form_module']`, if set, is a developer's explicit
     *      instruction and is honored — same precedence as every other explicit
     *      config override in this codebase (e.g. `endpoint.path`) — with two
     *      limits that are about the generated import, not about second-guessing
     *      the developer (see resolveExplicitInlineCreateModule()).
     *   2. Otherwise, fall back to `$field['relatedModule']` — already
     *      correctly resolved from real FK/`foreign_table` introspection by
     *      `IntrospectionToConfig::resolveRelatedModule()` (correctly
     *      plural, e.g. `locations` -> `Locations`), the SAME value the FK
     *      cell-renderer already trusts (see
     *      generateCustomCellRenderersFromListFields()). Deliberately not
     *      re-derived from the column name — an earlier version of this
     *      logic did exactly that (`Str::studly(str_replace('_id', '', $key))`),
     *      which guesses `Location` (singular) for `location_id` against a
     *      project convention of plural module names.
     *   3. Skip self-references (`Locations.parent_id` -> `Locations`) — a
     *      "Create Location" modal opening from inside a Location's own
     *      create form is confusing, not helpful.
     *   4. `{RelatedModule}CreateForm.vue` must actually exist on disk.
     *
     * Checked with `file_exists()`, not a `module.json` feature flag —
     * confirmed live against this package's real consuming project that
     * `features.frontend.create` in a module's persisted `module.json` is
     * routinely stale/absent even when a real, working `CreateForm.vue`
     * exists (the same class of config/reality drift already found this
     * session for `features.backend.list.bulk_actions`/`export`/`import`).
     * Trusting that flag here would make this default silently inert for
     * exactly the modules most likely to be an "Add New" target. The file's
     * existence is the one fact that actually determines whether the static
     * Vue `import` this generates will resolve — check that directly.
     *
     * Unlike `RelatedRecordLink` (a runtime string-prop lookup that degrades
     * to plain text for an unregistered target), the caller here needs that
     * static import — it can't degrade at runtime, so an unverifiable target
     * must fall back to a plain select at GENERATION time, not guess and
     * risk a build-time import-resolution failure. An explicit
     * `create_form_module` bypasses this check deliberately, same as any
     * other override.
     */
    protected function resolveInlineCreateModule(array $field): ?string
    {
        if (($field['inline_create'] ?? true) === false) {
            return null;
        }

        if (!empty($field['create_form_module'])) {
            return $this->resolveExplicitInlineCreateModule((string) $field['create_form_module']);
        }

        $relatedModule = $field['relatedModule'] ?? '';
        if ($relatedModule === '' || $relatedModule === $this->moduleName) {
            return null;
        }

        $importSegment = PathManager::resolveFrontendImportSegment($relatedModule);
        if ($importSegment === '') {
            return null;
        }

        $createFormPath = PathManager::getFrontendModulesPath()
            . "/{$importSegment}/Components/{$relatedModule}CreateForm.vue";

        return file_exists($createFormPath) ? $relatedModule : null;
    }

    /**
     * An explicit `create_form_module` skips the derivation above, and it still has to produce an
     * import that resolves, because unlike RelatedRecordLink this one is static: an unresolvable
     * path is a build failure in every form that carries it, not a runtime fallback.
     *
     *  - A module the frontend cannot place at all (in neither the project's registry nor the
     *    frontend's own modules.json) gives an empty import segment, i.e.
     *    `@/pages/modules//Components/XCreateForm.vue` — never right. No affordance.
     *  - A module that is NOT part of this project, only listed in the frontend's modules.json, is
     *    one the shell shipped: its CreateForm.vue exists or it never will, so it is checked like
     *    an auto-detected one. Found by rebuilding a 48-module project on the new frontend base,
     *    whose Users and Locations have a FormModal rather than a CreateForm: the project builder
     *    marks every FK select `create_form_module` = its related module, and 46 forms imported
     *    two files that were never there.
     *  - A module of this project (in the registry) is trusted without a file check: it is
     *    generated in the same run, possibly after this form, so its file may not exist yet.
     */
    protected function resolveExplicitInlineCreateModule(string $module): ?string
    {
        $segment = PathManager::resolveFrontendImportSegment($module);
        if ($segment === '') {
            return null;
        }

        if (PathManager::findModuleInRegistry($module) !== null) {
            return $module;
        }

        $createFormPath = PathManager::getFrontendModulesPath() . "/{$segment}/Components/{$module}CreateForm.vue";

        return file_exists($createFormPath) ? $module : null;
    }

    /**
     * Seam for the import-path segment used to build the inline-create
     * `import {Module}CreateForm from '@/pages/modules/{segment}/...'`
     * statement in generateFormFieldImports() -- deliberately a SEPARATE
     * override point from resolveInlineCreateModule() above (which only
     * gates whether inline-create applies at all). A mobile generator that
     * overrides just the gate but not this would still ask
     * resolveFrontendImportSegment() for WEB's nested {group}/{SubGroup}/
     * {Module} shape here, producing an import that doesn't exist on
     * MOBILE_APP's flat disk layout even for a module that legitimately
     * passed the (correctly mobile-aware) gate.
     */
    protected function resolveCreateFormImportSegment(string $moduleName): string
    {
        return PathManager::resolveFrontendImportSegment($moduleName);
    }

    protected function generateField(array $field, bool $trackFieldLabels = false): string
    {
        $key = $field['key'] ?? $field['name'];
        $type = $field['type'] ?? 'text';
        $fieldType = $field['field_type'] ?? $type;  // Use field_type if available
        $label = $field['label'] ?? $field['title'] ?? $this->generateFieldLabel($key);
        $required = isset($field['required']) && $field['required'] ? 'true' : 'false';
        $disabled = isset($field['disabled']) && $field['disabled'] ? 'true' : 'false';
        $placeholder = $field['placeholder'] ?? "Enter {$label}";
        // Format placeholder to remove underscores if present
        $placeholder = $this->formatPlaceholder($placeholder, $key);

        // Check if field should be hidden
        $isHidden = isset($field['hidden']) && $field['hidden'];
        // Generate condition: show if not explicitly hidden AND not in hiddens prop
        $hiddenCondition = $isHidden
            ? 'false' // Always hide if explicitly hidden
            : "!props.hiddens?.['{$key}']"; // Show if not in hiddens prop

        // Determine template based on field_type
        $templateType = $fieldType;

        // Normalize field_type aliases that don't have dedicated stub files
        $templateAliases = [
            'date-input'       => 'date',
            'datetime'         => 'date',
            'time-input'       => 'time',
            'boolean'          => 'checkbox',
            'toggle'           => 'checkbox',
            'switch'           => 'checkbox',
            'number'           => 'number-input',
            'email'            => 'input',
            'password'         => 'input',
            'text'             => 'input',
            'select_paginated' => 'api-select',
            'select-paginated' => 'api-select',
        ];
        if (isset($templateAliases[$templateType])) {
            $templateType = $templateAliases[$templateType];
        }

        // Handle boolean fields - can use checkbox or select with YES/NO
        if ($type === 'boolean' && $fieldType === 'select') {
            $templateType = 'select';
            // Boolean select will use static YES/NO options
        } elseif ($type === 'boolean' && $fieldType === 'checkbox') {
            $templateType = 'checkbox';
        } elseif ($fieldType === 'select') {
            $splashKey = $field['splashKey'] ?? null;
            $useApiSelect = false;

            // Use ApiSelect2 for model-backed sources; Select2 for custom/static data (e.g. YES/NO)
            if ($splashKey) {
                $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                $allSplash = array_merge($createSplash, $editSplash);

                foreach ($allSplash as $splashItem) {
                    if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                        $useApiSelect = true;
                        $templateType = 'api-select';
                        break;
                    }
                }

                // A splashKey with no matching createSplash/editSplash entry (every
                // actions[].fields caller, since actions never populate that config at
                // all — it's a Create/Edit-only mechanism) still means "model-backed
                // relation field", never "static options" (a genuinely static field
                // uses field['options'] instead, a separate branch below). Default to
                // api-select here too, matching InlineItemsFieldRenderer.vue's own
                // unconditional splashKey -> ApiSelect2Field resolution at runtime —
                // the fallback this replaces (a literal `splash.{field}` reference with
                // nothing anywhere declaring what `splash` even is) crashed the
                // generated component outright. Confirmed live 2026-08-20:
                // PurchaseOrders.recordPayment's account_id field.
                if (!$useApiSelect) {
                    $useApiSelect = true;
                    $templateType = 'api-select';
                }
            }

            // Upgrade to the inline "Add New" variant whenever resolveInlineCreateModule()
            // finds a verified target — default-on, not opt-in (see that
            // method's own docblock for the full eligibility contract).
            if ($templateType === 'api-select' && $this->resolveInlineCreateModule($field) !== null) {
                $templateType = 'api-select-inline';
            }
        } elseif ($fieldType === 'api-select') {
            // Direct api-select fields (not splash-backed) get the same
            // upgrade check — this branch didn't exist before, so a direct
            // api-select field never got inline-create even when explicitly
            // configured for it.
            if ($this->resolveInlineCreateModule($field) !== null) {
                $templateType = 'api-select-inline';
            }
        }

        // Defensive fallback: if no stub exists for this template type, fall back to 'input'
        // and log a warning so the issue is visible without breaking generation.
        $stubPath = $this->getStubPath("fields/{$templateType}", 'frontend');
        if (!file_exists($stubPath)) {
            // Reported through PathManager rather than the Log facade: the frontend
            // generators also run outside Laravel (FrontendPipeline / bin/gen-frontend),
            // where touching a facade fatals with "A facade root has not been set".
            // reportIssue() is the engine's own channel and the scaffolding commands
            // already wire it to console output, so this is strictly more visible than
            // a line in laravel.log that nobody reads mid-generation.
            PathManager::reportIssue(
                "No stub for field_type '{$fieldType}' (template '{$templateType}') on "
                . "{$this->moduleName}.{$key}. Falling back to 'input'."
            );
            $templateType = 'input';
        }

        $fieldTemplate = $this->getTemplateContent("fields/{$templateType}", 'frontend');

        $replacements = [
            '[[fieldKey]]' => $key,
            '[[fieldLabel]]' => $label,
            '[[fieldRequired]]' => $required,
            '[[fieldDisabled]]' => $disabled,
            '[[fieldPlaceholder]]' => $placeholder,
            '[[fieldHiddenCondition]]' => $hiddenCondition,
            '[[tabs]]' => "\t\t\t\t", // 4 tabs for proper indentation in grid
            '[[fieldLabelCapture]]' => '', // only api-select(-inline) sets this, and only when $trackFieldLabels
        ];

        // Add type-specific replacements
        if ($type === 'boolean' && $fieldType === 'select') {
            // Boolean field using Select2Field with YES/NO options
            // Format: [{ id: true, name: 'Yes' }, { id: false, name: 'No' }]
            $replacements['[[fieldOptions]]'] = "[\n\t\t\t\t\t{ id: true, name: 'Yes' },\n\t\t\t\t\t{ id: false, name: 'No' }\n\t\t\t\t]";
            $replacements['[[fieldOptionLabel]]'] = 'name';
            $replacements['[[fieldOptionValue]]'] = 'id';
            $replacements['[[fieldClearable]]'] = '';
        } elseif ($fieldType === 'select') {
            $splashKey = $field['splashKey'] ?? null;
            $useApiSelect = false;

            // Use ApiSelect2 for model-backed sources; Select2 for custom/static data (e.g. YES/NO)
            if ($splashKey) {
                $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                $allSplash = array_merge($createSplash, $editSplash);

                foreach ($allSplash as $splashItem) {
                    if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                        $useApiSelect = true;
                        // Convert splashKey to kebab-case for API endpoint (route pattern requires [a-z-]+)
                        // Str::kebab() only handles PascalCase, so convert snake_case manually
                        $endpointKey = str_replace('_', '-', $splashKey);
                        $apiEndpoint = "/select/{$endpointKey}";
                        $replacements['[[fieldApiEndpoint]]'] = $apiEndpoint;
                        $replacements['[[fieldPerPage]]'] = $field['per_page'] ?? 20;
                        $replacements['[[fieldMultiple]]'] = isset($field['multiple']) && $field['multiple'] ? 'true' : 'false';
                        $createFormModule = $this->resolveInlineCreateModule($field);
                        if ($createFormModule !== null) {
                            $replacements['[[createFormModule]]'] = $createFormModule;
                        }
                        break;
                    }
                }

                // No createSplash/editSplash match (every actions[].fields caller —
                // that config block is Create/Edit-only) still means "model-backed
                // relation field", never "static options" (that's the separate
                // field['options']-array case below). Default to api-select here too,
                // matching InlineItemsFieldRenderer.vue's own unconditional
                // splashKey -> ApiSelect2Field resolution at runtime. Uses
                // Str::kebab() ON TOP OF the existing manual underscore-to-hyphen
                // swap (not a straight replacement for it — see the matched branch
                // above, left untouched) because a hand-authored splash_key is
                // PascalCase (e.g. "Accounts", per docs/modules/actions.md), and
                // Str::kebab() alone leaves an already-snake_case value's
                // underscores untouched: Str::kebab('item_types') stays
                // 'item_types', not 'item-types' — confirmed directly against a
                // real Laravel install before writing this.
                if (!$useApiSelect) {
                    $useApiSelect = true;
                    $endpointKey = Str::kebab(str_replace('_', '-', $splashKey));
                    $replacements['[[fieldApiEndpoint]]'] = "/select/{$endpointKey}";
                    $replacements['[[fieldPerPage]]'] = $field['per_page'] ?? 20;
                    $replacements['[[fieldMultiple]]'] = isset($field['multiple']) && $field['multiple'] ? 'true' : 'false';
                    $createFormModule = $this->resolveInlineCreateModule($field);
                    if ($createFormModule !== null) {
                        $replacements['[[createFormModule]]'] = $createFormModule;
                    }
                }
            }

            if (!$useApiSelect) {
                // Static select - use splash options or inline options
                if (isset($field['options']) && is_array($field['options'])) {
                    // Inline options array provided
                    $replacements['[[fieldOptions]]'] = $this->arrayToJsObjectString($field['options']);
                } else {
                    // Use splash options
                    $replacements['[[fieldOptions]]'] = "splash." . ($field['options'] ?? Str::plural($key));
                }
            }

            $replacements['[[fieldOptionLabel]]'] = $field['option_label'] ?? 'name';
            $replacements['[[fieldOptionValue]]'] = $field['option_value'] ?? 'id';
            $replacements['[[fieldClearable]]'] = isset($field['clearable']) && $field['clearable'] ? ':clearable="true"' : '';
        } elseif ($fieldType === 'api-select') {
            // Direct api-select field (not splash-backed; api_url provided directly)
            $replacements['[[fieldApiEndpoint]]'] = $field['api_url'] ?? $field['apiUrl'] ?? '';
            $replacements['[[fieldOptionLabel]]']  = $field['option_label'] ?? $field['optionLabel'] ?? 'name';
            $replacements['[[fieldOptionValue]]']  = $field['option_value'] ?? $field['optionValue'] ?? 'id';
            $replacements['[[fieldPerPage]]']       = $field['per_page'] ?? $field['perPage'] ?? 20;
            $replacements['[[fieldMultiple]]']      = (isset($field['multiple']) && $field['multiple']) ? 'true' : 'false';
            $createFormModule = $this->resolveInlineCreateModule($field);
            if ($createFormModule !== null) {
                $replacements['[[createFormModule]]'] = $createFormModule;
            }
        } elseif ($fieldType === 'morph-select') {
            // Polymorphic type-selector: one config entry, two underlying form
            // keys (type_column + id_column) — see generateFormFields()'s own
            // morph-select special case for how the flat `form` object gets
            // both. No inline-create wiring here (explicitly out of scope).
            $idColumn = $field['id_column'] ?? '';
            $targets  = $field['targets'] ?? [];

            $replacements['[[fieldIdColumn]]'] = $idColumn;

            $typeOptions = array_map(
                static fn(array $t) => ['id' => $t['alias'], 'name' => $t['label'] ?? $t['alias']],
                $targets
            );
            $replacements['[[fieldTypeOptions]]'] = $this->arrayToJsObjectString($typeOptions);
            $replacements['[[fieldTargetMap]]'] = $this->generateMorphTargetMapLiteral($targets);
        } elseif ($templateType === 'number-input') {
            // Compares $templateType (already alias-resolved above, e.g. 'number' ->
            // 'number-input'), NOT the raw $fieldType -- actions[].fields entries reach
            // this method with field_type: 'number' (the canonical value
            // docs/modules/actions.md documents), never the internal 'number-input'
            // selector Create/Edit's own schema-derived fields always carry. Comparing
            // against raw $fieldType here meant this branch silently never fired for an
            // action's own number field, leaving `[[fieldDecimals]]` as a literal,
            // unsubstituted template token in the generated component. Confirmed live
            // 2026-08-20: PurchaseOrders.recordPayment's amount field.
            $decimals = $field['decimals'] ?? 0;
            $replacements['[[fieldDecimals]]'] = $decimals;
            $replacements['[[fieldType]]'] = ''; // No type attribute needed for NumberInputField
        } elseif ($fieldType === 'item-picker') {
            // Emit a hand-edit-protected wrapper component (write-once, see
            // writeItemPickerWrapperComponent()) instead of binding the old
            // shared <ItemPickerComponent> directly with inline config-array
            // props. `availableItems` stays a real prop reference (the
            // splash-loaded catalog var name, e.g. "products") passed from
            // this field's own embed point, since the wrapper is a separate
            // child component now, not an inline template snippet sharing
            // the parent form's own scope.
            $replacements['[[itemPickerWrapperComponent]]'] = $this->writeItemPickerWrapperComponent($field, $key);
            // The catalog is a splash key. The generated form keeps everything the splash route returned in
            // one `splash` ref (`splash.value = response.data`), so a bare `catalog` in the template was an
            // undefined identifier: the picker got `undefined`, threw on `.filter`, and took the whole form
            // down. `?? []` covers the render that happens before the splash response arrives.
            $replacements['[[availableItems]]'] = isset($field['availableItems'])
                ? "(splash['" . addslashes((string) $field['availableItems']) . "'] ?? [])"
                : '[]';
        } elseif ($fieldType === 'inline-items') {
            // Emit a hand-edit-protected wrapper component (write-once, see
            // writeInlineItemsWrapperComponent()) instead of binding
            // <InlineItemsComponent> directly with an inline :fields array.
            // The wrapper is what a module hand-fills with dynamicDisabled/
            // showField/render hooks and @item-change/@field-change
            // listeners for dependent-field scenarios (e.g. Order Items) --
            // regeneration never touches it again once it exists.
            $processedFields = $this->processInlineItemsFields($field['fields'] ?? []);
            $replacements['[[inlineItemsWrapperComponent]]'] = $this->writeInlineItemsWrapperComponent(
                $key,
                $processedFields,
                [
                    'primary_field'   => $field['primaryField'] ?? 'name',
                    'add_button_text' => $field['addButtonText'] ?? 'Add Item',
                    'empty_message'   => $field['emptyMessage'] ?? 'No items added',
                ]
            );
            // primaryField / addButtonText / emptyMessage are baked into the wrapper above, so
            // inline-items.stub passes it nothing but v-model. It used to pass them as attributes too,
            // which the wrapper (several root nodes, no props) can only warn about.
        } elseif ($fieldType === 'file-input') {
            // All of these map to real props on FileInputField.vue (verified against
            // SYSTEM_SHELL/FRONTEND/src/components/form-fields/FileInputField.vue) —
            // including enableCrop/aspectRatio/cropShape/uploadMode, which that
            // component handles itself via its own built-in ImageCropperModal.
            //
            // v-model does NOT point at form.[[fieldKey]] like every other field --
            // a File object can't live inside the reactive `form` object the same
            // way plain values do (see generateFormFields()'s file-input skip and
            // generateFileRefsBlock()). It binds to a separate ref<File|null>
            // instead, matching the hand-written MobileReleasesCreateForm.vue /
            // MediaCreateForm.vue reference pattern.
            $replacements['[[fieldModelRef]]'] = $this->fileRefName($key);
            $replacements['[[fieldMultiple]]'] = isset($field['multiple']) && $field['multiple'] ? 'true' : 'false';
            $replacements['[[fieldAccept]]'] = $field['accept'] ?? '';
            $replacements['[[fieldMaxSize]]'] = $field['maxSize'] ?? 5;
            $replacements['[[fieldMaxFiles]]'] = $field['maxFiles'] ?? 10;
            $replacements['[[fieldPreview]]'] = isset($field['preview']) && $field['preview'] ? 'true' : 'false';
            $replacements['[[fieldEnableCrop]]'] = isset($field['enableCrop']) && $field['enableCrop'] ? 'true' : 'false';
            $replacements['[[fieldAspectRatio]]'] = $field['aspectRatio'] ?? 0;
            $replacements['[[fieldCropShape]]'] = $field['cropShape'] ?? 'rect';
            $replacements['[[fieldUploadMode]]'] = $field['uploadMode'] ?? 'onSubmit';
            $replacements['[[fieldUploadUrl]]'] = $field['uploadUrl'] ?? '';
        } elseif ($fieldType === 'textarea') {
            $replacements['[[fieldClass]]'] = "class=\"col-span-full\"";
        } elseif (in_array($fieldType, ['input', 'email', 'password', 'date', 'time', 'number'])) {
            $replacements['[[fieldType]]'] = $fieldType !== 'input' ? "type=\"{$fieldType}\"" : '';
        }

        // ApiSelect2Field already emits @selected-object with the full chosen
        // option -- ApiSelect2Field's own event, unused until now. `form.key`
        // only ever holds the bare id (e.g. `10`), meaningless in a confirm
        // step's review summary, so capture the option's display label
        // ($replacements['[[fieldOptionLabel]]'], set above whenever this
        // resolved to api-select/api-select-inline) into a parallel
        // `fieldLabels` ref the summary can read from instead.
        if ($trackFieldLabels && in_array($templateType, ['api-select', 'api-select-inline'], true)) {
            $optionLabelProp = $replacements['[[fieldOptionLabel]]'] ?? 'name';
            $replacements['[[fieldLabelCapture]]'] = "\n{$replacements['[[tabs]]']}\t@selected-object=\"(obj: any) => { fieldLabels['{$key}'] = obj?.{$optionLabelProp} ?? '' }\"";
        }

        return $this->replacePlaceholders($fieldTemplate, $replacements);
    }

    protected function generateFormFields(array $config): string
    {
        $fieldDefinitions = [];

        // Fallback to old structure
        $fields = $config['fields'] ?? [];
        foreach ($fields as $field) {
            // File fields never live in the reactive `form` object -- a File
            // instance can't round-trip through form.value the way a plain
            // ref/JSON value does, and (per the hand-written
            // MobileReleasesCreateForm.vue / MediaCreateForm.vue reference
            // pattern) they're kept in their own separate `ref<File|null>`
            // instead, merged into the FormData payload at submit time.
            // See generateFileRefsBlock() / generateSubmitCall().
            if ($this->resolveFieldType($field) === 'file-input') {
                continue;
            }

            // morph-select is one config entry but TWO underlying flat `form`
            // keys — the type column (this field's own 'key', e.g.
            // 'payable_type') and the id column ('id_column', e.g.
            // 'payable_id'). Emitted as plain string/number|null values, same
            // as every other field — generateSubmitCall()/FormData handling
            // need no changes, they only ever see this flat `form` object.
            if ($this->resolveFieldType($field) === 'morph-select') {
                $typeKey = $field['key'] ?? $field['name'];
                $idKey   = $field['id_column'] ?? '';
                $fieldDefinitions[] = "  {$typeKey}: ''";
                $fieldDefinitions[] = "  {$idKey}: null as number | null";
                continue;
            }

            $key = $field['key'] ?? $field['name'];
            $defaultValue = $this->getFieldDefaultValue($field);
            $fieldDefinitions[] = "  {$key}: {$defaultValue}";
        }

        return implode(",\n", $fieldDefinitions);
    }

    /**
     * Resolve a field's effective "type" for file/boolean detection, regardless
     * of whether it's a raw config field (which carries 'field_type') or one
     * that's already passed through mapNewFormFieldsToLegacy() (which folds
     * field_type into 'type' and drops the 'field_type' key entirely).
     */
    protected function resolveFieldType(array $field): string
    {
        return $field['field_type'] ?? $field['type'] ?? '';
    }

    /**
     * True for a field rendered as an API-backed picker (ApiSelect2Field): an explicit
     * `field_type: 'api-select'`, or the older `select` + `splashKey`. This is the rule generateField()
     * uses to choose the picker template -- and so the rule for whether it attaches the label-capture
     * handler that writes `fieldLabels[key]`.
     *
     * The wizard's review step and its state block decided "is this an FK select?" from the older shape
     * alone (`type === 'select'` with a `splashKey`), so a field from introspection (`field_type:
     * 'api-select'`, `type: 'text'`) got the capture handler in its template while `fieldLabels` was
     * never declared: choosing any option threw, the picker stayed open, and the review step showed the
     * raw id. Found by the super-suite fixture's SuiteTickets (create wizard and Escalate action).
     *
     * @param array<string, mixed> $field
     */
    protected function isApiBackedSelect(array $field): bool
    {
        $fieldType = $this->resolveFieldType($field);

        return $fieldType === 'api-select'
            || ($fieldType === 'select' && !empty($field['splashKey']));
    }

    protected function isBooleanFieldType(string $fieldType): bool
    {
        return in_array($fieldType, ['checkbox', 'boolean', 'toggle', 'switch'], true);
    }

    /**
     * True if any field in the given list (raw or mapped-to-legacy shape) is a
     * file-input field -- the trigger for switching a generated form's submit
     * handler from sendPostRequest/sendPutRequest (plain JSON) to
     * sendFormDataRequest (multipart), per the conditional-switching approach:
     * forms with zero file-input fields are generated exactly as before.
     */
    protected function hasFileInputField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->resolveFieldType($field) === 'file-input') {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> Only the file-input fields, in original order. */
    protected function extractFileInputFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field): bool => $this->resolveFieldType($field) === 'file-input'
        ));
    }

    /**
     * Collect a flat field list from either the new sections-based config
     * shape or the old flat 'fields' shape -- mirrors the same fallback logic
     * already used by generateFormFieldImports().
     */
    protected function collectAllFieldsFromConfig(array $config): array
    {
        $sections = $config['sections'] ?? [];
        if (empty($sections)) {
            return $config['fields'] ?? [];
        }

        $all = [];
        foreach ($sections as $section) {
            $all = array_merge($all, $section['fields'] ?? []);
        }
        return $all;
    }

    /**
     * camelCase ref name for a file-input field's separate `ref<File|null>`.
     * Matches the hand-written convention seen in MobileReleasesCreateForm.vue
     * (apk_file -> apkFile, ota_file -> otaFile) for keys that already end in
     * a file-ish suffix, and appends "File" otherwise (e.g. image_path ->
     * imagePathFile) so the ref name never collides with an unrelated
     * camelCased key and always reads unambiguously as a file ref.
     */
    protected function fileRefName(string $key): string
    {
        $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
        $lower = strtolower($key);

        if ($lower === 'file') {
            return 'file';
        }

        foreach (['_file', '_image', '_document', '_photo', '_attachment'] as $suffix) {
            if (Str::endsWith($lower, $suffix)) {
                return $camel;
            }
        }

        return $camel . 'File';
    }

    /**
     * The `import {...} from "@/helpers"` line for a create/edit form.
     * File-input forms only ever need sendGetRequest (splash/view) +
     * sendFormDataRequest; non-file forms keep the exact import they had
     * before this feature existed.
     */
    protected function generateRequestImportLine(bool $hasFileFields, string $formType): string
    {
        if ($hasFileFields) {
            return 'import {sendGetRequest, sendFormDataRequest} from "@/helpers";';
        }

        return $formType === 'edit'
            ? 'import {sendGetRequest, sendPostRequest, sendPutRequest} from "@/helpers";'
            : 'import {sendGetRequest, sendPostRequest} from "@/helpers";';
    }

    /**
     * Declare a separate `ref<File|null>` per file-input field, placed outside
     * the reactive `form` object -- exactly the pattern used by hand in
     * MobileReleasesCreateForm.vue / MediaCreateForm.vue. Empty string (no-op)
     * when there are no file-input fields, so the common case gets zero diff.
     */
    protected function generateFileRefsBlock(array $fileFields): string
    {
        if (empty($fileFields)) {
            return '';
        }

        $lines = ['', '// File refs'];
        foreach ($fileFields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $ref = $this->fileRefName($key);
            $lines[] = "const {$ref} = ref<File | null>(null)";
        }

        return implode("\n", $lines);
    }

    /**
     * Build the handleSubmit() request call. When no file-input fields are
     * present this is byte-for-byte what the generator emitted before this
     * feature existed (regression guard). When file-input fields ARE present,
     * emits the FormData path: spread the non-file `form.value` fields,
     * converting booleans to '1'/'0' strings (a FormData-only requirement --
     * the JSON path never needed this), then conditionally merge each file
     * ref's value in, then call sendFormDataRequest.
     */
    protected function generateSubmitCall(array $fields, string $formType): string
    {
        $fileFields = $this->extractFileInputFields($fields);

        if (empty($fileFields)) {
            return $formType === 'edit'
                ? 'const response = await sendPutRequest(submitEndpoint.value, { ...form.value })'
                : 'const response = await sendPostRequest(submitEndpoint.value, form.value)';
        }

        $booleanLines = [];
        foreach ($fields as $field) {
            $fieldType = $this->resolveFieldType($field);
            if ($fieldType === 'file-input' || !$this->isBooleanFieldType($fieldType)) {
                continue;
            }
            $key = $field['key'] ?? $field['name'] ?? '';
            $booleanLines[] = "\t\t\t\t{$key}: form.value.{$key} ? '1' : '0',";
        }

        $fileAssignmentLines = [];
        foreach ($fileFields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $ref = $this->fileRefName($key);
            $fileAssignmentLines[] = "\t\t\tif ({$ref}.value) formData.{$key} = {$ref}.value";
        }

        $booleanBlock = !empty($booleanLines) ? "\n" . implode("\n", $booleanLines) : '';
        $fileAssignmentBlock = !empty($fileAssignmentLines) ? "\n" . implode("\n", $fileAssignmentLines) : '';

        // sendFormDataRequest always issues a POST (multipart PUT bodies aren't
        // reliably parsed by PHP), but the generated edit route is registered as
        // PUT (see RouteGenerator). Laravel's Request::enableHttpMethodParameterOverride()
        // -- enabled unconditionally for every request via Request::capture() --
        // resolves a POST to the PUT route when a `_method` field is present, the
        // same spoofing technique Blade's @method('PUT') directive uses for
        // native HTML file-upload forms. Without this, an edit form with a file
        // field would POST to a route only registered for PUT and 404.
        $methodOverrideLine = $formType === 'edit' ? "\n\t\t\t\t_method: 'PUT'," : '';

        return "const formData: Record<string, any> = {\n\t\t\t\t...form.value,{$methodOverrideLine}{$booleanBlock}\n\t\t\t}{$fileAssignmentBlock}\n\t\t\tconst response = await sendFormDataRequest(submitEndpoint.value, formData)";
    }

    protected function getFieldDefaultValue(array $field): string
    {
        $type = $field['type'] ?? 'text';
        $default = $field['default'] ?? '';

        // Both v-model an ARRAY (a plain-row list, or a list of configured
        // selections) -- checked before the generic $default/switch logic
        // below, which only ever produces a string/number/boolean literal
        // and would otherwise seed form.{key} as '' for these two field
        // types. Found live 2026-09-19 regenerating a real item-picker
        // field: `modelValue.value.push(...)` on the wrapper's own v-model
        // throws immediately on a string. A pre-existing bug, not
        // introduced by either mechanism's concrete-markup redesign.
        // Checks BOTH 'field_type' (a raw, not-yet-mapped config field) and
        // 'type' (mapNewFormFieldsToLegacy()'s own output shape, which
        // folds field_type into 'type' and drops the 'field_type' key
        // entirely -- confirmed live: checking 'field_type' alone silently
        // never matched, since generateFormFields() always receives the
        // already-mapped shape).
        if (in_array($field['field_type'] ?? $field['type'] ?? '', ['item-picker', 'inline-items'], true)) {
            return '[]';
        }

        if ($default !== '') {
            // Handle boolean defaults
            if ($type === 'boolean') {
                return $default === 'true' || $default === true || $default === 1 ? 'false' : 'false';
            }
            return "'{$default}'";
        }

        // A numeric input declares modelValue as Number | Null, so seeding it
        // with '' produced "Invalid prop: type check failed for prop
        // modelValue — Expected Number | Null, got String with value ''" on
        // every create form with a numeric column. $type here is the
        // field_type-derived component selector ('number-input'/'checkbox'),
        // not the semantic type, so this checks 'dataType' (preserved
        // separately by mapNewFormFieldsToLegacy()) directly rather than the
        // switch below — the same fix applies to boolean fields, whose
        // CheckboxField declares modelValue: Boolean and produced the
        // identical warning for the same reason ('checkbox' !== 'boolean').
        $dataType = $field['dataType'] ?? null;
        if ($dataType === 'number') {
            return 'null as number | null';
        }
        if ($dataType === 'boolean') {
            return 'false';
        }

        switch ($type) {
            case 'boolean':
                return 'false';
            case 'select':
                return "'' as string | number";
            case 'number':
                return 'null as number | null';
            case 'textarea':
            case 'text':
            case 'email':
            case 'password':
            default:
                return "''";
        }
    }

    protected function generateFormFieldImports(array $config): string
    {
        $sections = $config['sections'] ?? [];
        $imports = [];

        // Collect all fields to check for ApiSelect2 usage
        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
        } else {
            // New section-based structure - collect all fields from all sections
            $allFields = [];
            foreach ($sections as $section) {
                $sectionFields = $section['fields'] ?? [];
                $allFields = array_merge($allFields, $sectionFields);
            }
            $fields = $allFields;
        }

        $fieldTypes = array_unique(array_column($fields, 'field_type'));
        if (empty($fieldTypes)) {
            // Fallback to 'type' column if 'field_type' not available
            $fieldTypes = array_unique(array_column($fields, 'type'));
        }

        $hasApiSelect = false;
        $hasSelect2 = false;
        $inlineCreateImports = [];
        $inlineItemsWrapperImports = [];

        // Determine which select components are needed: ApiSelect2 for model sources, Select2 for custom/static
        foreach ($fields as $field) {
            $fieldType = $field['field_type'] ?? $field['type'] ?? '';

            // Each inline-items field gets its OWN wrapper component (see
            // writeInlineItemsWrapperComponent()), so this is a per-field
            // import, not a per-type one like every other case in the switch
            // below -- a form with two inline-items fields needs two
            // different sibling imports, not one shared package import.
            if ($fieldType === 'inline-items') {
                $key = $field['key'] ?? $field['name'] ?? '';
                $componentName = $this->inlineItemsWrapperComponentName($key);
                $inlineItemsWrapperImports[] = "import {$componentName} from './{$componentName}.vue';";
            }

            // Same per-field-wrapper reasoning as inline-items just above --
            // see writeItemPickerWrapperComponent().
            if ($fieldType === 'item-picker') {
                $key = $field['key'] ?? $field['name'] ?? '';
                $componentName = $this->itemPickerWrapperComponentName($key);
                $inlineItemsWrapperImports[] = "import {$componentName} from './{$componentName}.vue';";
            }

            if ($fieldType === 'select') {
                $hasSelect2 = true;
                $splashKey = $field['splashKey'] ?? null;
                if ($splashKey) {
                    $createSplash = $this->config['features']['backend']['createSplash']['splashData'] ?? [];
                    $editSplash = $this->config['features']['backend']['editSplash']['splashData'] ?? [];
                    $allSplash = array_merge($createSplash, $editSplash);

                    $matched = false;
                    foreach ($allSplash as $splashItem) {
                        if (($splashItem['key'] ?? '') === $splashKey && ($splashItem['type'] ?? 'model') === 'model') {
                            $matched = true;
                            $hasApiSelect = true;
                            $createModule = $this->resolveInlineCreateModule($field);
                            if ($createModule !== null) {
                                $importSegment = $this->resolveCreateFormImportSegment($createModule);
                                $inlineCreateImports[] = "import {$createModule}CreateForm from '@/pages/modules/{$importSegment}/Components/{$createModule}CreateForm.vue';";
                            }
                            break;
                        }
                    }

                    // Mirrors generateField()'s own unmatched-splashKey fallback (see that
                    // method's own comment for the full rationale) -- without this, a field
                    // that generateField() correctly renders as ApiSelect2Field (its own
                    // fallback firing) would still never get an import for it here, since
                    // $hasApiSelect only became true above on an actual createSplash match.
                    // Confirmed live 2026-08-20: exactly this would have left
                    // PurchaseOrders.recordPayment's account_id field referencing an
                    // unresolved <ApiSelect2Field> component even after fixing generateField().
                    if (!$matched) {
                        $hasApiSelect = true;
                        $createModule = $this->resolveInlineCreateModule($field);
                        if ($createModule !== null) {
                            $importSegment = $this->resolveCreateFormImportSegment($createModule);
                            $inlineCreateImports[] = "import {$createModule}CreateForm from '@/pages/modules/{$importSegment}/Components/{$createModule}CreateForm.vue';";
                        }
                    }
                }
            } elseif ($fieldType === 'api-select') {
                // Direct api-select fields (not splash-backed) need the same
                // inline-create import collection the 'select' branch above
                // does — this didn't exist before, so a direct api-select
                // field's [[createFormModule]] (set in generateField()) had
                // no matching import, an unresolved-component reference.
                $createModule = $this->resolveInlineCreateModule($field);
                if ($createModule !== null) {
                    $importSegment = $this->resolveCreateFormImportSegment($createModule);
                    $inlineCreateImports[] = "import {$createModule}CreateForm from '@/pages/modules/{$importSegment}/Components/{$createModule}CreateForm.vue';";
                }
            }
        }

        foreach ($fieldTypes as $type) {
            switch ($type) {
                case 'api-select':
                case 'api-select-inline':
                    // Direct api-select fields (field_type set to 'api-select' by IntrospectionToConfig)
                    $imports[] = "import ApiSelect2Field from '@/components/form-fields/ApiSelect2Field.vue';";
                    break;
                case 'select':
                    if ($hasApiSelect) {
                        $imports[] = "import ApiSelect2Field from '@/components/form-fields/ApiSelect2Field.vue';";
                    }
                    if ($hasSelect2) {
                        $imports[] = "import Select2Field from '@/components/form-fields/Select2Field.vue';";
                    }
                    break;
                case 'checkbox':
                case 'boolean':
                case 'toggle':
                case 'switch':
                    $imports[] = "import CheckboxField from '@/components/form-fields/CheckboxField.vue';";
                    break;
                case 'number-input':
                case 'number':
                    // 'number' is generateField()'s own $templateAliases entry for
                    // 'number-input' (the canonical field_type value
                    // docs/modules/actions.md documents for actions[].fields — Create/
                    // Edit's own schema-derived fields always carry the already-aliased
                    // 'number-input' form instead). $fieldTypes here is collected
                    // straight from raw field_type/type values with no alias
                    // resolution, so 'number' used to fall into the generic
                    // date/input/email/password/default group below, importing plain
                    // InputField -- Vue then warned "Failed to resolve component"
                    // for the NumberInputField the generated template still
                    // referenced. Confirmed live 2026-08-20: PurchaseOrders.
                    // recordPayment's amount field.
                    $imports[] = "import NumberInputField from '@/components/form-fields/NumberInputField.vue';";
                    break;
                case 'item-picker':
                    // No shared-package import here -- see the
                    // $inlineItemsWrapperImports loop above. Each field
                    // imports its own generated wrapper component instead
                    // of the old shared ItemPickerComponent directly.
                    break;
                case 'inline-items':
                    // No shared-package import here -- see $inlineItemsWrapperImports
                    // above, merged into $allImports below. Each field imports its
                    // own generated wrapper component instead of InlineItemsComponent
                    // directly.
                    break;
                case 'file-input':
                    // FileInputField.vue is the real component in SYSTEM_SHELL/FRONTEND
                    // (FileInputFieldWithCropper.vue does not exist there — it only ever
                    // existed in an unrelated legacy project). FileInputField.vue already
                    // has a built-in cropper (enableCrop/aspectRatio/cropShape props +
                    // its own ImageCropperModal), so no functionality is lost by importing it.
                    $imports[] = "import FileInputField from '@/components/form-fields/FileInputField.vue';";
                    break;
                case 'textarea':
                    $imports[] = "import TextAreaField from '@/components/form-fields/TextAreaField.vue';";
                    break;
                case 'morph-select':
                    // Hand-authored, one-time SYSTEM_SHELL/FRONTEND component
                    // composing Select2Field (type dropdown) + ApiSelect2Field
                    // (record picker) -- never generated or touched by this
                    // package. No inline-create wiring (out of scope).
                    $imports[] = "import MorphSelectField from '@/components/form-fields/MorphSelectField.vue';";
                    break;
                case 'date':
                case 'input':
                case 'email':
                case 'password':
                default:
                    $imports[] = "import InputField from '@/components/form-fields/InputField.vue';";
                    break;
            }
        }

        // Merge inline create imports and inline-items wrapper imports (deduplicated)
        $allImports = array_unique(array_merge($imports, $inlineCreateImports, $inlineItemsWrapperImports));

        return implode("\n", $allImports);
    }

    protected function generateSplashData(array $config): string
    {
        $sections = $config['sections'] ?? [];
        $splashFields = [];

        $processFields = function($fields) use (&$splashFields, &$processFields) {
            foreach ($fields as $field) {
                if ($field['type'] === 'select') {
                    $options = $field['options'] ?? ($field['key'] ?? $field['name']) . 's';
                    $splashFields[] = "\t{$options}: []";
                } elseif (($field['field_type'] ?? '') === 'item-picker' && isset($field['availableItems'])) {
                    $splashFields[] = "\t{$field['availableItems']}: []";
                } elseif (($field['field_type'] ?? '') === 'inline-items' && isset($field['fields'])) {
                    // Recurse for inline-items fields
                    $processFields($field['fields']);
                }
            }
        };

        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
            $processFields($fields);
        } else {
            // New section-based structure
            foreach ($sections as $section) {
                $fields = $section['fields'] ?? [];
                $processFields($fields);
            }
        }

        return implode(",\n", array_unique($splashFields));
    }

    protected function hasSplashData(array $config): bool
    {
        $sections = $config['sections'] ?? [];

        $checkFields = function($fields) use (&$checkFields) {
            foreach ($fields as $field) {
                if ($field['type'] === 'select') {
                    return true;
                }
                if (($field['field_type'] ?? '') === 'item-picker' && isset($field['availableItems'])) {
                    return true;
                }
                if (($field['field_type'] ?? '') === 'inline-items' && isset($field['fields'])) {
                    if ($checkFields($field['fields'])) {
                        return true;
                    }
                }
            }
            return false;
        };

        if (empty($sections)) {
            // Fallback to old structure
            $fields = $config['fields'] ?? [];
            if ($checkFields($fields)) return true;
        } else {
            // New section-based structure
            foreach ($sections as $section) {
                $fields = $section['fields'] ?? [];
                if ($checkFields($fields)) return true;
            }
        }

        return false;
    }

    /**
     * Given a column name, resolve it to a relationship (relation name +
     * display field) purely from column/create-field metadata -- no
     * dot-path needed in the caller's own config. Column-authored View/List
     * field configs frequently store the raw FK id column ("location_id")
     * rather than a relationship dot-path ("location?.name"), which every
     * existing relationship-aware render path (generateInformationRows(),
     * generateHeaderBadges(), generateCustomCellRenderersFromListFields())
     * only recognizes via `strpos($dataPath, '.')`. This is the fallback
     * those call sites use when that dot is missing, so an unconfigured or
     * naively-configured module still resolves real names instead of
     * showing a raw id.
     *
     * Returns null for anything that isn't a `foreignId` column with a
     * `relatedModule` (i.e. not a relationship at all).
     */
    protected function resolveColumnRelationship(string $columnName): ?array
    {
        $column = null;
        foreach ($this->config['columns'] ?? [] as $c) {
            if (($c['name'] ?? null) === $columnName) {
                $column = $c;
                break;
            }
        }
        if (!$column || ($column['type'] ?? null) !== 'foreignId' || empty($column['relatedModule'])) {
            return null;
        }

        // Eloquent's relationsToArray() snake-cases relation keys in the
        // actual JSON response regardless of the camelCase relation method
        // name (see deriveRelationshipMethodName(), inherited from
        // BaseGenerator -- the same rule ModelGenerator's own belongsTo()
        // generation uses, so this always matches the Model's real relation).
        $relation = Str::snake($this->deriveRelationshipMethodName($columnName));

        $displayField = 'name';
        foreach (['create', 'edit'] as $formType) {
            foreach ($this->config['features']['frontend'][$formType]['fields'] ?? [] as $f) {
                $fieldKey = $f['field'] ?? $f['name'] ?? null;
                if ($fieldKey === $columnName && !empty($f['option_label'])) {
                    $displayField = $f['option_label'];
                    break 2;
                }
            }
        }

        return [
            'relation' => $relation,
            'displayField' => $displayField,
            // Deliberately scoped to "column literally named ..._status_id"
            // rather than a fuzzier relatedModule-name heuristic, which
            // could misfire on non-status lookup tables (Locations,
            // Vendors, Categories, ...).
            'isStatusLike' => str_ends_with($columnName, 'status_id'),
        ];
    }

    // View generation helper methods (shared across view generators)
    protected function mapViewFieldsToInformationFields(array $fields): array
    {
        $mapped = [];
        foreach ($fields as $field) {
            $dataPath = $field['data'] ?? '';
            $label = $field['title'] ?? '';
            
            // Check if this is a relationship field (has dot notation like "district.name" or "item_category?.name")
            if (!empty($dataPath) && strpos($dataPath, '.') !== false) {
                // Clean the path - remove optional chaining operators for processing
                // "item_category?.name" -> "item_category.name"
                $cleanPath = str_replace('?.', '.', $dataPath);
                $cleanPath = str_replace('?', '', $cleanPath);
                
                // Split the clean path: "item_category.name" -> relationship: "item_category", displayField: "name"
                $parts = explode('.', $cleanPath);
                $relationship = $parts[0];
                $displayField = $parts[1] ?? 'name';
                
                // Generate label if not provided
                if (empty($label)) {
                    // Generate relationship label (remove _id suffix if present, convert to Title Case)
                    $relationshipLabel = $this->generateFieldLabel($relationship);
                    // For display field, just capitalize first letter (usually "name", "title", etc.)
                    $displayFieldLabel = ucfirst($displayField);
                    $label = $relationshipLabel . ' ' . $displayFieldLabel;
                }
                
                // Eloquent's relationsToArray() snake-cases relation keys in the
                // actual JSON response regardless of the camelCase relation method
                // name (e.g. an itemCategory() relation method surfaces as
                // "item_category" in the response) — normalize here so the
                // generated data path matches the real API response shape instead
                // of silently referencing a key that never exists.
                $relationshipKey = Str::snake($relationship);

                $mapped[] = [
                    'key' => $relationshipKey, // Use snake_cased relationship name as key
                    'label' => $label,
                    'type' => 'foreignKey',
                    'related_module' => $relationship,
                    'displayField' => $displayField,
                    'dataPath' => $relationshipKey . '.' . $displayField, // snake_cased to match Eloquent's relationsToArray() JSON keys
                    'group' => $field['group'] ?? null,
                ];
            } else {
                // Regular field (no dot notation) -- but may still be a
                // relationship the config just never authored a dot-path
                // for (raw FK id column). Resolve from column metadata
                // before falling all the way to plain text.
                $key = $dataPath ?: 'name';
                if (empty($label)) {
                    $label = $this->generateFieldLabel($key);
                }

                $relInfo = $key ? $this->resolveColumnRelationship($key) : null;
                if ($relInfo !== null) {
                    $mapped[] = [
                        'key' => $relInfo['relation'],
                        'label' => $label,
                        'type' => 'foreignKey',
                        'related_module' => $relInfo['relation'],
                        'displayField' => $relInfo['displayField'],
                        'dataPath' => $relInfo['relation'] . '.' . $relInfo['displayField'],
                        'isStatusLike' => $relInfo['isStatusLike'],
                        'group' => $field['group'] ?? null,
                    ];
                    continue;
                }

                // Check if it's a boolean field
                $type = $field['type'] ?? 'text';
                if ($type === 'boolean' || strpos(strtolower($key), 'is_') === 0) {
                    $type = 'boolean';
                }

                $mapped[] = [
                    'key' => $key,
                    'label' => $label,
                    'type' => $type,
                    'group' => $field['group'] ?? null,
                ];
            }
        }
        return $mapped;
    }

    /**
     * Buckets mapViewFieldsToInformationFields()'s flat output into
     * generateInformationSection()'s $groups shape when any field declares
     * a 'group' key, preserving group-of-first-appearance order and
     * stripping the now-redundant 'group' key off each field entry before
     * it reaches generateInformationRows(). Returns ['fields' => $mappedFields]
     * unchanged when no field sets 'group' -- byte-identical output to
     * before this existed for every module that doesn't opt in.
     *
     * @return array{fields: array}|array{groups: array}
     */
    protected function bucketViewFieldsIntoGroups(array $mappedFields): array
    {
        $hasGroups = false;
        foreach ($mappedFields as $field) {
            if (!empty($field['group'])) {
                $hasGroups = true;
                break;
            }
        }
        if (!$hasGroups) {
            return ['fields' => array_map(function (array $field) {
                unset($field['group']);
                return $field;
            }, $mappedFields)];
        }

        $buckets = []; // groupLabel => field[]
        foreach ($mappedFields as $field) {
            $groupLabel = $field['group'] ?? '';
            unset($field['group']);
            $buckets[$groupLabel][] = $field;
        }

        $groups = [];
        foreach ($buckets as $groupLabel => $fields) {
            $groups[] = $groupLabel !== '' ? ['label' => $groupLabel, 'fields' => $fields] : ['fields' => $fields];
        }

        return ['groups' => $groups];
    }

    protected function generateViewSections(array $config): string
    {
        $sections = $config['sections'] ?? [
            [
                'key' => 'information',
                'title' => 'Overview',
                'fields' => $config['columns'] ?? []
            ]
        ];
        $sectionContent = [];

        foreach ($sections as $section) {
            $key = $section['key'] ?? '';
            $title = $section['title'] ?? '';
            $icon = $section['icon'] ?? 'InfoIcon';
            $fields = $section['fields'] ?? [];
            $groups = $section['groups'] ?? [];
            $sectionContent[] = $this->generateInformationSection($title, $icon, $fields, $groups);
        }

        return implode("\n\n", $sectionContent);
    }

    /**
     * Render one field's info row -- shared between the flat single-list
     * layout and each column of a grouped layout (see generateInformationSection())
     * so both paths produce byte-identical row markup for the same field.
     *
     * @return string[]
     */
    private function generateInformationRows(array $fields): array
    {
        $rows = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? $field['name'] ?? '';
            $label = $field['label'] ?? '';
            $type = $field['type'] ?? 'text';

            if ($type === 'foreignKey') {
                $relationship = $field['related_module'] ?? $field['relationship'] ?? '';
                $displayField = $field['displayField'] ?? 'name';
                $relationshipSnake = $relationship !== '' ? Str::snake($relationship) : '';

                if (!empty($field['dataPath'])) {
                    $dataPath = $field['dataPath'];
                    $vueExpression = 'data?.' . str_replace('.', '?.', $dataPath);
                    $valueHtml = "{{ {$vueExpression} || 'N/A' }}";
                } elseif (!empty($relationship)) {
                    $valueHtml = "{{ data?.{$relationshipSnake}?.{$displayField} || 'N/A' }}";
                } else {
                    $valueHtml = "{{ data?.{$key} || 'N/A' }}";
                }

                // Status-like relationships (resolveColumnRelationship()'s
                // isStatusLike flag) get a small color dot next to the
                // resolved name, same `.color` convention StatusBadge/
                // generateRelationshipBadge() already use.
                if (!empty($field['isStatusLike']) && $relationshipSnake !== '') {
                    $valueHtml = "<span class=\"h-2 w-2 rounded-full shrink-0\" :style=\"data?.{$relationshipSnake}?.color ? `background-color: \${data?.{$relationshipSnake}.color}` : ''\"></span>{$valueHtml}";
                }

                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right inline-flex items-center gap-1.5 justify-end\">{$valueHtml}</span>\n\t\t\t\t</div>";
            } elseif ($type === 'boolean') {
                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right\" :class=\"{'text-green-500': data?.{$key}, 'text-red-500': !data?.{$key}}\">{{ data?.{$key} ? 'Yes' : 'No' }}</span>\n\t\t\t\t</div>";
            } else {
                $rows[] = "\t\t\t\t<div class=\"flex items-center justify-between gap-3 px-4 py-2.5 border-b border-border/60\">\n\t\t\t\t\t<span class=\"text-xs text-muted-foreground shrink-0\">{$label}</span>\n\t\t\t\t\t<span class=\"text-xs font-semibold text-right\">{{ data?.{$key} || 'N/A' }}</span>\n\t\t\t\t</div>";
            }
        }

        return $rows;
    }

    /**
     * @param array $groups  Optional. When non-empty, each entry is
     *                       ['fields' => [...], 'label' => string (optional)]
     *                       and renders as its own divided column inside ONE
     *                       Card instead of the default single stacked field
     *                       list -- e.g. a 3-column grouped info panel like
     *                       ONGEZA_PRO_SYSTEM's BudgetExpensesDetailsOverviewPage.vue.
     *                       An omitted/empty 'label' renders a bare column
     *                       with no heading (the original, pre-'label' shape
     *                       -- still supported so an existing consumer that
     *                       only ever set 'fields' keeps identical output).
     *                       $fields is ignored when $groups is supplied.
     *                       Omitted/empty $groups (the default) produces
     *                       byte-identical output to before this parameter
     *                       existed.
     */
    protected function generateInformationSection(string $title, string $icon, array $fields, array $groups = []): string
    {
        if (!empty($groups)) {
            $columns = [];
            foreach ($groups as $group) {
                $rowsContent = implode("\n", $this->generateInformationRows($group['fields'] ?? []));
                $labelHtml = !empty($group['label'])
                    ? "\t\t\t\t\t<div class=\"px-4 pt-3 pb-1 text-[11px] font-medium uppercase tracking-wide text-muted-foreground\">{$group['label']}</div>\n"
                    : '';
                $columns[] = "\t\t\t\t<div>\n{$labelHtml}{$rowsContent}\n\t\t\t\t</div>";
            }
            $columnCount = count($groups);
            $columnsContent = implode("\n", $columns);

            return "<Card class=\"gap-0 overflow-hidden p-0\">\n\t\t\t<div class=\"px-4 py-3 border-b\">\n\t\t\t\t<span class=\"text-sm font-semibold\">{$title}</span>\n\t\t\t</div>\n\t\t\t<CardContent class=\"p-0\">\n\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-{$columnCount} divide-y md:divide-y-0 md:divide-x\">\n{$columnsContent}\n\t\t\t\t</div>\n\t\t\t</CardContent>\n\t\t</Card>";
        }

        $rowsContent = implode("\n", $this->generateInformationRows($fields));

        return "<Card class=\"gap-0 overflow-hidden p-0\">\n\t\t\t<div class=\"px-4 py-3 border-b\">\n\t\t\t\t<span class=\"text-sm font-semibold\">{$title}</span>\n\t\t\t</div>\n\t\t\t<CardContent class=\"p-0\">\n\t\t\t\t<div class=\"grid grid-cols-1 md:grid-cols-2\">\n{$rowsContent}\n\t\t\t\t</div>\n\t\t\t</CardContent>\n\t\t</Card>";
    }

    protected function generateFormatDateImport(array $config): string
    {
        return "import { formatDate } from '@/helpers'";
    }

    /**
     * @param string $stateVar The Vue ref/prop name holding the fetched record
     *        in the caller's own generated file (e.g. 'record' for
     *        details_layout.stub, 'data' for a component whose own prop is
     *        literally named `data`). Every emitted expression below is
     *        prefixed with this, so it MUST match the caller's actual state
     *        variable or the badge silently never renders (v-if on an
     *        undefined variable is falsy, not an error — see the two bugs
     *        fixed 2026-08-06 below).
     *
     * Bug 1 (fixed 2026-08-06): this method's only real caller,
     * ViewLayoutGenerator (details_layout.stub), names its fetched record
     * `record`, never `data` — every header badge this method ever emitted
     * for a DetailsLayout.vue page referenced a `data` variable that does
     * not exist in that file, so `v-if="data?.{field}"` was always falsy and
     * the badge never rendered, for any module, regardless of field. Fixed
     * by threading the caller's real state variable name through instead of
     * hardcoding 'data'.
     *
     * Bug 2 (fixed 2026-08-06, found live on SYSTEM_SHELL's `Roles` module):
     * the badge-eligible-field auto-detect matched any field whose data-path
     * last segment merely *contained* the substring 'status' or 'type' —
     * true of `role_type`, a plain string column, not a relationship — and
     * then unconditionally treated it as `type: 'relationship'`, parsing
     * the bare one-segment key "role_type" as relationship="role_type",
     * displayPath="name" (the `explode('.', ...)` fallback default). The
     * emitted badge then rendered `{{ record?.role_type?.name }}` — reading
     * `.name` off a plain string is always undefined, so a real,
     * non-relationship "type"/"status"-named field's badge would never show
     * its value even once bug 1 above is fixed. Fixed: a field is only ever
     * classified 'relationship' when its data path is genuinely multi-segment
     * (contains a literal '.', e.g. "status?.name") — the real signal this
     * config shape uses for "this is a resolved FK display path" per
     * IntrospectionToConfig::buildViewFields(). A bare single-segment field
     * name (any plain scalar column, "type"/"status"-named or not) now falls
     * through to the plain-text badge branch instead.
     */
    protected function generateHeaderBadges(array $config, string $stateVar = 'data'): string
    {
        $headerConfig = $config['features']['frontend']['view']['header'] ?? [];
        $badges = $headerConfig['badges'] ?? [];

        // Auto-detect common status/type fields if no badges configured
        if (empty($badges)) {
            $viewConfig = $config['features']['frontend']['view'] ?? [];
            $fields = $viewConfig['fields'] ?? [];

            foreach ($fields as $field) {
                $key = $field['data'] ?? '';
                $lastSegment = $key ? preg_replace('/^.*\./', '', $key) : '';

                // Check for status or type fields
                if (str_contains($lastSegment, 'status') || str_contains($lastSegment, 'type')) {
                    $icon = str_contains($lastSegment, 'type') ? 'TagIcon' : 'InfoIcon';

                    // A bare single-segment key (e.g. "role_type") could
                    // still be a genuine relationship the config just never
                    // authored a dot-path for (raw FK id column, e.g.
                    // "status_id") -- resolve from column metadata (a much
                    // more precise signal than the substring match above)
                    // before falling back to a plain scalar text badge.
                    $relInfo = !str_contains($key, '.') ? $this->resolveColumnRelationship($key) : null;

                    if ($relInfo !== null) {
                        $badges[] = [
                            'relationship' => $relInfo['relation'],
                            'displayPath' => $relInfo['displayField'],
                            'type' => 'relationship',
                            'icon' => $icon,
                            'showColor' => $relInfo['isStatusLike'],
                        ];
                    } else {
                        $badges[] = [
                            'data' => $key,
                            // Only a genuinely multi-segment path (e.g. "status?.name")
                            // is a resolved FK display path -- a bare single-segment
                            // key (e.g. "role_type") is always a plain scalar column.
                            'type' => str_contains($key, '.') ? 'relationship' : 'text',
                            'icon' => $icon,
                            'showColor' => false
                        ];
                    }
                }
            }
        }

        if (empty($badges)) {
            return '';
        }

        $badgeContent = [];
        foreach ($badges as $badge) {
            $type = $badge['type'] ?? 'text';
            $icon = $badge['icon'] ?? null;
            $showColor = $badge['showColor'] ?? false;

            if ($type === 'relationship') {
                // New structure: relationship and displayPath
                $relationship = $badge['relationship'] ?? '';
                $displayPath = $badge['displayPath'] ?? 'name';

                // Fallback to data field if relationship not set (backward compatibility)
                if (empty($relationship) && !empty($badge['data'])) {
                    // Parse old format: "country?.name" -> relationship: "country", displayPath: "name"
                    $parts = preg_replace('/\\?/', '', $badge['data']);
                    $segments = explode('.', $parts);
                    $relationship = $segments[0] ?? '';
                    $displayPath = $segments[1] ?? 'name';
                }

                $badgeContent[] = $this->generateRelationshipBadge($relationship, $displayPath, $icon, $showColor, $stateVar);
            } else {
                $field = $badge['data'] ?? '';
                $badgeContent[] = $this->generateTextBadge($field, $icon, $stateVar);
            }
        }

        return implode("\n\t\t\t\t", $badgeContent);
    }

    protected function generateBadgeImport(array $config): string
    {
        $view = $config['features']['frontend']['view'] ?? [];
        $headerConfig = $view['header'] ?? [];
        $badges = $headerConfig['badges'] ?? [];

        if (empty($badges)) {
            return '';
        }

        return "import { Badge } from '@/components/ui/badge'";
    }

    protected function generateRelationshipBadge(string $relationship, string $displayPath, ?string $icon, bool $showColor, string $stateVar = 'data'): string
    {
        $iconComponent = $icon ? "<component :is=\"icons.{$icon}\" class=\"h-3 w-3\" />" : '';

        // Color uses relationship.color for styling
        $colorStyle = $showColor ? ":style=\"{$stateVar}?.{$relationship}?.color ? `border-color: \${{$stateVar}?.{$relationship}.color}; background-color: \${{$stateVar}?.{$relationship}.color}20;` : ''\"" : '';
        $colorIndicator = $showColor ? "<span class=\"h-2 w-2 rounded-full\" :style=\"{$stateVar}?.{$relationship}?.color ? `background-color: \${{$stateVar}?.{$relationship}.color}` : ''\"></span>" : '';

        // Display uses relationship.displayPath
        $displayValue = "{{ {$stateVar}?.{$relationship}?.{$displayPath} }}";

        return "<Badge v-if=\"{$stateVar}?.{$relationship}\"
\t\t\t\tvariant=\"outline\"
\t\t\t\tclass=\"px-3 py-1 flex items-center gap-1\"
\t\t\t\t{$colorStyle}
\t\t\t>
\t\t\t\t{$iconComponent}
\t\t\t\t{$colorIndicator}
\t\t\t\t{$displayValue}
\t\t\t</Badge>";
    }

    protected function generateTextBadge(string $field, ?string $icon, string $stateVar = 'data'): string
    {
        $iconComponent = $icon ? "<component :is=\"icons.{$icon}\" class=\"h-3 w-3\" />" : '';

        return "<Badge v-if=\"{$stateVar}?.{$field}\"
\t\t\t\tvariant=\"outline\"
\t\t\t\tclass=\"px-3 py-1 flex items-center gap-1\"
\t\t\t>
\t\t\t\t{$iconComponent}
\t\t\t\t{{ {$stateVar}?.{$field} }}
\t\t\t</Badge>";
    }

    protected function generateHeaderActions(array $config): string
    {
        $headerConfig = $config['features']['frontend']['view']['header'] ?? [];
        $customActions = $headerConfig['customActions'] ?? [];

        $actionContent = [];

        // Add configured custom actions
        foreach ($customActions as $action) {
            $type = $action['type'] ?? 'button';
            $component = $action['component'] ?? '';
            $label = $action['label'] ?? 'Action';
            $props = $action['props'] ?? [];

            if ($type === 'modal') {
                $propsString = implode(' ', array_map(fn($prop) => ":{$prop}=\"{$prop}\"", $props));
                $actionContent[] = "<{$component} {$propsString} />";
            } else {
                $actionContent[] = "<Button size=\"sm\" variant=\"outline\" class=\"h-6 px-2 flex items-center gap-1\">
\t\t\t\t\t{$label}
\t\t\t\t</Button>";
            }
        }

        // Add delegations as header actions (only modal type)
        $customFeatures = $config['delegations'] ?? [];
        foreach ($customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'modal' || $uiType === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $label = $customFeature['label'] ?? $featureName;
                $icon = $customFeature['icon'] ?? 'ListIcon';
                $componentName = $this->moduleName . $featureName . 'Modal';
                $modalStateVar = 'show' . $featureName . 'Modal';

                $actionContent[] = "<Button size=\"sm\" variant=\"outline\" class=\"h-6 px-2 flex items-center gap-1\" @click=\"{$modalStateVar} = true\">
\t\t\t\t\t<component :is=\"icons['{$icon}']\" class=\"h-3.5 w-3.5\" />
\t\t\t\t\t<span>{$label}</span>
\t\t\t\t</Button>
\t\t\t\t<{$componentName} :is-open=\"{$modalStateVar}\" @update:open=\"{$modalStateVar} = \$event\" :parent-uuid=\"recordId\" />";
            }
        }

        if (empty($actionContent)) {
            return '';
        }

        return implode("\n\t\t\t\t", $actionContent);
    }

    protected function generateTabNavigation(array $config): string
    {
        $viewConfig = $config['features']['frontend']['view'] ?? [];
        $tabs = $viewConfig['tabs'] ?? [];

        $tabContent = [];

        // Always add the default overview tab first
        $tabContent[] = "\t{ id: 'overview', label: 'Overview', icon: icons.BookOpenIcon }";
        $tabContent[] = "\t{ id: 'history', label: 'History', icon: icons.HistoryIcon }";

        // Add configured tabs
        foreach ($tabs as $tab) {
            $id = $tab['id'] ?? '';
            $label = $tab['label'] ?? '';
            $icon = $tab['icon'] ?? 'BookOpenIcon';

            $tabContent[] = "\t{ id: '{$id}', label: '{$label}', icon: icons.{$icon} }";
        }

        // Add delegations as tabs (only tab type)
        $customFeatures = $config['delegations'] ?? [];
        foreach ($customFeatures as $featureKey => $customFeature) {
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                $rawId = $customFeature['name'] ?? $featureKey;

                // MUST match FrontendRoutesGenerator, which registers the child
                // route as Str::kebab($customFeature['name']). details_layout.stub
                // links each tab to `.../details/${tab.id}`, so a StudlyCase id
                // here produced `/details/ScratchItems` against a route declared
                // as `scratch-items`. Vue Router paths are case-sensitive, so the
                // link matched nothing: every delegation tab rendered in the nav
                // and went nowhere when clicked. The built-in overview/history
                // tabs were unaffected because their ids already equal their paths.
                $id = Str::kebab($rawId);
                $label = $customFeature['label'] ?? ucfirst($rawId);
                $icon = $customFeature['icon'] ?? 'ListIcon';

                $tabContent[] = "\t{ id: '{$id}', label: '{$label}', icon: icons.{$icon} }";
            }
        }

        return implode(",\n", $tabContent);
    }

    protected function generateCustomFeatureImports(array $config): string
    {
        $imports = [];
        $customFeatures = $config['delegations'] ?? [];

        foreach ($customFeatures as $featureKey => $customFeature) {
            if (($customFeature['displayType'] ?? '') === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $componentName = $this->moduleName . $featureName . 'Modal';
                $imports[] = "import {$componentName} from './{$componentName}.vue'";
            }
        }

        return !empty($imports) ? implode("\n", $imports) : '';
    }

    protected function generateCustomFeatureModalStates(array $config): string
    {
        $states = [];
        $customFeatures = $config['delegations'] ?? [];

        foreach ($customFeatures as $featureKey => $customFeature) {
            if (($customFeature['displayType'] ?? '') === 'header-action') {
                $featureName = Str::studly($customFeature['name'] ?? $featureKey);
                $modalStateVar = 'show' . $featureName . 'Modal';
                $states[] = "const {$modalStateVar} = ref(false)";
            }
        }

        return !empty($states) ? implode("\n", $states) : '';
    }

    /**
     * Generate fields from columns when fields array is empty
     * This is a fallback to ensure forms have fields even if they weren't explicitly configured
     */
    protected function generateFieldsFromColumns(array $config, string $featureType = 'create'): array
    {
        $columns = $config['columns'] ?? [];
        $fields = [];

        foreach ($columns as $column) {
            // Skip system columns
            if (in_array($column['name'] ?? '', ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }

            $columnName = $column['name'] ?? '';
            if (empty($columnName)) {
                continue;
            }

            $columnType = $column['type'] ?? 'string';
            $nullable = $column['nullable'] ?? false;

            // Determine field type based on column type
            $type = 'text';
            $fieldType = 'input';
            $splashKey = '';
            $decimals = null;

            if ($columnType === 'foreignId') {
                $type = 'text';
                $fieldType = 'select';
                // splashData[].key is conventionally snake_case plural (e.g. "accounts", "order_items")
                // not the PascalCase module name. Normalize here so the field's splashKey
                // actually matches the splashData entry the splash-service generator emits.
                $related = $column['relatedModule'] ?? '';
                $splashKey = $related ? Str::snake(Str::plural($related)) : '';
            } elseif ($columnType === 'date' || $columnType === 'datetime' || $columnType === 'timestamp') {
                $type = 'date';
                $fieldType = 'date';
            } elseif (in_array($columnType, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger'])) {
                $type = 'number';
                $fieldType = 'number-input';
                $decimals = 0;
            } elseif (in_array($columnType, ['float', 'double', 'decimal'])) {
                $type = 'number';
                $fieldType = 'number-input';
                $decimals = 2;
            } elseif ($columnType === 'boolean') {
                $type = 'boolean';
                $fieldType = 'checkbox';
            } elseif (in_array($columnType, ['text', 'longText', 'mediumText'])) {
                $type = 'text';
                $fieldType = 'textarea';
            }

            $fieldLabel = $this->generateFieldLabel($columnName);
            $field = [
                'field' => $columnName,
                'label' => $fieldLabel,
                'placeholder' => "Enter {$fieldLabel}",
                'type' => $type,
                'field_type' => $fieldType,
                'splashKey' => $splashKey,
                'required' => !$nullable,
            ];

            if ($decimals !== null) {
                $field['decimals'] = $decimals;
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Build the four splash code blocks for a form stub.
     *
     * Returns an array of [splashPropBlock, splashBlock, refreshAndSetBlock, onMountedBlock].
     *
     * When $hasSplash is false every block is an empty string (or a no-op onMounted line),
     * so the generated Vue file has zero splash artefacts.
     *
     * When $hasSplash is true the full splash plumbing is returned, keyed to the given
     * $formType ('create' or 'edit').
     *
     * @param string $formType  'create' or 'edit'
     * @param bool   $hasSplash Whether constants are declared in the module config
     * @return array{string, string, string, string}
     */
    protected function buildSplashBlocks(string $formType, bool $hasSplash): array
    {
        if (!$hasSplash) {
            // No splash: prop omitted, no computed endpoint, no ref — but
            // refreshAndSet(key, value) must still perform the "set" half of
            // its name.
            //
            // Bug (fixed 2026-08-06): every inline-create-eligible FK field
            // (api-select-inline.stub / the splash-backed select's inline
            // variant, both wired via generateField()'s
            // resolveInlineCreateModule() path — default since v2.30.0) emits
            // an `@created` handler that calls
            // `refreshAndSet('{fieldKey}', data.id)` expecting the newly
            // created related record's id to land in `form.{fieldKey}`. That
            // call is unconditional — it does not care whether the module
            // also happens to need a splash endpoint. The no-splash branch
            // here dropped both arguments on the floor, so "Add New {Thing}"
            // successfully created the related record but never selected it
            // into the form; the user had to search for it again by hand.
            // Every module without a `constants`-driven splash (the common
            // case) was affected. Fix: still apply key/value when given, just
            // skip the network fetch there is no splash endpoint to make.
            $splashPropBlock    = '';
            $splashBlock        = '';
            $refreshAndSetBlock = <<<'TS'
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    if (key != null && value != null) (form.value as any)[key] = value
    isLoading.value = false
}
TS;
            $onMountedBlock     = 'await refreshAndSet()';

            return [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock];
        }

        // With splash
        $splashPropBlock = "splashUrl: {default: null},\n\t";

        // Resolve moduleRoute eagerly here — this string is used as a replacement value
        // inside replacePlaceholders() so [[moduleRoute]] inside it won't be processed again.
        $moduleRoute = Str::kebab($this->moduleName);
        $endpoint = $formType === 'edit'
            ? "props.splashUrl || '/{$moduleRoute}/edit/splash'"
            : "props.splashUrl || '/{$moduleRoute}/create/splash'";

        $splashBlock = <<<TS
const splashEndpoint = computed(() => {
\treturn {$endpoint}
})

// Options loaded from splash endpoint
const splash = ref<Record<string, any>>({})
const hasSplash = true

TS;

        $refreshAndSetBlock = <<<'TS'
// Use this to build external data for selection while you are still on this page.
// Special use case in select fields.
async function refreshAndSet(key: string|null = null, value: any|null = null) {
    isLoading.value = true
    const response = await sendGetRequest(splashEndpoint.value)
    if (response.status) {
        splash.value = response.data
        if (key != null && value != null) (form.value as any)[key] = value
    } else {
        toast.error('Failed to load form data. Please refresh the page.')
    }
    isLoading.value = false
}
TS;

        $onMountedBlock = 'await refreshAndSet()';

        return [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock];
    }

    // ─── Draft autosave helpers ─────────────────────────────────────────────

    /**
     * Build the "Save as Draft" wiring for a generated Create/Edit form --
     * the same server-backed autosave (generic Core/Drafts backend +
     * useDraft.ts composable) that was previously only hand-wired into
     * Users' own CreateForm/EditForm as a reference implementation. On by
     * default (opt-out via features.frontend.{create|edit}.drafts: false in
     * module.json), since every module has the same generic substrate
     * available.
     *
     * Every returned block is '' when $hasDrafts is false, so a module that
     * opts out generates byte-identical output to before this feature
     * existed -- zero draft artefacts.
     *
     * Create and edit deliberately use DIFFERENT UX here:
     *  - Edit already has a natural unique key (the record's own uuid) --
     *    there is only ever one possible draft, so it keeps the simple
     *    single-slot DraftRestoreBanner (restore/discard).
     *  - Create has NO natural unique key -- multiple unrelated create
     *    attempts for the same module (opened at different times, or one
     *    standalone + one via a nested "+ Add New" quick-create popup) used
     *    to all collapse onto one upsert slot, so opening a second one
     *    surfaced -- and silently overwrote -- the first's draft. Fixed by
     *    giving every create form mount its own fresh client-generated key
     *    (useDraftList().newDraftKey()) instead of a fixed sentinel, and
     *    replacing the single-draft banner with DraftListPanel -- a picker
     *    showing every draft this create context currently has, letting the
     *    user resume any one of them (switches the active key) or discard
     *    any of them, while typing always autosaves to whichever key is
     *    currently active.
     *
     * @param string $formType 'create' or 'edit'
     * @param bool $hasDrafts
     * @return array{draftBannerBlock: string, draftImports: string, draftWatchImport: string, draftSetupBlock: string, discardDraftOnSuccess: string, draftCheckBlock: string, draftWatchBlock: string, draftContextProp: string}
     */
    protected function buildDraftBlocks(string $formType, bool $hasDrafts): array
    {
        if (!$hasDrafts) {
            return [
                'draftBannerBlock' => '',
                'draftImports' => '',
                'draftWatchImport' => '',
                'draftSetupBlock' => '',
                'discardDraftOnSuccess' => '',
                'draftCheckBlock' => '',
                'draftWatchBlock' => '',
                'draftContextProp' => '',
            ];
        }

        return $formType === 'edit'
            ? $this->buildEditDraftBlocks()
            : $this->buildCreateDraftBlocks();
    }

    /** Single-slot restore/discard banner -- see buildDraftBlocks()'s docblock for why edit stays simple. */
    private function buildEditDraftBlocks(): array
    {
        $draftBannerBlock = <<<'VUE'
<DraftRestoreBanner
			v-if="hasDraft"
			:updated-at="draftUpdatedAt"
			class="mb-3"
			@restore="restoreDraft"
			@discard="dismissDraft"
		/>
VUE;

        $draftImports = <<<'TS'
import { useDraft } from '@/composables/useDraft';
import DraftRestoreBanner from '@/components/DraftRestoreBanner.vue';
TS;

        // shelui-engine fork: the draft's record key is THIS module's own
        // record identifier prop -- 'uuid' when ModuleConfigContract::hasUuid(),
        // else 'id' (see idParam()'s docblock above), not the literal 'uuid'
        // this previously always read off props regardless of what the
        // EditForm actually declares (features/edit/form.stub resolves the
        // SAME prop name).
        $useDraftCall = "useDraft('{$this->moduleName}', '{$this->moduleGroup}', 'edit', props.{$this->idParam()})";

        $draftSetupBlock = <<<TS
// Draft autosave -- server-backed, generic Core/Drafts substrate.
const { hasDraft, draftPayload, draftUpdatedAt, checkForDraft, saveDraft, scheduleDraftSave, discardDraft } = {$useDraftCall}

const restoreDraft = () => {
\tif (draftPayload.value) {
\t\tform.value = { ...form.value, ...draftPayload.value }
\t\t// A field left blank when the draft was saved round-trips through the
\t\t// backend's global ConvertEmptyStringsToNull middleware as null, not
\t\t// '' -- same reason the loaded-record path above coerces this (see its
\t\t// own comment); InputField's modelValue only accepts String | Number.
\t\tObject.keys(form.value).forEach((key) => {
\t\t\tif (form.value[key] === null) {
\t\t\t\tform.value[key] = ''
\t\t\t}
\t\t})
\t}
\thasDraft.value = false
}

const dismissDraft = () => {
\tdiscardDraft()
}

const isSavingDraft = ref(false)
const handleSaveDraftClick = async () => {
\tisSavingDraft.value = true
\tconst response = await saveDraft(form.value)
\tisSavingDraft.value = false
\tif (response.status) {
\t\ttoast.success('Draft saved')
\t} else {
\t\ttoast.error(response.message || 'Failed to save draft')
\t}
}
TS;

        return [
            'draftBannerBlock' => $draftBannerBlock,
            'draftImports' => $draftImports,
            'draftWatchImport' => ', watch',
            'draftSetupBlock' => $draftSetupBlock,
            'discardDraftOnSuccess' => 'discardDraft()',
            'draftCheckBlock' => 'await checkForDraft()',
            'draftWatchBlock' => <<<'TS'

// Debounced draft autosave -- skipped while the form is still hydrating
// (isLoading) so the initial mount/load-data pass never itself counts as a
// user edit worth drafting.
watch(form, (value) => {
	if (!isLoading.value) {
		scheduleDraftSave(value)
	}
}, { deep: true })
TS,
            'draftContextProp' => '',
        ];
    }

    /** Multi-draft picker -- see buildDraftBlocks()'s docblock for the full rationale. */
    private function buildCreateDraftBlocks(): array
    {
        $draftBannerBlock = <<<'VUE'
<DraftListPanel
			:drafts="drafts"
			:active-key="activeDraftKey"
			class="mb-3"
			@resume="handleResumeDraft"
			@delete="handleDeleteDraft"
		/>
VUE;

        $draftImports = <<<'TS'
import { useDraft, useDraftList } from '@/composables/useDraft';
import DraftListPanel from '@/components/DraftListPanel.vue';
TS;

        $useDraftListCall = "useDraftList('{$this->moduleName}', '{$this->moduleGroup}')";
        $useDraftCall     = "useDraft('{$this->moduleName}', '{$this->moduleGroup}', 'create', activeDraftKey)";

        $draftSetupBlock = <<<TS
// Draft autosave -- server-backed, generic Core/Drafts substrate. Every
// create-form mount gets its OWN fresh draft key (see onMounted below) --
// multiple unrelated in-progress creates for this module never collide.
const activeDraftKey = ref<string | null>(null)
const { drafts, loadDrafts, deleteDraft, newDraftKey } = {$useDraftListCall}
const { draftPayload, checkForDraft, saveDraft, scheduleDraftSave, discardDraft } = {$useDraftCall}

const handleResumeDraft = async (recordKey: string) => {
\tactiveDraftKey.value = recordKey
\tawait checkForDraft()
\tif (draftPayload.value) {
\t\tform.value = { ...form.value, ...draftPayload.value }
\t\t// A field left blank when the draft was saved round-trips through the
\t\t// backend's global ConvertEmptyStringsToNull middleware as null, not
\t\t// '' -- InputField's modelValue only accepts String | Number.
\t\tObject.keys(form.value).forEach((key) => {
\t\t\tif (form.value[key] === null) {
\t\t\t\tform.value[key] = ''
\t\t\t}
\t\t})
\t}
}

const handleDeleteDraft = async (uuid: string) => {
\tawait deleteDraft(uuid)
}

const isSavingDraft = ref(false)
const handleSaveDraftClick = async () => {
\tisSavingDraft.value = true
\tconst response = await saveDraft(form.value)
\tisSavingDraft.value = false
\tif (response.status) {
\t\ttoast.success('Draft saved')
\t\tawait loadDrafts()
\t} else {
\t\ttoast.error(response.message || 'Failed to save draft')
\t}
}
TS;

        return [
            'draftBannerBlock' => $draftBannerBlock,
            'draftImports' => $draftImports,
            'draftWatchImport' => ', watch',
            'draftSetupBlock' => $draftSetupBlock,
            'discardDraftOnSuccess' => 'discardDraft()',
            // Allocates this mount's own draft key and loads the picker list
            // -- deliberately does NOT auto-restore anything (unlike edit's
            // checkForDraft()); the user picks a draft to resume explicitly
            // via DraftListPanel's Resume button (handleResumeDraft above).
            'draftCheckBlock' => "activeDraftKey.value = newDraftKey()\n\tawait loadDrafts()",
            'draftWatchBlock' => <<<'TS'

// Debounced draft autosave -- skipped while the form is still hydrating
// (isLoading) so the initial mount/defaults pass never itself counts as a
// user edit worth drafting. Always targets whichever draft is currently
// active (activeDraftKey) -- a freshly allocated one, or one the user
// explicitly resumed via DraftListPanel.
watch(form, (value) => {
	if (!isLoading.value) {
		scheduleDraftSave(value)
	}
}, { deep: true })
TS,
            'draftContextProp' => '',
        ];
    }

    // ─── Inline Items helpers ─────────────────────────────────────────────────

    /**
     * Generate the wrapper-component Card block for each top-level
     * `inline_items` entry (this package's documented parent-child pattern,
     * e.g. Order Items -- see README.md's `inline_items` shape). Replaces
     * [[inlineItemsBlock]] in the form stub.
     *
     * Bug (fixed 2026-08-02): this used to bind <InlineItemsComponent>
     * directly with an inline `:fields="{ref}"`, where {ref} was a const
     * array declared elsewhere in the SAME generated form file (see the old
     * generateInlineItemsFieldDefs()) -- there was nowhere for a module to
     * add dynamicDisabled/showField/render hooks or listen to
     * @item-change/@field-change without hand-editing generated code that
     * regeneration would later clobber. Fix: each item now gets its own
     * hand-edit-protected wrapper component (see
     * writeInlineItemsWrapperComponent()), same as the field_type:
     * 'inline-items' case in generateField().
     */
    protected function generateInlineItemsBlock(array $inlineItems): string
    {
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key   = $item['key'];
            $label = $item['label'] ?? ucwords(str_replace('_', ' ', $key));

            // Every knob on this item (primary_field, modal_size/columns,
            // add_button_text, the three modal titles, empty/delete messages,
            // can_add/edit/view/delete, variant, totals) is rendered INTO the
            // wrapper file itself by writeInlineItemsWrapperComponent(), not
            // passed to it at the call site -- the wrapper declares no props
            // beyond its v-model and a `totals-change` emit. This tag used to
            // carry all of them as attributes anyway (a leftover from when the
            // wrapper forwarded to a shared InlineItemsComponent), and because
            // the wrapper has several root nodes Vue can't fall them through:
            // every page mounting one logged "Extraneous non-props attributes".
            // CrossFileContractTest pins that this tag only ever carries
            // attributes its wrapper declares.
            $normalizedItemFields = array_map(
                fn (array $f): array => $this->normalizeInlineItemConfigField($f),
                $item['fields'] ?? []
            );
            $componentName = $this->writeInlineItemsWrapperComponent($key, $normalizedItemFields, $item);

            // Financial-line-items pattern (variant/totals): the wrapper sums
            // the configured fields itself and emits `totals-change` (see
            // buildInlineItemsTotalsScriptBlock()). `totals[].sync_to`
            // names a top-level form field that should always equal that
            // total -- wired as an inline @totals-change handler here, the one
            // attribute the wrapper does declare, rather than a new
            // onMounted/script placeholder, since Vue template expressions
            // already have direct access to `form` and `disabledFieldsList`.
            // The totals shape was already validated by the wrapper write
            // above (buildInlineItemsTotalsScriptBlock()).
            $totalsConfig = $item['totals'] ?? [];
            $totalsChangeAttr = '';
            if (!empty($totalsConfig)) {
                $syncAssignments = [];
                $disableCalls    = [];
                foreach ($totalsConfig as $total) {
                    if (empty($total['sync_to'])) {
                        continue;
                    }
                    $syncField = $total['sync_to'];
                    $syncAssignments[] = "form.{$syncField} = totals.{$total['field']} ?? 0";
                    $disableCalls[]    = "if (!disabledFieldsList.includes('{$syncField}')) disabledFieldsList.push('{$syncField}')";
                }
                if (!empty($syncAssignments)) {
                    $body = implode('; ', array_merge($syncAssignments, $disableCalls));
                    $totalsChangeAttr = "\n\t\t\t\t\t@totals-change=\"(totals) => { {$body} }\"";
                }
            }

            // Same border/header-bar convention as generateFormSection()'s
            // "Main Details" card (not a shadcn <Card> -- that has its own
            // shadow/rounded/padding rhythm that doesn't match the plain-div
            // sections every other part of the form uses, reading as a
            // visually mismatched nested box). Confirmed live 2026-08-17 on
            // Expenses' full-page Edit view.
            //
            // Modal mode's outer wrapper carries its own `px-4` (not just
            // `mt-2`) -- CardContent used to supply that horizontal inset via
            // its own default padding, which this plain-div replacement
            // doesn't get for free; without it the "Items" label rendered
            // flush against the modal's edge while the item-list box below
            // it (which gets its own `p-4`) stayed correctly indented.
            // Confirmed live 2026-08-17 on Expenses' Create modal.
            //
            // Bug (found live 2026-08-18, PurchaseOrders' modal edit form):
            // that same `px-4 mt-2` had no BOTTOM padding at all -- non-modal
            // mode gets one for free from the inner content div's own `p-4`
            // (all sides), but in modal mode this block usually sits as the
            // LAST thing before the form's footer bar, so the "+ Add Item"
            // button rendered flush against it with zero breathing room.
            // Added `pb-4` to match.
            $blocks[] = <<<VUE

		<!-- {$label} -->
		<div :class="!modal ? 'rounded-md border overflow-hidden mt-2' : 'px-4 pb-4 mt-2'">
			<div v-if="!modal" class="px-4 py-3 border-b shrink-0">
				<span class="text-sm font-semibold">{$label}</span>
			</div>
			<p v-else class="text-sm font-semibold text-foreground mb-3">{$label}</p>
			<div :class="!modal ? 'p-4' : ''">
				<{$componentName}
					v-model="form.{$key}"{$totalsChangeAttr}
				/>
			</div>
		</div>
VUE;
        }

        return implode('', $blocks);
    }

    /**
     * Build the `[{ key: '...', ... }, ...]` JS array literal body shared by
     * generateInlineItemsBlock() (each item's wrapper component) -- extracted
     * from the old generateInlineItemsFieldDefs(), which used to declare this
     * same array as a bare `const {ref}: InlineItemField[] = [...]` directly
     * inside the generated form file. `inline_items` config fields use
     * snake_case keys (splash_key, api_url, table_width, show_in_table,
     * col_span) -- a different convention than the camelCase
     * processInlineItemsFields() uses for the field_type: 'inline-items'
     * case, so this is deliberately a separate builder, not a shared one.
     */
    /**
     * Bug (found + fixed 2026-08-09, while capturing documentation
     * screenshots of the inline_items feature this fixture -- and its own
     * docs page -- exist to demonstrate): `InlineItemsFieldRenderer.vue`
     * only recognizes WIDGET-selector values here ('input', 'number-input',
     * 'checkbox', 'date', 'select'/'api-select', 'textarea') -- the same
     * `field_type` vocabulary used everywhere else in this generator (see
     * IntrospectionToConfig::buildMorphFrontendFields() for the identical
     * `type: 'text'` + `field_type: 'input'` pairing this mirrors). But
     * `inline_items[].fields[]` config only ever had a single `type` key,
     * and buildInlineItemFieldsJs() passed it straight through unmapped --
     * every fixture and every docs example uses the SEMANTIC value
     * ('text'/'number'), which matches none of the renderer's cases, so
     * every field in the Add/Edit modal silently rendered nothing at all.
     * Confirmed live: opening orders-suite's own "Add Item" modal (the
     * fixture this exact config shape is copied from) showed zero visible
     * form fields, `getByLabel('Product')` timing out.
     *
     * Fixed: an explicit `field_type` key (matching every other field
     * config surface's convention) is honored first; otherwise a small
     * map covers the semantic types the docs/fixtures actually use.
     * Anything already a recognized widget value (someone having worked
     * around the bug by hand) passes through unchanged -- zero regression
     * for existing hand-edited configs.
     */
    private const INLINE_ITEM_TYPE_TO_WIDGET = [
        'text'    => 'input',
        'string'  => 'input',
        'number'  => 'number-input',
        'boolean' => 'checkbox',
    ];

    /**
     * Reconciliation (2026-08-18): this was the more limited of the two
     * inline-items field-config surfaces this package has -- the OTHER one
     * (field_type: 'inline-items', see processInlineItemsFields()) passes
     * readonly/disabled/default/inputType/optionLabel/optionValue/options
     * straight through untouched, none of which this method read at all,
     * despite inline_items[] being the documented, primary mechanism
     * (see docs/modules/inline-items.md's "choosing between the two").
     * Brought to parity (and kept this method's own existing snake_case
     * config-key convention -- splash_key, api_url, table_width, etc. --
     * for the new keys too, rather than switching to arrayToJsObjectString()
     * as processInlineItemsFields() does, which would reformat every
     * existing field's own already-tested one-line-per-field output).
     */
    protected function buildInlineItemFieldsJs(array $fields): string
    {
        $fieldLines = [];

        foreach ($fields as $field) {
            $configuredType = $field['type'] ?? 'text';
            $widgetType     = $field['field_type'] ?? (self::INLINE_ITEM_TYPE_TO_WIDGET[$configuredType] ?? $configuredType);

            $parts   = [];
            $parts[] = "key: '{$field['key']}'";
            $parts[] = "label: '{$field['label']}'";
            $parts[] = "type: '{$widgetType}'";

            if (!empty($field['required']))      $parts[] = 'required: true';
            if (!empty($field['readonly']))      $parts[] = 'readonly: true';
            if (!empty($field['disabled']))      $parts[] = 'disabled: true';
            if (!empty($field['splash_key']))     $parts[] = "splashKey: '{$field['splash_key']}'";
            if (!empty($field['api_url']))        $parts[] = "apiUrl: '{$field['api_url']}'";
            if (isset($field['decimals']))        $parts[] = "decimals: {$field['decimals']}";
            if (!empty($field['table_width']))    $parts[] = "tableWidth: '{$field['table_width']}'";
            if (isset($field['show_in_table']) && !$field['show_in_table']) $parts[] = 'showInTable: false';
            if (!empty($field['col_span']))       $parts[] = "colSpan: {$field['col_span']}";
            if (!empty($field['placeholder']))    $parts[] = "placeholder: '{$field['placeholder']}'";
            if (!empty($field['input_type']))     $parts[] = "inputType: '{$field['input_type']}'";
            if (!empty($field['option_label']))   $parts[] = "optionLabel: '{$field['option_label']}'";
            if (!empty($field['option_value']))   $parts[] = "optionValue: '{$field['option_value']}'";
            if (!empty($field['option_subtitle_field'])) $parts[] = "optionSubtitleField: '{$field['option_subtitle_field']}'";

            // Default's JS literal shape follows the field's own declared
            // TYPE (not PHP's runtime type of the config value) -- matches
            // how `decimals`/`col_span` above are already emitted as bare
            // numbers regardless of how they arrived from JSON.
            if (array_key_exists('default', $field) && $field['default'] !== null) {
                $defaultValue = $field['default'];
                if ($configuredType === 'number') {
                    $parts[] = 'default: ' . (is_numeric($defaultValue) ? $defaultValue : 0);
                } elseif ($configuredType === 'boolean') {
                    $parts[] = 'default: ' . ($defaultValue ? 'true' : 'false');
                } else {
                    $parts[] = "default: '" . addslashes((string) $defaultValue) . "'";
                }
            }

            // A LOCAL (non-API) select's fixed option list -- Array<{id, name}>
            // or whatever shape option_label/option_value point at. Genuinely
            // renders now (see InlineItemsFieldRenderer.vue's Select2Field
            // branch, added alongside this fix -- 'select' + `options` present
            // used to silently fall through to the API-driven ApiSelect2Field
            // and just never worked without an api_url/splash_key).
            if (!empty($field['options']) && is_array($field['options'])) {
                $parts[] = 'options: ' . $this->arrayToJsObjectString($field['options']);
            }

            $fieldLines[] = "\t{ " . implode(', ', $parts) . " },";
        }

        return "[\n" . implode("\n", $fieldLines) . "\n]";
    }

    /**
     * Reject a malformed `totals` config with an error that names the module
     * and what was found. This is all buildInlineItemTotalsJs() still did once
     * the wrapper stopped taking a `:totals` prop (it sums the configured
     * fields itself), and it is worth keeping: without it the same mistake
     * reaches buildInlineItemsTotalsScriptBlock() as a plain string and dies
     * with "Cannot access offset of type string on string".
     */
    protected function assertInlineItemTotalsShape(array $totals): void
    {
        foreach ($totals as $total) {
            // `totals` is a LIST OF MAPS — [{"field": "line_total", "label": "Total"}] — not a keyed
            // map of field => label. Written the wrong way round it used to reach the line below as a
            // plain string and die with "Cannot access offset of type string on string", naming
            // neither the module nor the offending key, from a file no config author has any reason
            // to be reading. This is the array-vs-keyed-map confusion consuming projects already
            // warn each other about; say which module and what was found instead.
            if (!is_array($total) || !isset($total['field'])) {
                $found = is_array($total) ? 'an array without a "field" key' : gettype($total);
                throw new \InvalidArgumentException(sprintf(
                    '%s: inline_items "totals" must be a list of maps, e.g. [{"field": "line_total", '
                    . '"label": "Total"}] — got %s. A keyed map like {"line_total": "Total"} is the '
                    . 'usual mistake.',
                    $this->moduleName,
                    $found
                ));
            }
        }
    }

    /**
     * Replaces [[inlineItemsFieldDefs]] in the form stub. Always empty now:
     * each item's field list lives inside its own wrapper component (written
     * by generateInlineItemsBlock() as a side effect) instead of being
     * declared inline in the generated form file -- see this method's
     * docblock history in generateInlineItemsBlock() for why. Kept (rather
     * than removing the placeholder from create/form.stub and edit/form.stub
     * outright) so neither stub needs touching for this change.
     */
    protected function generateInlineItemsFieldDefs(array $inlineItems): string
    {
        return '';
    }

    // ── LineItemsList view wrappers (Overview / detail page) ─────────────────

    /**
     * Write a hand-edit-protected {Module}{Key}LineItemsView.vue: the read-only rendering of one
     * inline_items relation on the Details/Overview page. Mirrors writeInlineItemsWrapperComponent()
     * for Create/Edit -- written once via writeFileOnce() and never touched by regeneration, so it
     * can be edited freely.
     *
     * Concrete markup built from the inline_items fields[] config: one column per field that isn't
     * hidden from the table (`show_in_table: false`), headed by the field's label, each cell resolved
     * by widget type -- a literal-options select shows the option's label, an FK / splash_key select
     * shows its server-resolved `{field}_object` name (falling back to the raw value), a checkbox
     * shows Yes/No, a number honours `decimals`. `totals` entries become a footer row of column sums.
     *
     * This replaced a heuristic that guessed name/quantity/unit-price/total columns and rendered them
     * through a shared `LineItemsList.vue`, which the current frontend base doesn't ship (an
     * unresolvable import, found by the super-suite fixture's production build) and which could only
     * ever describe invoice-shaped rows anyway.
     *
     * @param string $key        The inline_items key, e.g. 'sale_items'
     * @param array  $fields     The inline_items fields[] config entries
     * @param string $label      Human-readable label (unused in output; kept for callers' signature)
     * @param array  $itemConfig The whole inline_items entry (read for `totals`)
     * @return string            The component name, e.g. 'SalesSaleItemsLineItemsView'
     */
    protected function writeLineItemsViewComponent(string $key, array $fields, string $label, array $itemConfig = []): string
    {
        $componentName = $this->moduleName . Str::studly($key) . 'LineItemsView';

        $normalized = array_values(array_filter(
            array_map(fn (array $f) => $this->normalizeInlineItemConfigField($f), $fields),
            fn (array $f) => $f['key'] !== '' && $f['showInTable'] !== false
        ));

        $fieldList = implode(', ', array_column($fields, 'key'));

        $totalsByField = [];
        foreach (($itemConfig['totals'] ?? []) as $total) {
            if (is_array($total) && !empty($total['field'])) {
                $totalsByField[$total['field']] = $total;
            }
        }

        $header = [];
        $body   = [];
        foreach ($normalized as $f) {
            $align    = $f['type'] === 'number-input' ? ' text-right' : '';
            $header[] = '<th class="px-2 py-2 font-medium' . $align . '">' . htmlspecialchars((string) $f['label'], ENT_QUOTES) . '</th>';
            $body[]   = '<td class="px-2 py-2' . $align . '">{{ ' . $this->lineItemsViewCellExpr($f) . ' }}</td>';
        }

        $footer = '';
        if ($totalsByField !== [] && $normalized !== []) {
            $cells = [];
            foreach ($normalized as $i => $f) {
                if (isset($totalsByField[$f['key']])) {
                    $decimals = (int) ($f['decimals'] ?? 0);
                    $cells[]  = '<td class="px-2 py-2 text-right">{{ items.reduce((sum: number, i: any) => sum + Number(i.'
                        . $f['key'] . ' ?? 0), 0).toFixed(' . $decimals . ') }}</td>';
                } elseif ($i === 0) {
                    $first    = reset($totalsByField);
                    $cells[]  = '<td class="px-2 py-2">' . htmlspecialchars((string) ($first['label'] ?? 'Total'), ENT_QUOTES) . '</td>';
                } else {
                    $cells[] = '<td class="px-2 py-2"></td>';
                }
            }
            $footer = "\n\t\t\t<tfoot>\n\t\t\t\t<tr class=\"border-t font-semibold\">\n\t\t\t\t\t"
                . implode("\n\t\t\t\t\t", $cells) . "\n\t\t\t\t</tr>\n\t\t\t</tfoot>";
        }

        $stub    = $this->getTemplateContent('fields/line-items-view-wrapper', 'frontend');
        $content = $this->replacePlaceholders($stub, [
            '[[componentName]]' => $componentName,
            '[[fieldList]]'     => $fieldList,
            '[[headerCells]]'   => implode("\n\t\t\t\t\t", $header),
            '[[bodyCells]]'     => implode("\n\t\t\t\t\t", $body),
            '[[footer]]'        => $footer,
        ]);

        $path = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$componentName}.vue";
        $this->writeFileOnce($path, $content);

        return $componentName;
    }

    /**
     * The template expression for one field's value in the read-only line-items table. `item` is the
     * row; a missing value renders as an em dash so an empty cell is never a blank gap.
     */
    private function lineItemsViewCellExpr(array $field): string
    {
        $key  = $field['key'];
        $type = $field['type'];

        if (in_array($type, ['select', 'api-select'], true)) {
            $optionLabel = $field['optionLabel'] ?? 'name';
            $optionValue = $field['optionValue'] ?? 'id';
            if (!empty($field['options']) && is_array($field['options'])) {
                $optionsJs = $this->arrayToJsObjectString($field['options']);
                return "({$optionsJs}.find((o: any) => o.{$optionValue} === item.{$key})?.{$optionLabel} ?? item.{$key} ?? '—')";
            }
            // An FK: ViewServiceGenerator::generateInlineItemsLoad() re-attaches the related record
            // as `{field}_object`. A non-relation picker (splash_key) has none, so it falls back to
            // the stored value.
            return "item.{$key}_object?.{$optionLabel} ?? item.{$key} ?? '—'";
        }

        if ($type === 'checkbox') {
            return "item.{$key} ? 'Yes' : 'No'";
        }

        if ($type === 'number-input' && (int) ($field['decimals'] ?? 0) > 0) {
            return "item.{$key} != null ? Number(item.{$key}).toFixed(" . (int) $field['decimals'] . ") : '—'";
        }

        return "item.{$key} ?? '—'";
    }

    /**
     * Generate the import lines for each {Module}{Key}LineItemsView wrapper
     * component so the overview page can reference them.  Replaces
     * [[lineItemsImports]] in overview.stub.
     */
    protected function generateLineItemsViewImports(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $imports = [];
        foreach ($inlineItems as $item) {
            $componentName = $this->moduleName . Str::studly($item['key']) . 'LineItemsView';
            $imports[]     = "import {$componentName} from './Components/{$componentName}.vue'";
        }

        return implode("\n", $imports);
    }

    /**
     * Generate Card blocks for each inline_items entry using LineItemsList
     * view wrappers.  Called by ViewOverviewGenerator to populate
     * [[lineItemsSections]] in overview.stub.
     */
    protected function generateLineItemsSections(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key    = $item['key'];
            $label  = $item['label'] ?? ucwords(str_replace('_', ' ', $key));
            $fields = $item['fields'] ?? [];

            $componentName = $this->writeLineItemsViewComponent($key, $fields, $label, $item);

            $blocks[] = <<<VUE

		<!-- {$label} -->
		<Card class="gap-0 overflow-hidden p-0">
			<div class="px-4 py-3 border-b">
				<span class="text-sm font-semibold">{$label}</span>
			</div>
			<CardContent class="p-4">
				<{$componentName} :items="data?.{$key} ?? []" />
			</CardContent>
		</Card>
VUE;
        }

        return implode("\n", $blocks);
    }

}

