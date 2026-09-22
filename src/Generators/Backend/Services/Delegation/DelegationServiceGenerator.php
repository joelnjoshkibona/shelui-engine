<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

class DelegationServiceGenerator extends BaseServiceGenerator
{
    protected array $delegation;
    protected string $delegationKey;
    protected string $relatedModuleName;

    /**
     * The related module's declared sub-group, as supplied by the caller
     * (e.g. parsed from the blueprint) -- null when the caller didn't
     * declare one. Kept distinct from the literal string 'Core' so
     * resolveRelatedModuleGroupPath() can tell "no sub-group hint at all"
     * apart from "caller explicitly said this lives flat under Core".
     */
    protected ?string $relatedModuleGroup;

    public function __construct(
        string $moduleName,
        string $moduleGroup = 'Core',
        array $config = [],
        string $delegationKey = '',
        array $delegation = []
    ) {
        parent::__construct($moduleName, $moduleGroup, $config);

        $this->delegationKey = $delegationKey;
        $this->delegation = $delegation;

        $relatedModule = $delegation['relatedModule'] ?? null;
        if ($relatedModule && is_array($relatedModule)) {
            $this->relatedModuleName = $relatedModule['name'] ?? '';
            $this->relatedModuleGroup = $relatedModule['group'] ?? null;
        } else {
            $this->relatedModuleName = is_string($relatedModule) ? $relatedModule : '';
            $this->relatedModuleGroup = null;
        }
    }

    /**
     * Resolve "Group\SubGroup" (e.g. "System\Custom") for the related
     * module's `use` import.
     *
     * Resolution order:
     *   1. Self-delegation (e.g. Accounts.children → Accounts): the caller
     *      only appends a module to PathManager's array registry *after* it
     *      finishes generating, so a module can't find *itself* there yet
     *      while it's still being generated. This module's own group/
     *      sub-group is already known directly -- use it, no lookup needed.
     *   2. Registry / default_modules.json, via PathManager -- authoritative
     *      whenever the related module is already known.
     *   3. The sub-group the caller declared for the related module
     *      ($this->relatedModuleGroup), combined with this module's own
     *      top-level group. This is the common case for delegations:
     *      unlike auto-derived FK relations (topologically sorted so the
     *      target always precedes the module referencing it), a delegation
     *      routinely points from a parent module (FK target, scaffolded
     *      first) to a child module (FK source, scaffolded later) that
     *      simply isn't in the registry yet. The caller-declared sub-group
     *      predates and doesn't depend on generation order -- it comes
     *      straight from the blueprint -- and every delegation observed in
     *      practice targets a sibling scaffolded into the same top-level
     *      group as the module declaring it.
     *   4. None of the above resolves: a delegation is an explicit, human/
     *      blueprint-authored declaration, not a guess -- fail loudly rather
     *      than silently emit a `use App\Project\Modules\Core\{Module}` that
     *      references a class that doesn't exist there. Mirrors
     *      ModelGenerator::assertManualRelationModuleResolves().
     */
    protected function resolveRelatedModuleGroupPath(): string
    {
        if (empty($this->relatedModuleName)) {
            return $this->relatedModuleGroup ?? '';
        }

        if ($this->relatedModuleName === $this->moduleName) {
            return $this->moduleSubGroup
                ? "{$this->moduleGroup}\\{$this->moduleSubGroup}"
                : $this->moduleGroup;
        }

        $fullNs = PathManager::resolveBackendModuleNamespaceOrNull($this->relatedModuleName);
        if ($fullNs !== null) {
            return str_replace(
                ['App\\Project\\Modules\\', "\\{$this->relatedModuleName}"],
                '',
                $fullNs
            );
        }

        if (!empty($this->relatedModuleGroup)) {
            return $this->moduleGroup . '\\' . Str::studly($this->relatedModuleGroup);
        }

        throw new \RuntimeException(
            "Cannot resolve related module '{$this->relatedModuleName}' declared in delegation " .
            "'{$this->delegationKey}' on module '{$this->moduleName}': no matching module found in " .
            "the registry or shell defaults, and no sub-group was declared for it either. Check for " .
            "a typo in the related module name, or generate that module first."
        );
    }

