<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class DeleteFormGenerator extends BaseGenerator
{
    public function generate(): bool
    {
        // $frontendConfig = $this->config['features']['frontend']['delete'] ?? null;
        // if (empty($frontendConfig)) {
        //     return false;
        // }

        $content = $this->getTemplateContent('features/delete/form', 'frontend');

        // shelui-engine fork: the record-identifier PROP this DeleteForm
        // itself declares -- 'uuid' when ModuleConfigContract::hasUuid(),
        // else 'id'. This class extends BaseGenerator directly (not
        // BaseComponentGenerator, which has no delete-form subclass), so it
        // resolves the same way FrontendRoutesGenerator's own constructor
        // property does -- the `features.frontend.view.idParam` escape hatch
        // still wins when set, since that is the same config FrontendRoutesGenerator
        // consults to register this module's `:{idParam}/delete` route, which
        // must match the prop this component actually reads its record
        // identifier from. delete/form.stub used to declare a prop literally
        // named `uuid` and read `props.uuid` for the check/submit endpoint
        // URLs and the "open full page" link -- for a has_uuid: false module,
        // callers (DeletePageGenerator's page.stub, ViewLayoutGenerator's
        // own-module delete modal) pass the record's real 'id', never a
        // 'uuid', so the prop -- and every URL built from it -- was
        // undefined.
        $idParam = $this->config['features']['frontend']['view']['idParam']
            ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');

        $content = $this->replacePlaceholders($content, [
            '[[idParam]]' => $idParam,
        ]);

        $componentName = $this->moduleName . 'DeleteForm';
        $frontendModulePath = \Blutrixx\GeneratorEngine\Generators\PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName);
        $filePath = "{$frontendModulePath}/Components/{$componentName}.vue";

        return $this->writeFile($filePath, $content);
    }
}
