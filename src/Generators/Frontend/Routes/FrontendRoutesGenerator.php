<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Routes;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\DelegationConfigNormalizer;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use Illuminate\Support\Str;

class FrontendRoutesGenerator extends BaseGenerator
{
    protected array $features;
    protected array $customFeatures;

    /**
     * shelui-engine fork: this module's own record-identifier route-param
     * name -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'.
     * `features.frontend.view.idParam` (an existing, pre-fork escape hatch)
     * still always wins when explicitly set; this only changes what it
     * falls back to when absent, matching the backend's [[routeKeyParam]]
     * default (BaseGenerator::replacePlaceholders()) for the same config.
     * Computed once and threaded through every route this generator emits,
     * rather than re-derived (and inconsistently applied) per block --
     * see the fix in generate()/generateActionRoutes()/
     * generateCustomFeatureRoutes() for the bug this closes: the delete
     * route and action-page routes hardcoded the literal 'uuid' segment
     * even when edit/view had already resolved a different idParam.
     */
    protected string $idParam;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features from features.frontend
        $this->features = [];
        $frontendFeatures = $config['features']['frontend'] ?? [];
        foreach (['list', 'create', 'view', 'edit', 'delete'] as $feature) {
            if (isset($frontendFeatures[$feature])) {
                $this->features[] = $feature;
            }
        }
        $this->features = array_unique($this->features);

        $this->customFeatures = $config['delegations'] ?? [];

        $this->idParam = $frontendFeatures['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($config) ? 'uuid' : 'id');
    }

    public function generate(): bool
    {
        $moduleRoute = Str::kebab($this->moduleName);
        // Route `meta.title` becomes the browser tab title (see router.ts).
        // Raw PascalCase moduleName must never be interpolated verbatim —
        // humanize() spaces it, and singular/plural follows the convention
        // confirmed against hand-completed modules (Roles, Users, Locations,
        // etc.): the list route stays plural ("Roles"), everything else
        // (Delete/Details/History) uses the singular form ("Delete Role",
        // "Role Details", "Role History").
        $pluralTitle   = $this->humanize($this->moduleName);
        $singularTitle = $this->humanize(Str::singular($this->moduleName));

        $content = "import type {RouteRecordRaw} from \"vue-router\";
import type {EntityModuleConfig} from \"@/composables/useEntityNavigation\";

export const {$this->moduleName}Routes: RouteRecordRaw[] = [";

        // Generate list route
        if (in_array('list', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/list',
\t\tname: '{$this->moduleName}',
\t\tcomponent: () => import('./{$this->moduleName}ListPage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.list',
\t\t\ttitle: '{$pluralTitle}'
\t\t}
\t},";
        }

        // Generate create route
        if (in_array('create', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/create',
\t\tname: '{$moduleRoute}-create',
\t\tcomponent: () => import('./{$this->moduleName}CreatePage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.create'
\t\t}
\t},";
        }

        // Generate edit route
        if (in_array('edit', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$this->idParam}/edit',
\t\tname: '{$moduleRoute}-edit',
\t\tcomponent: () => import('./{$this->moduleName}EditPage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.edit'
\t\t},
\t\tprops: true
\t},";
        }

        // Generate delete route
        if (in_array('delete', $this->features)) {
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$this->idParam}/delete',
\t\tname: '{$moduleRoute}-delete',
\t\tcomponent: () => import('./{$this->moduleName}DeletePage.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.delete',
\t\t\ttitle: 'Delete {$singularTitle}'
\t\t},
\t\tprops: true
\t},";
        }

        // Generate view/details route with children
        if (in_array('view', $this->features)) {
            $idParam = $this->idParam;

            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$idParam}/details',