    public function generate(): bool
    {
        $content = $this->getTemplateContent('Features/delegation/service', 'backend');

        $delegationName = Str::studly($this->delegation['name'] ?? $this->delegationKey);
        // shelui-engine fork: must resolve identically to RoutesGenerator::
        // generateDelegationRoutes()/ControllerGenerator::generateDelegationMethods()'s
        // own parentKey default -- this service's method signatures are
        // called with whatever value the controller extracted from the URL
        // segment those two build, so all three must agree on the same name.
        $parentKey = $this->delegation['parentKey'] ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');
        $filterKey = $this->delegation['filterKey'] ?? 'parent_id';
        $parentIdField = $this->delegation['parentIdField'] ?? 'id';

        // Resolve the full namespace path for the related module (e.g. "System\Custom")
        $relatedModuleGroupPath = $this->resolveRelatedModuleGroupPath();

        $replacements = [
            '[[DelegationName]]' => $delegationName,
            '[[RelatedModuleName]]' => $this->relatedModuleName,
            '[[RelatedModuleGroup]]' => $relatedModuleGroupPath,
            '[[RelatedModuleServiceImports]]' => $this->buildRelatedModuleServiceImports($relatedModuleGroupPath),
            '[[listMethod]]' => $this->buildListMethod($parentKey, $filterKey, $parentIdField),
            '[[createMethod]]' => $this->buildCreateMethod($parentKey, $filterKey, $parentIdField),
            '[[editMethod]]' => $this->buildEditMethod($parentKey, $filterKey, $parentIdField),
            '[[viewMethod]]' => $this->buildViewMethod($parentKey, $filterKey, $parentIdField),
            '[[deleteMethod]]' => $this->buildDeleteMethod($parentKey, $filterKey, $parentIdField),
            '[[deleteCheckMethod]]' => $this->buildDeleteCheckMethod($parentKey, $filterKey, $parentIdField),
            '[[bulkActionMethod]]' => $this->buildBulkActionMethod($parentKey, $filterKey, $parentIdField),
            '[[importTemplateMethod]]' => $this->buildImportTemplateMethod(),
            '[[importMethod]]' => $this->buildImportMethod($parentKey, $filterKey, $parentIdField),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        // Dedupe `use` statements — when a delegation self-references its owning module
        // (e.g. Accounts.children → Accounts, PayrollDepartments.children → PayrollDepartments)
        // the stub's two imports (module + related module) collapse to the same FQN,
        // producing a PHP "Duplicate use" warning. Collapse back-to-back identical imports.
        $content = $this->dedupeUseStatements($content);

        $serviceName = $this->moduleName . $delegationName . 'Service';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Collapse duplicate `use Foo\Bar\Baz;` lines in the generated source.
     * Preserves order and first-occurrence only.
     */
    protected function dedupeUseStatements(string $content): string
    {
        $lines = explode("\n", $content);
        $seenUses = [];
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^use\s+[^;]+;\s*$/', trim($line))) {
                $normalized = trim($line);
                if (isset($seenUses[$normalized])) {
                    continue; // skip duplicate
                }
                $seenUses[$normalized] = true;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    /**
     * The extra `->where(...)` fragment every query-building method below
     * appends after the ordinary `filterKey` scope. Only non-empty for a
     * morph-target reverse delegation (`delegation['morphFilter']` --
     * `{column, value}`, e.g. `{"column": "payable_type", "value": "vendor"}`)
     * where a single FK column isn't enough to scope the query: filtering by
     * `payable_id` alone would also match a PurchaseOrder or Expense whose id
     * happens to collide numerically with this Vendor's id, since `payable_id`
     * is shared across every polymorphic owner type. Ordinary delegations
     * leave `morphFilter` unset and get an empty string here -- byte-identical
     * output to before this existed.
     */
    private function buildMorphFilterClause(): string
    {
        $morphFilter = $this->delegation['morphFilter'] ?? null;
        if (empty($morphFilter['column']) || !isset($morphFilter['value'])) {
            return '';
        }

        return "->where('{$morphFilter['column']}', '{$morphFilter['value']}')";
    }

    /**
     * `use` imports for the related module's own native services -- one per
     * enabled operation, plus DeleteCheckService riding along with `delete`
     * (mirrors native modules' own delete/deleteCheck pairing convention).
     * This is the whole point of the redesign: the delegation class calls
     * into these instead of reimplementing their logic.
     */
    private function buildRelatedModuleServiceImports(string $relatedGroupPath): string
    {
        $base = "App\\Project\\Modules\\{$relatedGroupPath}\\{$this->relatedModuleName}\\Services\\{$this->relatedModuleName}";
        $ops = $this->delegation['operations'] ?? [];
        $lines = [];

        foreach (['list' => 'ListService', 'create' => 'CreateService', 'edit' => 'EditService', 'view' => 'ViewService'] as $op => $suffix) {
            if (!empty($ops[$op]['enabled'])) {
                $lines[] = "use {$base}{$suffix};";
            }
        }
        if (!empty($ops['delete']['enabled'])) {
            $lines[] = "use {$base}DeleteService;";
            $lines[] = "use {$base}DeleteCheckService;";
        }

        return implode("\n", $lines);
    }

    /**
     * Record scope seam for a delegation's parent fetch (plan 039). Every
     * method below resolves its parent by `{$parentKey}` before touching the
     * related module at all -- an unscoped `firstOrFail()` there let a user
     * without access to that specific parent still get full delegated
     * list/create/edit/delete/view under it, since the child-side scoping
     * never runs. Same method_exists()-guarded call as every native
     * view/edit/delete/deleteCheck fetch (plan 031), applied to the PARENT
     * module, not the related one -- a foreign parent now 404s here before
     * any child row is ever looked at.
     */
    private function buildScopedParentFetch(string $parentKey): string
    {
        return "        \$parentQuery = {$this->moduleName}Model::query();\n"
            . "        if (method_exists({$this->moduleName}Model::class, 'applyRecordScope')) {\n"
            . "            \$parentQuery = {$this->moduleName}Model::applyRecordScope(\$parentQuery);\n"
            . "        }\n"
            . "        \$parent = \$parentQuery->where('{$parentKey}', \${$parentKey})->firstOrFail();";
    }

    /**
     * Export-aware: reads export/format off its own $params rather than a
     * separate proxy method, avoiding a second copy of the parent-
     * resolution/query-build snippet. The controller side still has a
     * distinct export{Delegation}() route/method (see
     * ControllerGenerator::generateDelegationMethods()), matching the
     * native controller's own separate export{Module}() — it just calls
     * back into this same list() with export=true baked into $data.
     */
    private function buildListMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['list']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function list(string \${$parentKey}, array \$params = []): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};
        \$export = filter_var(\$params['export'] ?? false, FILTER_VALIDATE_BOOLEAN);
        \$format = \$params['format'] ?? 'csv';

        return {$this->relatedModuleName}ListService::execute(\$params, \$export, \$format, \$query);
    }
PHP;
    }

