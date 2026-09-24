<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class MigrationGenerator extends BaseGenerator
{
    protected array $fields;
    protected array $indexes;
    protected array $uniqueConstraints;
    protected string $tableName;
    protected string $idType;
    protected string $idColumnName;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->fields = $this->config['columns']; // the normalised copy (BaseGenerator), not the raw parameter
        $this->indexes = $config['indexes'] ?? [];
        $this->uniqueConstraints = $config['unique_constraints'] ?? [];
        $this->tableName = $config['table_name'];
        
        // Extract ID configuration
        $this->idType = $config['id_type'];
        $this->idColumnName = 'id';
    }

    public function generate(): bool
    {
        // Bug this guards against: the "create" migration filename embeds
        // the CURRENT timestamp (date('Y_m_d_His')), which is different on
        // every invocation — so BaseGenerator::writeFile()'s file_exists()
        // check (which compares this exact, always-fresh filename) could
        // never detect that a migration for this table already existed.
        // Re-running generation for a module whose table already had a
        // hand-written migration therefore wrote a SECOND "create" migration
        // with a slightly different (introspected, and sometimes wrong)
        // schema right alongside the real one (confirmed for
        // LedgerTransactions and member_phones). Guard by TABLE NAME instead
        // of exact filename.
        if ($this->createMigrationAlreadyExists()) {
            return false;
        }

        $content = $this->getTemplateContent('migration', 'backend');
        $content = $this->replacePlaceholders($content, [
            '[[tableName]]' => $this->tableName,
            '[[schema]]' => $this->generateSchema(),
            '[[auditFields]]' => $this->generateAuditFields(),
            '[[timestampsLine]]' => $this->generateTimestampsLine(),
            '[[softDeletesLine]]' => $this->generateSoftDeletesLine(),
            '[[uuidLine]]' => $this->generateUuidLine(),
            '[[uuidImports]]' => $this->generateUuidImports(),
            '[[systemIndexes]]' => $this->generateSystemIndexes(),
            '[[indexes]]' => $this->generateIndexes(),
        ]);

        $fileName = date('Y_m_d_His') . "_create_{$this->tableName}_table.php";
        $filePath = "{$this->modulePath}/Migrations/{$fileName}";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Whether a "create {table} table" migration already exists in this
     * module's Migrations directory, regardless of its timestamp prefix.
     */
    protected function createMigrationAlreadyExists(): bool
    {
        $dir = "{$this->modulePath}/Migrations";
        if (!is_dir($dir)) {
            return false;
        }

        $matches = glob($dir . '/*_create_' . $this->tableName . '_table.php');

        return !empty($matches);
    }

    protected function generateSchema(): string
    {
        $schema = [];

        // Generate primary key based on ID type
        $schema[] = $this->generatePrimaryKey();

        // Collect morph pair column names so we skip individual emissions
        $morphs         = $this->config['morphs'] ?? [];
        $morphPairCols  = [];
        $emittedMorphs  = []; // track which morph names we've already emitted
        foreach ($morphs as $morph) {
            if (!empty($morph['type_column'])) {
                $morphPairCols[$morph['type_column']] = $morph['name'];
            }
            if (!empty($morph['id_column'])) {
                $morphPairCols[$morph['id_column']] = $morph['name'];
            }
        }

        // Add other fields; replace morph pair columns with a single morphs() call
        foreach ($this->fields as $field) {
            $name = $field['name'];

            if (isset($morphPairCols[$name])) {
                $morphName = $morphPairCols[$name];
                if (!isset($emittedMorphs[$morphName])) {
                    $nullable = (bool) ($field['nullable'] ?? false);
                    $morphLine = $nullable
                        ? "\$table->nullableMorphs('{$morphName}');"
                        : "\$table->morphs('{$morphName}');";
                    $schema[] = $morphLine;
                    $emittedMorphs[$morphName] = true;
                }
                // Skip the individual column — the morphs() call covers both
                continue;
            }

            $schema[] = $this->generateFieldSchema($field);
        }

        return implode("\n            ", $schema);
    }

    protected function generatePrimaryKey(): string
    {
        switch ($this->idType) {
            case 'autoincrement':
                return '$table->id();';
                
            case 'uuid':
                return '$table->string(\'' . $this->idColumnName . '\')->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();';
                
            case 'manual':
                return "\$table->integer('{$this->idColumnName}')->primary();";
                
            default:
                return '$table->id();';
        }
    }

    protected function generateFieldSchema(array $field): string
    {
        // Ignore id field
        if ($field['name'] === $this->idColumnName) {
            return '';
        }

        $name = $field['name'];
        $type = $field['type'] ?? 'string';
        $length = $field['length'] ?? null;
        $nullable = $field['nullable'] ?? false;
        $default = $field['default'] ?? null;
        $unique = $field['unique'] ?? false;

        // A hashed/encrypted column's stored bytes differ on every write
        // (bcrypt salts randomly, Laravel's encrypter uses a random IV per
        // call) -- a DB unique index on one can never actually prevent two
        // rows sharing the same real secret, AND spuriously rejects
        // re-saving a row's own unchanged value the next time it's written.
        // Refuse the constraint outright rather than emit a migration that
        // is broken from the moment two rows exist.
        $storage = ModuleConfigContract::isSensitive($this->config, $name)
            ? ModuleConfigContract::sensitiveColumnStorage($this->config, $name)
            : 'plain';
        if ($unique && in_array($storage, ['hashed', 'encrypted'], true)) {
            PathManager::reportIssue(
                "{$this->tableName}.{$name}: dropped the unique constraint -- a {$storage} column's stored bytes differ on every write, so a unique index cannot work and would reject re-saving the row's own value.",
                'error',
            );
            $unique = false;
        }

        // Ciphertext is opaque and length-unstable: Laravel's `encrypted`
        // cast stores base64-encoded, JSON-wrapped ciphertext routinely 2-4x
        // longer than the plaintext -- a configured varchar length (or the
        // introspected one) cannot be trusted to hold it. Force `text`
        // regardless of what was configured.
        if ($storage === 'encrypted') {
            $type = 'text';
            $length = null;
        }

        // A short pin/otp column (e.g. length 10) must still fit a ~60-byte
        // bcrypt hash. Only widens an already-string column -- a non-string
        // type sensitive column (rare, and not this generator's concern) is
        // left alone.
        if ($storage === 'hashed' && $type === 'string') {
            $length = max((int) ($length ?? 0), 255);
        }

        // precision/scale come from real introspected decimal(P,S) metadata
        // when SchemaIntrospector/IntrospectionToConfig supplied it (see
        // SchemaIntrospector::extractPrecisionScale()). The `?? 10` / `?? 2`
        // fallbacks below are ONLY for configs that genuinely lack the data
        // (e.g. hand-authored configs) -- they must never mask real
        // introspected values, which is why they're applied after reading
        // $field, not baked into the defaults above.
        $precision = $field['precision'] ?? null;
        $scale = $field['scale'] ?? null;
        $enumValues = $field['enum_values'] ?? [];

        if ($type === 'enum') {
            // $table->enum('name', ['a', 'b']) takes an array literal, not
            // scalar constructor args like every other column type here --
            // built separately rather than forced through the generic
            // "$table->{$type}('{$name}'" + args-then-close path below.
            $valuesLiteral = '[' . implode(', ', array_map(
                static fn($v) => "'" . addslashes((string) $v) . "'",
                $enumValues
            )) . ']';
            $schema = "\$table->enum('{$name}', {$valuesLiteral})";
        } else {
            $schema = "\$table->{$type}('{$name}'";

            // Handle decimal type with precision and scale
            if ($type === 'decimal') {
                $precision = $precision ?? 10;  // Default precision
                $scale = $scale ?? 2;  // Default scale
                $schema .= ", {$precision}, {$scale}";
            } elseif ($length && in_array($type, ['string', 'char'])) {
                // Handle string/char with length
                $schema .= ", {$length}";
            }

            $schema .= ')';
        }

        if ($nullable) {
            $schema .= '->nullable()';
        }
        
        if ($default !== null && $default !== '') {
            // Handle boolean type defaults
            if ($type === 'boolean') {
                if (is_string($default)) {
                    // A string boolean default is almost never the word "true".
                    // MySQL has no BOOLEAN type: `$table->boolean()` compiles to
                    // TINYINT(1), and information_schema reports its default as the
                    // string "1" or "0" (sometimes quoted, "'1'"). Matching only the
                    // literal "true" therefore sent every real introspected default
                    // down the else branch and emitted `->default(false)` —
                    // silently INVERTING it. Confirmed against a live module:
                    // mobile_releases.is_active has DEFAULT 1 in the database and
                    // `->default(true)` in its committed migration, and
                    // regenerating it from its own module.json (which correctly
                    // stores "1") produced `->default(false)`. A --force regenerate
                    // was quietly flipping production defaults.
                    $normalized = strtolower(trim($default, " '\""));
                    $isTrue     = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
                    $schema    .= '->default(' . ($isTrue ? 'true' : 'false') . ')';
                } else {
                    $schema .= "->default(" . ($default ? 'true' : 'false') . ")";
                }
            } elseif (is_string($default)) {
                // MySQL information_schema may return defaults with surrounding single quotes
                // (e.g. "'0'" for DECIMAL DEFAULT '0'). Strip them before embedding into PHP.
                if (preg_match("/^'(.*)'$/s", $default, $m)) {
                    $default = $m[1];
                }
                $schema .= "->default('{$default}')";
            } else {
                $schema .= "->default({$default})";
            }
        }
        
        if ($unique) {
            $constraintName = $this->safeConstraintName([$name], 'unique');
            $schema .= "->unique('{$constraintName}')";
        }
        
        // Add comment if provided
        $comment = $field['comment'] ?? null;
        if ($comment && trim($comment) !== '') {
            // Escape single quotes and backslashes in comment for PHP string
            $escapedComment = addslashes($comment);
            $schema .= "->comment('{$escapedComment}')";
        }
        
        return $schema . ';';
    }

    protected function generateIndexes(): string
    {
        // Composite unique constraints (config['unique_constraints']) render
        // through the exact same $table->unique([...], 'name') path as a
        // regular index entry marked unique=>true -- generateIndexSchema()
        // already branches on the 'unique' flag, so they're folded into the
        // same list here rather than duplicating that rendering logic.
        // Single-column uniques are NOT included here: those are rendered
        // inline on the column definition itself (see generateFieldSchema()'s
        // $unique handling) by IntrospectionToConfig's design -- see
        // IntrospectionToConfig::buildIndexesAndUniqueConstraints().
        // Defensive reconciliation: drop any config['indexes'] entry that
        // exactly matches a system index generateSystemIndexes() is ABOUT TO
        // emit unconditionally (uuid / created_by_id / updated_by_id /
        // deleted_at, gated on the same has_*() flags). IntrospectionToConfig
        // already filters these out for the introspection path (see
        // IntrospectionToConfig::buildIndexesAndUniqueConstraints()), but
        // MigrationGenerator is the highest-risk surface in this package and
        // the one place every config (introspected OR hand-authored) must
        // pass through -- so it re-checks here rather than trusting every
        // caller got the filtering right upstream.
        //
        // Bug (found + fixed 2026-08-02, via morphs-suite live verification):
        // this same reconciliation never covered a morph pair's own
        // composite index. generateSchema() collapses a morph pair's two
        // columns into a single `$table->morphs($name)` call, which itself
        // creates a composite index over (type_column, id_column) -- but a
        // real table with that pair also genuinely has a composite DB index
        // over those same two columns (that's how introspection found the
        // pair in the first place, in a real regenerate-from-introspection
        // round trip), so config['indexes'] legitimately contains a matching
        // entry. Without this filter, the regenerated migration emitted BOTH
        // `$table->morphs('payable')` AND a redundant explicit
        // `$table->index(['payable_type', 'payable_id'], ...)` for the exact
        // same two columns. Harmless in MySQL (duplicate indexes are legal,
        // just wasteful), but real generated-output noise every morphs
        // regenerate would have produced.
        $systemIndexColumnSets = [];
        if ($this->hasUuid()) {
            $systemIndexColumnSets[] = ['uuid'];
        }
        if ($this->hasCreatorUpdater()) {
            $auditColumns = ModuleConfigContract::creatorUpdaterColumns($this->config);
            $systemIndexColumnSets[] = [$auditColumns['created']];
            if ($auditColumns['updated'] !== null) {
                $systemIndexColumnSets[] = [$auditColumns['updated']];
            }
        }
        if ($this->hasSoftDeletes()) {
            $systemIndexColumnSets[] = [ModuleConfigContract::softDeleteColumn($this->config)];
        }
        foreach ($this->config['morphs'] ?? [] as $morph) {
            if (!empty($morph['type_column']) && !empty($morph['id_column'])) {
                $systemIndexColumnSets[] = [$morph['type_column'], $morph['id_column']];
            }
        }

        $items = array_values(array_filter($this->indexes, function (array $index) use ($systemIndexColumnSets) {
            $columns = is_array($index['columns'] ?? null)
                ? array_values($index['columns'])
                : array_map('trim', explode(',', (string) ($index['columns'] ?? '')));

            return !in_array($columns, $systemIndexColumnSets, true);
        }));

        foreach ($this->uniqueConstraints as $constraint) {
            $items[] = [
                'columns' => $constraint['columns'] ?? [],
                'unique'  => true,
                'name'    => $constraint['name'] ?? null,
            ];
        }

        if (empty($items)) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = $this->generateIndexSchema($item);
        }

        return "\n            " . implode("\n            ", $lines);
    }

    protected function generateIndexSchema(array $index): string
    {
        $columns = is_array($index['columns'])
            ? $index['columns']
            : array_map('trim', explode(',', $index['columns']));
        $name = $index['name'] ?? null;
        $unique = $index['unique'] ?? false;
        
        $method = $unique ? 'unique' : 'index';
        $columnsStr = "'" . implode("', '", $columns) . "'";
        
        $name = $name ?? $this->safeConstraintName($columns, $method);

        return "\$table->{$method}([{$columnsStr}], '{$name}');";
    }

    protected function safeConstraintName(array $columns, string $type): string
    {
        $auto = $this->tableName . '_' . implode('_', $columns) . '_' . $type;
        if (strlen($auto) <= 64) {
            return $auto;
        }
        // Name exceeds MySQL's 64-char limit — keep a readable prefix and append an 8-char hash
        $hash   = substr(md5($auto), 0, 8);
        $suffix = '_' . $hash . '_' . $type;
        $prefix = substr($this->tableName . '_' . implode('_', $columns), 0, 64 - strlen($suffix));
        return $prefix . $suffix;
    }

    /**
     * Laravel-convention system column names — SchemaIntrospector::
     * SKIP_COLUMNS minus 'id'/'uuid' (irrelevant here: the id column has
     * its own dedicated skip in generateFieldSchema(), and uuid has its own
     * dedicated generateUuidLine()). SchemaIntrospector excludes exactly
     * these from live introspection, on the understanding that
     * generateAuditFields()/generateTimestampsLine()/generateSoftDeletesLine()
     * own emitting them — so a REAL introspected `columns` array can never
     * legitimately contain one of these exact names. See
     * columnAlreadyDeclaredElsewhere()'s docblock for why that matters.
     */
    private const RESERVED_SYSTEM_COLUMN_NAMES = [
        'created_at', 'updated_at', 'deleted_at', 'created_by_id', 'updated_by_id',
    ];

    /**
     * Whether $name is already declared by generateSchema()'s per-field
     * loop over $this->fields (the introspected/configured `columns` list)
     * — every OTHER field-emission method below (generateAuditFields(),
     * generateTimestampsLine(), generateSoftDeletesLine()) must skip a
     * configured column name this returns true for, rather than declaring
     * it a second time.
     *
     * Deliberately returns false outright for one of
     * self::RESERVED_SYSTEM_COLUMN_NAMES, even if it happens to appear in
     * $this->fields — SchemaIntrospector::SKIP_COLUMNS guarantees that name
     * is never a REAL introspected column (see that constant's docblock),
     * so its presence in a hand-authored config is being used as the
     * flag-absent fallback-detection SIGNAL ModuleConfigContract::
     * hasSoftDeletes()/hasTimestamps()/hasCreatorUpdater() document
     * ("rescan $config['columns'] for a field literally named ...") —
     * *not* a real, separately-declared column that would make the emitter
     * below's own declaration a duplicate. Confirmed against
     * ModuleConfigContractTest::test_model_and_migration_generators_agree_on_has_soft_deletes()'s
     * "deleted_at column present, flag absent" case: MigrationGenerator
     * must still emit `$table->softDeletes();` there.
     *
     * Bug this guards against, for anything NOT in that reserved list: a
     * legacy table this project adapts in place (see
     * MigrationGeneratorCustomColumnNamesTest's class docblock) can have
     * creator_updater_columns/timestamp_columns/soft_delete_column point at
     * a column name introspection ALREADY found and put in $this->fields —
     * e.g. a real, nullable, non-FK-typed `created_by` integer column with
     * no `_id` suffix (SKIP_COLUMNS only recognises 'created_by_id', so a
     * differently-spelled real column survives introspection as an
     * ordinary field). Confirmed live against `ward`
     * (creator_updater_columns: {created_by: created_by, updated_by:
     * null}): generateSchema() emitted
     * `$table->integer('created_by')->nullable();` for the real,
     * introspected column, and generateAuditFields() then ALSO emitted a
     * second, wrong-typed `$table->foreignId('created_by');` for the exact
     * same name — it treated the configured name as "always declare a
     * fresh column" with no check against what generateSchema() had
     * already declared.
     */
    protected function columnAlreadyDeclaredElsewhere(string $name): bool
    {
        if (in_array($name, self::RESERVED_SYSTEM_COLUMN_NAMES, true)) {
            return false;
        }

        return in_array($name, array_column($this->fields, 'name'), true);
    }

    protected function generateAuditFields(): string
    {
        if (!$this->hasCreatorUpdater()) {
            return '';
        }

        // Audit fields use foreignId() for proper FK semantics. Column names
        // come from ModuleConfigContract::creatorUpdaterColumns() — the
        // historical 'created_by_id'/'updated_by_id' pair by default, or a
        // module.json override (e.g. this project's legacy 'created_by'/
        // 'modified_by' naming). A single-actor module (creatorUpdaterColumns()
        // returns 'updated' => null) gets only the creator column.
        $columns = ModuleConfigContract::creatorUpdaterColumns($this->config);

        $auditFields = [];
        if (!$this->columnAlreadyDeclaredElsewhere($columns['created'])) {
            $auditFields[] = "\$table->foreignId('{$columns['created']}');";
        }
        if ($columns['updated'] !== null && !$this->columnAlreadyDeclaredElsewhere($columns['updated'])) {
            $auditFields[] = "\$table->foreignId('{$columns['updated']}')->nullable();";
        }

        if (empty($auditFields)) {
            return '';
        }

        return "\n            " . implode("\n            ", $auditFields);
    }

    /**
     * Bug this guards against: this migration template used to hardcode
     * $table->timestamps(); / $table->softDeletes(); / a separate
     * $table->uuid()->...->unique(); plus their indexes and
     * created_by_id/updated_by_id, unconditionally, for every generated
     * migration — regardless of whether the table being scaffolded actually
     * has any of those columns in reality. This is the migration-side twin
     * of the bug already fixed in ModelGenerator (v2.10.8, SoftDeletes trait
     * + $timestamps property): confirmed on `price_lists` /
     * `stock_transfers` — tables deliberately scaffolded with NO uuid, NO
     * timestamps, NO soft-deletes and NO audit columns. A --force
     * regeneration (or a fresh scaffold) of such a module previously
     * produced a migration that tried to create columns the real schema
     * never had, requiring hand-correction after every scaffold.
     *
     * Fix: mirror ModelGenerator::hasTimestamps()/hasSoftDeletes() — trust
     * the same $config['has_timestamps']/['has_soft_deletes'] flags (already
     * plumbed through by IntrospectionToConfig::build()), plus the two new
     * $config['has_uuid']/['has_creator_updater'] flags introduced alongside
     * this fix, and default to `true` for all four when omitted (matching
     * the project's existing convention that most tables have all of them).
     *
     * All four now delegate to ModuleConfigContract — the single sanctioned
     * resolution rule shared with ModelGenerator, so the two can never
     * disagree about the same config (this is what used to happen:
     * hasSoftDeletes() here used a bare `?? false` with no deleted_at
     * rescan, while ModelGenerator's twin rescanned $fields — two different
     * answers for the same config).
     */
    protected function hasTimestamps(): bool
    {
        return ModuleConfigContract::hasTimestamps($this->config);
    }

    protected function hasSoftDeletes(): bool
    {
        return ModuleConfigContract::hasSoftDeletes($this->config);
    }

    protected function hasUuid(): bool
    {
        return ModuleConfigContract::hasUuid($this->config);
    }

    protected function hasCreatorUpdater(): bool
    {
        return ModuleConfigContract::hasCreatorUpdater($this->config);
    }

    /**
     * shelui-engine fork: column NAMES are configurable via
     * ModuleConfigContract::timestampColumns() (module.json's
     * `timestamp_columns`) — a module mapped onto a pre-existing table with
     * differently named columns (this project's legacy 'created_date'/
     * 'modified_date') still gets real, Eloquent-managed timestamps, not
     * plain string columns the app has to populate itself. The Laravel
     * default pair still emits the familiar `$table->timestamps();` call
     * rather than two spelled-out `$table->timestamp()` calls, so every
     * module generated before this accessor existed produces byte-identical
     * output.
     *
     * Guards against the same already-declared-column duplication
     * generateAuditFields() guards against (see
     * columnAlreadyDeclaredElsewhere()'s docblock) — a custom
     * `timestamp_columns` name can equally coincide with a real column
     * introspection already put in $this->fields. Never trips for the
     * default 'created_at'/'updated_at' pair specifically (that reserved-
     * name carve-out is columnAlreadyDeclaredElsewhere()'s job, not this
     * method's).
     */
    protected function generateTimestampsLine(): string
    {
        if (!$this->hasTimestamps()) {
            return '';
        }

        $columns = ModuleConfigContract::timestampColumns($this->config);
        $createdAlreadyDeclared = $this->columnAlreadyDeclaredElsewhere($columns['created']);
        $updatedAlreadyDeclared = $this->columnAlreadyDeclaredElsewhere($columns['updated']);

        if ($createdAlreadyDeclared && $updatedAlreadyDeclared) {
            return '';
        }

        if (!$createdAlreadyDeclared && !$updatedAlreadyDeclared
            && $columns['created'] === 'created_at' && $columns['updated'] === 'updated_at') {
            return '$table->timestamps();';
        }

        $lines = [];
        if (!$createdAlreadyDeclared) {
            $lines[] = "\$table->timestamp('{$columns['created']}')->nullable();";
        }
        if (!$updatedAlreadyDeclared) {
            $lines[] = "\$table->timestamp('{$columns['updated']}')->nullable();";
        }

        return implode("\n            ", $lines);
    }

    /**
     * shelui-engine fork: ModuleConfigContract::softDeleteType() picks
     * between Laravel's own nullable `deleted_at` timestamp (default) and
     * this project's legacy integer flag convention ('flag' — see
     * App\Project\_Src\Traits\HasIsDeleted, which ModelGenerator wires up
     * for the matching trait choice). softDeleteColumn() supplies the
     * column NAME either way, so `$table->softDeletes()` still emits for
     * the untouched default case and only spells out a custom name
     * (`$table->softDeletes('...')`) when one was configured.
     *
     * Guards against the same already-declared-column duplication
     * generateAuditFields() guards against (see
     * columnAlreadyDeclaredElsewhere()'s docblock) — a custom
     * `soft_delete_column` (flag type especially: its OWN default is
     * already the non-reserved name `is_deleted`, this project's real
     * convention) can equally coincide with a real column introspection
     * already put in $this->fields.
     */
    protected function generateSoftDeletesLine(): string
    {
        if (!$this->hasSoftDeletes()) {
            return '';
        }

        $column = ModuleConfigContract::softDeleteColumn($this->config);
        if ($this->columnAlreadyDeclaredElsewhere($column)) {
            return '';
        }

        if (ModuleConfigContract::softDeleteType($this->config) === 'flag') {
            return "\$table->boolean('{$column}')->default(false);";
        }

        return $column === 'deleted_at' ? '$table->softDeletes();' : "\$table->softDeletes('{$column}');";
    }

    protected function generateUuidLine(): string
    {
        return $this->hasUuid()
            ? '$table->uuid()->default(DB::raw(Helpers::getDefaultUuidByDriver()))->unique();'
            : '';
    }

    protected function generateUuidImports(): string
    {
        return $this->hasUuid()
            ? "use App\\Project\\_Src\\Helpers;\nuse Illuminate\\Support\\Facades\\DB;"
            : '';
    }

    protected function generateSystemIndexes(): string
    {
        $lines = [];

        if ($this->hasUuid()) {
            $lines[] = "\$table->index(['uuid'], 'idx_{$this->tableName}_uuid');";
        }
        if ($this->hasCreatorUpdater()) {
            $auditColumns = ModuleConfigContract::creatorUpdaterColumns($this->config);
            $lines[] = "\$table->index(['{$auditColumns['created']}'], 'idx_{$this->tableName}_{$auditColumns['created']}');";
            if ($auditColumns['updated'] !== null) {
                $lines[] = "\$table->index(['{$auditColumns['updated']}'], 'idx_{$this->tableName}_{$auditColumns['updated']}');";
            }
        }
        if ($this->hasSoftDeletes()) {
            $softDeleteColumn = ModuleConfigContract::softDeleteColumn($this->config);
            $lines[] = "\$table->index(['{$softDeleteColumn}'], 'idx_{$this->tableName}_{$softDeleteColumn}');";
        }

        if (empty($lines)) {
            return '';
        }

        return implode("\n            ", $lines);
    }
}
