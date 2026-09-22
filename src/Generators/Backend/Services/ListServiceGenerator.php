<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Helpers\BulkActionConfigNormalizer;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class ListServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['list'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/list/service', 'backend');

        $replacements = [
            '[[filterableFields]]' => $this->generateFilterableFields(),
            '[[sortableFields]]' => $this->generateSortableFields(),
            '[[eagerLoadRelationships]]' => $this->generateEagerLoadRelationships('list'),
            '[[locationScopeIncludesNull]]' => $this->generateLocationScopeFlags(),
            '[[filterableRelationships]]' => $this->generateFilterableRelationships(),
            '[[filterFields]]' => $this->generateFilterFields(),
            '[[importMethods]]' => $this->generateImportMethods(),
            '[[bulkActionsArray]]' => $this->generateBulkActionsArray(),
            '[[bulkRecordKeyColumn]]' => $this->generateBulkRecordKeyColumn(),
            '[[defaultListFiltersArray]]' => $this->generateDefaultListFiltersArray(
                $this->config['features']['backend']['list']['default_list_filters'] ?? []
            ),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);
        
        $serviceName = $this->moduleName . 'ListService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";
        
        return $this->writeFile($filePath, $content);
    }

    /**
     * Bug (found 2026-08-17 via the retail-ERP demo fixture): every list query
     * runs through `LocationContextService::applyLocationFiltering()`, which
     * does a plain `whereIn(location_id, $accessibleIds)` -- and NULL never
     * matches `whereIn()` in SQL, so any row with `location_id: null` silently
     * vanishes from list results for any user who has assigned locations.
     * `applyLocationFiltering()` already has an opt-out for this
     * (`$locationScopeIncludesNull = true`, added 2026-08-08), but nothing
     * ever emitted it -- confirmed live: not one module anywhere in this
     * codebase declared it, including modules with a genuinely nullable
     * `location_id` column (a NULL there means "applies everywhere", which
     * should obviously still be visible).
     *
     * A nullable `location_id` column is exactly the signal that NULL is an
     * intentional, expected state for this module, not missing data -- so
     * auto-declare the opt-out whenever the column is nullable, rather than
     * relying on every module remembering to opt in by hand (which never
     * happened once in practice).
     */
    private function generateLocationScopeIncludesNull(): string
    {
        foreach (($this->config['columns'] ?? []) as $col) {
            if (($col['name'] ?? '') === 'location_id' && ($col['nullable'] ?? false) === true) {
                return <<<'PHP'

    /**
     * `location_id` is nullable for this module -- NULL means "applies
     * everywhere", so it must stay visible under location-scoped list
     * queries rather than being silently excluded by whereIn()'s
     * NULL-never-matches semantics.
     */
    protected static bool $locationScopeIncludesNull = true;
PHP;
            }
        }

        return '';
    }

    /**
     * The location flag block for this list service: the opt-OUT when the module says so, else the
     * nullable-column rule.
     *
     * A module with a `location_id` column that declares `"location_bearing": false` is a
     * deliberate opt-out (see the consuming app's LocationBearingDeclarationTest): the column
     * records where something happened, it does not restrict who may see it. The record scope
     * already honours that -- it keys on the model's flag -- but the list filter keys on "the table
     * has a location_id column" and never asked, so such a module's list was still scoped while
     * its by-uuid view was not. Found by the super-suite fixture's location-isolation test
     * (SuitePings). Only Notifications got the intended behaviour, by hand-overriding its list
     * service.
     *
     * Emitted only for an EXPLICIT false, never for "no declaration": an undeclared model with a
     * location_id column is scoped today and must stay scoped.
     */
    private function generateLocationScopeFlags(): string
    {
        if ($this->locationScopeIsDisabled()) {
            return <<<'PHP'

    /**
     * This module declares `location_bearing: false`: its `location_id` records where a row
     * happened, it does not restrict who may see it. The list must agree with the by-uuid fetch,
     * which is already unscoped for such a module.
     */
    protected static bool $locationScopeDisabled = true;
PHP;
        }

        return $this->generateLocationScopeIncludesNull();
    }

    private function locationScopeIsDisabled(): bool
    {
        if (($this->config['location_bearing'] ?? null) !== false) {
            return false;
        }

        foreach (($this->config['columns'] ?? []) as $col) {
            if (($col['name'] ?? '') === 'location_id') {
                return true;
            }
        }

        return false;
    }

    private function generateBulkActionsArray(): string
    {
        $bulkActions = BulkActionConfigNormalizer::normalizeAll(
            $this->config['features']['backend']['list']['bulk_actions'] ?? []
        );
        if (empty($bulkActions)) {
            return '[]';
        }
        $keys = array_map(fn($a) => "'" . addslashes($a['key']) . "'", $bulkActions);
        return '[' . implode(', ', $keys) . ']';
    }

    /**
     * shelui-engine fork: App\Project\_Src\ListServiceTrait::processBulkAction()
     * (the consuming app's shared bulk-action dispatcher) hardcoded 'uuid' as
     * the record-identifier column throughout — a has_uuid: false module (this
     * project's legacy-repointed tables) has no such column, so every bulk
     * action (mode=ids intersection, mode=filter id collection, and the
     * per-id dispatch to the named action service) queried/passed a column
     * that doesn't exist. The trait now reads this property (falling back to
     * 'uuid' via getStaticProperty()'s own default when absent), so only a
     * has_uuid: false module needs to declare it at all — every module
     * generated before this existed keeps generating a byte-identical file.
     */
    private function generateBulkRecordKeyColumn(): string
    {
        if (ModuleConfigContract::hasUuid($this->config)) {
            return '';
        }

        return "\n    protected static string \$bulkRecordKeyColumn = 'id';";
    }

    private function generateDefaultListFiltersArray(array $entries): string
    {
        if (empty($entries)) {
            return '[]';
        }

        $lines = [];
        foreach ($entries as $entry) {
            $column   = addslashes($entry['column'] ?? '');
            $operator = addslashes($entry['operator'] ?? 'eq');
            $raw      = $entry['value'] ?? '';
            $value    = $this->phpLiteralValue($raw);
            $lines[]  = "        '{$column}' => ['operator' => '{$operator}', 'value' => {$value}],";
        }

        return "[\n" . implode("\n", $lines) . "\n    ]";
    }

    /**
     * Convert a PHP value from config to a PHP literal string for emission.
     * Strings → quoted, numbers/booleans → bare, arrays → inline array literal.
     */
    private function phpLiteralValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(fn($v) => $this->phpLiteralValue($v), $value);
            return '[' . implode(', ', $items) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value) && !is_string($value)) {
            return (string) $value;
        }
        // Treat numeric strings as quoted (they are identifiers, not arithmetic)
        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * The `$importColumns` entries: the module's own create fields, key => label.
     *
     * Emitted as an empty array (a `// 'field_name' => 'Field Label'` comment) this used to leave the
     * app's template download to fall back to the filterable columns with an EMPTY label each, so a
     * freshly generated importable module offered a template whose header row was a run of blank
     * cells -- nothing told the user which columns to fill. Found by the super-suite fixture's
     * TicketActionsTest. The create fields are the right source: they are exactly what a create
     * accepts, which is what an imported row becomes. File and morph fields have no place in a flat
     * CSV row and are left out; the developer still owns which of them processImportRow() honours.
     */
    private function importColumnLines(): string
    {
        $lines = [];
        foreach (($this->config['features']['frontend']['create']['fields'] ?? []) as $field) {
            $key = (string) ($field['field'] ?? '');
            if ($key === '' || in_array($field['field_type'] ?? 'input', ['file-input', 'morph-select'], true)) {
                continue;
            }
            $label = (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $key)));
            $lines[] = "        '" . addslashes($key) . "' => '" . addslashes($label) . "',";
        }

        return $lines === [] ? "        // 'field_name' => 'Field Label'," : implode("\n", $lines);
    }

    private function generateImportMethods(): string
    {
        $importEnabled = $this->config['features']['backend']['list']['import'] ?? false;
        if (!$importEnabled) {
            return '';
        }

        $name = $this->moduleName;
        $importColumns = $this->importColumnLines();

        return <<<PHP
    /**
     * Columns available for import (key => human-readable label).
     */
    protected static array \$importColumns = [
{$importColumns}
    ];

    public static function getImportTemplate(?string \$format = 'csv'): mixed
    {
        return self::downloadImportTemplate(\$format);
    }

    /**
     * Public wrapper: importData() is protected, so an external caller (a
     * delegation's thin-proxy service, forcing its parent FK onto every
     * imported row) cannot call it directly. Mirrors execute_bulkAction()'s
     * existing role relative to processBulkAction().
     */
    public static function execute_import(array \$data, ?\Illuminate\Http\UploadedFile \$file, array \$forcedFields = []): array
    {
        return self::importData(\$data, \$file, \$forcedFields);
    }

    /**
     * Process a single import row. Throw an exception to mark the row as failed.
     *
     * \$forcedFields (e.g. a delegation's parent FK) must be merged AFTER
     * your own row validation — same rule as CreateService::execute()'s
     * \$params, for the same reason: validator()->validate() silently strips
     * any key your own rules don't declare.
     *   \$validRow = validator(\$row, [...])->validate();
     *   \$validRow = array_merge(\$validRow, \$forcedFields);
     */
    protected static function processImportRow(array \$row, int \$rowNumber, array \$forcedFields = []): void
    {
        // TODO: implement {$name} import logic
    }
PHP;
    }
}

