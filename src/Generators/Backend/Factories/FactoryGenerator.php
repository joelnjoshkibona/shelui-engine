<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Factories;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

/**
 * Generates a `{Module}Factory.php` co-located inside the module directory
 * (e.g. `App/Project/Modules/Core/ItemTypes/ItemTypesFactory.php`), matching
 * the hand-built convention in SYSTEM_SHELL (StatusesFactory, LocationsFactory,
 * MobileReleasesFactory, MediaFactory, UserInvitationsFactory, ...) rather
 * than Laravel's default `database/factories/` location — none of those
 * reference factories live there, and every one of them is picked up purely
 * through `{Module}Model::newFactory()` returning `{Module}Factory::new()`
 * (see BaseModel/StatusesModel), NOT Laravel's class-name-guessing default
 * resolution.
 *
 * This generator exists to close a real, confirmed bug: generated CRUD
 * tests (PhpUnitTestGenerator) run under RefreshDatabase, so a required
 * cross-module foreign key (e.g. Items.item_type_id -> item_types) has no
 * existing parent row to reference at fixture time. The fix is for the
 * dependent module's test to CREATE its own parent row via the parent
 * module's factory (`ItemTypesModel::factory()->create()->id`) instead of
 * looking one up. That only works once every module ships a factory, which
 * is what this generator produces.
 *
 * definition() values are derived straight from the module's own
 * `config['columns']` (the same shape MigrationGenerator/ModelGenerator
 * read) — never invented business data (this project's seeders
 * deliberately ship "data": [] for the same reason: a generic generator
 * cannot know what a real value SHOULD be), only structurally-valid
 * fake()/literal values that satisfy the column's own type/nullable/unique
 * constraints so a `->create()` call succeeds against a freshly migrated
 * database.
 */
class FactoryGenerator extends BaseGenerator
{
    protected array $columns;
    protected string $idType;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);
        $this->columns = $this->config['columns'] ?? []; // the normalised copy (BaseGenerator), not the raw parameter
        $this->idType = $config['id_type'] ?? 'autoincrement';
    }

    public function generate(): bool
    {
        $lines = [];

        $lines[] = $this->buildIdLine();
        $lines[] = $this->buildUuidLine();

        foreach ($this->columns as $column) {
            $line = $this->buildColumnLine($column);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($this->hasCreatorUpdater()) {
            $createdByColumn = ModuleConfigContract::creatorUpdaterColumns($this->config)['created'];
            $lines[] = "            '{$createdByColumn}' => 1,";
        }

        $lines = array_values(array_filter($lines));
        $body = implode("\n", $lines);

        $namespace = $this->getNamespace();
        $modelFqcn = $this->moduleName . 'Model';

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\\{$namespace}\\{$modelFqcn}>
 */
class {$this->moduleName}Factory extends Factory
{
    protected \$model = {$modelFqcn}::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
{$body}
        ];
    }
}

