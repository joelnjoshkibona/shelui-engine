<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class EditPageGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['edit'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/edit/page', 'frontend');

        // shelui-engine fork: this module's own record-identifier route-param
        // name -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'.
        // This class extends BaseGenerator directly (no $this->idParam()
        // available, that's BaseComponentGenerator-only), so it resolves the
        // identical formula FrontendRoutesGenerator::$idParam uses in its own
        // constructor -- the actual edit route this page is mounted under is
        // registered as `:{idParam}/edit`, so `route.params.uuid` (the old,
        // unconditional literal) read undefined for a has_uuid: false module
        // and every request this page's EditForm made 404'd. Also threads the
        // resolved name through as the prop name passed to <{Module}EditForm>,
        // matching that component's own renamed prop (see EditFormGenerator).
        $idParam = $this->config['features']['frontend']['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');

        $content = $this->replacePlaceholders($content, [
            '[[idParam]]' => $idParam,
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/{$this->moduleName}EditPage.vue";
        
        return $this->writeFile($filePath, $content);
    }
}

