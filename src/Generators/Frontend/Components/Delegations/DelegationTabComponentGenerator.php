<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\CustomFeatureTabComponentGenerator;

class DelegationTabComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return false;
    }

    public function generateDelegation(string $delegationKey, array $delegation): bool
    {
        if (($delegation['uiType'] ?? 'tab') !== 'tab') {
            return false;
        }

        $adapted = $this->adaptDelegationToCustomFeature($delegation);

        $generator = new CustomFeatureTabComponentGenerator($this->moduleName, $this->moduleGroup, $this->config);
        // Force must be handed to the inner generator explicitly: setForce()
        // was applied to THIS object, and the delegate is a brand-new
        // instance defaulting to force=false. Without this a --force run
        // reported "Skipped (already exists)" and silently kept the old
        // component, so config changes (e.g. enabling a delegation's create
        // operation) never reached the generated file.
        $generator->setForce($this->force);
        return $generator->generateCustomFeature($delegationKey, $adapted);
    }

    private function adaptDelegationToCustomFeature(array $delegation): array
    {
        $operations = $delegation['operations'] ?? [];

        $backendFeatures = [];
        $frontendFeatures = [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            $opData = $operations[$op] ?? [];
            $backendOp = $opData['backend'] ?? [];
            $frontendOp = $opData['frontend'] ?? [];

            $backendFeatures[$op] = array_merge(
                ['enabled' => $opData['enabled'] ?? false],
                isset($opData['endpoint']) ? ['endpoint' => $opData['endpoint']] : [],
                $backendOp
            );

            $frontendFeatures[$op] = $frontendOp;
        }

        return [
            'name' => $delegation['name'] ?? '',
            'label' => $delegation['label'] ?? '',
            'displayType' => 'tab-action',
            'relatedModule' => $delegation['relatedModule'] ?? null,
            // shelui-engine fork: this is the DELEGATING (parent) module's own
            // record-identifier key -- 'uuid' when ModuleConfigContract::hasUuid(),
            // else 'id' (see BaseComponentGenerator::idParam()'s docblock).
            // Mirrors the backend's already-fixed DelegationConfigNormalizer::
            // normalize()/RoutesGenerator's own parentKey default fix. The
            // RELATED/child item's own key is a separate, deferred question
            // (see CustomFeatureTabComponentGenerator's docblock).
            'parentKey' => $delegation['parentKey'] ?? $this->idParam(),
            'filterKey' => $delegation['filterKey'] ?? 'parent_id',
            'parentIdField' => $delegation['parentIdField'] ?? 'id',
            'features' => [
                'backend' => $backendFeatures,
                'frontend' => $frontendFeatures,
            ],
        ];
    }
}
