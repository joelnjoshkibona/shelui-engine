<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BaseServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

class ActionServiceGenerator extends BaseServiceGenerator
{
    protected array $action;
    protected string $actionKey;

    public function __construct(
        string $moduleName,
        string $moduleGroup = 'Core',
        array $config = [],
        string $actionKey = '',
        array $action = []
    ) {
        parent::__construct($moduleName, $moduleGroup, $config);

        $this->actionKey = $actionKey;
        $this->action = $action;
    }

    public function generate(): bool
    {
        // Resolved BEFORE anything is loaded or written -- an invalid
        // serviceMethod/serviceArgs must fail loudly here, while
        // generating, not produce a first-time stub with a signature the
        // controller can never actually call.
        $invocation = ActionServiceInvocation::resolve($this->actionKey, $this->action);

        $content = $this->getTemplateContent('Features/action/service', 'backend');

        if ($invocation['declared'] && !str_contains($content, '[[serviceParams]]')) {
            PathManager::reportIssue(
                "{$this->moduleName} action '{$this->actionKey}': the Features/action/service stub in use has no [[serviceParams]] placeholder (a project override under stubs/generator/backend/?), so serviceMethod/serviceArgs are ignored — copy the placeholders from the engine stub into the override."
            );
        }

        $actionName = Str::studly($this->action['name'] ?? $this->actionKey);

        // Override serviceName if provided, strip module prefix and 'Service' suffix.
        // !empty(), not ?? — ActionConfigNormalizer::normalize() always sets
        // serviceName to '' when the caller doesn't provide one (never null),
        // so ?? never actually falls back to $actionName: every
        // blank-serviceName action generated e.g. "StatusesService" instead
        // of "StatusesApproveService", and a second such action on the same
        // module collided with (overwrote) the first one's service file.
        $serviceNameRaw = !empty($this->action['serviceName']) ? $this->action['serviceName'] : $actionName;
        if (str_starts_with($serviceNameRaw, $this->moduleName)) {
            $serviceNameRaw = substr($serviceNameRaw, strlen($this->moduleName));
        }
        if (str_ends_with($serviceNameRaw, 'Service')) {
            $serviceNameRaw = substr($serviceNameRaw, 0, -7);
        }

        // Build URL param declarations — TWO forms:
        //   [[urlParams]]     → typed signature form  ", string $uuid, string $year"   (valid in function signatures)
        //   [[urlParamsArgs]] → call-site args form   ", $uuid, $year"                 (valid in function calls)
        // Pasting the typed form into a call produces `process($data, string $uuid)` which is a PHP fatal.
        $urlParams = $this->action['urlParams'] ?? [];
        $urlParamsStr = '';
        $urlParamsArgs = '';
        if (!empty($urlParams)) {
            $typedParts = array_map(fn($p) => "string \${$p}", $urlParams);
            $urlParamsStr  = ', ' . implode(', ', $typedParts);
            $callParts = array_map(fn($p) => "\${$p}", $urlParams);
            $urlParamsArgs = ', ' . implode(', ', $callParts);
        }

        // shelui-engine fork: the record-lookup seam used to trigger only on
        // a literal 'uuid' urlParam -- a has_uuid: false module's action
        // (urlParams: ['id'], the correct convention for such a module) got
        // no auto-scoped lookup at all, and even if a developer copied the
        // old hand-filled body verbatim it would query a 'uuid' column that
        // doesn't exist. Recognizes this module's own record-identifier
        // param name (ModuleConfigContract::hasUuid()) instead of the
        // literal string.
        $recordLookupParam = in_array($this->routeKeyParam(), $urlParams, true) ? $this->routeKeyParam() : null;

        $content = $this->replacePlaceholders($content, [
            '[[ActionName]]'     => $serviceNameRaw,
            '[[urlParams]]'      => $urlParamsStr,
            '[[urlParamsArgs]]'  => $urlParamsArgs,
            '[[recordLookup]]'   => $recordLookupParam !== null ? $this->buildRecordLookup($recordLookupParam) : '',
            '[[serviceMethod]]'      => $invocation['method'],
            '[[serviceParams]]'      => ActionServiceInvocation::serviceParameters($invocation),
            '[[serviceProcessArgs]]' => ActionServiceInvocation::processArguments($invocation),
        ]);

        $fullServiceName = $this->moduleName . $serviceNameRaw . 'Service';
        $filePath = "{$this->modulePath}/Services/{$fullServiceName}.php";

        // Unlike Create/Edit's pure-CRUD services, an action's whole purpose
        // is custom business logic -- the stub's own "Add your custom logic
        // here" TODO is written assuming a developer fills it in by hand.
        // Plain writeFile() gets force-overwritten on every regenerate (same
        // bug class as the inline-items wrapper, see BaseGenerator::
        // writeFileOnce()'s docblock), silently wiping that hand-written
        // logic back to the empty stub the next time this module regenerates
        // for any unrelated reason (a schema tweak, another action, etc).
        return $this->writeFileOnce($filePath, $content);
    }

    /**
     * This module's own record-identifier param name -- 'uuid' when
     * ModuleConfigContract::hasUuid(), else 'id'. Matches BaseGenerator's
     * own [[routeKeyParam]] default, and RoutesGenerator::
     * generateActionRoutes()'s urlParams-derived route segment for the same
     * config.
     */
    private function routeKeyParam(): string
    {
        return ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id';
    }

    /**
     * Record scope seam for an action taking its own record-identifier as a
     * urlParam (engine v3.5.17). Only emitted when the action's own
     * urlParams include this module's own key ('uuid', or 'id' for a
     * has_uuid: false module -- shelui-engine fork) -- an action with no
     * such param operates on nothing this generator can scope.
     * Uses a fully-qualified Model reference since action/service.stub
     * never `use`s the Model class (its stub body is otherwise
     * Model-agnostic, write-once, hand-filled business logic).
     *
     * Placed as the FIRST line of process(), above the "Add your custom
     * logic here" TODO, so a record outside the acting user's reach 404s
     * before any hand-written logic runs at all -- the same "not found,
     * not forbidden" rule every other generated fetch follows.
     */
    private function buildRecordLookup(string $param): string
    {
        $model = '\\' . $this->getNamespace() . '\\' . $this->moduleName . 'Model';

        return <<<PHP
// Record scope seam: an app whose BaseModel defines applyRecordScope() narrows this
        // lookup to the rows the acting user may reach, so a {$param} outside their reach 404s here.
        \$recordQuery = {$model}::query();
        if (method_exists({$model}::class, 'applyRecordScope')) {
            \$recordQuery = {$model}::applyRecordScope(\$recordQuery);
        }
        \$record = \$recordQuery->where('{$param}', \${$param})->first();
        if (!\$record) {
            abort(404, 'Record not found');
        }
PHP;
    }
}
