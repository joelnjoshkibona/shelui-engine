<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * ModuleConfigContract
 *
 * THE single sanctioned place to read derived module-level facts
 * (has_soft_deletes / has_timestamps / has_uuid / has_creator_updater) off a
 * built GeneratorModule config array.
 *
 * Bug this guards against: before this class existed, the same fact was
 * re-derived independently in at least three places with three different
 * defaulting/fallback rules —
 *   - IntrospectionToConfig::build() defaulted an absent 'has_soft_deletes'
 *     meta key to `false` silently (see the strict-mode validation added
 *     alongside this class).
 *   - ModelGenerator::hasSoftDeletes() read the config flag AND, when the
 *     flag key was entirely absent from $config, separately rescanned
 *     $fields for a literal 'deleted_at' entry.
 *   - MigrationGenerator::hasSoftDeletes() read the config flag with a bare
 *     `?? false` and never rescanned fields at all.
 * A caller that forgot to thread the live-introspected value into $meta
 * therefore got a silent `false` — indistinguishable from an intentional
 * `false` — and the two generators could each reach a DIFFERENT answer for
 * the exact same config, depending on which of the three rules it happened
 * to implement. This actually shipped: a downstream command discarded the
 * live introspection result and every generated module quietly lost
 * SoftDeletes.
 *
 * Fix: every generator that needs one of these facts calls the matching
 * static accessor here instead of re-deriving it. Each accessor documents
 * its ONE resolution rule. Where a rescan-the-fields fallback is genuinely
 * useful (ModelGenerator's historical deleted_at-column rescan), it is
 * folded in here, explicitly, as a documented fallback shared by every
 * caller — not a private behaviour only one generator happened to have.
 */
final class ModuleConfigContract
{
    /**
     * Whether the module's underlying table has a `deleted_at` column
     * (i.e. `$table->softDeletes()` was used / the Eloquent model should
     * `use SoftDeletes`).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_soft_deletes'] is present, trust it verbatim
     *      (cast to bool) — this is expected to have been set by
     *      IntrospectionToConfig::build() from the live
     *      SchemaIntrospector::hasSoftDeletes() result.
     *   2. Otherwise (flag key entirely absent — a hand-authored or legacy
     *      config that predates the flag), fall back to scanning
     *      $config['columns'] for a field literally named 'deleted_at'.
     *      This fallback exists ONLY for configs that never had the flag
     *      threaded through at all; any config built via
     *      IntrospectionToConfig::build() in strict mode always has the key
     *      present, so this branch is never reached for it.
     *   3. If neither is present, default to `false` — soft deletes are an
     *      opt-in migration feature; most tables don't have deleted_at.
     */
    public static function hasSoftDeletes(array $config): bool
    {
        if (array_key_exists('has_soft_deletes', $config)) {
            return (bool) $config['has_soft_deletes'];
        }

        foreach ($config['columns'] ?? [] as $field) {
            if (($field['name'] ?? null) === 'deleted_at') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the module's underlying table has `created_at` / `updated_at`
     * columns (i.e. `$table->timestamps()` was used).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_timestamps'] is present, trust it verbatim (cast
     *      to bool) — expected to have been set by
     *      IntrospectionToConfig::build() from the live
     *      SchemaIntrospector::hasTimestamps() result.
     *   2. Otherwise default to `true` — the Laravel migration convention
     *      default is $table->timestamps(); created_at/updated_at are
     *      deliberately excluded from $config['columns'] (see
     *      SchemaIntrospector::SKIP_COLUMNS), so — unlike soft deletes —
     *      their absence from columns[] is never evidence either way and is
     *      not used as a fallback signal here.
     */
    public static function hasTimestamps(array $config): bool
    {
        return array_key_exists('has_timestamps', $config) ? (bool) $config['has_timestamps'] : true;
    }

    /**
     * Whether the module's underlying table has a separate public-addressing
     * `uuid` column (independent of the `id` column's own type).
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_uuid'] is present, trust it verbatim (cast to
     *      bool).
     *   2. Otherwise default to `true` — the project convention is that
     *      every table gets a routing uuid unless a caller that actually
     *      introspected the real table says otherwise.
     */
    public static function hasUuid(array $config): bool
    {
        return array_key_exists('has_uuid', $config) ? (bool) $config['has_uuid'] : true;
    }

    /**
     * Whether the module's underlying table has paired
     * `created_by_id` / `updated_by_id` audit columns.
     *
     * Resolution rule (single source of truth):
     *   1. If $config['has_creator_updater'] is present, trust it verbatim
     *      (cast to bool).
     *   2. Otherwise default to `true` — most tables in this project's
     *      convention have paired creator/updater tracking.
     */
    public static function hasCreatorUpdater(array $config): bool
    {
        return array_key_exists('has_creator_updater', $config) ? (bool) $config['has_creator_updater'] : true;
    }

    /**
     * The migration/model column names backing `$table->timestamps()` for
     * this module: `{created: string, updated: string}`. Only meaningful
     * when hasTimestamps() is true.
     *
     * Defaults to Laravel's own 'created_at' / 'updated_at' pair, unchanged
     * for any module that doesn't override — every module generated before
     * this accessor existed keeps generating exactly what it generated
     * before. A module mapped onto a pre-existing table with differently
     * named columns (this project's legacy tables use 'created_date' /
     * 'modified_date') overrides via `timestamp_columns` in module.json:
     *   "timestamp_columns": { "created_at": "created_date", "updated_at": "modified_date" }
     *
     * shelui-engine fork: replaces the ad-hoc, hardcoded 'created_date'/
     * 'modified_date' rescan that used to live only in ModelGenerator
     * (and nowhere else — MigrationGenerator/BaseServiceGenerator never knew
     * about it at all, so a legacy-named module's migration and filters
     * still assumed 'created_at'/'updated_at'). One resolution rule, shared
     * by every generator that touches these columns.
     *
     * @return array{created: string, updated: string}
     * @throws \InvalidArgumentException when `timestamp_columns` is present
     *         but not shaped as documented.
     */
    public static function timestampColumns(array $config): array
    {
        $override = $config['timestamp_columns'] ?? [];
        if (!is_array($override)) {
            throw new \InvalidArgumentException('timestamp_columns must be an array shaped {"created_at": string, "updated_at": string}.');
        }

        $created = $override['created_at'] ?? 'created_at';
        $updated = $override['updated_at'] ?? 'updated_at';

        if (!is_string($created) || $created === '' || !is_string($updated) || $updated === '') {
            throw new \InvalidArgumentException('timestamp_columns.created_at and timestamp_columns.updated_at must both be non-empty strings.');
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** The only values softDeleteType() may return. */
    private const VALID_SOFT_DELETE_TYPES = ['timestamp', 'flag'];

    /**
     * How this module implements soft deletes, when hasSoftDeletes() is
     * true:
     *   - 'timestamp' (default): Laravel's own nullable `deleted_at`
     *     column, model `use SoftDeletes`. Column NAME can still differ
     *     from 'deleted_at' — see softDeleteColumn().
     *   - 'flag': an integer flag column (0 = active, 1 = deleted) — this
     *     project's legacy convention, ported from ongeza-pro as
     *     `App\Project\_Src\Traits\HasIsDeleted` in the consuming app.
     *     ModelGenerator uses this trait instead of Laravel's own
     *     SoftDeletes for a 'flag' module.
     *
     * Declared via `"soft_delete_type": "flag"` in module.json. Defaults to
     * 'timestamp', unchanged for any module that doesn't override — this
     * project's own `permission_group` table genuinely has a real
     * `deleted_at` column and stays on the default.
     *
     * @throws \InvalidArgumentException when `soft_delete_type` is present
     *         but not one of the two values above.
     */
    public static function softDeleteType(array $config): string
    {
        $type = $config['soft_delete_type'] ?? 'timestamp';
        if (!in_array($type, self::VALID_SOFT_DELETE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'soft_delete_type must be one of: ' . implode(', ', self::VALID_SOFT_DELETE_TYPES)
            );
        }

        return $type;
    }

    /**
     * The actual column name backing soft deletes, when hasSoftDeletes() is
     * true. Defaults to 'deleted_at' for softDeleteType() 'timestamp' and
     * 'is_deleted' for 'flag' — this project's own real 'flag' tables are
     * all spelled 'is_deleted', which is why that (not 'deleted_at') is the
     * 'flag' default rather than requiring every such module to repeat
     * itself. Override either default via `"soft_delete_column": "..."` in
     * module.json.
     *
     * @throws \InvalidArgumentException when `soft_delete_column` is
     *         present but not a non-empty string.
     */
    public static function softDeleteColumn(array $config): string
    {
        $default = self::softDeleteType($config) === 'flag' ? 'is_deleted' : 'deleted_at';
        $column = $config['soft_delete_column'] ?? $default;

        if (!is_string($column) || $column === '') {
            throw new \InvalidArgumentException('soft_delete_column must be a non-empty string.');
        }

        return $column;
    }

    /**
     * The migration/model column names backing creator/updater audit
     * tracking for this module, when hasCreatorUpdater() is true:
     *   `{created: string, updated: string|null}`
     * `updated` is `null` for a single-actor module that only tracks who
     * CREATED a row, never who last touched it — this project's legacy
     * convention has tables with only `created_by`, no paired `modified_by`.
     * A caller that populates or relates the updater column (ModelGenerator's
     * updater() relation, EditServiceGenerator's actor assignment) must
     * treat `null` here as "there is no such column", not as "use the
     * default name".
     *
     * Defaults to the generator's own historical pair, 'created_by_id' /
     * 'updated_by_id', unchanged for any module that doesn't override.
     * Override via module.json:
     *   "creator_updater_columns": { "created_by": "created_by", "updated_by": "modified_by" }
     *   "creator_updater_columns": { "created_by": "created_by", "updated_by": null }
     *
     * @return array{created: string, updated: string|null}
     * @throws \InvalidArgumentException when `creator_updater_columns` is
     *         present but not shaped as documented.
     */
    public static function creatorUpdaterColumns(array $config): array
    {
        $override = $config['creator_updater_columns'] ?? [];
        if (!is_array($override)) {
            throw new \InvalidArgumentException('creator_updater_columns must be an array shaped {"created_by": string, "updated_by": string|null}.');
        }

        $created = array_key_exists('created_by', $override) ? $override['created_by'] : 'created_by_id';
        $updated = array_key_exists('updated_by', $override) ? $override['updated_by'] : 'updated_by_id';

        if (!is_string($created) || $created === '') {
            throw new \InvalidArgumentException('creator_updater_columns.created_by must be a non-empty string.');
        }
        if ($updated !== null && (!is_string($updated) || $updated === '')) {
            throw new \InvalidArgumentException('creator_updater_columns.updated_by must be a non-empty string or null.');
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * The fully-qualified class name creator()/updater() BelongsTo relations
     * point at, when hasCreatorUpdater() is true.
     *
     * Defaults to this project's own logged-in-user model,
     * `App\Project\Modules\Core\Users\Users\UsersModel`, unchanged for any
     * module that doesn't override — most tables' created_by/updated_by
     * really do record which authenticated User acted. Override via
     * module.json for a table whose audit columns record a different actor
     * entirely — e.g. this project's own `permission_group`/`permission`/
     * `menu`/`menu_category`, whose `created_by`/`modified_by` are backfilled
     * against `worker.id` (schemas/update001.sql), not `user.id`:
     *   "creator_updater_model": "\\App\\Project\\Modules\\Core\\Users\\Worker\\WorkerModel"
     *
     * @throws \InvalidArgumentException when `creator_updater_model` is
     *         present but not a non-empty string.
     */
    public static function creatorUpdaterModel(array $config): string
    {
        $model = $config['creator_updater_model'] ?? '\\App\\Project\\Modules\\Core\\Users\\Users\\UsersModel';

        if (!is_string($model) || $model === '') {
            throw new \InvalidArgumentException('creator_updater_model must be a non-empty string.');
        }

        return $model;
    }

    /**
     * The raw PHP expression CreateServiceGenerator/EditServiceGenerator
     * assign into the created_by/updated_by column, when hasCreatorUpdater()
     * is true. Defaults to `'Auth::id()'`, unchanged for any module that
     * doesn't override — the authenticated principal's own id really is the
     * right value for most tables. Override for a module whose audit columns
     * record a different actor than "the logged-in model itself" — e.g. this
     * project's own `permission_group`/`permission`/`menu`/`menu_category`,
     * whose `created_by`/`modified_by` mean `worker.id` (schemas/
     * update001.sql's backfill), not `user.id`, even for rows created going
     * forward: decided over `creator_updater_model` staying in sync with a
     * mismatched raw Auth::id() value, since a column meaning two different
     * things depending on when a row was created defeats the point of an
     * audit trail. Pairs with `creator_updater_model` (above) — when that's
     * overridden to a different actor entity, this should resolve to that
     * entity's id, not the authenticated model's own:
     *   "creator_updater_actor_value": "Auth::user()?->worker_id"
     *
     * @throws \InvalidArgumentException when `creator_updater_actor_value`
     *         is present but not a non-empty string.
     */
    public static function creatorUpdaterActorValue(array $config): string
    {
        $expr = $config['creator_updater_actor_value'] ?? 'Auth::id()';

        if (!is_string($expr) || $expr === '') {
            throw new \InvalidArgumentException('creator_updater_actor_value must be a non-empty string.');
        }

        return $expr;
    }

    /**
     * The FQCN of the Sanctum-authenticatable model every generated PHPUnit
     * test suite logs in as (`Sanctum::actingAs(...)` in
     * `{Module}TestCase::setUp()`), and that
     * `PhpUnitTestGenerator::usersModelImportLine()` imports under the fixed
     * local alias `UsersModel` — every generated test file's other
     * `UsersModel::...` references (the audit-column fixture value, JSON-path
     * assertions, the location-scoping fixture heuristic) go through that
     * same alias, so they all move together when this changes.
     *
     * A DIFFERENT question from creatorUpdaterModel()/creatorUpdaterActorValue()
     * (above): those answer "what model/value does THIS module's created_by/
     * updated_by column mean", assuming SOMEONE is already authenticated —
     * `creatorUpdaterActorValue()`'s own default, `'Auth::id()'`, can't
     * bootstrap Sanctum::actingAs() itself (nothing is authenticated yet at
     * that point — the test actor is what Auth::id() reads back afterward).
     * This one answers "what does the app's Sanctum guard actually
     * authenticate for a generated test" — a module's audit columns can
     * legitimately point at a different, non-authenticatable model (e.g. the
     * `permission_group`/`permission` Worker case creatorUpdaterModel()'s own
     * docblock describes) while every HTTP request in the app, generated
     * tests included, still logs in as a real Sanctum-guarded User. Most
     * consumers never draw that distinction, which is why this defaults to
     * creatorUpdaterModel() when not set — overriding creator_updater_model
     * alone (the existing, older key) keeps working unchanged for them; a
     * consumer that needs the split sets `test_actor_model` on its own,
     * without disturbing creator_updater_model:
     *   "test_actor_model": "App\\Project\\Modules\\Core\\Users\\User\\UserModel"
     *
     * @throws \InvalidArgumentException when `test_actor_model` is present
     *         but not a non-empty string.
     */
    public static function testActorModel(array $config): string
    {
        if (!array_key_exists('test_actor_model', $config)) {
            return self::creatorUpdaterModel($config);
        }

        $value = $config['test_actor_model'];
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('test_actor_model must be a non-empty string.');
        }

        return $value;
    }

    /**
     * The PHP expression, written in terms of the `UsersModel` alias
     * testActorModel() is imported under, that yields the acting test
     * user's id — e.g. the default `'UsersModel::DEVELOPER'` reads a
     * `DEVELOPER` constant off whatever class that alias resolves to. Used
     * only to bootstrap `Sanctum::actingAs(UsersModel::find(...))` itself
     * (see testActorModel()'s docblock for why creatorUpdaterActorValue()'s
     * `Auth::id()`-shaped default can't do that job).
     *
     * A consumer whose replacement test-actor model has no `DEVELOPER`
     * constant (or names the seeded developer row differently) overrides
     * this independently of testActorModel(), e.g.:
     *   "test_actor_id_expression": "UsersModel::first()->id"
     *
     * Every reference to this expression is written in terms of the literal
     * token `UsersModel` — PhpUnitTestGenerator substitutes that token for
     * either the bare alias (a file that already imports it) or the fully-
     * qualified testActorModel() class name (a literal embedded somewhere
     * that doesn't), so a caller-supplied expression must also lead with
     * `UsersModel` to work in both places.
     *
     * @throws \InvalidArgumentException when `test_actor_id_expression` is
     *         present but not a non-empty string.
     */
    public static function testActorIdExpression(array $config): string
    {
        if (!array_key_exists('test_actor_id_expression', $config)) {
            return 'UsersModel::DEVELOPER';
        }

        $value = $config['test_actor_id_expression'];
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('test_actor_id_expression must be a non-empty string.');
        }

        return $value;
    }

    /**
     * Whether this module's Model.php is hand-maintained and must never be
     * touched by generation, regardless of --force.
     *
     * Unlike the has_X flags above (introspected facts about the real
     * table), this is a developer *declaration* with no introspected
     * default — it defaults to false (the generator owns Model.php, as for
     * every other module) unless module.json explicitly opts a module out.
     *
     * Built for modules whose Model can't be expressed by the generator's
     * plain-BaseModel template at all — e.g. Users, which extends
     * Authenticatable with Sanctum/Notifiable/SoftDeletes/HasHistory and a
     * set of hand-written relationships. The generator's own
     * Authenticatable-capable template (model_users.stub) exists but is
     * unreachable (ModelGenerator hardcodes $modelType = 'default') and, even
     * if reached, has no slot for those traits/relationships — teaching it
     * to express this one module's exact shape was judged not worth it for
     * a single caller. This flag is the escape hatch: ModelGenerator uses it
     * to switch from writeFile() to writeFileOnce() (see BaseGenerator) so a
     * developer's hand-maintained Model.php survives every future --force
     * regenerate of everything else in the module.
     */
    public static function isModelHandMaintained(array $config): bool
    {
        return (bool) ($config['model_hand_maintained'] ?? false);
    }

    /**
     * Whether this module should also scaffold a Mobile App backend
     * counterpart (Model/Migration/Seeder/Controller/Services/Routes/Registry
     * under the offline-sync mobile app).
     *
     * Defaults to false — a developer opt-in, not an introspected fact.
     * Every `make:module`/`make:modules-from-db` run used to scaffold this
     * unconditionally, for every module, whether wanted or not: confirmed
     * live across a full session of module ports, where the Mobile App
     * counterpart was unwanted and had to be manually reverted (rm -rf the
     * new module directory + git checkout the touched registry.json) after
     * literally every single regenerate. `features.mobile_app.mode`
     * (online|offline|both) already existed to configure HOW mobile
     * generation behaves once enabled, but nothing gated WHETHER it ran at
     * all — this flag is that gate.
     */
    public static function isMobileAppEnabled(array $config): bool
    {
        return (bool) ($config['features']['mobile_app']['enabled'] ?? false);
    }

    /**
     * Whether this module's rows each belong to a location, and so must be
     * restricted to the locations the acting user can reach.
     *
     * Derived, not asked for, in the common case: a module with a
     * `location_id` column is location-bearing by definition. That mirrors
     * ListServiceGenerator::generateLocationScopeIncludesNull(), which
     * already introspects the same column to decide NULL handling for
     * location-scoped list queries — one column, one meaning, two consumers.
     *
     * `location_bearing` in the module config overrides the derivation in
     * both directions, and exists for the case introspection cannot see: a
     * row that belongs to a location through a join rather than a column.
     * A user is the standing example — `users` has no `location_id`, yet a
     * person is reachable only through their `user_locations` assignments.
     * Declaring `location_bearing: true` there lets the consuming app's
     * scope apply a resolver the schema could never have inferred.
     *
     * Why this exists at all. A consuming app's location scoping has, until
     * now, only ever reached list queries — `ListServiceTrait::applyLocationFiltering()`
     * in the app, not in this package. The generated view, edit, delete and
     * deleteCheck services all fetch by uuid through
     * `($query ?? Model::query())->where(['uuid' => ...])->first()`, which no
     * scope touches. So a record outside a user's locations is absent from
     * their list and still fully readable, editable and deletable by uuid.
     * Declaring the fact on the model is what lets the app close that gap in
     * one shared place, rather than at every fetch site in every module.
     *
     * Defaults to false, so a module that says nothing generates exactly
     * what it generates today.
     */
    public static function isLocationBearing(array $config): bool
    {
        if (array_key_exists('location_bearing', $config)) {
            return (bool) $config['location_bearing'];
        }

        foreach (($config['columns'] ?? []) as $column) {
            if (($column['name'] ?? '') === 'location_id') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this module should generate a frontend at all — pages,
     * `routes.ts`, locales, a Playwright spec, and a `modules.json`/
     * `menus.json`/`api-contract.json` entry.
     *
     * Defaults to **true** — the opposite default from isMobileAppEnabled()
     * above, and deliberately so: every module generated before this flag
     * existed already has a frontend and must keep getting one on every
     * future regenerate. This is a developer opt-OUT, not an opt-in.
     *
     * Why this exists. Every `make:module`/`make:modules-from-db` run wrote
     * a full Vue frontend unconditionally, even for tables only an API, a
     * job, or another module's endpoint ever touches (logs, heartbeats,
     * pivot/ledger tables). The only escape was a `--only=` filter on
     * `make:module`, which still writes `routes.ts` (`--only=Routes` also
     * matches the `FrontendRoutes` label), has no equivalent on
     * `make:modules-from-db` at all, and is forgotten by the very next
     * plain `--force`, which regrows the whole frontend. `features.frontend.
     * enabled: false` is a config declaration instead — checked by
     * FrontendPipeline::run() before it writes anything, and carried
     * forward by a consuming app's merge logic the same way
     * `features.mobile_app.enabled` already is, so it survives every future
     * `--force` rather than needing to be re-passed as a flag each time.
     */
    public static function isFrontendEnabled(array $config): bool
    {
        return (bool) ($config['features']['frontend']['enabled'] ?? true);
    }

    /** Names that ARE the whole secret, not just shaped like one. */
    private const SENSITIVE_EXACT_NAMES = [
        'password', 'secret', 'token', 'api_key', 'private_key', 'pin', 'otp', 'otp_code', 'salt', 'remember_token',
    ];

    /** A column ending in one of these is a secret regardless of its prefix. */
    private const SENSITIVE_SUFFIXES = [
        '_password', '_hash', '_secret', '_token', '_api_key', '_private_key', '_pin', '_otp', '_salt',
    ];

    /**
     * Whether a column name, by shape alone, looks like it holds a secret --
     * a password, a hash, a token, an API key, a PIN/OTP, a salt.
     *
     * Real cost of not having this: NJIWA's Webhooks Model has no `$hidden`
     * at all, so its signing `secret` is serialized into every list row and
     * is even offered as a sortable column. SYSTEM_SHELL's Users module
     * lists `password` as both filterable and sortable, and the list
     * filter's `begins` operator (`LIKE 'value%'`) turns `Users.list` into a
     * character-by-character prefix oracle against the password hash for
     * anyone holding that one permission.
     *
     * Deliberately narrower than "contains pin/secret/api_key anywhere":
     * `_id`/`_at` columns are excluded first (an `api_key_id` foreign key or
     * a `token_expires_at` timestamp holds no secret value), and the
     * substring checks below are anchored to whole `_`-separated segments
     * or suffixes rather than raw substrings — a raw "contains" check on
     * `pin` would flag `shipping_address`/`opinion`, on `secret` would flag
     * `secretary_id`, and on `api_key` would flag nothing extra today but is
     * exactly the same class of false positive waiting to happen.
     * `sensitive_columns.exclude` in module.json is the escape hatch for a
     * name this heuristic gets wrong the other way (e.g. `body_hash`, a
     * content fingerprint used for change detection, not a secret).
     */
    public static function isSensitiveColumnName(string $name): bool
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '_id') || str_ends_with($lower, '_at')) {
            return false;
        }

        if (in_array($lower, self::SENSITIVE_EXACT_NAMES, true)) {
            return true;
        }

        foreach (self::SENSITIVE_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return true;
            }
        }

        // Segment-exact, not suffix-only: catches a secret used as a
        // PREFIX (secret_key, secret_token) that none of the suffix checks
        // above would ever match, without widening to a raw "contains"
        // check that would also flag `secretary_id`.
        return in_array('secret', explode('_', $lower), true);
    }

    /**
     * Validate and normalize a module's `sensitive_columns` override --
     * `{"include": string[], "exclude": string[]}`, both optional. Shared by
     * isSensitive() and sensitiveColumns() so the two can never validate
     * differently.
     *
     * @return array{include: list<string>, exclude: list<string>}
     * @throws \InvalidArgumentException when the key is present but not
     *         shaped as documented.
     */
    private static function validateSensitiveColumnsOverride(array $config): array
    {
        $override = $config['sensitive_columns'] ?? [];
        if (!is_array($override)) {
            throw new \InvalidArgumentException('sensitive_columns must be an array shaped {"include": string[], "exclude": string[]}.');
        }

        $normalized = [];
        foreach (['include', 'exclude'] as $key) {
            $list = $override[$key] ?? [];
            if (!is_array($list)) {
                throw new \InvalidArgumentException("sensitive_columns.{$key} must be an array of strings.");
            }
            foreach ($list as $entry) {
                if (!is_string($entry)) {
                    throw new \InvalidArgumentException("sensitive_columns.{$key} must contain only strings.");
                }
            }
            $normalized[$key] = array_values($list);
        }

        return $normalized;
    }

    /**
     * Whether one named column should be treated as sensitive for this
     * module: on the heuristic above, or explicitly declared via
     * `sensitive_columns.include` -- unless explicitly overridden back off
     * via `sensitive_columns.exclude`, which always wins.
     */
    public static function isSensitive(array $config, string $column): bool
    {
        ['include' => $include, 'exclude' => $exclude] = self::validateSensitiveColumnsOverride($config);

        $isSensitive = in_array($column, $include, true) || self::isSensitiveColumnName($column);

        return $isSensitive && !in_array($column, $exclude, true);
    }

    /**
     * Every sensitive column name for this module: columns present in
     * `$config['columns']` for which isSensitive() holds (in column order),
     * then any `sensitive_columns.include` name that names a column NOT in
     * `$config['columns']` at all (in the order given, minus anything also
     * `exclude`d) -- covering a column introspection doesn't know about yet
     * (e.g. added by a hand-written migration ahead of a schema re-run).
     *
     * @return list<string>
     */
    public static function sensitiveColumns(array $config): array
    {
        ['include' => $include, 'exclude' => $exclude] = self::validateSensitiveColumnsOverride($config);

        $names = [];
        $existingNames = [];
        foreach ($config['columns'] ?? [] as $columnDef) {
            $name = $columnDef['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $existingNames[] = $name;
            if (self::isSensitive($config, $name)) {
                $names[] = $name;
            }
        }

        foreach ($include as $name) {
            if (!in_array($name, $existingNames, true) && !in_array($name, $exclude, true) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return array_values($names);
    }

    /** The only values sensitiveColumnStorage() (and its override) may return. */
    private const VALID_STORAGE_MODES = ['hashed', 'encrypted', 'plain'];

    /** One-way: the plaintext is never needed again, only verified against. */
    private const HASHED_COLUMN_NAMES = ['password', 'pin', 'otp', 'otp_code', 'salt'];
    private const HASHED_COLUMN_SUFFIXES = ['_password', '_pin', '_otp', '_salt'];

    /** Reversible: the plaintext must be readable again by the application itself. */
    private const ENCRYPTED_COLUMN_NAMES = ['secret', 'token', 'api_key', 'private_key'];
    private const ENCRYPTED_COLUMN_SUFFIXES = ['_secret', '_token', '_api_key', '_private_key'];

    /**
     * How a sensitive column's VALUE must be stored: `hashed` (one-way,
     * `Hash::make()`/`Hash::check()`), `encrypted` (reversible, transparent
     * on model attribute access), or `plain` (still hidden from
     * serialization per isSensitive(), but written exactly as submitted).
     * Returns `plain` for a column that isn't sensitive at all.
     *
     * The three-way split is load-bearing, not a style choice:
     *
     * - NJIWA's Webhooks `secret` is read back in the CLEAR by
     *   `WebhookSigningService::sign()` to compute an HMAC signature over an
     *   outgoing payload — a one-way `hashed` cast would make signing
     *   permanently impossible. It needs `encrypted`, which Eloquent
     *   decrypts transparently on attribute access, so the signing code
     *   needs no change at all.
     * - NJIWA's `ApiKeys.key_hash`/`Devices.token_hash` already hold a hash
     *   the APPLICATION computed before ever assigning it to the model.
     *   Casting either `hashed` would hash the hash, and the app's own
     *   comparison code (which hashes the incoming value and compares
     *   strings) would never match again. Any column whose name identifies
     *   it as already-a-hash (the generic `*_hash` shape, sensitive by
     *   isSensitiveColumnName() but not in either name/suffix list below)
     *   defaults to `plain` for exactly this reason.
     * - `remember_token` is sensitive by name (isSensitiveColumnName()'s
     *   exact list) but Laravel's own `EloquentUserProvider::
     *   retrieveByToken()` compares it with `hash_equals()` directly
     *   against the raw cookie value, never `Hash::check()`. Casting it
     *   `hashed` would break "remember me" for every login the moment this
     *   lands. Forced to `plain` unconditionally by the heuristic —
     *   overridable only by an explicit per-column `sensitive_columns.
     *   storage` entry, for a project that has verified it truly wants
     *   something else.
     *
     * @throws \InvalidArgumentException when `sensitive_columns.storage.
     *         {$column}` is present but not one of hashed/encrypted/plain.
     */
    public static function sensitiveColumnStorage(array $config, string $column): string
    {
        $override = $config['sensitive_columns']['storage'][$column] ?? null;
        if ($override !== null) {
            if (!is_string($override) || !in_array($override, self::VALID_STORAGE_MODES, true)) {
                throw new \InvalidArgumentException(
                    "sensitive_columns.storage.{$column} must be one of: hashed, encrypted, plain."
                );
            }

            return $override;
        }

        $lower = strtolower($column);

        if ($lower === 'remember_token') {
            return 'plain';
        }

        if (in_array($lower, self::HASHED_COLUMN_NAMES, true)) {
            return 'hashed';
        }
        foreach (self::HASHED_COLUMN_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return 'hashed';
            }
        }

        if (in_array($lower, self::ENCRYPTED_COLUMN_NAMES, true)) {
            return 'encrypted';
        }
        foreach (self::ENCRYPTED_COLUMN_SUFFIXES as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return 'encrypted';
            }
        }
        if (in_array('secret', explode('_', $lower), true)) {
            return 'encrypted';
        }

        return 'plain';
    }

    /**
     * A `json` column was validated only as `array` -- any nested content
     * saved as-is, with no declarative way to say what it must contain.
     * NJIWA's Policies.params (pacing caps, send windows, warm-up, balance
     * check) needed hand-added nested rules in its generated Create/Edit
     * services, which the next `--force` regenerate overwrote every time.
     *
     * `json_rules` records that shape durably: a per-column map of relative
     * dot-paths to Laravel validation rules, plus a `sample` value the
     * generated PHPUnit tests submit. Laravel's own validator
     * (`excludeUnvalidatedArrayKeys`) drops any nested key NOT declared here
     * from `validated()` on save -- so `sample` must be the complete
     * accepted shape, not just enough to pass validation, or a real payload
     * with extra keys would silently lose them.
     *
     * Every malformed declaration throws here, at generation time -- a
     * config author gets a clear error instead of a working-but-wrong rule
     * emission.
     *
     * @return array<string, array{rules: array<string, list<string>>, sample: array}>
     */
    public static function jsonRules(array $config): array
    {
        $raw = $config['json_rules'] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('json_rules must be an object keyed by column name');
        }

        $jsonColumns = [];
        foreach ($config['columns'] ?? [] as $column) {
            if (($column['type'] ?? null) === 'json' && !empty($column['name'])) {
                $jsonColumns[$column['name']] = true;
            }
        }

        $result = [];
        foreach ($raw as $columnName => $entry) {
            if (!isset($jsonColumns[$columnName])) {
                throw new \InvalidArgumentException("json_rules.{$columnName}: no column named \"{$columnName}\" with type \"json\" in columns[]");
            }

            if (!is_array($entry) || array_is_list($entry)) {
                throw new \InvalidArgumentException("json_rules.{$columnName}: must be an object with keys \"rules\" and \"sample\"");
            }

            $unknownKeys = array_diff(array_keys($entry), ['rules', 'sample']);
            if (!empty($unknownKeys)) {
                $unknown = reset($unknownKeys);
                throw new \InvalidArgumentException("json_rules.{$columnName}: unknown key \"{$unknown}\" (only \"rules\" and \"sample\" are allowed)");
            }

            $rawRules = $entry['rules'] ?? null;
            if (!is_array($rawRules) || empty($rawRules) || array_is_list($rawRules)) {
                throw new \InvalidArgumentException("json_rules.{$columnName}.rules: must be a non-empty object mapping a relative path to a rule string or a list of rule strings");
            }

            $parsedRules = [];
            foreach ($rawRules as $path => $ruleValue) {
                if (!is_string($path) || $path === '' || !preg_match('/^(?:\*|[A-Za-z0-9_-]+)(?:\.(?:\*|[A-Za-z0-9_-]+))*$/', $path)) {
                    throw new \InvalidArgumentException("json_rules.{$columnName}.rules: path \"{$path}\" is not a valid dot-path");
                }

                $firstSegment = explode('.', $path)[0];
                if ($firstSegment === $columnName) {
                    $suggested = substr($path, strlen($columnName) + 1);
                    throw new \InvalidArgumentException("json_rules.{$columnName}.rules: path \"{$path}\" must be relative to the column — write \"{$suggested}\", not \"{$path}\"");
                }

                if (is_string($ruleValue)) {
                    $list = array_values(array_filter(array_map('trim', explode('|', $ruleValue)), static fn ($r) => $r !== ''));
                } elseif (
                    is_array($ruleValue)
                    && array_is_list($ruleValue)
                    && !empty($ruleValue)
                    && array_reduce($ruleValue, static fn ($carry, $r) => $carry && is_string($r) && $r !== '', true)
                ) {
                    $list = $ruleValue;
                } else {
                    throw new \InvalidArgumentException("json_rules.{$columnName}.rules.{$path}: must be a non-empty pipe-delimited rule string or a list of non-empty rule strings");
                }

                $parsedRules[$path] = $list;
            }

            if (!array_key_exists('sample', $entry)) {
                throw new \InvalidArgumentException("json_rules.{$columnName}.sample: \"sample\" is required — an example value these rules accept; the generated PHPUnit tests submit it");
            }
            $sample = $entry['sample'];
            if (!is_array($sample)) {
                throw new \InvalidArgumentException("json_rules.{$columnName}.sample: must be an array");
            }

            $result[$columnName] = ['rules' => $parsedRules, 'sample' => $sample];
        }

        return $result;
    }
}