PHP;

        $filePath = "{$this->modulePath}/{$this->moduleName}Factory.php";

        return $this->writeFile($filePath, $content);
    }

    protected function hasCreatorUpdater(): bool
    {
        return ModuleConfigContract::hasCreatorUpdater($this->config);
    }

    /**
     * Column names auto-managed elsewhere and never emitted individually
     * via buildColumnLine() — 'id' (buildIdLine()), and this module's real
     * uuid/timestamp/soft-delete column names (ModuleConfigContract's
     * resolved names, not just the Laravel-default literals a custom-named
     * module — this project's legacy 'created_date'/'modified_date'/
     * 'is_deleted' convention — never actually uses). A compliant config
     * never declares these in columns[] to begin with, so this is purely
     * defensive, same as ModelGenerator::generateCasts()'s equivalent list.
     *
     * @return string[]
     */
    protected function managedColumnNames(): array
    {
        $names = ['id'];

        if (ModuleConfigContract::hasUuid($this->config)) {
            $names[] = 'uuid';
        }
        if (ModuleConfigContract::hasTimestamps($this->config)) {
            $timestampColumns = ModuleConfigContract::timestampColumns($this->config);
            $names[] = $timestampColumns['created'];
            $names[] = $timestampColumns['updated'];
        }
        if (ModuleConfigContract::hasSoftDeletes($this->config)) {
            $names[] = ModuleConfigContract::softDeleteColumn($this->config);
        }

        return $names;
    }

    /**
     * Non-autoincrement primary keys (uuid/manual) need an explicit id —
     * mirrors UsersFactory/UserInvitationsFactory/PermissionsFactory, which
     * all set 'id' themselves because Eloquent's create() can't read back a
     * DB-generated default when $incrementing is false. Autoincrement ids
     * are omitted entirely, matching every reference-table factory
     * (StatusesFactory, LocationTypesFactory, ...).
     *
     * IMPORTANT: this must be a WHITELIST of the known non-autoincrement
     * types ('uuid'/'string'), not a blacklist of the literal string
     * 'autoincrement'. IntrospectionToConfig::build() (see its docblock)
     * documents `id_type` as only ever being `'uuid' | 'bigint'` for
     * real generated module.json configs — it never emits the literal
     * 'autoincrement'. A blacklist check against that one literal therefore
     * never matches a real 'bigint' module, so every generated factory fell
     * through to the uuid branch regardless of the column's real type —
     * this was a confirmed bug: MySQL rejected the resulting inserts with
     * "Data truncated for column 'id'" because the table's `id` is actually
     * `bigint unsigned AUTO_INCREMENT`. (The FactoryGenerator constructor's
     * own `?? 'autoincrement'` fallback only matters for hand-rolled/legacy
     * configs that omit `id_type` entirely; it still correctly omits the id
     * line via this whitelist since 'autoincrement' isn't in it.)
     */
    protected function buildIdLine(): ?string
    {
        if (!in_array($this->idType, ['uuid', 'string'], true)) {
            return null;
        }

        return "            'id' => (string) Str::uuid(),";
    }

    /**
     * Every hand-built factory sets 'uuid' explicitly even though it has a
     * DB-level default expression, because Eloquent's create() never reads
     * a DB-computed default back onto the in-memory model without an
     * explicit ->fresh()/->refresh() (see StatusesFactory's docblock).
     */
    protected function buildUuidLine(): ?string
    {
        if (!ModuleConfigContract::hasUuid($this->config)) {
            return null;
        }

        return "            'uuid' => (string) Str::uuid(),";
    }

    protected function buildColumnLine(array $column): ?string
    {
        $name = $column['name'] ?? null;
        if ($name === null || in_array($name, $this->managedColumnNames(), true)) {
            return null;
        }

        // created_by_id/updated_by_id (or a module.json's configured
        // equivalent, e.g. this project's legacy created_by/modified_by) are
        // handled separately (or, for the updated column, deliberately
        // omitted — no hand-built factory sets it, since it's always
        // nullable and irrelevant to a fresh fixture).
        $auditColumns = ModuleConfigContract::creatorUpdaterColumns($this->config);
        if ($name === $auditColumns['created'] || $name === $auditColumns['updated']) {
            return null;
        }

        $type = $column['type'] ?? 'string';
        $nullable = (bool) ($column['nullable'] ?? false);
        $unique = (bool) ($column['unique'] ?? false);

        if ($type === 'foreignId' || str_ends_with($name, '_id')) {
            return "            '{$name}' => " . $this->buildForeignKeyValue($column, $nullable) . ',';
        }

        return "            '{$name}' => " . $this->buildScalarValue($name, $type, $unique, $column) . ',';
    }

    /**
     * Resolve a foreign-key column's factory value.
     *
     * Self-referential FKs (a hierarchy column pointing back at this same
     * module/table) and nullable FKs to a module this generator can't place
     * both degrade to `null` — exactly the same reasoning
     * PhpUnitTestGenerator::buildFieldValueLiteral() already documents for
     * the identical problem on the test-fixture side: on the very first
     * insert into an empty table there is categorically no parent row yet
     * for a self-reference to point at, and a related module we can't
     * identify has no factory we can safely reference.
     *
     * A REQUIRED (non-nullable) reference to a resolvable related module
     * recursively uses that module's own factory
     * (`\Ns\RelatedModel::factory()` — Eloquent resolves a Factory instance
     * used as an attribute value by creating it and substituting the new
     * row's key, exactly like MobileReleasesFactory's
     * `'apk_media_id' => MediaModel::factory()`). When the related module
     * can't be resolved, falls back to the literal `1` last resort called
     * out in the brief.
     */
    protected function buildForeignKeyValue(array $column, bool $nullable): string
    {
        $name = $column['name'];
        $relatedModuleName = $column['relatedModule'] ?? null;

        $isSelfReferential = $relatedModuleName === $this->moduleName
            || ($relatedModuleName === null && $this->looksSelfReferential($name));

        if ($isSelfReferential) {
            return 'null';
        }

        if ($relatedModuleName === null || $relatedModuleName === '') {
            return $nullable ? 'null' : '1';
        }

        if (!$this->relatedModuleResolvable($relatedModuleName)) {
            return $nullable ? 'null' : '1';
        }

        $relatedNamespace = PathManager::resolveBackendModuleNamespace($relatedModuleName);
        $relatedModelFqcn = $relatedNamespace . '\\' . $relatedModuleName . 'Model';

        if ($nullable) {
            // A nullable-but-resolvable FK: null is simpler and always
            // valid, and avoids creating an extra row nothing requires.
            return 'null';
        }

        return "\\{$relatedModelFqcn}::factory()";
    }

    /**
     * Heuristic used only when a column has no explicit relatedModule
     * metadata at all (hand-rolled configs, or introspection that couldn't
     * resolve a real FK constraint): a column named "parent_id" almost
     * always self-references its own table (item_categories.parent_id ->
     * item_categories.id), matching the exact case
     * PhpUnitTestGenerator::buildFieldValueLiteral() was fixed for.
     */
    protected function looksSelfReferential(string $columnName): bool
    {
        return $columnName === 'parent_id';
    }

    /**
     * Whether $relatedModuleName resolves to a real, known module — via the
     * array-based module registry (the same source PhpUnitTestGenerator's
     * resolveCrossModuleFkLiteral() and ModelGenerator's
     * guessedModuleExists() both already trust).
     */
    protected function relatedModuleResolvable(string $relatedModuleName): bool
    {
        return PathManager::findModuleInRegistry($relatedModuleName) !== null;
    }

    protected function buildScalarValue(string $name, string $type, bool $unique, array $column): string
    {
        if (str_contains(strtolower($name), 'email')) {
            return $unique ? 'fake()->unique()->safeEmail()' : 'fake()->safeEmail()';
        }

        switch (strtolower($type)) {
            case 'boolean':
                $default = $column['default'] ?? null;
                if ($default === null) {
                    return 'true';
                }
                if (is_string($default)) {
                    return strtolower($default) === 'true' ? 'true' : 'false';
                }
                return $default ? 'true' : 'false';

            case 'date':
                return 'now()->toDateString()';

            case 'datetime':
            case 'timestamp':
                return 'now()';

            case 'decimal':
            case 'float':
            case 'double':
                return $unique
                    ? 'fake()->unique()->randomFloat(2, 1, 100000)'
                    : 'fake()->randomFloat(2, 1, 1000)';

            case 'integer':
            case 'bigint':
            case 'bigInteger':
            case 'smallint':
            case 'smallInteger':
            case 'tinyint':
            case 'tinyInteger':
                return $unique
                    ? 'fake()->unique()->numberBetween(100000, 999999)'
                    : 'fake()->numberBetween(1, 1000)';

            case 'text':
            case 'longtext':
            case 'mediumtext':
                return 'fake()->paragraph()';

            case 'enum':
                // The real key populated by IntrospectionToConfig::build()
                // (and documented on the `enum_values` column property in
                // schema/module-config.schema.json) is `enum_values` — a
                // plain string[] of the column's allowed values. Neither
                // `options` nor `values` is ever actually written by the
                // config builder, so reading either here silently produced
                // a generic fake string instead of a real, constraint-valid
                // enum value.
                $enumValues = $column['enum_values'] ?? null;
                if (is_array($enumValues) && !empty($enumValues)) {
                    // var_export(), not addslashes(), because these values
                    // are being spliced into a SINGLE-quoted PHP literal:
                    // addslashes() also escapes `"` to `\"`, which a
                    // single-quoted string does not recognize as an escape
                    // at all — the backslash survives literally in the
                    // resulting string, corrupting any enum value containing
                    // a double quote. var_export() always emits a literal
                    // correct for the context it renders (single-quoted, `\`
                    // and `'` escaped, nothing else), so it's safe for any
                    // value regardless of which of `'`, `"`, `\` it contains.
                    $literalOptions = implode(', ', array_map(
                        static fn ($opt) => var_export((string) $opt, true),
                        $enumValues
                    ));
                    return "fake()->randomElement([{$literalOptions}])";
                }
                return "'" . addslashes(Str::studly($name)) . "'";

            case 'json':
            case 'jsonb':
                return '[]';

            default:
                $studly = Str::studly($name);
                $length = $this->resolveColumnLength($column);

                // A string column with a schema default gets that default, not two random words.
                //
                // Confirmed live 2026-08 on a rental-CRM domain: a `status` column backed by module
                // `constants` (DRAFT/ACTIVE/ENDED/TERMINATED) is a plain varchar as far as the DB is
                // concerned, so it got `Str::limit(fake()->words(2, true), 255, '')` and every
                // factory-built row was born as "incidunt sit" — a value no guard, action or state
                // machine in the application accepts. Every fixture therefore started in an
                // impossible state, and each affected project patched its own factories by hand.
                //
                // `constants` cannot answer this on its own: it is a flat name => value map with no
                // column association (one module had 8 constants spanning `status` and
                // `deposit_status`). The column's own `default`, read from the migration by
                // IntrospectionToConfig::buildColumn(), does — and it is right for every project,
                // not just ones using `constants`. Booleans have honoured their default since this
                // method was written (see the 'boolean' case above); this is the same rule applied
                // to strings.
                //
                // Deliberately skipped for a UNIQUE column: every generated row would collide on the
                // second insert. var_export() for the literal, matching the 'enum' case's reasoning
                // about single-quoted PHP strings.
                $default = $column['default'] ?? null;
                if (!$unique && is_string($default) && $default !== '') {
                    $upper = strtoupper($default);
                    // Not a value — a DB function the column computes at insert time.
                    if (!in_array($upper, ['CURRENT_TIMESTAMP', 'NULL', 'NOW()'], true)
                        && ($length === null || mb_strlen($default) <= $length)
                    ) {
                        return var_export($default, true);
                    }
                }

                // Confirmed bug (THC_V2 OrdersFactory.php:43): a plain
                // varchar(20) `payment_type` column got
                // `fake()->words(2, true)` unconditionally — two random
                // words frequently exceed 20 chars, so every factory
                // ->create() (including the parent-row fixtures other CRUD
                // tests create via OrdersModel::factory()) 500'd with
                // SQLSTATE 22001 "Data too long for column". This is the
                // exact same max-length blindness
                // PhpUnitTestGenerator::buildFieldValueLiteral() was already
                // fixed for on the test-fixture side (see
                // buildMaxAwareUniqueStringLiteral() there) — this generator
                // just never got the equivalent fix, and works from the
                // column's own `length` config key (IntrospectionToConfig::
                // buildColumn()), not a validation `max:` rule, since a
                // factory has no validation rules to read from.
                if ($length === null) {
                    // No length constraint on this column: byte-for-byte the
                    // same literal as before this fix, for every column
                    // IntrospectionToConfig ever leaves `length` unset/empty
                    // (e.g. a hand-rolled config, or a column type the DB
                    // never bounds).
                    return $unique
                        ? "'Test {$studly} ' . uniqid()"
                        : "fake()->words(2, true)";
                }

                return $unique
                    ? $this->buildMaxAwareUniqueStringLiteral($studly, $length)
                    : "Str::limit(fake()->words(2, true), {$length}, '')";
        }
    }

    /**
     * Resolve a column's real varchar/char length as an int, or null when
     * none is configured. IntrospectionToConfig::buildColumn() always casts
     * `length` to a STRING and defaults it to `''` (never null or 0) for a
     * column with no length constraint — `(string) ($col['length'] ?? '')`
     * — so `''`, non-numeric, and non-positive values must all normalize to
     * "no constraint" here, not just a literal null/missing key.
     */
    protected function resolveColumnLength(array $column): ?int
    {
        $length = $column['length'] ?? null;

        if ($length === null || $length === '' || !is_numeric($length)) {
            return null;
        }

        $length = (int) $length;

        return $length > 0 ? $length : null;
    }

    /**
     * Build a `'Test {Studly} ' . uniqid()`-shaped literal (or a
     * pure-entropy fallback) that never exceeds $max bytes, while keeping
     * the fixture unique across repeated factory ->create() calls in the
     * same test.
     *
     * Deliberately the same shape as
     * PhpUnitTestGenerator::buildMaxAwareUniqueStringLiteral() (see that
     * method's docblock for the full reasoning): the label is truncated
     * from the RIGHT, never `uniqid()` itself, since `uniqid()`'s
     * fast-changing part is its tail, and a column too short even for the
     * bare 13-char `uniqid()` token falls back to `substr(uniqid(), -$max)`
     * so uniqueness survives even a varchar(6)/varchar(7).
     */
    protected function buildMaxAwareUniqueStringLiteral(string $studly, int $max): string
    {
        $uidLength = 13;
        $label = "Test {$studly} ";

        if ($max >= strlen($label) + $uidLength) {
            return "'{$label}' . uniqid()";
        }

        if ($max <= 0) {
            return "''";
        }

        if ($max < $uidLength) {
            return "substr(uniqid(), -{$max})";
        }

        $labelBudget = $max - $uidLength;
        $truncatedLabel = substr($label, 0, $labelBudget);

        return "'{$truncatedLabel}' . uniqid()";
    }
}
