<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class CustomFeatureModalComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        // This generator should be called via generateCustomFeature()
        return false;
    }

    public function generateCustomFeature(string $featureKey, array $customFeature): bool
    {
        $displayType = $customFeature['displayType'] ?? 'header-action';
        if ($displayType !== 'header-action') {
            return false;
        }
        
        $content = $this->getTemplateContent('features/custom/header_modal', 'frontend');

        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $relatedModule = $customFeature['relatedModule'] ?? null;
        
        // Handle relatedModule as object or string
        $relatedModuleName = '';
        if ($relatedModule) {
            if (is_array($relatedModule)) {
                $relatedModuleName = $relatedModule['name'] ?? '';
            } else {
                $relatedModuleName = $relatedModule;
            }
        }
        
        // Use enabled flags from features.backend
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $enabledOps = $customFeature['enabledOperations'] ?? [];
        
        $hasList = isset($backendFeatures['list']['enabled']) 
            ? ($backendFeatures['list']['enabled'] ?? false)
            : ($enabledOps['list'] ?? false);
        $hasCreate = isset($backendFeatures['create']['enabled']) 
            ? ($backendFeatures['create']['enabled'] ?? false)
            : ($enabledOps['create'] ?? true);
        $hasEdit = isset($backendFeatures['edit']['enabled'])
            ? ($backendFeatures['edit']['enabled'] ?? false)
            : ($enabledOps['edit'] ?? false);
        $hasView = isset($backendFeatures['view']['enabled'])
            ? ($backendFeatures['view']['enabled'] ?? false)
            : ($enabledOps['view'] ?? false);
        $hasDelete = isset($backendFeatures['delete']['enabled'])
            ? ($backendFeatures['delete']['enabled'] ?? false)
            : ($enabledOps['delete'] ?? false);
        
        // Get API endpoint from backend list feature config (for ListPageBare)
        $listConfig = $backendFeatures['list'] ?? [];
        // shelui-engine fork: PARENT module's own record-identifier key --
        // 'uuid' when ModuleConfigContract::hasUuid(), else 'id' (see
        // idParam()'s docblock, BaseComponentGenerator). DelegationModalComponentGenerator
        // already resolves this the same way for a real delegation
        // (adaptDelegationToCustomFeature()); this covers a custom feature
        // built without going through a delegation. Only changes the DEFAULT
        // path this module builds when no explicit endpoint.path is
        // configured -- the item/child's own key (${deletingItem.value.uuid}
        // below) is a separate, deferred question (mirrors the backend's
        // "related module's own key" precedent).
        $parentKey = $customFeature['parentKey'] ?? $this->idParam();
        $listBasePath = $listConfig['endpoint']['path'] ?? "/{$this->moduleNameLower}/{{$parentKey}}/{$featureNameLower}";
        // Replace parent key placeholder with template literal
        $listBasePath = str_replace("{{$parentKey}}", '${props.parentUuid}', $listBasePath);
        // Also handle {uuid} for backward compatibility
        $listBasePath = str_replace('{uuid}', '${props.parentUuid}', $listBasePath);
        // Ensure it starts with /
        $listBasePath = '/' . ltrim($listBasePath, '/');
        // Remove any existing operation suffix
        $listBasePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $listBasePath);
        $listBasePath = rtrim($listBasePath, '/');
        // Append /list operation suffix for ListPageBare
        $listEndpointPath = $listBasePath . '/list';
        
        // Get create endpoint from backend create feature config
        $createConfig = $backendFeatures['create'] ?? [];
        $createBasePath = $createConfig['endpoint']['path'] ?? "/{$this->moduleNameLower}/{{$parentKey}}/{$featureNameLower}";
        // Replace parent key placeholder with template literal
        $createBasePath = str_replace("{{$parentKey}}", '${props.parentUuid}', $createBasePath);
        // Also handle {uuid} for backward compatibility
        $createBasePath = str_replace('{uuid}', '${props.parentUuid}', $createBasePath);
        // Ensure it starts with /
        $createBasePath = '/' . ltrim($createBasePath, '/');
        // Remove any existing operation suffix
        $createBasePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $createBasePath);
        $createBasePath = rtrim($createBasePath, '/');
        // Append /create operation suffix
        $endpointPath = $createBasePath . '/create';
        
        // Get delete endpoint from backend delete feature config
        $deleteConfig = $backendFeatures['delete'] ?? [];
        $deleteBasePath = $deleteConfig['endpoint']['path'] ?? '';
        if (empty($deleteBasePath)) {
            // Fallback: construct from list endpoint base
            $deleteBasePath = $listBasePath;
        } else {
            // Replace parent key placeholder with template literal
            $deleteBasePath = str_replace("{{$parentKey}}", '${props.parentUuid}', $deleteBasePath);
            // Also handle {uuid} for backward compatibility
            $deleteBasePath = str_replace('{uuid}', '${props.parentUuid}', $deleteBasePath);
            // Replace item UUID placeholder (e.g., {review_id}, {item_id}) with ${deletingItem.value.uuid}
            $deleteBasePath = preg_replace('/\{[^}]+\}/', '${deletingItem.value.uuid}', $deleteBasePath);
        }
        // Ensure it starts with /
        $deleteBasePath = '/' . ltrim($deleteBasePath, '/');
        // Remove any existing operation suffix
        $deleteBasePath = preg_replace('/\/(list|create|view|edit|delete)(\/|$)/', '', $deleteBasePath);
        $deleteBasePath = rtrim($deleteBasePath, '/');
        // Ensure it has the item UUID parameter before appending /delete
        if (!str_contains($deleteBasePath, '${deletingItem.value.uuid}')) {
            $deleteBasePath .= '/${deletingItem.value.uuid}';
        }
        // Append /delete operation suffix
        $deleteEndpointPath = $deleteBasePath . '/delete';
        
        // Get columns for list view if enabled
        $listFields = $customFeature['features']['frontend']['list']['fields'] ?? [];
        $columns = !empty($listFields) ? $this->generateColumnsFromListFields($listFields) : '';
        
        // Generate form fields for create operation
        $createFields = $customFeature['features']['frontend']['create']['fields'] ?? [];
        $formFields = '';
        $formFieldImports = '';
        $formInitialization = '';
        $formSections = '';
        $splashData = '';
        $hasSplash = false;
        
        if (!empty($createFields) && $hasCreate) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createFields);
            $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            $formFields = $this->generateFieldsGrid($mappedFields);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            $formInitialization = $this->generateFormFields(['fields' => $mappedFields]);
            $splashData = $this->generateSplashData(['fields' => $mappedFields]);
            $hasSplash = $this->hasSplashData(['fields' => $mappedFields]);
        }
        
        $frontendConfig = $customFeature['features']['frontend'] ?? [];
        $createFrontendConfig = $frontendConfig['create'] ?? [];
        $buttonText = $createFrontendConfig['button_text'] ?? 'Add';
        $buttonIcon = $createFrontendConfig['button_icon'] ?? 'PlusIcon';
        $modalTitle = $createFrontendConfig['modal_title'] ?? "Add {$featureName}";
        
        // Generate component imports for EditForm and ViewComponent if enabled
        $componentImports = '';
        if ($hasEdit && !empty($relatedModuleName)) {
            $importSegment = PathManager::resolveFrontendImportSegment($relatedModuleName);
            $componentImports .= "import {$relatedModuleName}EditForm from '@/pages/modules/{$importSegment}/Components/{$relatedModuleName}EditForm.vue';\n\t";
        }
        if ($hasView && !empty($relatedModuleName)) {
            $importSegment = PathManager::resolveFrontendImportSegment($relatedModuleName);
            $componentImports .= "import {$relatedModuleName}ViewComponent from '@/pages/modules/{$importSegment}/Components/{$relatedModuleName}ViewComponent.vue';\n\t";
        }
        
        $content = $this->replacePlaceholders($content, [
            '[[FeatureName]]' => $featureName,
            '[[featureName]]' => strtolower($featureName),
            '[[RelatedModule]]' => $relatedModuleName,
            '[[relatedModule]]' => strtolower($relatedModuleName),
            '[[hasList]]' => $hasList ? 'true' : 'false',
            '[[hasCreate]]' => $hasCreate ? 'true' : 'false',
            '[[hasEdit]]' => $hasEdit ? 'true' : 'false',
            '[[hasView]]' => $hasView ? 'true' : 'false',
            '[[hasDelete]]' => $hasDelete ? 'true' : 'false',
            '[[buttonText]]' => $buttonText,
            '[[buttonIcon]]' => $buttonIcon,
            '[[modalTitle]]' => $modalTitle,
            '[[columns]]' => $columns,
            '[[apiEndpointPath]]' => $listEndpointPath,
            '[[deleteEndpointPath]]' => $deleteEndpointPath,
            '[[createEndpointPath]]' => $endpointPath,
            '[[formFields]]' => $formFields,
            '[[formFieldImports]]' => $formFieldImports,
            '[[formInitialization]]' => $formInitialization,
            '[[formSections]]' => $formSections,
            '[[splashData]]' => $splashData,
            '[[hasSplash]]' => $hasSplash ? 'true' : 'false',
            '[[componentImports]]' => $componentImports,
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}{$featureName}Modal.vue";
        
        return $this->writeFile($filePath, $content);
    }
}

