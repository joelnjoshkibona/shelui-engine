<?php

namespace Blutrixx\GeneratorEngine\Helpers;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class DelegationConfigNormalizer
{
    /**
     * @param array $moduleConfig The delegating (parent) module's own config
     *        -- used only to resolve parentKey's default via
     *        ModuleConfigContract::hasUuid() (see below). Optional and
     *        defaults to `[]` (which hasUuid() itself defaults to `true`
     *        for), so every caller that predates this parameter keeps
     *        resolving 'uuid' exactly as before.
     */
    public static function normalize(array $delegation, array $moduleConfig = []): array
    {
        $delegation['name'] = $delegation['name'] ?? '';
        $delegation['label'] = $delegation['label'] ?? $delegation['name'];
        $delegation['uiType'] = $delegation['uiType'] ?? 'tab';

        // Normalize relatedModule to object format
        if (isset($delegation['relatedModule'])) {
            if (is_string($delegation['relatedModule'])) {
                $delegation['relatedModule'] = [
                    'name' => $delegation['relatedModule'],
                    'group' => 'Core',
                ];
            } elseif (is_array($delegation['relatedModule'])) {
                $delegation['relatedModule']['name'] = $delegation['relatedModule']['name'] ?? '';
                $delegation['relatedModule']['group'] = $delegation['relatedModule']['group'] ?? 'Core';
            }
        }

        // Bug (found 2026-08-16 running the retail-ERP demo fixture's own
        // generated delegation service live — a real "Column not found:
        // parent_id" SQLSTATE[42S22], not a test-only symptom): V1's own
        // GeneratorDelegationDefaultsService.php nests these three keys
        // under `parentContext` (`parentContext.parentKey`/`.filterKey`/
        // `.parentIdField`), matching its own real, currently-stored config
        // shape (confirmed against a live module.json) -- but this method
        // only ever read them as flat top-level keys, so every one of
        // these three silently fell back to its hardcoded default
        // ('uuid'/'parent_id'/'id') regardless of what was actually
        // configured. `parentKey` defaulting to 'uuid' happened to be right
        // by coincidence in the common case; `filterKey` defaulting to the
        // literal string 'parent_id' was not -- DelegationServiceGenerator
        // generated `where('parent_id', ...)` against a table with no such
        // column. Read parentContext.* first (the real, current shape);
        // fall back to the flat keys for any older config that predates
        // parentContext existing at all; only then fall back to the
        // hardcoded default.
        // shelui-engine fork: the 'uuid' fallback assumed every delegating
        // module has a uuid column. A has_uuid: false module's delegation
        // route/controller-method (RoutesGenerator::generateDelegationRoutes()/
        // ControllerGenerator::generateDelegationMethods(), which computes the
        // identical default directly off the same module config) registered
        // and required a {uuid} segment the module's own routes never
        // actually receive -- 404ing every time. ModuleConfigContract::
        // hasUuid($moduleConfig) is the single resolution rule both now share.
        $parentContext = $delegation['parentContext'] ?? [];
        $defaultParentKey = ModuleConfigContract::hasUuid($moduleConfig) ? 'uuid' : 'id';
        $delegation['parentKey'] = $parentContext['parentKey'] ?? $delegation['parentKey'] ?? $defaultParentKey;
        $delegation['filterKey'] = $parentContext['filterKey'] ?? $delegation['filterKey'] ?? 'parent_id';
        $delegation['parentIdField'] = $parentContext['parentIdField'] ?? $delegation['parentIdField'] ?? 'id';

        $delegation['operations'] = self::normalizeOperations($delegation['operations'] ?? []);

        return $delegation;
    }

    private static function normalizeOperations(array $operations): array
    {
        $defaults = self::getOperationDefaults();
        return array_replace_recursive($defaults, $operations);
    }

