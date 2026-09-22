<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;

class ViewHistoryGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['view'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/view/history', 'frontend');
        
        $viewConfig = $this->config['features']['frontend']['view'] ?? [];
        // shelui-engine fork: same pre-fork hardcoded-'uuid' fallback
        // FrontendRoutesGenerator/ViewLayoutGenerator had -- resolve via the
        // shared $this->idParam() (BaseComponentGenerator) instead of
        // re-deriving an independent copy of the same default. [[idParam]]
        // is not currently interpolated into history.stub's own markup, but
        // this keeps the resolved value consistent everywhere this class
        // computes it, matching the single-source-of-truth convention the
        // rest of this fork's idParam fix uses.
        $idParam = $this->idParam();
        
        $content = $this->replacePlaceholders($content, [
            '[[idParam]]' => $idParam,
            '[[moduleName]]' => $this->moduleName,
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}DetailsHistoryPage.vue";

        return $this->writeFile($filePath, $content);
    }
}
