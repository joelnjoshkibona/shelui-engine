<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class EditFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['edit'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }
        
        $content = $this->getTemplateContent('features/edit/form', 'frontend');
        
        // Prefer new payload fields for edit
        $editConfig = $this->config['features']['frontend']['edit'] ?? [];
        $frontendConfig = $this->config ?? [];
        
        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $fieldsForSubmit = [];

        // Draft autosave is on by default -- opt out via
        // features.frontend.edit.drafts: false in module.json.
        $hasDrafts = ($editConfig['drafts'] ?? true) !== false;

        // Wizard mode: optional, additive, opt-in via features.frontend.edit.wizard.enabled.
        // See BaseComponentGenerator::generateWizardSteps()'s docblock for the full design.
        $wizardConfig = $editConfig['wizard'] ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        // Sibling to `wizard`, not nested in it -- see CreateFormGenerator's
        // identical comment for the full default-resolution rationale.
        $confirmStepConfig = $editConfig['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        // Merge the RESOLVED decision back in -- see CreateFormGenerator's
        // identical comment for why the raw (possibly-empty) config can't be
        // passed through a ternary gate.
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $claimedInlineItemKeys = [];
        // $hasFkFieldLabels is only known once generateWizardSteps() runs
        // (inside the fields branch below) -- $wizardStateBlock is computed
        // after that, not here.
        $hasFkFieldLabels = false;
        $confirmCheckboxBlock = (!$isWizard && $requiresConfirmation) ? $this->generateFlatConfirmBlock($confirmStepConfig) : '';

        $footer = $this->generateFormFooter('edit', $hasDrafts, $isWizard, $requiresConfirmation);

        // $footer is emitted as its own [[formFooter]] token (see below) --
        // see CreateFormGenerator's identical comment for the full rationale
        // (inline_items rendering below the Save/Create buttons).
        if (!empty($editConfig['fields']) && is_array($editConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($editConfig['fields']);
            if ($isWizard) {
                [$formSections, $claimedInlineItemKeys, $hasFkFieldLabels] = $this->generateWizardSteps(
                    $wizardConfig,
                    $mappedFields,
                    $this->config['inline_items'] ?? [],
                    $confirmStepConfig
                );
            } else {
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
            }
            $formFields = $this->generateFormFields(['fields' => $mappedFields]);
            $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
            if ($isWizard) {
                $formFieldImports .= "\nimport { Stepper } from '@/components/ui/stepper';";
            }
            if ($requiresConfirmation && !str_contains($formFieldImports, 'CheckboxField')) {
                $formFieldImports .= "\nimport CheckboxField from '@/components/form-fields/CheckboxField.vue';";
            }
            $fieldsForSubmit = $mappedFields;
        } else {
            // Fallback: Try to generate fields from columns if fields are empty
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'edit');
            if (!empty($fallbackFields)) {
                $mappedFields = $this->mapNewFormFieldsToLegacy($fallbackFields);
                $formSections = $this->generateFormSection(['title' => 'Main Details'], $mappedFields);
                $formFields = $this->generateFormFields(['fields' => $mappedFields]);
                $formFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
                $fieldsForSubmit = $mappedFields;
            } else {
                // Fallback to previous derivations
                $formSections = $this->generateFormSections($frontendConfig);
                $formFields = $this->generateFormFields($frontendConfig);
                $formFieldImports = $this->generateFormFieldImports($frontendConfig);
                $fieldsForSubmit = $this->collectAllFieldsFromConfig($frontendConfig);
            }
        }

        $wizardStateBlock = $isWizard ? $this->generateWizardStateBlock($wizardConfig, $hasDrafts, $confirmStepConfig, $hasFkFieldLabels) : '';
        if (!$isWizard && $requiresConfirmation) {
            $wizardStateBlock = "const confirmed = ref(false)\n";
        }

        // shelui-engine fork: the record-identifier PROP this EditForm itself
        // declares -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'
        // (see BaseComponentGenerator::idParam()'s docblock). edit/form.stub
        // used to declare a prop literally named `uuid` and read
        // `props.uuid` to build both the submit and view endpoint URLs,
        // regardless of has_uuid -- for a has_uuid: false module, whatever
        // called this component (EditPageGenerator's page.stub, or
        // ViewLayoutGenerator's own-module edit modal) passes the record's
        // real 'id', never a 'uuid', so the prop -- and every URL built from
        // it -- was undefined. BaseComponentGenerator::generateFormFooter()/
        // buildEditDraftBlocks() were already fixed to read `props.{idParam}`
        // and the auto-exposed `{idParam}` template binding for the "open
        // full page" link and useDraft() call -- this is the matching fix on
        // the prop declaration itself those two depend on.
        $idParam = $this->idParam();
        // Only meaningful once fieldLabels itself is declared (see
        // generateFieldLabelsSeedBlock()'s own docblock for why this needs a
        // separate seed on top of generateField()'s live-pick capture).
        // Stub places this directly after the loadedData merge with no
        // newline of its own (see form.stub) so the empty case introduces
        // zero extra characters -- a leading "\n" only appears when there's
        // an actual line to add.
        $fieldLabelsSeed = $hasFkFieldLabels ? $this->generateFieldLabelsSeedBlock($mappedFields) : '';
        $fieldLabelsSeedBlock = $fieldLabelsSeed !== '' ? "\n{$fieldLabelsSeed}" : '';

        // Conditional switching: only forms with a file-input field ever get
        // the FormData/sendFormDataRequest treatment -- everything else is
        // generated exactly as before (see generateRequestImportLine() /
        // generateFileRefsBlock() / generateSubmitCall()).
        $hasFileFields = $this->hasFileInputField($fieldsForSubmit);
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'edit');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'edit');

        // Splash plumbing is opt-in -- mirrors CreateFormGenerator's own fix, see that
        // file's identical comment for the full root cause: this used to check ONLY
        // constants, but RoutesGenerator.php requires BOTH constants non-empty AND
        // features.backend.editSplash to be set before it registers the
        // /{module}/edit/splash route this triggers a call to.
        $hasSplash = !empty($this->config['constants']) && !empty($this->config['features']['backend']['editSplash']);
        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $this->buildSplashBlocks('edit', $hasSplash);
        $draftBlocks = $this->buildDraftBlocks('edit', $hasDrafts);

        // Inline items — append form fields and imports, then generate the template block
        //
        // Bug (fixed 2026-08-03): same fix as CreateFormGenerator's
        // identical block -- see its comment for the full rationale. The
        // form no longer references InlineItemsComponent/InlineItemField
        // directly; it needs the wrapper component's own sibling import.
        $inlineItems = $this->config['inline_items'] ?? [];
        if (!empty($inlineItems)) {
            // generateFormFields() joins fields with ",\n" and never trails
            // the last one with a comma -- appending directly here produced
            // a hard Vue SFC compile error (missing "," between the last
            // regular field and the first inline_items field), confirmed
            // live 2026-08-08 for every module carrying `inline_items`
            // (broke Orders*Form.vue outright, and cascaded via Vite's
            // global HMR error overlay into unrelated modules' e2e runs).
            if ($formFields !== '') {
                $formFields = rtrim($formFields) . ',';
            }
            foreach ($inlineItems as $item) {
                $formFields .= "\n\t{$item['key']}: [] as any[],";
                $componentName = $this->inlineItemsWrapperComponentName($item['key']);
                $formFieldImports .= "\nimport {$componentName} from './{$componentName}.vue';";
            }
        }

        // Wizard mode already rendered any inline_items claimed by a step --
        // see CreateFormGenerator's identical block for the full rationale.
        $unclaimedInlineItems = $isWizard
            ? array_values(array_filter($inlineItems, fn ($item) => !in_array($item['key'], $claimedInlineItemKeys, true)))
            : $inlineItems;

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]'         => $formSections,
            '[[formFooter]]'           => $footer,
            '[[formFields]]'           => $formFields,
            '[[formFieldImports]]'     => $formFieldImports,
            '[[wizardStateBlock]]'     => $wizardStateBlock,
            '[[fieldLabelsSeedBlock]]' => $fieldLabelsSeedBlock,
            '[[splashPropBlock]]'      => $splashPropBlock,
            '[[splashBlock]]'          => $splashBlock,
            '[[refreshAndSetBlock]]'   => $refreshAndSetBlock,
            '[[onMountedBlock]]'       => $onMountedBlock,
            '[[inlineItemsBlock]]'     => $this->generateInlineItemsBlock($unclaimedInlineItems),
            '[[inlineItemsFieldDefs]]' => $this->generateInlineItemsFieldDefs($inlineItems),
            '[[confirmCheckboxBlock]]' => $confirmCheckboxBlock,
            '[[idParam]]'              => $idParam,
            '[[requestImportLine]]'    => $requestImportLine,
            '[[fileRefsBlock]]'        => $fileRefsBlock,
            '[[submitCall]]'           => $submitCall,
            '[[draftBannerBlock]]'        => $draftBlocks['draftBannerBlock'],
            '[[draftImports]]'            => $draftBlocks['draftImports'],
            '[[draftWatchImport]]'        => $draftBlocks['draftWatchImport'],
            '[[draftSetupBlock]]'         => $draftBlocks['draftSetupBlock'],
            '[[discardDraftOnSuccess]]'   => $draftBlocks['discardDraftOnSuccess'],
            '[[draftCheckBlock]]'         => $draftBlocks['draftCheckBlock'],
            '[[draftWatchBlock]]'         => $draftBlocks['draftWatchBlock'],
        ]);
        
        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName) 
            . "/Components/{$this->moduleName}EditForm.vue";
        
        return $this->writeFile($filePath, $content);
    }
}