    /**
     * Generation-time gate only, not a re-declared allow-list — the
     * dispatched action key is still validated against the RELATED
     * module's own $bulkActions allow-list (its own native
     * {Related}ListService::$bulkActions, generated from that module's own
     * features.backend.list.bulk_actions). This delegation never generates
     * its own per-key action services; it stays a true thin proxy.
     */
    private function buildBulkActionMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['list']['enabled'])
            || empty($this->delegation['operations']['list']['backend']['bulk_actions'])) {
            return '';
        }

        return <<<PHP

    public static function bulkAction(string \${$parentKey}, array \$data): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};

        return {$this->relatedModuleName}ListService::execute_bulkAction(\$data, \$query);
    }
PHP;
    }

    /**
     * Static column headers only — parent scoping is meaningless for a
     * template, so this deliberately does not resolve/validate the parent.
     * Stays on the delegation service anyway (rather than the controller
     * calling the related module's ListService directly) purely for
     * consistency: every other delegation capability dispatches through
     * `new {Module}{Delegation}Service()`.
     */
    private function buildImportTemplateMethod(): string
    {
        if (empty($this->delegation['operations']['list']['enabled'])
            || empty($this->delegation['operations']['list']['backend']['import'])) {
            return '';
        }

        return <<<PHP

    public static function importTemplate(string \$format = 'csv'): mixed
    {
        return {$this->relatedModuleName}ListService::getImportTemplate(\$format);
    }
PHP;
    }

    /**
     * Forces the parent FK onto every imported row via $forced, threaded
     * through to the related module's own processImportRow() as its own
     * third parameter (never pre-merged into a row here) — mirrors
     * create()/edit()'s anti-tampering pattern and the same reason:
     * validator()->validate() would silently strip the forced column from
     * a pre-merge unless the module's own import rules happen to declare
     * it.
     */
    private function buildImportMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['list']['enabled'])
            || empty($this->delegation['operations']['list']['backend']['import'])) {
            return '';
        }

        return <<<PHP

    public static function import(string \${$parentKey}, array \$data, ?\\Illuminate\\Http\\UploadedFile \$file): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$forced = ['{$filterKey}' => \$parent->{$parentIdField}];

        return {$this->relatedModuleName}ListService::execute_import(\$data, \$file, \$forced);
    }
