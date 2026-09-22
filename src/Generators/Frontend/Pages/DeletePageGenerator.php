<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class DeletePageGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        // $frontendConfig = $this->config['features']['frontend']['delete'] ?? null;
        // if (empty($frontendConfig)) {
        //     return false;
        // }

        $content = $this->getTemplateContent('features/delete/page', 'frontend');

        // shelui-engine fork: same fix as EditPageGenerator's identical
        // block -- see its comment for the full rationale. This module's own
        // record-identifier route-param name, resolved the same way
        // FrontendRoutesGenerator::$idParam is (this class extends
        // BaseGenerator directly, so no $this->idParam() is available).
        $idParam = $this->config['features']['frontend']['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');

        $content = $this->replacePlaceholders($content, [
            '[[idParam]]' => $idParam,
        ]);

        $featureName = 'DeletePage';
        $fileName = $this->moduleName . $featureName . '.vue';
        $frontendModulePath = \Blutrixx\GeneratorEngine\Generators\PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
        $filePath = "{$frontendModulePath}/{$fileName}";

        return $this->writeFile($filePath, $content);
    }
}
