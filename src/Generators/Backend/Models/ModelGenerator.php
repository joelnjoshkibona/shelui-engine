<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class ModelGenerator extends BaseGenerator
{
    protected array $fields;
    protected array $relationships;
    protected string $idType;
    protected string $idColumnName;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->fields = $this->config['columns']; // the normalised copy (BaseGenerator), not the raw parameter
        $this->relationships = $config['relationships'] ?? [];
        
        // Extract ID configuration
        $this->idType = $config['id_type'];
        $this->idColumnName = 'id';
    }

    public function generate(): bool
    {
        // Determine template based on model type
        $modelType = 'default';
        $templateName = $this->getModelTemplate($modelType);
        $content = $this->getTemplateContent($templateName, 'backend');
        
        $replacements = [
            '[[constants]]' => $this->generateConstants(),
            '[[primaryKey]]' => $this->generatePrimaryKeyProperty(),
            '[[keyType]]' => $this->generateKeyTypeProperty(),
            '[[incrementing]]' => $this->generateIncrementingProperty(),
            '[[hasUuidOverride]]' => $this->generateHasUuidOverride(),
            '[[connection]]' => $this->generateConnection(),
            '[[timestamps]]' => $this->generateTimestamps(),
            '[[softDeletesImport]]' => $this->generateSoftDeletesImport(),
            '[[softDeletesTrait]]' => $this->generateSoftDeletesTrait(),
            '[[softDeleteColumnOverride]]' => $this->generateSoftDeleteColumnOverride(),
            '[[bootMethod]]' => $this->generateBootMethod(),
            '[[auditRelationships]]' => $this->generateAuditRelationships(),
            '[[relationships]]' => $this->generateRelationships(),
            '[[casts]]' => $this->generateCasts(),
            '[[hidden]]' => $this->generateHidden(),
            '[[locationBearing]]' => $this->generateLocationBearing(),
        ];
        
        $content = $this->replacePlaceholders($content, $replacements);

        $filePath = "{$this->modulePath}/{$this->moduleName}Model.php";

        // A hand-maintained Model (e.g. Users -- Authenticatable-based, with
        // traits/relationships the generator's plain template can't express
        // at all, see ModuleConfigContract::isModelHandMaintained()) must
        // survive every future --force regenerate of the rest of the
        // module, not just be skipped on a plain re-run like writeFile()
        // does. writeFileOnce() has no --force escape hatch at all.
        if (ModuleConfigContract::isModelHandMaintained($this->config)) {
            return $this->writeFileOnce($filePath, $content);
        }

        return $this->writeFile($filePath, $content);
    }
    
    /**
     * Declare, on the model itself, that this module's rows belong to a
     * location — so the consuming app's shared query scope can restrict
     * every fetch of them, including the fetch-by-uuid in the generated
     * view/edit/delete services that no location scoping reaches today.
     *
     * Emits nothing at all when the module is not location-bearing, so a
     * regenerate of an unrelated module produces a byte-identical Model.
     * See ModuleConfigContract::isLocationBearing() for the rule and why
     * it is derived from the column rather than asked for.
     */
    protected function generateLocationBearing(): string
    {
        if (!ModuleConfigContract::isLocationBearing($this->config)) {
            return '';
        }

        return <<<'PHP'

    /**
     * Rows of this model belong to a location. The application's shared
     * location scope reads this to restrict which of them the acting user
     * may fetch — lists and single-record fetches alike.
     */
    protected static bool $locationBearing = true;
PHP;
    }

    /**
     * Hide secret-like columns (ModuleConfigContract::sensitiveColumns()) from
     * every serialization of this model — list rows, view/export/create/edit
     * responses, and $model->toArray()/toJson() generally.
     *
     * Emits nothing at all when the module has no sensitive columns, so a
     * regenerate of an unrelated module produces a byte-identical Model —
     * same rule as generateLocationBearing() above.
     */
    protected function generateHidden(): string
    {
        $sensitiveColumns = ModuleConfigContract::sensitiveColumns($this->config);
        if (empty($sensitiveColumns)) {
            return '';
        }

        $entries = implode("\n", array_map(
            static fn (string $column): string => "        '{$column}',",
            $sensitiveColumns,
        ));

        return <<<PHP


    /**
     * Secret-like columns (ModuleConfigContract::sensitiveColumns()): never serialized into
     * list, view, export, create or edit responses.
     */
    protected \$hidden = [
{$entries}
    ];
PHP;
    }

    protected function generateConnection(): string
    {
        $conn = $this->config['connection'] ?? config('generator.default_connection');
        return $conn ? "protected \$connection = '{$conn}';" : '';
    }

    /**
     * shelui-engine fork: column NAMES come from ModuleConfigContract::
     * timestampColumns() (module.json's `timestamp_columns`) instead of a
     * hardcoded 'created_date'/'modified_date' rescan of this module's own
     * columns. The old rescan fired regardless of has_timestamps and
     * disagreed with MigrationGenerator, which only ever read the flag —
     * a module using the rescan's literal names got Eloquent-managed
     * timestamps here while MigrationGenerator (before its own fork fix)
     * would separately try to emit a conflicting `$table->timestamps()`
     * call for the SAME table whenever has_timestamps defaulted true. One
     * resolution rule now, shared with MigrationGenerator, same as every
     * other has_X fact in this class.
     */
    protected function generateTimestamps(): string
    {
        if (!$this->hasTimestamps()) {
            return 'public $timestamps = false;';
        }

        $columns = ModuleConfigContract::timestampColumns($this->config);
        if ($columns['created'] === 'created_at' && $columns['updated'] === 'updated_at') {
            return '';
        }

        return "const CREATED_AT = '{$columns['created']}';\n    const UPDATED_AT = '{$columns['updated']}';";
    }

    /**
     * A custom soft-delete column name needs a matching model-side
     * declaration so the record is actually read/written through the real
     * column instead of the default 'deleted_at' ('timestamp' type) or
     * 'is_deleted' ('flag' type, App\Project\_Src\Traits\HasIsDeleted in
     * the consuming app — see that trait's own `$isDeletedColumn` static
     * property, which this mirrors the same way generateTimestamps()
     * mirrors Laravel's own CREATED_AT/UPDATED_AT override mechanism).
     * Emits nothing for a module on either default, or one without soft
     * deletes at all — most generated Models are unaffected.
     */
    protected function generateSoftDeleteColumnOverride(): string
    {
        if (!$this->hasSoftDeletes()) {
            return '';
        }

        $column = ModuleConfigContract::softDeleteColumn($this->config);

        if (ModuleConfigContract::softDeleteType($this->config) === 'flag') {
            return $column === 'is_deleted' ? '' : "protected static string \$isDeletedColumn = '{$column}';";
        }

        return $column === 'deleted_at' ? '' : "const DELETED_AT = '{$column}';";
    }

    /**
     * Whether this model's underlying migration actually has created_at /
     * updated_at columns.
     *
     * Delegates to ModuleConfigContract::hasTimestamps() — the single
     * sanctioned resolution rule shared with MigrationGenerator, so the two
     * can never disagree about the same config. See that method's docblock
     * for the full resolution rule and the bug history behind it.
     */
    protected function hasTimestamps(): bool
    {
        return ModuleConfigContract::hasTimestamps($this->config);
    }

    /**
     * Whether this model's underlying migration actually has a deleted_at
     * column (i.e. `$table->softDeletes()` was used).
     *
     * Delegates to ModuleConfigContract::hasSoftDeletes() — the single
     * sanctioned resolution rule (config flag, falling back to a
     * deleted_at-column rescan only when the flag key is entirely absent)
     * shared with MigrationGenerator, so the two can never disagree about
     * the same config. See that method's docblock for the full resolution
     * rule and the bug history behind it.
     */
    protected function hasSoftDeletes(): bool
    {
        return ModuleConfigContract::hasSoftDeletes($this->config);
    }

    /**
     * shelui-engine fork: ModuleConfigContract::softDeleteType() picks
     * between Laravel's own SoftDeletes (default) and this project's
     * legacy integer-flag convention, App\Project\_Src\Traits\HasIsDeleted
     * in the consuming app (ported from ongeza-pro) — see that method's
     * docblock. A module generated before soft_delete_type existed always
     * resolves 'timestamp', so it keeps importing SoftDeletes exactly as
     * before.
     */
    protected function generateSoftDeletesImport(): string
    {
        if (!$this->hasSoftDeletes()) {
            return '';
        }

        return ModuleConfigContract::softDeleteType($this->config) === 'flag'
            ? 'use App\\Project\\_Src\\Traits\\HasIsDeleted;'
            : 'use Illuminate\\Database\\Eloquent\\SoftDeletes;';
    }

    protected function generateSoftDeletesTrait(): string
    {
        if (!$this->hasSoftDeletes()) {
            return '';
        }

        return ModuleConfigContract::softDeleteType($this->config) === 'flag' ? ', HasIsDeleted' : ', SoftDeletes';
    }

    protected function getModelTemplate(string $modelType): string
    {
        switch ($modelType) {
            case 'user':
            case 'authenticatable':
                return 'model_users';
            default:
                return 'model';
        }
    }
    

    protected function generateCasts(): string
    {
        $casts = [];

        // Skip primary key and any system timestamp/soft-delete column this
        // module actually has -- by its CONFIGURED name (ModuleConfigContract::
        // timestampColumns()/softDeleteColumn()), not a fixed guess list. A
        // compliant config never declares these in columns[] to begin with
        // (the same convention SKIP_COLUMNS enforces for the Laravel-default
        // names during introspection); this is purely defensive.
        $skipNames = ['id'];
        if ($this->hasTimestamps()) {
            $tsColumns = ModuleConfigContract::timestampColumns($this->config);
            $skipNames[] = $tsColumns['created'];
            $skipNames[] = $tsColumns['updated'];
        }
        if ($this->hasSoftDeletes()) {
            $skipNames[] = ModuleConfigContract::softDeleteColumn($this->config);
        }

        // Auto-detect casts from database fields
        foreach ($this->fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'] ?? 'string';

            if (in_array($fieldName, $skipNames, true)) {
                continue;
            }
            
            // Determine cast type based on field type
            $castType = $this->getCastType($fieldType, $field['scale'] ?? null);
            if ($castType) {
                $casts[$fieldName] = $castType;
            }
        }
        
        // A sensitive column (ModuleConfigContract::sensitiveColumns()) that
        // must be hashed or encrypted gets the matching Eloquent cast, so a
        // generated create can never leave a secret in the clear -- see
        // ModuleConfigContract::sensitiveColumnStorage()'s own docblock for
        // why this is a three-way split (hashed / encrypted / plain), not
        // "hash everything". A `plain`-storage sensitive column (e.g. a
        // `*_hash` column the application already hashed itself) gets no
        // cast entry here at all -- it is still hidden from serialization
        // by generateHidden() above, just never cast.
        foreach (ModuleConfigContract::sensitiveColumns($this->config) as $sensitiveField) {
            $storage = ModuleConfigContract::sensitiveColumnStorage($this->config, $sensitiveField);
            if ($storage !== 'plain') {
                $casts[$sensitiveField] = $storage;
            }
        }

        // Add any manually configured casts
        $manualCasts = $this->config['backend']['model']['casts'] ?? [];
        foreach ($manualCasts as $key => $value) {
            // Handle both old format: [{ "field": "field_name", "type": "cast_type" }]
            // and new format: { "field_name": "cast_type" }
            if (is_array($value) && isset($value['field']) && isset($value['type'])) {
                // Old format
                $casts[$value['field']] = $value['type'];
            } else {
                // New format
                $casts[$key] = $value;
            }
        }
        
        if (empty($casts)) {
            return '';
        }

        $castArray = [];
        foreach ($casts as $field => $cast) {
            $castArray[] = "        '{$field}' => '{$cast}'";
        }

        return "protected \$casts = [\n" . implode(",\n", $castArray) . "\n    ];";
    }
    
    protected function getCastType(string $fieldType, ?int $scale = null): ?string
    {
        switch (strtolower($fieldType)) {
            case 'json':
            case 'jsonb':
                return 'array';
            case 'boolean':
            case 'tinyint(1)':
                return 'boolean';
            case 'date':
                // Deliberately 'date:Y-m-d', not a bare 'date'. Eloquent's
                // plain 'date' cast serializes to JSON via Carbon's default
                // toJSON() format, which converts to UTC first — so on any
                // app whose config('app.timezone') isn't UTC, a date column
                // holding a local midnight value comes back over the API as
                // the *previous* day at e.g. 21:00Z. Confirmed live: a
                // generated ItemPrices module with `effective_date` cast as
                // 'date' returned "2026-07-23T21:00:00.000000Z" for a row
                // stored as 2026-07-24 under Africa/Dar_es_Salaam
                // (UTC+3) — silently corrupting the calendar date for any
                // consumer (including the generated PHPUnit test's own
                // assertion, and any real frontend rendering the value).
                // The parameterized 'date:Y-m-d' cast formats with
                // ->format($format) directly and is NOT timezone-converted,
                // so it round-trips the exact stored calendar date
                // regardless of app timezone.
                return 'date:Y-m-d';
            case 'time':
                return 'string';
            case 'enum':
                // Explicit, not an accidental fallthrough to the default `null`
                // (no-cast) branch. This project's enum columns are plain
                // strings stored in a MySQL `enum` column -- there is no PHP
                // backed-enum class generated anywhere in this pipeline for
                // getCastType() to reference, so casting to a specific enum
                // type is not on the table. A 'string' cast is still the
                // right call over "no cast at all": it documents, at the
                // Model, that this attribute is a closed set of string
                // values (mirrors the Rule::in() validation BaseServiceGenerator
                // already emits and the Select2Field the form already renders),
                // and it's safe for nullable enum columns too -- Eloquent's
                // castAttribute() special-cases null for every primitive cast
                // type (see HasAttributes::$primitiveCastTypes), so a null
                // enum value round-trips as null, not an empty string.
                return 'string';
            case 'datetime':
            case 'timestamp':
                return 'datetime';
            case 'integer':
            case 'int':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return 'integer';
            // 'decimal' gets its own branch, not folded into float/double: a `decimal(P,S)`
            // column is exact fixed-point storage specifically to avoid float rounding drift
            // (this project's own convention elsewhere is "bcadd/bcsub/bccomp, never floats" —
            // see AllocationService/ScheduleGenerationService), so casting it to PHP 'float'
            // reintroduces the exact drift the column type exists to prevent. Confirmed live
            // 2026-08-25: a decimal(15,2) 'amount' cast as 'float' round-tripped a stored 40.00
            // as PHP 40.0, comparing unequal to the '40.00' a decimal-precision service computed.
            // 'decimal:{scale}' is the correct Eloquent cast — it always returns a
            // fixed-precision numeric STRING, matching the column's own semantics exactly, and
            // matching MigrationGenerator's own `$scale ?? 2` default for a decimal column whose
            // scale wasn't explicitly configured (see that class's own decimal branch).
            // 'float'/'double' are left as 'float' — those genuinely have no fixed scale to cast
            // to, unlike 'decimal'.
            case 'decimal':
                return 'decimal:' . ($scale ?? 2);
            case 'float':
            case 'double':
                return 'float';
            default:
                return null; // No cast needed for strings and other types
        }
    }

    protected function generateRelationships(): string
    {
        $relationships = [];
        $seenMethods = [];

        // 0. morphTo — auto-generate from config['morphs'] entries
        $morphRelationships = $this->generateMorphRelationships();
        foreach ($morphRelationships as $method) {
            $methodKey = strtolower($method['name'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $method['code'];
        }

        // 0.5. belongsTo(Media) — explicit file-upload columns (config['file_columns'],
        // threaded from IntrospectionToConfig's file_columns meta). These are plain
        // unsignedBigInteger columns with NO real DB FK (this project's "no hard FK
        // constraints anywhere" convention -- see MobileReleases.apk_media_id/
        // ota_media_id), so they'll never have normalized_type 'foreignId' and would
        // otherwise be silently skipped by the loop below. Emitted here, before it,
        // so seenMethods still lets a genuine FK win if one somehow collides.
        $fileColumnRelationships = $this->generateFileColumnRelationships();
        foreach ($fileColumnRelationships as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $relationship['code'];
        }

        // 1. belongsTo — auto-generate from this module's own foreignId columns
        $autoRelationships = $this->generateAutoRelationshipsFromForeignIds();
        foreach ($autoRelationships as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 2. hasMany — auto-derive inverse relationships by scanning other modules in the
        //    same project for foreignId columns pointing back at THIS module. Templates that
        //    reference the parent's child collection (e.g. $source->inventoryTransferItems)
        //    previously saw nulls because the hasMany was never emitted.
        $inverseRelationships = $this->generateInverseHasManyRelationships();
        foreach ($inverseRelationships as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 3. Manually declared hasMany / belongsToMany via `relations.hasMany[]` on module config.
        //    Escape hatch for non-standard FK naming or cross-DB relations.
        $manualInverse = $this->generateManualInverseRelationships();
        foreach ($manualInverse as $relationship) {
            $methodKey = strtolower($relationship['method'] ?? '');
            if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
            $seenMethods[$methodKey] = true;
            $relationships[] = $this->generateRelationshipMethod($relationship);
        }

        // 4. Legacy manually configured relationships array ($config['relationships'])
        if (!empty($this->relationships)) {
            foreach ($this->relationships as $relationship) {
                $methodKey = strtolower($relationship['method'] ?? $relationship['name'] ?? '');
                if ($methodKey === '' || isset($seenMethods[$methodKey])) continue;
                $seenMethods[$methodKey] = true;
                $relationships[] = $this->generateRelationshipMethod($relationship);
            }
        }

        if (empty($relationships)) {
            return '';
        }

        return "\n" . implode("\n", $relationships);
    }

    /**
     * Scan other modules in the same project for foreignId columns that point back
     * at this module, and emit a hasMany relation for each. Method name is the
     * camelCase plural of the child module name (e.g. InventoryTransferItems on
     * the InventoryTransfers model becomes `inventoryTransferItems()`).
     *
     * When multiple FK columns on the child module point at this module
     * (e.g. from_location_id + to_location_id → Locations), each gets its own
     * suffixed method so method names don't collide.
     */
    protected function generateInverseHasManyRelationships(): array
    {
        $relationships = [];

        // --- Array registry path (new, decoupled) ---
        $registry = PathManager::getModuleRegistryAll();
        if (!empty($registry)) {
            foreach ($registry as $entry) {
                $childModuleName = $entry['name'] ?? null;
                if ($childModuleName === null || $childModuleName === $this->moduleName) {
                    continue;
                }
                $childConfig  = $entry['config'] ?? $entry;
                $childColumns = $childConfig['columns'] ?? [];

                $matchingCols = [];
                foreach ($childColumns as $col) {
                    if (($col['type'] ?? '') !== 'foreignId') continue;
                    if (($col['relatedModule'] ?? '') !== $this->moduleName) continue;
                    $matchingCols[] = $col;
                }
                if (empty($matchingCols)) continue;

                $multipleFks = count($matchingCols) > 1;
                foreach ($matchingCols as $col) {
                    $fkColumn = $col['name'] ?? '';
                    if ($fkColumn === '') continue;

                    $baseMethod = lcfirst(\Illuminate\Support\Str::camel(\Illuminate\Support\Str::plural($childModuleName)));
                    $method = $multipleFks
                        ? lcfirst(\Illuminate\Support\Str::camel(str_replace('_id', '', $fkColumn))) . ucfirst($baseMethod)
                        : $baseMethod;

                    $relationships[] = [
                        'type'         => 'hasMany',
                        'module_name'  => $childModuleName,
                        'module_type'  => 'Model',
                        'name'         => $method,
                        'method'       => $method,
                        'foreign_key'  => $fkColumn,
                        'local_key'    => null,
                    ];
                }
            }
            return $relationships;
        }

        // Registry is empty — no cross-module relationships can be derived.
        return $relationships;
    }

    /**
     * Manual escape hatch: users can declare extra inverse relations in module config
     * under `relations.hasMany[]` / `relations.belongsToMany[]` when the auto-derivation
     * can't cover the case (non-standard FK names, custom local keys, pivot tables).
     *
     * Expected shape:
     *   relations: {
     *     hasMany: [
     *       { module: "OrderItems", method: "items", foreignKey: "order_ref", localKey: "id" },
     *       ...
     *     ],
     *     belongsToMany: [
     *       { module: "Tags", method: "tags", pivotTable: "order_tag", foreignPivotKey: "order_id", relatedPivotKey: "tag_id" }
     *     ],
     *     morphMany: [
     *       { module: "Payments", method: "payments", morphName: "payable" }
     *     ]
     *   }
     *
     * `morphMany` exists specifically for a morph TARGET (e.g. Vendors, for
     * Payments.payable) — the reverse side of a `morphs[]` declaration never
     * gets a relation automatically (see generateMorphRelationships(), which
     * only ever emits morphTo() on the OWNING model). Declaring it here
     * instead of splicing it into the generated file post-hoc (this
     * package's original v3.3.0 approach, `ModelRelationInjector`, removed
     * in v3.4.0) means it survives every future regenerate for free, the
     * same as every other relation this method already handles — no
     * separate pass, no risk of a plain `--force` silently wiping it.
     */
    protected function generateManualInverseRelationships(): array
    {
        $relations = $this->config['relations'] ?? [];
        if (!is_array($relations)) return [];

        $out = [];

        foreach (($relations['morphMany'] ?? []) as $decl) {
            if (!is_array($decl)) continue;
            $moduleName = $decl['module'] ?? '';
            $method = $decl['method'] ?? '';
            $morphName = $decl['morphName'] ?? '';
            if ($moduleName === '' || $method === '' || $morphName === '') continue;
            $this->assertManualRelationModuleResolves($moduleName, 'relations.morphMany', $method);
            $out[] = [
                'type' => 'morphMany',
                'module_name' => $moduleName,
                'module_type' => 'Model',
                'name' => $method,
                'method' => $method,
                'morph_name' => $morphName,
            ];
        }

        foreach (($relations['hasMany'] ?? []) as $decl) {
            if (!is_array($decl)) continue;
            $moduleName = $decl['module'] ?? '';
            $method = $decl['method'] ?? '';
            if ($moduleName === '' || $method === '') continue;
            $this->assertManualRelationModuleResolves($moduleName, 'relations.hasMany', $method);
            $out[] = [
                'type' => 'hasMany',
                'module_name' => $moduleName,
                'module_type' => 'Model',
                'name' => $method,
                'method' => $method,
                'foreign_key' => $decl['foreignKey'] ?? null,
                'local_key' => $decl['localKey'] ?? null,
            ];
        }

        foreach (($relations['belongsToMany'] ?? []) as $decl) {
            if (!is_array($decl)) continue;
            $moduleName = $decl['module'] ?? '';
            $method = $decl['method'] ?? '';
            if ($moduleName === '' || $method === '') continue;
            $this->assertManualRelationModuleResolves($moduleName, 'relations.belongsToMany', $method);
            $out[] = [
                'type' => 'belongsToMany',
                'module_name' => $moduleName,
                'module_type' => 'Model',
                'name' => $method,
                'method' => $method,
                'pivot_table' => $decl['pivotTable'] ?? null,
                'foreign_pivot_key' => $decl['foreignPivotKey'] ?? null,
                'related_pivot_key' => $decl['relatedPivotKey'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Guard for a hand-authored `relations.hasMany[]` /
     * `relations.belongsToMany[]` declaration. Unlike the auto-derived FK
     * path (see generateAutoRelationshipsFromForeignIds()), this module name
     * was typed by a human, not read off a real FK constraint, so there is
     * no "it's just a guess, skip quietly" fallback available: the config
     * author explicitly asked for this relation to exist. If the module
     * can't be resolved via guessedModuleExists()'s full registry/directory
     * lookup chain, this used to silently default to 'Core' (via the
     * now-removed determineModuleGroup()) and emit a
     * belongsTo/hasMany/belongsToMany pointing at a class that doesn't
     * exist there — surfacing only much later as a runtime
     * class-not-found. Fail at generation time instead, naming the
     * unresolvable module so the typo is obvious immediately.
     */
    protected function assertManualRelationModuleResolves(string $moduleName, string $configPath, string $method): void
    {
        if (!$this->guessedModuleExists($moduleName)) {
            throw new \RuntimeException(
                "Cannot resolve module '{$moduleName}' declared in {$configPath} (method '{$method}') ".
                "on module '{$this->moduleName}': no matching module found in the registry or generated ".
                "project. Check for a typo in the module name, or generate that module first."
            );
        }
    }

    /**
     * Generate a Relation::morphMap() registration for this model's own
     * boot() method, built from every config['morphs'][].targets[] entry.
     * Registration lives on the OWNING model (the one with the morph
     * columns), not on each target model -- confirmed against the only
     * real precedent in the codebase, NotificationSubscriptionsModel::boot(),
     * whose own module.json owns subscriber_type/subscriber_id and whose
     * own boot() registers the map for both of its targets (user, role).
     * Placing it here also means the mapping is active as soon as any
     * instance of the owning model is queried -- exactly when a *_type
     * column value would need resolving -- without depending on some
     * unrelated target model happening to boot first.
     *
     * Returns '' when this module declares no morphs (or no morph has any
     * targets set) -- the placeholder is a no-op for every module generated
     * before this feature existed.
     *
     * @throws \RuntimeException if two different models are registered
     *         under the same alias within this module's own morphs config
     *         (a local, same-model guard; cross-module alias conflicts are
     *         caught separately by Schema\MorphAliasValidator before
     *         generation starts).
     */
    protected function generateBootMethod(): string
    {
        $morphs = $this->config['morphs'] ?? [];

        $mapEntries = []; // alias => fully-qualified model class string
        foreach ($morphs as $morph) {
            foreach (($morph['targets'] ?? []) as $target) {
                if (!is_array($target)) {
                    continue; // defensive: ignore a stray legacy bare-string entry
                }
                $alias = $target['alias'] ?? '';
                $model = $target['model'] ?? '';
                if ($alias === '' || $model === '') {
                    continue;
                }
                $fqcn = '\\' . ltrim($model, '\\');

                if (isset($mapEntries[$alias]) && $mapEntries[$alias] !== $fqcn) {
                    throw new \RuntimeException(
                        "Morph alias '{$alias}' on module '{$this->moduleName}' is registered for two ".
                        "different models ({$mapEntries[$alias]} and {$fqcn}) within the same morphs ".
                        "config. Relation::morphMap() keys must be unique. Fix the duplicate targets[] entry."
                    );
                }
                $mapEntries[$alias] = $fqcn;
            }
        }

        if (empty($mapEntries)) {
            return '';
        }

        $lines = [
            '    protected static function boot(): void',
            '    {',
            '        parent::boot();',
            '        \\Illuminate\\Database\\Eloquent\\Relations\\Relation::morphMap([',
        ];
        foreach ($mapEntries as $alias => $fqcn) {
            $lines[] = "            '{$alias}' => {$fqcn}::class,";
        }
        $lines[] = '        ]);';
        $lines[] = '    }';

        return "\n" . implode("\n", $lines);
    }

    /**
     * Generate morphTo() relation methods from config['morphs'] entries.
     * Returns an array of ['name' => string, 'code' => string].
     */
    protected function generateMorphRelationships(): array
    {
        $morphs = $this->config['morphs'] ?? [];
        if (empty($morphs)) {
            return [];
        }

        $out = [];
        foreach ($morphs as $morph) {
            $name = $morph['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'code' => implode("\n", [
                    "    public function {$name}(): \\Illuminate\\Database\\Eloquent\\Relations\\MorphTo",
                    "    {",
                    "        return \$this->morphTo();",
                    "    }",
                ]),
            ];
        }

        return $out;
    }

    /**
     * Collect all column names that are part of a morph pair (to skip belongsTo for them).
     */
    protected function getMorphPairColumnNames(): array
    {
        $morphs = $this->config['morphs'] ?? [];
        $names  = [];
        foreach ($morphs as $morph) {
            if (!empty($morph['type_column'])) {
                $names[] = $morph['type_column'];
            }
            if (!empty($morph['id_column'])) {
                $names[] = $morph['id_column'];
            }
        }
        return $names;
    }

    /**
     * belongsTo(\App\Project\Modules\Core\Media\MediaModel::class, $column) for
     * every column named in $config['file_columns'] that this module actually
     * owns. Method name = the column with its '_id' suffix stripped, camelCased
     * (deriveRelationshipMethodName() -- same rule that turns 'category_id'
     * into 'category'), e.g. 'image_media_id' -> 'imageMedia', matching the
     * hand-written MobileReleasesModel::apkMedia()/otaMedia() convention
     * exactly (see 'apk_media_id' -> 'apkMedia').
     *
     * Media's namespace is hardcoded rather than resolved via
     * PathManager::resolveBackendModuleNamespace()/the module registry --
     * mirrors MobileReleasesModel's own hardcoded
     * \App\Project\Modules\Core\Media\MediaModel::class reference. Media is a
     * fixed, always-present Core module in every consuming project; it isn't
     * guaranteed to carry a ModuleConfig/registry entry the way introspected
     * business modules do, so gating this relation on registry resolution
     * (the way generateAutoRelationshipsFromForeignIds() gates *guessed* FK
     * targets) would risk silently dropping it on projects where Media was
     * never registered that way.
     *
     * @return array<int, array{method: string, code: string}>
     */
    protected function generateFileColumnRelationships(): array
    {
        $fileColumns = $this->config['file_columns'] ?? [];
        if (empty($fileColumns)) {
            return [];
        }

        $ownColumnNames = array_map(fn($f) => $f['name'] ?? null, $this->fields);

        $out = [];
        foreach ($fileColumns as $columnName) {
            if (!is_string($columnName) || $columnName === '' || !in_array($columnName, $ownColumnNames, true)) {
                // Not one of this module's own columns -- e.g. a stray/typo'd
                // --file-columns entry, or one meant for a different module.
                continue;
            }

            $method = $this->deriveRelationshipMethodName($columnName);

            $out[] = [
                'method' => $method,
                'code'   => implode("\n", [
                    "    public function {$method}(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo",
                    "    {",
                    "        return \$this->belongsTo(",
                    "            \\App\\Project\\Modules\\Core\\Media\\MediaModel::class, '{$columnName}'",
                    "        );",
                    "    }",
                ]),
            ];
        }

        return $out;
    }

    protected function generateAutoRelationshipsFromForeignIds(): array
    {
        $relationships = [];
        $morphPairCols = $this->getMorphPairColumnNames();

        foreach ($this->fields as $field) {
            // Only process foreignId columns
            if (($field['type'] ?? '') !== 'foreignId') {
                continue;
            }

            $columnName = $field['name'];

            // Skip audit fields (ModuleConfigContract::creatorUpdaterColumns() --
            // 'created_by_id'/'updated_by_id' by default, or a module.json override) --
            // they're handled separately by generateAuditRelationships().
            $auditColumns = ModuleConfigContract::creatorUpdaterColumns($this->config);
            if ($columnName === $auditColumns['created'] || $columnName === $auditColumns['updated']) {
                continue;
            }

            // Skip columns that are part of a morph pair (they get morphTo(), not belongsTo())
            if (in_array($columnName, $morphPairCols, true)) {
                continue;
            }

            // Derive relationship method name from column name
            // e.g., "category_id" -> "category", "user_id" -> "user"
            $methodName = $this->deriveRelationshipMethodName($columnName);

            // Get related module information.
            // When relatedModule is set (from a DB FK constraint), use it directly.
            // When it is empty (e.g. status_id with no FK constraint), derive from column name:
            //   strip _id, pluralize, StudlyCase  → "status_id" → "status" → "statuses" → "Statuses"
            $relatedModuleName = $field['relatedModule'] ?? '';
            $wasGuessed = false;
            if ($relatedModuleName === '' && str_ends_with($columnName, '_id')) {
                $base = substr($columnName, 0, -3); // strip _id
                $relatedModuleName = \Illuminate\Support\Str::studly(
                    \Illuminate\Support\Str::plural($base)
                );
                $wasGuessed = true;
            }

            if ($relatedModuleName === '') {
                continue; // Cannot derive a target — skip
            }

            // Bug this guards against: a column merely NAMED like a foreign
            // key (e.g. "external_trans_id", a plain string idempotency key
            // with no real FK) used to get a module name guessed purely from
            // its name and StudlyCase-pluralized ("ExternalTrans") — with no
            // check that such a module actually exists. The emitted
            // belongsTo() then referenced a class that was never generated.
            // A guessed name (as opposed to one resolved from real FK
            // metadata via $field['relatedModule']) must resolve against the
            // actual module registry/project before we ever emit a relation
            // pointing at it; otherwise skip it silently.
            if ($wasGuessed && !$this->guessedModuleExists($relatedModuleName)) {
                continue;
            }

            $relationships[] = [
                'type' => 'belongsTo',
                'module_name' => $relatedModuleName,
                'module_type' => 'Model',
                'name' => $methodName,
                'method' => $methodName,
                'foreign_key' => $columnName,
                'local_key' => null // Use default 'id'
            ];
        }

        return $relationships;
    }

    /**
     * Whether a GUESSED related module name actually resolves to a known
     * module — via the array registry, the generated project's own
     * registry files, or an existing module directory.
     */
    protected function guessedModuleExists(string $moduleName): bool
    {
        // 1. Array-based module registry (authoritative when populated).
        if (PathManager::findModuleInRegistry($moduleName) !== null) {
            return true;
        }

        try {
            // 2. Generated project's own registry files.
            foreach (['registry_core.json', 'registry.json'] as $file) {
                $registryPath = PathManager::getBackendRegistryPath() . '/' . $file;
                if (file_exists($registryPath)) {
                    $registry = json_decode(file_get_contents($registryPath), true);
                    if (is_array($registry) && isset($registry[$moduleName])) {
                        return true;
                    }
                }
            }

            // 3. Actual module directory structure in the generated project.
            $modulesPath = PathManager::getBackendModulesPath();
            if (is_dir($modulesPath)) {
                foreach (array_filter(glob($modulesPath . '/*'), 'is_dir') as $groupPath) {
                    if (is_dir($groupPath . '/' . $moduleName)) {
                        return true;
                    }
                }
            }
        } catch (\Exception) {
            // PathManager not set up (project root not set) — fall through.
        }

        // 4. Generator system's own registry (backward compatibility, V1/Laravel only).
        if (function_exists('base_path')) {
            foreach (['registry_core.json', 'registry.json'] as $file) {
                // fromLaravel(): the helper is autoloaded in a consuming project but the
                // container may never have been booted (a plain CLI). See PathManager.
                $registryPath = (string) PathManager::fromLaravel(
                    static fn () => base_path("app/Project/_Src/{$file}"),
                    ''
                );
                if (file_exists($registryPath)) {
                    $registry = json_decode(file_get_contents($registryPath), true);
                    if (is_array($registry) && isset($registry[$moduleName])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function generateRelationshipMethod(array $relationship): string
    {
        $type = $relationship['type'];
        
        // Use module information to generate the relationship
        $moduleName = $relationship['module_name'] ?? 'Unknown';
        $moduleType = $relationship['module_type'] ?? 'Model';
        $method = $relationship['name'] ?? $relationship['method'] ?? strtolower($moduleName);
        $foreignKey = $relationship['foreign_key'] ?? null;
        $localKey = $relationship['local_key'] ?? null;

        // Generate the namespaced class using module information
        $namespacedClass = $this->generateNamespacedClass($moduleName, $moduleType);

        switch ($type) {
            case 'hasMany':
                $params = $foreignKey ? ", '{$foreignKey}'" : '';
                return "    public function {$method}()\n    {\n        return \$this->hasMany({$namespacedClass}::class{$params});\n    }";
            
            case 'belongsTo':
                $params = $foreignKey ? ", '{$foreignKey}'" : '';
                return "    public function {$method}()\n    {\n        return \$this->belongsTo({$namespacedClass}::class{$params});\n    }";
            
            case 'morphMany':
                $morphName = $relationship['morph_name'] ?? '';
                return "    public function {$method}()\n    {\n        return \$this->morphMany({$namespacedClass}::class, '{$morphName}');\n    }";

            case 'belongsToMany':
                $table = $relationship['pivot_table'] ?? null;
                $foreignPivotKey = $relationship['foreign_pivot_key'] ?? null;
                $relatedPivotKey = $relationship['related_pivot_key'] ?? null;
                
                $params = [];
                if ($table) $params[] = "'{$table}'";
                if ($foreignPivotKey) $params[] = "'{$foreignPivotKey}'";
                if ($relatedPivotKey) $params[] = "'{$relatedPivotKey}'";
                
                $paramString = $params ? ', ' . implode(', ', $params) : '';
                return "    public function {$method}()\n    {\n        return \$this->belongsToMany({$namespacedClass}::class{$paramString});\n    }";
            
            default:
                return '';
        }
    }

    protected function generateNamespacedClass(string $moduleName, string $moduleType): string
    {
        $className = $moduleName . $moduleType;

        // Self-referential relation (e.g. parent_id belongsTo the same
        // module): the caller (e.g. SYSTEM_SHELL's make:modules-from-db)
        // only appends a module to PathManager's array registry *after* it
        // finishes generating, so a module can't find *itself* there yet
        // while it's still being generated. resolveBackendModuleNamespace()
        // would otherwise silently fall through to the Core default. This
        // module's own group/sub-group is already known directly, without
        // any registry lookup -- use it.
        if ($moduleName === $this->moduleName) {
            $namespace = "App\\Project\\Modules\\{$this->moduleGroup}";
            if ($this->moduleSubGroup) {
                $namespace .= "\\{$this->moduleSubGroup}";
            }
            return "\\{$namespace}\\{$moduleName}\\{$className}";
        }

        $namespace = PathManager::resolveBackendModuleNamespace($moduleName);
        return "\\{$namespace}\\{$className}";
    }


    protected function generateRules(): string
    {
        $rules = [];
        foreach ($this->fields as $field) {
            if (isset($field['rules'])) {
                $rules[] = "'{$field['name']}' => '{$field['rules']}'";
            }
        }

        if (empty($rules)) {
            return '';
        }

        return "[\n        " . implode(",\n        ", $rules) . "\n    ]";
    }

    protected function generatePrimaryKeyProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "protected \$primaryKey = 'id';";
                
            case 'uuid':
            case 'manual':
                return "protected \$primaryKey = '{$this->idColumnName}';";
                
            default:
                return "protected \$primaryKey = 'id';";
        }
    }

    protected function generateKeyTypeProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "protected \$keyType = 'int';";
                
            case 'uuid':
            case 'manual':
                return "protected \$keyType = 'string';";
                
            default:
                return "protected \$keyType = 'int';";
        }
    }

    /**
     * shelui-engine fork: BaseModel::$hasUuid defaults to true, and nothing
     * ever overrode it for a has_uuid: false module — MigrationGenerator
     * correctly dropped the uuid column and FrontendRoutesGenerator/
     * RouteGenerator correctly route by {id}, but ModelClass::hasUuid()
     * itself still answered true, since no generator ever wrote this
     * property. Every caller that branches on the model's own hasUuid() to
     * decide which request param to trust — confirmed live:
     * BaseActivityListService::execute(), which reads 'uuid' when hasUuid()
     * says true — got the wrong answer and 422'd every request against a
     * has_uuid: false module's /activity endpoint (a numeric id fails the
     * uuid format regex). Emits nothing when has_uuid is true (the default,
     * unchanged for every other module).
     */
    protected function generateHasUuidOverride(): string
    {
        if (ModuleConfigContract::hasUuid($this->config)) {
            return '';
        }

        return "protected static bool \$hasUuid = false;";
    }

    protected function generateIncrementingProperty(): string
    {
        switch ($this->idType) {
            case 'integer':
                return "public \$incrementing = true;";
                
            case 'uuid':
            case 'manual':
                return "public \$incrementing = false;";
                
            default:
                return "public \$incrementing = true;";
        }
    }

    protected function generateConstants(): string
    {
        $constants = $this->config['constants'] ?? [];
        
        if (empty($constants)) {
            return '';
        }

        $constantLines = [];
        foreach ($constants as $name => $value) {
            $formatted = is_numeric($value) ? $value : "'{$value}'";
            $constantLines[] = "    public const {$name} = {$formatted};";
        }

        return "\n" . implode("\n", $constantLines);
    }

    /**
     * Whether this model's underlying migration actually has
     * created_by_id/updated_by_id columns.
     *
     * Bug this guards against: generateAuditRelationships() below used to be
     * called unconditionally, emitting creator()/updater() BelongsTo
     * relations that reference created_by_id/updated_by_id even on tables
     * with no such columns at all (e.g. price_lists, stock_transfers —
     * tables deliberately scaffolded without audit-trail columns). Every
     * such model shipped with two relation methods that throw a SQL error
     * the moment anything eager-loads or queries them.
     *
     * Delegates to ModuleConfigContract::hasCreatorUpdater() — the single
     * sanctioned resolution rule shared with MigrationGenerator, so the two
     * can never disagree about the same config.
     */
    protected function hasCreatorUpdater(): bool
    {
        return ModuleConfigContract::hasCreatorUpdater($this->config);
    }

    /**
     * shelui-engine fork: FK column names come from ModuleConfigContract::
     * creatorUpdaterColumns() instead of the hardcoded 'created_by_id'/
     * 'updated_by_id' pair. A single-actor module (creatorUpdaterColumns()
     * returns 'updated' => null, e.g. this project's legacy tables that
     * only ever recorded who created a row) gets ONLY creator() — emitting
     * an updater() relation for a column that doesn't exist would throw the
     * moment anything eager-loads it, the exact bug hasCreatorUpdater()
     * itself already guards against for a module with no audit columns at
     * all.
     */
    protected function generateAuditRelationships(): string
    {
        if (!$this->hasCreatorUpdater()) {
            return '';
        }

        $columns = ModuleConfigContract::creatorUpdaterColumns($this->config);
        $usersNs = ModuleConfigContract::creatorUpdaterModel($this->config);

        $auditRelationships = [
            "    public function creator(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo",
            "    {",
            "        return \$this->belongsTo(",
            "            {$usersNs}::class, '{$columns['created']}', 'id'",
            "        );",
            "    }",
        ];

        if ($columns['updated'] !== null) {
            $auditRelationships[] = "";
            $auditRelationships[] = "    public function updater(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo";
            $auditRelationships[] = "    {";
            $auditRelationships[] = "        return \$this->belongsTo(";
            $auditRelationships[] = "            {$usersNs}::class, '{$columns['updated']}', 'id'";
            $auditRelationships[] = "        );";
            $auditRelationships[] = "    }";
        }

        return implode("\n", $auditRelationships);
    }

    protected function extractModuleNameFromModel(string $modelClass): string
    {
        // Extract module name from full model class path
        // e.g., "App\Project\Modules\Core\Entities\EntitiesModel" -> "Entities"
        $parts = explode('\\', $modelClass);
        $modelName = end($parts);
        
        // Remove "Model" suffix if present
        if (str_ends_with($modelName, 'Model')) {
            return substr($modelName, 0, -5);
        }
        
        return $modelName;
    }
}
