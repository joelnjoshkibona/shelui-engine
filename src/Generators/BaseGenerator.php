<?php

namespace Blutrixx\GeneratorEngine\Generators;

use Blutrixx\GeneratorEngine\Schema\ColumnDefault;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

abstract class BaseGenerator
{
    protected string $moduleName;
    protected string $moduleGroup;
    protected ?string $moduleSubGroup;
    protected string $modulePath;
    protected array $config;
    protected bool $force = false;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        $this->moduleName = $moduleName;
        $this->moduleGroup = PathManager::normalizeGroupName($moduleGroup);
        $this->moduleSubGroup = PathManager::getModuleSubGroup();
        $this->modulePath = $this->getModulePath();
        // Column defaults are normalised here, once, for every generator: a module.json written by an older
        // engine still holds MariaDB's raw 'NULL' / quoted forms (see ColumnDefault).
        $this->config = ColumnDefault::normalizeConfig($config);

        // Ensure output directories exist
        PathManager::ensureOutputDirectories();
    }

    protected function getModulePath(): string
    {
        return PathManager::getBackendModulePath($this->moduleGroup, $this->moduleName);
    }

    protected function getStubPath(string $stubName, string $type = 'backend'): string
    {
        $subdir = $type === 'backend' ? 'backend' : ($type === 'mobile_app' ? 'mobile_app' : 'frontend');
        if (function_exists('base_path')) {
            $override = (string) PathManager::fromLaravel(
                static fn () => base_path("stubs/generator/{$subdir}/{$stubName}.stub"),
                ''
            );
            if (is_file($override)) {
                return $override;
            }
        }

        if ($type === 'backend') {
            return PathManager::getBackendTemplatePath() . "/{$stubName}.stub";
        } elseif ($type === 'mobile_app') {
            return PathManager::getMobileAppTemplatePath() . "/{$stubName}.stub";
        } else {
            return PathManager::getFrontendTemplatePath() . "/{$stubName}.stub";
        }
    }

    protected function getTemplateContent(string $stubName, string $type = 'backend'): string
    {
        $stubPath = $this->getStubPath($stubName, $type);
        
        if (!file_exists($stubPath)) {
            throw new \Exception("Template file not found: {$stubPath}");
        }
        
        return file_get_contents($stubPath);
    }

    protected function replacePlaceholders(string $content, array $replacements = []): string
    {
        $defaultReplacements = [
            '[[ModuleName]]' => $this->moduleName,
            '[[moduleName]]' => strtolower($this->moduleName),
            // Permission key convention MUST match what the backend seeder actually creates.
            // seeder.stub's permissions() calls `Helpers::saveModuleCRUDPermissions([[ModuleName]])`
            // unconditionally for the base list/view/create/edit/delete/bulkAction set, PLUS
            // loops SeederGenerator::mergeListPermissions()'s output (SeederData.json's
            // 'permissions' array) for genuine extras (import, custom actions, deleteCheck) —
            // both register perms as `{ModuleName}.{action}` (PascalCase). (v2.46.0 removed the
            // Helper call as redundant with — and less correct than — mergeListPermissions()'s
            // own feature-gated CRUD derivation, which only emits a permission for a feature
            // that's actually enabled; v2.53.0 reinstated it as the deliberate, permanent design
            // after every already-generated module in the primary consuming project had quietly
            // drifted back to calling both anyway — accepting the Helper's feature-blind
            // over-provisioning as a known tradeoff needing a manual per-module cleanup pass,
            // rather than re-fixing 17+ independent regressions of the same shape. See
            // SeederGeneratorNoRedundantCrudCallTest's docblock for the full history.)
            // The old behaviour of falling back to
            // $config['permission_base_name'] (typically lowercase snake_case like
            // "items") caused the frontend checks to look up `items.create` while the DB
            // has `Items.create` — users with the right role saw "Access Denied" on every
            // form. Always use PascalCase moduleName.
            '[[PermissionBaseName]]' => $this->moduleName,
            '[[moduleRoute]]' => Str::kebab($this->moduleName),
            '[[moduleNamePlural]]' => strtolower(Str::plural($this->moduleName)),
            '[[ModuleNamePlural]]' => Str::plural($this->moduleName),
            '[[moduleVarName]]' => strtolower($this->moduleName), // For variable names like 'users', 'roles', etc.
            '[[ModuleGroup]]' => $this->moduleGroup,
            '[[moduleGroup]]' => $this->moduleGroup,
            '[[tableName]]' => $this->getTableName(),
            '[[namespace]]' => $this->getNamespace(),
            '[[ModuleNamespace]]' => $this->getNamespace(),
            '[[timestamp]]' => date('Y_m_d_His'),
            // shelui-engine fork: record identifiers were hardcoded to `uuid` throughout every
            // view/edit/delete/deleteCheck route, controller method, and service, regardless of
            // `has_uuid` -- a module without a uuid column (has_uuid: false) had no working way to
            // route to its own records at all. These three resolve together from the single
            // ModuleConfigContract::hasUuid() source of truth, matching every other has_uuid-gated
            // decision in this codebase (MigrationGenerator, ModelGenerator).
            '[[routeKeyParam]]' => ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id',
            '[[routeKeyLabel]]' => ModuleConfigContract::hasUuid($this->config) ? 'Uuid' : 'Id',
            '[[routeKeyRule]]'  => ModuleConfigContract::hasUuid($this->config) ? 'string' : 'integer',
        ];

        $replacements = array_merge($defaultReplacements, $replacements);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Convert a raw PascalCase/StudlyCase module (or feature) name into spaced
     * Title Case for human-facing text — menu labels, page/route titles, locale
     * strings, permission titles. E.g. "ItemCategories" -> "Item Categories",
     * "ZzzGeneratorVerifyTest" -> "Zzz Generator Verify Test".
     *
     * Grammatical number (singular/plural) is NOT touched here — callers pass
     * in whatever form (raw moduleName, or Str::singular($moduleName)) matches
     * the convention for that specific string.
     */
    protected function humanize(string $name): string
    {
        return Str::headline($name);
    }

    protected function getTableName(): string
    {
        // Use table_name from config if available
        if (isset($this->config['table_name']) && !empty($this->config['table_name'])) {
            return $this->config['table_name'];
        }
        
        // Fallback: Convert CamelCase to snake_case
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $this->moduleName));
    }

    protected function getNamespace(): string
    {
        $ns = "App\\Project\\Modules\\{$this->moduleGroup}";
        if ($this->moduleSubGroup) {
            $ns .= "\\{$this->moduleSubGroup}";
        }
        return $ns . "\\{$this->moduleName}";
    }

    protected function ensureDirectoryExists(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Derive the Eloquent relation method name a `foreignId` column's
     * belongsTo() gets (see ModelGenerator's own belongsTo-generation loop,
     * the source of truth this mirrors) -- e.g. "payment_method_id" ->
     * "paymentMethod", "status_id" -> "status". Shared here (not just on
     * ModelGenerator) so Frontend/MobileApp generators can reference the
     * exact same relation name the Model actually exposes, instead of a
     * second, possibly-drifting guess.
     */
    protected function deriveRelationshipMethodName(string $columnName): string
    {
        if (str_ends_with($columnName, '_id')) {
            $base = substr($columnName, 0, -3);
            return lcfirst(Str::camel($base));
        }

        return lcfirst(Str::camel($columnName));
    }

    /** Base for an action's artifacts: `serviceName` when set, else the action's own StudlyCase
     *  name, module prefix and trailing `Service` suffix stripped. Shared so RoutesGenerator,
     *  ControllerGenerator and ActionSplashServiceGenerator resolve one string, not three guesses. */
    protected function resolveActionServiceNameRaw(string $actionKey, array $action): string
    {
        $actionName = Str::studly($action['name'] ?? $actionKey);
        $serviceNameRaw = !empty($action['serviceName']) ? $action['serviceName'] : $actionName;

        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }

        return $serviceNameRaw;
    }

    /** Base an action's non-splash controller method / route handler uses: `methodName` when set,
     *  else resolveActionServiceNameRaw(). `splash: true`'s `{baseMethod}Splash` route handler and
     *  controller method MUST use this same base (see plans/038: they didn't, and diverged). */
    protected function resolveActionBaseMethod(string $actionKey, array $action): string
    {
        $serviceNameRaw = $this->resolveActionServiceNameRaw($actionKey, $action);

        return !empty($action['methodName']) ? $action['methodName'] : $serviceNameRaw;
    }

    public function setForce(bool $force): self
    {
        $this->force = $force;
        return $this;
    }

    /** @var array<string, true> One "frontend is opted out" notice per project root + module, per process. */
    private static array $frontendOptOutNoted = [];

    /**
     * Whether $path lies inside the frontend tree of a module that has opted out of the frontend
     * (`features.frontend.enabled: false` -- see ModuleConfigContract::isFrontendEnabled()).
     *
     * Such a module's pages, locales, specs and registry entries are written by hand, and the generator writes
     * the same file names (`UsersListPage.vue`, `locales/en.json`, `users-list.e2e.js`, ...). FrontendPipeline
     * already refuses to run for it, but that is one door: a command that builds a single generator itself
     * (make:action, make:delegation) never passes through it, and neither does any command written later. So
     * the rule is enforced where every write ends up, here, and holds for all of them: whatever asks, nothing
     * inside FRONTEND/ is created, replaced or deleted for an opted-out module.
     *
     * A module without a `features` block (or without the key) is frontend-enabled, as ever; and when no project
     * root is set there is no frontend tree to protect.
     */
    protected function isBlockedFrontendPath(string $path): bool
    {
        if (ModuleConfigContract::isFrontendEnabled($this->config)) {
            return false;
        }

        $root = PathManager::getProjectRoot();
        if ($root === null) {
            return false;
        }

        $frontend = self::lexicalPath(PathManager::getFrontendBasePath()) . '/';

        return str_starts_with(self::lexicalPath($path) . '/', $frontend);
    }

    /** Collapse `//`, `/./` and `/../` without touching the filesystem (the target usually doesn't exist yet). */
    private static function lexicalPath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $parts);
    }

    /** Say once per module (not once per file -- a module writes dozens) that its frontend was left alone. */
    protected function noteBlockedFrontendWrite(string $path): void
    {
        $key = (PathManager::getProjectRoot() ?? '') . '|' . $this->moduleGroup . '/' . $this->moduleName;
        if (isset(self::$frontendOptOutNoted[$key])) {
            return;
        }
        self::$frontendOptOutNoted[$key] = true;

        PathManager::reportIssue(
            "Skipped every frontend write for {$this->moduleName}: features.frontend.enabled is false, so its frontend is "
            . "hand-written and no command changes it (first one skipped: " . basename($path) . "). "
            . "Only the backend was generated. To have the frontend generated again: make:module <path> --layers=backend,frontend.",
            'warning'
        );
    }

    /**
     * file_put_contents() for a generator that cannot use writeFile()'s skip-if-exists rules. Still refuses a
     * frontend path of an opted-out module. Returns false when it wrote nothing.
     */
    protected function putFile(string $path, string $content): bool
    {
        if ($this->isBlockedFrontendPath($path)) {
            $this->noteBlockedFrontendWrite($path);

            return false;
        }

        return file_put_contents($path, $content) !== false;
    }

    /** unlink() that refuses a frontend path of an opted-out module. Returns false when it removed nothing. */
    protected function removeFile(string $path): bool
    {
        if ($this->isBlockedFrontendPath($path)) {
            $this->noteBlockedFrontendWrite($path);

            return false;
        }

        return unlink($path);
    }

    protected function writeFile(string $path, string $content): bool
    {
        if ($this->isBlockedFrontendPath($path)) {
            $this->noteBlockedFrontendWrite($path);

            return false;
        }

        // Skip existing files unless force-overwrite is enabled
        if (!$this->force && file_exists($path)) {
            return false;
        }
        $this->ensureDirectoryExists($path);
        return file_put_contents($path, $content) !== false;
    }

    /**
     * Write a file only if it doesn't already exist yet — full stop, no
     * `$this->force` escape hatch.
     *
     * Bug (found + fixed 2026-08-02): writeInlineItemsWrapperComponent()
     * called plain writeFile() for the InlineItems wrapper component, whose
     * entire purpose is to be hand-edited once and survive every future
     * regeneration ("a developer hand-fills exactly one file that
     * regeneration never touches again" — see that method's own docblock).
     * But writeFile()'s skip-if-exists is gated on `!$this->force`, so a
     * plain re-run correctly skipped it while any `--force` run (exactly
     * the case that matters — e.g. a developer force-regenerating Orders
     * for an unrelated schema change) silently clobbered the hand-edited
     * file back to its freshly-generated template state. Confirmed via a
     * live `make:module Custom/Orders --force` run against a real
     * SYSTEM_SHELL scratch module: a hand-added `dynamicDisabled` hook in
     * the wrapper component was destroyed with no warning. Use this method
     * instead of writeFile() for any output that is meant to be generated
     * once and never regenerated, by design, regardless of --force.
     */
    protected function writeFileOnce(string $path, string $content): bool
    {
        if ($this->isBlockedFrontendPath($path)) {
            $this->noteBlockedFrontendWrite($path);

            return false;
        }

        if (file_exists($path)) {
            return false;
        }
        $this->ensureDirectoryExists($path);
        return file_put_contents($path, $content) !== false;
    }

    /**
     * Write a file unconditionally, bypassing writeFile()'s force-check.
     *
     * Use this for shared "registry" style outputs (module registries,
     * modules.json, menus.json, ...) that must be kept in sync on every
     * generation run, not just forced ones. Callers such as
     * ModuleScaffolder's generic runner construct the generator, then
     * unconditionally call setForce($force) using the CLI's --force
     * option — that silently clobbers any force=true a constructor set,
     * so writeFile() would skip the write whenever the shared file
     * already exists (the normal case for a plain, non --force run).
     * Mirrors the approach MobileRegistryGenerator has always used.
     */
    protected function writeFileAlways(string $path, string $content): bool
    {
        if ($this->isBlockedFrontendPath($path)) {
            $this->noteBlockedFrontendWrite($path);

            return false;
        }

        $this->ensureDirectoryExists($path);
        return file_put_contents($path, $content) !== false;
    }

    abstract public function generate(): bool;

    /**
     * Encode JSON for a shared config file, preserving the file's existing
     * indentation width.
     *
     * PHP's JSON_PRETTY_PRINT always emits 4 spaces per level. `menus.json` is
     * stored with 2, so every write reindented the entire file — one scaffolded
     * module produced a 439-line diff that was almost entirely whitespace. That
     * makes review impractical and turns any shared config file into a
     * guaranteed merge conflict as soon as two people scaffold.
     *
     * Detects the indent unit from the file's first indented line and rewrites
     * the encoded output to match. Falls back to PHP's native 4 when the file
     * does not exist or its indentation cannot be determined.
     */
    protected function encodeJsonPreservingIndent(string $path, array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $unit = $this->detectJsonIndentUnit($path);
        if ($unit === null || $unit === 4) {
            return $encoded;
        }

        // JSON_PRETTY_PRINT emits exactly 4 spaces per depth level; rescale.
        return preg_replace_callback(
            '/^( +)/m',
            static fn (array $m) => str_repeat(' ', (int) (strlen($m[1]) / 4) * $unit),
            $encoded
        );
    }

    /**
     * Number of spaces per indent level in an existing JSON file, or null.
     */
    protected function detectJsonIndentUnit(string $path): ?int
    {
        if (!file_exists($path)) {
            return null;
        }

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^( +)\S/', $line, $m)) {
                return strlen($m[1]);
            }
        }

        return null;
    }
}