\t\tcomponent: () => import('./{$this->moduleName}DetailsLayout.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$this->moduleName}.view',
\t\t\ttitle: '{$singularTitle} Details'
\t\t},
\t\tprops: true,
\t\tchildren: [
\t\t\t{
\t\t\t\tpath: '',
\t\t\t\tredirect: to => `/{$moduleRoute}/\${to.params.{$idParam}}/details/overview`
\t\t\t},
\t\t\t{
\t\t\t\tpath: 'overview',
\t\t\t\tname: '{$moduleRoute}-overview',
\t\t\t\tcomponent: () => import('./{$this->moduleName}DetailsOverviewPage.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$this->moduleName}.view',
\t\t\t\t\ttitle: '{$singularTitle} Details'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t},
\t\t\t{
\t\t\t\tpath: 'history',
\t\t\t\tname: '{$moduleRoute}-history',
\t\t\t\tcomponent: () => import('./{$this->moduleName}DetailsHistoryPage.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$this->moduleName}.view',
\t\t\t\t\ttitle: '{$singularTitle} History'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t}" . $this->generateCustomFeatureRoutes($moduleRoute) . "
\t\t]
\t},";
        }

        $content .= $this->generateActionRoutes($moduleRoute);

        $content .= "
]";

        $moduleConfigExport = $this->generateModuleConfigExport($moduleRoute);
        if ($moduleConfigExport !== '') {
            $content .= "\n" . $moduleConfigExport;
        }

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) . "/routes.ts";
        return $this->writeFile($filePath, $content);
    }

    /**
     * Emit a `<ModuleName>ModuleConfig` export — registers this module with
     * useEntityNavigation() so RelatedRecordLink (used for FK-derived list/view
     * cells, anywhere they point at this module, including this module's own
     * self-referential FKs) can open this module's own record details.
     *
     * Mirrors the hand-added block in the reference Locations/Locations/routes.ts.
     * Only emitted when the 'view' feature is enabled: detailsView imports
     * {ModuleName}ViewModal.vue, which only exists for view-enabled modules.
     */
    protected function generateModuleConfigExport(string $moduleRoute): string
    {
        if (!in_array('view', $this->features)) {
            return '';
        }

        return "
// Registers {$this->moduleName} with useEntityNavigation() — needed for RelatedRecordLink
// to open a {$this->moduleName} record's own details view whenever a FK column
// (in this module's own list, or another module's) links back to it.
export const {$this->moduleName}ModuleConfig: EntityModuleConfig = {
\tmode: 'modal',
\troute: '/{$moduleRoute}',
\tdetailsView: () => import('./Components/{$this->moduleName}ViewModal.vue'),
\t// Which record-identifier prop RelatedRecordLink must bind onto this
\t// module's own {$this->moduleName}ViewModal.vue -- resolved the same way
\t// as this generator's own \$idParam (see that property's docblock).
\t// Lets a RelatedRecordLink pointed at THIS module read the right
\t// identifier off the loaded relation regardless of the linking module's
\t// own routing scheme.
\tidParam: '{$this->idParam}',
\t// Title + size MUST match what the list page passes to its own <AppDialog>
\t// ({$moduleRoute}.page_details at 3xl). Without them the same ViewModal opened
\t// via a RelatedRecordLink rendered with no header bar and at a narrower
\t// width, so the two routes to an identical component looked like two
\t// different dialogs.
\tdetailsTitleKey: '{$moduleRoute}.page_details',
\tmodalSize: '3xl'
};";
    }

    /**
     * Routes for `uiType: "page"` actions.
     *
     * Actions previously generated no frontend route at all, so a page action's
     * component was emitted and then unreachable — nothing imported it and no
     * URL resolved to it. The view modal's button navigates here.
     *
     * The permission MUST match RoutesGenerator::generateActionRoutes() and
     * SeederGenerator, i.e. `{Module}.{actionName}`; the earlier drift between
     * those three is what made every action 403.
     */
    protected function generateActionRoutes(string $moduleRoute): string
    {
        $content = '';
        $actions = $this->config['actions'] ?? [];

        foreach ($actions as $actionKey => $action) {
            if (($action['uiType'] ?? '') !== 'page' || empty($action['hasUI'])) {
                continue;
            }

            $name       = $action['name'] ?? $actionKey;
            $studly     = Str::studly($name);
            $kebab      = Str::kebab($name);
            $label      = $action['label'] ?? $this->humanize($studly);
            $permission = "{$this->moduleName}.{$name}";

            // Every sibling block in this file ENDS with its comma. Prefixing
            // one here instead produced `},,` — an array elision, so the routes
            // array contained an `undefined` entry and vue-router crashed on
            // startup with "Cannot read properties of undefined (reading
            // 'path')", taking down the whole SPA including /login.
            $content .= "
\t{
\t\tpath: '/{$moduleRoute}/:{$this->idParam}/{$kebab}',
\t\tname: '{$moduleRoute}-{$kebab}',
\t\tcomponent: () => import('./{$this->moduleName}{$studly}Page.vue'),
\t\tmeta: {
\t\t\trequiresAuth: true,
\t\t\tpermission: '{$permission}',
\t\t\ttitle: '{$label}'
\t\t},
\t\tprops: true
\t},";
        }

        return $content;
    }

    protected function generateCustomFeatureRoutes(string $moduleRoute = ''): string
    {
        if (empty($moduleRoute)) {
            $moduleRoute = Str::kebab($this->moduleName);
        }
        $routes = '';

        foreach ($this->customFeatures as $featureKey => $customFeature) {
            // Only tab delegations generate frontend routes (they appear as tabs in details view)
            $uiType = $customFeature['uiType'] ?? ($customFeature['displayType'] ?? '');
            if ($uiType === 'tab' || $uiType === 'tab-action') {
                // kebab for the URL segment, StudlyCase for the component name.
                $featureName = Str::kebab($customFeature['name'] ?? $featureKey);
                $FeatureName = Str::studly($customFeature['name'] ?? $featureKey);
                // Same raw-name leak as the module title above: fall back to a
                // humanized label when the blueprint doesn't supply one.
                $label = $customFeature['label'] ?? $this->humanize($FeatureName);

                // Permission MUST match RoutesGenerator::generateDelegationRoutes()'s
                // backend guard for this delegation's 'list' operation — both call
                // DelegationConfigNormalizer::resolveOperationPermission() with the
                // SAME (relatedModuleName, 'list', endpoint) triple, deliberately
                // reusing the related module's own permission (e.g. `UserLocations.list`)
                // rather than inventing a delegation-specific one, so a role granted on
                // a module works identically whether it's reached via its own list page
                // or embedded in a parent's delegation tab. This used to hardcode
                // `{ParentModule}.{StudlyFeature}.list` instead — a permission string
                // nothing ever seeds, so the frontend route guard 403'd (blocked
                // navigation for) every role, even though the backend endpoint itself
                // was correctly reachable — confirmed live while wiring
                // Locations.UserLocations, whose backend guard was `UserLocations.list`
                // and whose frontend guard was the unseeded `Locations.UserLocations.list`.
                $relatedModuleName = $customFeature['relatedModule']['name'] ?? $FeatureName;
                $listEndpoint      = $customFeature['operations']['list']['endpoint'] ?? [];
                $permission        = DelegationConfigNormalizer::resolveOperationPermission(
                    $relatedModuleName,
                    'list',
                    $listEndpoint
                );

                $routes .= ",
\t\t\t{
\t\t\t\tpath: '{$featureName}',
\t\t\t\tname: '{$moduleRoute}-{$featureName}',
\t\t\t\tcomponent: () => import('./{$this->moduleName}{$FeatureName}Tab.vue'),
\t\t\t\tmeta: {
\t\t\t\t\trequiresAuth: true,
\t\t\t\t\tpermission: '{$permission}',
\t\t\t\t\ttitle: '{$label}'
\t\t\t\t},
\t\t\t\tprops: true
\t\t\t}";
            }
        }

        return $routes;
    }
}