    private static function getOperationDefaults(): array
    {
        $ops = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            $ops[$op] = [
                'enabled' => false,
                // Per-operation, not a blanket 'GET': RoutesGenerator::
                // generateDelegationRoutes() reads this via
                // $endpoint['method'] ?? (list/view ? 'get' : 'post'), and
                // that fallback only fires on a NULL/missing method — a
                // concrete (if generic) 'GET' here defeated it for every
                // operation, so every delegation's create/edit/delete
                // operation was registered as a GET route regardless of
                // what the caller configured.
                //
                // Per-op, not just per pair: create/edit/delete now proxy to
                // the related module's own native CreateForm/EditForm/
                // DeleteForm unconditionally (see CrudListPanel), and those
                // forms always send POST/PUT/DELETE respectively — a generic
                // 'POST' default for all three broke edit/delete against the
                // route this normalizer registers unless every delegation
                // explicitly overrode the method.
                'endpoint' => [
                    'method' => match ($op) {
                        'list', 'view' => 'GET',
                        'edit' => 'PUT',
                        'delete' => 'DELETE',
                        default => 'POST', // create
                    },
                    'path' => '',
                    'permission' => '',
                ],
                'backend' => self::getBackendOperationDefaults($op),
                'frontend' => self::getFrontendOperationDefaults($op),
            ];
        }
        return $ops;
    }

    private static function getBackendOperationDefaults(string $op): array
    {
        if ($op === 'list') {
            return [
                'filterableFields' => '',
                'sortableFields' => '',
                'filterFields' => [],
                'eagerLoadRelationships' => '',
                'filterableRelationships' => [],
                // Generation-time gates only, not re-declared allow-lists —
                // the delegation never regenerates its own bulk-action
                // services or export/import logic; it stays a true thin
                // proxy, forwarding to the related module's own natively
                // generated {Related}ListService (which already carries its
                // own $bulkActions allow-list from ITS OWN
                // features.backend.list.bulk_actions). These three only
                // control whether the delegation's own route/controller-
                // method/service-method get generated at all.
                'bulk_actions' => [],
                'export' => false,
                'import' => false,
            ];
        }
        if (in_array($op, ['create', 'edit'])) {
            return ['fields' => []];
        }
        if ($op === 'view') {
            return ['eagerLoadRelationships' => ''];
        }
        if ($op === 'delete') {
            return ['success_message' => 'Record deleted successfully'];
        }
        return [];
    }

    private static function getFrontendOperationDefaults(string $op): array
    {
        if ($op === 'list') {
            return ['primaryField' => '', 'fields' => []];
        }
        if (in_array($op, ['create', 'edit'])) {
            return [
                'fields' => [],
                'sections' => [],
                'button_text' => $op === 'create' ? 'Create' : 'Update',
                'hiddens' => [],
                'defaults' => [],
            ];
        }
        if ($op === 'view') {
            return ['fields' => [], 'titleData' => '', 'badges' => []];
        }
        if ($op === 'delete') {
            return ['fields' => []];
        }
        return [];
    }

    public static function normalizeAll(array $delegations, array $moduleConfig = []): array
    {
        return array_map(fn (array $delegation): array => self::normalize($delegation, $moduleConfig), $delegations);
    }

    /**
     * The single source of truth for a delegation operation's permission
     * string — shared by RoutesGenerator (backend route middleware) and
     * CustomFeatureTabComponentGenerator (frontend hasPermission gating), so
     * the two can never drift apart. Defaults to "{RelatedModule}.{op}" —
     * the RELATED module's own permission (e.g. "StockMovements.edit"), the
     * exact same one that module's own standalone CRUD already checks, not
     * a delegation-specific permission. A role granted on a module then
     * works identically whether that module is reached through its own
     * list page or embedded in a parent's delegation tab. Overridable via
     * operations.{op}.endpoint.permission for the rare case a delegation
     * genuinely needs a different gate than the related module's own.
     *
     * !empty(), not ??: normalize()'s own defaults always set permission to
     * '' (never null), so a plain ?? would never actually fall back.
     */
    public static function resolveOperationPermission(
        string $relatedModuleName,
        string $op,
        array $endpointConfig
    ): string {
        return !empty($endpointConfig['permission'])
            ? $endpointConfig['permission']
            : "{$relatedModuleName}.{$op}";
    }

    public static function validate(array $delegation): array
    {
        $errors = [];

        if (empty($delegation['name'])) {
            $errors[] = 'Delegation name is required';
        }

        $uiType = $delegation['uiType'] ?? '';
        if (!in_array($uiType, ['tab', 'modal'])) {
            $errors[] = "Invalid uiType: {$uiType}. Must be one of: tab, modal";
        }

        if (empty($delegation['relatedModule'])) {
            $errors[] = 'relatedModule is required for delegations';
        }

        return $errors;
    }

    public static function getEnabledOperations(array $delegation): array
    {
        $enabled = [];
        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (!empty($delegation['operations'][$op]['enabled'])) {
                $enabled[] = $op;
            }
        }
        return $enabled;
    }

    public static function hasEnabledOperations(array $delegation): bool
    {
        return !empty(self::getEnabledOperations($delegation));
    }
}