PHP;
    }

    private function buildCreateMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['create']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function create(string \${$parentKey}, array \$data): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$forced = ['{$filterKey}' => \$parent->{$parentIdField}];

        return {$this->relatedModuleName}CreateService::execute(array_merge(\$data, \$forced), \$forced);
    }
PHP;
    }

    private function buildEditMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['edit']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function edit(string \${$parentKey}, string \$itemUuid, array \$data): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$forced = ['{$filterKey}' => \$parent->{$parentIdField}];
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};

        return {$this->relatedModuleName}EditService::execute(array_merge(\$data, \$forced), ['uuid' => \$itemUuid], \$query);
    }
PHP;
    }

    private function buildViewMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['view']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function view(string \${$parentKey}, string \$itemUuid): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};

        return {$this->relatedModuleName}ViewService::execute(['uuid' => \$itemUuid], \$query);
    }
PHP;
    }

    private function buildDeleteMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['delete']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function delete(string \${$parentKey}, string \$itemUuid): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};

        return {$this->relatedModuleName}DeleteService::execute([], ['uuid' => \$itemUuid], \$query);
    }
PHP;
    }

    /**
     * New capability -- delegation tabs previously had no cascade/relationship
     * check equivalent to native DeleteCheckService at all. Piggybacks on
     * `delete` being enabled, same as native deleteCheck piggybacks on delete
     * in ControllerGenerator/RoutesGenerator.
     */
    private function buildDeleteCheckMethod(string $parentKey, string $filterKey, string $parentIdField): string
    {
        if (empty($this->delegation['operations']['delete']['enabled'])) {
            return '';
        }

        return <<<PHP

    public static function deleteCheck(string \${$parentKey}, string \$itemUuid): array
    {
{$this->buildScopedParentFetch($parentKey)}
        \$query = {$this->relatedModuleName}Model::query()->where('{$filterKey}', \$parent->{$parentIdField}){$this->buildMorphFilterClause()};

        return {$this->relatedModuleName}DeleteCheckService::execute(['uuid' => \$itemUuid], \$query);
    }
PHP;
    }
}
