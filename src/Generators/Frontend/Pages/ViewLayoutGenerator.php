<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Illuminate\Support\Str;

class ViewLayoutGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/view/details_layout', 'frontend');
        
        // Process frontend configuration for view feature (new payload path)
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        
        // Generate header badges. details_layout.stub's fetched record is
        // always named `record` (see its `const record = ref<any>(null)`),
        // never `data` — must be passed explicitly since
        // generateHeaderBadges() defaults to 'data' for other, hypothetical
        // callers.
        $headerBadges = $this->generateHeaderBadges($this->config, 'record');
        
        // Generate header actions
        $headerActions = $this->generateHeaderActions($this->config);
        
        // Generate tab navigation
        $tabs = $this->generateTabNavigation($this->config);
        
        // Get primary display field
        $primaryField = $viewConfig['titleData'] ?? 'name';
        $secondaryField = $viewConfig['subtitleData'] ?? 'id';
        
        // Get ID parameter. shelui-engine fork: this used to hardcode the
        // 'uuid' fallback independently of FrontendRoutesGenerator's own
        // computed idParam -- for a has_uuid: false module, the route this
        // page is mounted under is registered with an `:id` segment (see
        // FrontendRoutesGenerator::$idParam), so `route.params.[[idParam]]`
        // resolving to `route.params.uuid` here read undefined and
        // recordId/navigateTo() broke for every generated action on such a
        // module. $this->idParam() (BaseComponentGenerator, which this class
        // extends) is the single shared resolution point every Frontend
        // component generator should use instead of re-deriving its own
        // copy of the same fallback -- see its docblock.
        $idParam = $this->idParam();
        
        // Check if Badge import is needed
        $badgeImport = $this->generateBadgeImport($this->config);
        
        // Generate custom feature imports and modal states
        $customFeatureImports = $this->generateCustomFeatureImports($this->config);
        $customFeatureModalStates = $this->generateCustomFeatureModalStates($this->config);
        
        // Edit/Delete markup + imports, emitted only for operations this module actually has.
        // $idParam is threaded through: the Edit/Delete modal below binds the record identifier
        // into {Module}EditForm/{Module}DeleteForm under this exact prop name (see the method's
        // own docblock addition below for why this must match, not just be a literal 'uuid').
        [$actionToolbar, $crudModals, $crudFormImports] = $this->generateCrudOperationBlocks($idParam);

        $content = $this->replacePlaceholders($content, [
            '[[actionToolbar]]' => $actionToolbar,
            '[[crudModals]]' => $crudModals,
            '[[crudFormImports]]' => $crudFormImports,
            '[[statusBadge]]' => $headerBadges,
            '[[headerActions]]' => $headerActions,
            '[[tabs]]' => $tabs,
            '[[metricsConfigs]]' => $this->generateMetricsConfigs($this->config),
            '[[primaryDisplayField]]' => $primaryField,
            '[[secondaryDisplayField]]' => $secondaryField,
            '[[idParam]]' => $idParam,
            '[[badgeImport]]' => $badgeImport,
            '[[customFeatureImports]]' => $customFeatureImports,
            '[[customFeatureModalStates]]' => $customFeatureModalStates,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}DetailsLayout.vue";

        return $this->writeFile($filePath, $content);
    }

    /**
     * Build the Edit/Delete toolbar, modals and component imports for whichever of those
     * operations the module actually has.
     *
     * A module with a reduced operation set -- an append-only ledger (list/view), a receipts
     * table (create/list/view) -- has no {Module}EditForm.vue / {Module}DeleteForm.vue on disk,
     * because EditFormGenerator/DeleteFormGenerator never wrote one. Until 2026-08-25 this
     * layout imported both unconditionally, which `vite dev` tolerates (the module is only
     * resolved when someone opens that route) but `vite build` does not: it resolves every
     * import statically, so the whole production bundle failed with
     *
     *     [UNRESOLVED_IMPORT] Could not resolve './Components/PaymentsEditForm.vue'
     *
     * i.e. an app containing one such module could not be built for production at all. This is
     * the frontend counterpart of the backend bug v3.4.17 fixed (a disabled operation leaving
     * its generated test file behind) and follows ListPageGenerator::generateCrudOperationBlocks()'s
     * established pattern.
     *
     * Resolves [[ModuleName]]/[[PermissionBaseName]]/[[moduleRoute]] itself rather than
     * embedding those tokens in the returned strings: replacePlaceholders() substitutes every
     * key in one non-recursive str_replace pass, so a token inside a value injected via this
     * method's own placeholder would never get a second pass to resolve it.
     *
     * shelui-engine fork: the Edit/Delete modals below bind the record id into
     * {Module}EditForm/{Module}DeleteForm as `:{$idParam}="recordId"` -- EditFormGenerator/
     * DeleteFormGenerator now declare that prop as `[[idParam]]` ('uuid' when
     * ModuleConfigContract::hasUuid(), else 'id'), not a literal `uuid`, so this used to
     * silently fail to bind for a has_uuid: false module (an attribute matching no declared
     * prop falls through instead of binding, leaving the Form's own required id prop
     * undefined and every request it builds 404ing). $idParam is passed in from generate(),
     * which already resolves it via $this->idParam() (BaseComponentGenerator) -- the same
     * value used for '[[idParam]]' in details_layout.stub's own route.params/navigateTo() code,
     * so recordId's source and the modal's prop name stay consistent.
     *
     * @param string $idParam this module's own record-identifier prop/param name
     * @return array{0: string, 1: string, 2: string} [actionToolbar, crudModals, crudFormImports]
     */
    private function generateCrudOperationBlocks(string $idParam): array
    {
        $frontendFeatures = $this->config['features']['frontend'] ?? [];
        $hasEdit   = !empty($frontendFeatures['edit']);
        $hasDelete = !empty($frontendFeatures['delete']);

        $module = $this->moduleName;
        $perm   = $this->moduleName;
        $route  = Str::kebab($this->moduleName);

        // ── Toolbar ──────────────────────────────────────────────────────────────────────
        $toolbar = '';
        if ($hasEdit || $hasDelete) {
            $restore = '';
            if ($hasDelete) {
                // Restore is reachable only for a soft-deleted row, which only a delete
                // operation can produce -- so it is gated on the same feature, exactly as its
                // own hasPermission('{Module}.delete') check already gated it at runtime.
                $restore = <<<VUE
				<!-- Deleted: Restore only -->
				<template v-if="record?.deleted_at">
					<Button v-if="hasPermission('{$perm}.delete')" size="xs" variant="ghost"
						class="flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
						:disabled="isRestoring"
						@click="restoreRecord">
						<component :is="isRestoring ? icons.Loader2Icon : icons.RotateCcwIcon" class="h-3 w-3" :class="{ 'animate-spin': isRestoring }" />
						<span>{{ isRestoring ? \$t('common.restoring') : \$t('common.restore') }}</span>
					</Button>
				</template>

VUE;
            }

            $active = [];
            if ($hasEdit) {
                $active[] = <<<VUE
					<Button v-if="hasPermission('{$perm}.edit')" size="xs" variant="ghost"
						class="flex items-center gap-1.5 text-muted-foreground hover:text-foreground"
						@click="editOpen = true">
						<component :is="icons.EditIcon" class="h-3 w-3" />
						<span>{{ \$t('common.edit') }}</span>
					</Button>
VUE;
            }
            if ($hasDelete) {
                $active[] = <<<VUE
					<DropdownMenu v-if="hasPermission('{$perm}.delete')">
						<DropdownMenuTrigger as-child>
							<Button size="xs" variant="ghost" class="flex items-center gap-1.5 text-muted-foreground hover:text-foreground">
								{{ \$t('common.more_actions') }}
								<component :is="icons.ChevronDownIcon" class="h-3 w-3" />
							</Button>
						</DropdownMenuTrigger>
						<DropdownMenuContent align="start" class="w-36">
							<DropdownMenuItem class="text-xs text-destructive focus:text-destructive gap-1.5 px-2 py-1" @click="deleteOpen = true">
								<component :is="icons.Trash2Icon" class="h-3 w-3" />
								{{ \$t('common.delete') }}
							</DropdownMenuItem>
						</DropdownMenuContent>
					</DropdownMenu>
VUE;
            }

            // With no delete there is no restore branch, so the v-if/v-else pair collapses to
            // a plain block rather than an empty <template v-if> nobody can ever satisfy.
            $activeBody = implode("\n\n", $active);
            $activeBlock = $hasDelete
                ? "\t\t\t\t<!-- Active: Edit + More Actions (Delete inside) -->\n\t\t\t\t<template v-else>\n{$activeBody}\n\t\t\t\t</template>"
                : "\t\t\t\t<!-- Active: Edit -->\n{$activeBody}";

            $toolbar = "<!-- Header Row 2: Action toolbar -->\n"
                . "\t\t\t<div class=\"flex flex-wrap items-center gap-x-3 gap-y-2 border-b pb-3\">\n"
                . $restore
                . $activeBlock . "\n"
                . "\t\t\t</div>";
        }

        // ── Modals ───────────────────────────────────────────────────────────────────────
        $modals = [];
        if ($hasEdit) {
            $modals[] = "<!-- Edit Modal -->\n"
                . "\t\t<AppDialog v-model:open=\"editOpen\" :title=\"\$t('{$route}.page_edit')\" size=\"lg\" persistent>\n"
                . "\t\t\t<{$module}EditForm :{$idParam}=\"recordId\" modal @cancel=\"editOpen = false\" @updated=\"onUpdated\" />\n"
                . "\t\t</AppDialog>";
        }
        if ($hasDelete) {
            $modals[] = "<!-- Delete Modal -->\n"
                . "\t\t<AppDialog v-model:open=\"deleteOpen\" :title=\"\$t('{$route}.page_delete')\" size=\"md\">\n"
                . "\t\t\t<{$module}DeleteForm :{$idParam}=\"recordId\" modal @cancel=\"deleteOpen = false\" @deleted=\"onDeleted\" />\n"
                . "\t\t</AppDialog>";
        }

        // ── Imports ──────────────────────────────────────────────────────────────────────
        $imports = [];
        if ($hasEdit) {
            $imports[] = "import {$module}EditForm from './Components/{$module}EditForm.vue'";
        }
        if ($hasDelete) {
            $imports[] = "import {$module}DeleteForm from './Components/{$module}DeleteForm.vue'";
        }

        return [$toolbar, implode("\n\n\t\t", $modals), implode("\n", $imports)];
    }

    protected function generateMetricsConfigs(array $config): string
    {
        // Support both 'header_metrics' (frontend format) and 'header.metrics' (legacy nested format)
        $metrics = $config['features']['frontend']['view']['header_metrics'] 
            ?? $config['features']['frontend']['view']['header']['metrics'] 
            ?? [];
        $configs = [];
        foreach ($metrics as $metric) {
            // Support both 'title' (frontend format) and 'label' (legacy format)
            $label = $metric['title'] ?? $metric['label'] ?? 'Metric';
            // Support 'field', 'dataPath', 'data' (API format), or 'key' as the property path
            $dataPath = $metric['field'] ?? $metric['dataPath'] ?? $metric['data'] ?? $metric['key'] ?? '';
            $icon = $metric['icon'] ?? 'InfoIcon';
            // Support color from metric config, default to blue
            $metricColor = $metric['color'] ?? 'blue';
            $color = "text-{$metricColor}-400";

            $configs[] = "	{
		label: \"{$label}\",
		value: record?.{$dataPath} || 'N/A',
		icon: \"{$icon}\",
		color: \"{$color}\",
	}";
        }
        return implode(",\n", $configs);
    }
}
