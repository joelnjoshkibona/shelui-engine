<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\PathManager;

class CreateFormGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        $frontendConfig = $this->config['features']['frontend']['create'] ?? null;
        if (empty($frontendConfig)) {
            return false;
        }

        $content = $this->getTemplateContent('features/create/form', 'frontend');

        // Prefer new payload fields for create
        $createConfig = $this->config['features']['frontend']['create'] ?? [];
        $frontendConfig = $this->config;

        $formSections = '';
        $formFields = '';
        $formFieldImports = '';
        $fieldsForSubmit = [];

        // Draft autosave is on by default -- opt out via
        // features.frontend.create.drafts: false in module.json.
        $hasDrafts = ($createConfig['drafts'] ?? true) !== false;

        // Wizard mode: optional, additive, opt-in via features.frontend.create.wizard.enabled.
        // See BaseComponentGenerator::generateWizardSteps()'s docblock for the full design.
        $wizardConfig = $createConfig['wizard'] ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        // Sibling to `wizard`, not nested in it -- confirm_step applies to
        // flat forms too. Defaults differ by shape: ON for wizards (multiple
        // steps mean the user never sees everything at once -- a review
        // earns its keep), OFF for flat forms (everything's already on one
        // screen; forcing a checkbox onto every 2-field edit just becomes
        // something people stop reading). Either shape can override via an
        // explicit confirm_step.enabled.
        $confirmStepConfig = $createConfig['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        // Merge the RESOLVED decision back in -- generateWizardSteps()/
        // generateWizardStateBlock() each re-check ['enabled'] internally
        // (?? false), so passing the original, still-empty $confirmStepConfig
        // through a `$requiresConfirmation ? $confirmStepConfig : []` gate
        // silently lost an ON-by-default decision the moment the config key
        // was omitted (empty array in, empty array out, internal check sees
        // no 'enabled' key and defaults to false again). Explicit > implicit.
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $claimedInlineItemKeys = [];
        // $hasFkFieldLabels is only known once generateWizardSteps() runs
        // (inside the fields branch below) -- $wizardStateBlock is computed
        // after that, not here.
        $hasFkFieldLabels = false;
        $confirmCheckboxBlock = (!$isWizard && $requiresConfirmation) ? $this->generateFlatConfirmBlock($confirmStepConfig) : '';

        $footer = $this->generateFormFooter('create', $hasDrafts, $isWizard, $requiresConfirmation);

        // $footer is emitted as its own [[formFooter]] token (see below), placed
        // AFTER [[inlineItemsBlock]] in the stub -- NOT baked into
        // generateFormSection()/generateFormSections()'s own returned HTML.
        // Baking it in put the Save Draft/Create buttons inside the "Main
        // Details" card itself, so the Items card (inline_items) — spliced in
        // as a later sibling — rendered visually BELOW the submit buttons.
        // Confirmed live 2026-08-16 on a real Expenses create page. Passing ''
        // here (rather than $footer) still pins the footer correctly at the
        // bottom when `modal===true`: form.stub wraps [[draftBannerBlock]],
        // [[formSections]] and [[inlineItemsBlock]] together in one
        // `flex-1 min-h-0 overflow-y-auto` region, with [[formFooter]] as a
        // `shrink-0` sibling after it -- so modal forms WITH inline_items
        // (e.g. Expenses' Create modal, confirmed live 2026-08-17) get a
        // single shared scrollbar for the whole body instead of the fields
        // section collapsing into its own tiny scrollbox. See
        // generateFormSection()'s docblock for the fix's full history.
        if (!empty($createConfig['fields']) && is_array($createConfig['fields'])) {
            $mappedFields = $this->mapNewFormFieldsToLegacy($createConfig['fields']);
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
            $fallbackFields = $this->generateFieldsFromColumns($this->config, 'create');
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

        // shelui-engine fork: this module's own record-identifier field name
        // on the freshly-created record -- 'uuid' when
        // ModuleConfigContract::hasUuid(), else 'id' (see
        // BaseComponentGenerator::idParam()'s docblock). create/form.stub's
        // handleCreated() used to read response.data.uuid unconditionally to
        // build the post-create redirect (`/{route}/${uuid}/details`) -- for
        // a has_uuid: false module the create response never has a `uuid`
        // key at all, so that read was always undefined and the redirect
        // silently fell back to cancelLink instead of opening the new
        // record's own details page (the route FrontendRoutesGenerator
        // actually registers for such a module is `:id/details`).
        $idParam = $this->idParam();

        // Conditional switching: only forms with a file-input field ever get
        // the FormData/sendFormDataRequest treatment -- everything else is
        // generated exactly as before (see generateRequestImportLine() /
        // generateFileRefsBlock() / generateSubmitCall()).
        $hasFileFields = $this->hasFileInputField($fieldsForSubmit);
        $requestImportLine = $this->generateRequestImportLine($hasFileFields, 'create');
        $fileRefsBlock = $this->generateFileRefsBlock($this->extractFileInputFields($fieldsForSubmit));
        $submitCall = $this->generateSubmitCall($fieldsForSubmit, 'create');

        // create -> view by default (unconditional): the generated route for
        // a module's own View is `/{moduleRoute}/{uuid}/details` -- but only
        // emit that redirect when this module actually has a View page at
        // all (features.frontend.view enabled). Otherwise fall back to the
        // pre-existing cancelLink-based redirect (back to list), same as a
        // module with no View mounted in a modal context has no view to
        // open either -- see CrudListPanel.vue's onCreated() for the modal
        // side of this same default.
        $viewEnabledForCreateRedirect = !empty($this->config['features']['frontend']['view']) ? 'true' : 'false';

        // Splash plumbing is opt-in -- but this used to check ONLY $config['constants'],
        // while RoutesGenerator.php (which decides whether the backend ACTUALLY
        // registers the /{module}/create/splash route the network call this triggers
        // hits) requires BOTH constants non-empty AND features.backend.createSplash to be
        // set (RoutesGenerator.php:30-36). `constants` is now populated on nearly every
        // module (every activate/deactivate action needs it, via the Step 9-documented
        // status_target mechanism -- see scaffold-domain.md), while real createSplash
        // pre-load config is comparatively rare, so this mismatch meant almost every
        // Create form in a real project called an endpoint the backend never registered
        // on mount, producing a 404 masked behind a generic "Failed to load form data"
        // toast on every single page load. Confirmed live 2026-08-21: every module across
        // an entire project's e2e suite hit this, none of it new -- present even in the
        // last fully-green run, just never surfaced as a failure since refreshAndSet()'s
        // own error branch never throws.
        $hasSplash = !empty($this->config['constants']) && !empty($this->config['features']['backend']['createSplash']);
        [$splashPropBlock, $splashBlock, $refreshAndSetBlock, $onMountedBlock] = $this->buildSplashBlocks('create', $hasSplash);
        $draftBlocks = $this->buildDraftBlocks('create', $hasDrafts);

        // Inline items — append form fields and imports, then generate the template block
        //
        // Bug (fixed 2026-08-03): this used to unconditionally import the
        // shared InlineItemsComponent + InlineItemField type directly into
        // the generated form -- stale since generateInlineItemsBlock()
        // (v2.23.0) started emitting a hand-edit-protected wrapper
        // component per item and rendering <{componentName}> instead of
        // <InlineItemsComponent> directly. The form no longer references
        // either import; it needs the wrapper's own sibling import
        // instead, one per item (mirrors generateFormFieldImports()'s
        // per-field inline-items case, which already got this fix).
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

        // Wizard mode already rendered any inline_items claimed by a step
        // (inside [[formSections]] itself, via generateWizardSteps()) --
        // only UNCLAIMED items (an author forgot to assign to a step) still
        // go through the old catch-all [[inlineItemsBlock]] placeholder, so
        // nothing renders twice and nothing silently vanishes either.
        $unclaimedInlineItems = $isWizard
            ? array_values(array_filter($inlineItems, fn ($item) => !in_array($item['key'], $claimedInlineItemKeys, true)))
            : $inlineItems;

        $content = $this->replacePlaceholders($content, [
            '[[formSections]]'         => $formSections,
            '[[formFooter]]'           => $footer,
            '[[formFields]]'           => $formFields,
            '[[formFieldImports]]'     => $formFieldImports,
            '[[wizardStateBlock]]'     => $wizardStateBlock,
            '[[splashPropBlock]]'      => $splashPropBlock,
            '[[splashBlock]]'          => $splashBlock,
            '[[refreshAndSetBlock]]'   => $refreshAndSetBlock,
            '[[onMountedBlock]]'       => $onMountedBlock,
            '[[inlineItemsBlock]]'     => $this->generateInlineItemsBlock($unclaimedInlineItems),
            '[[inlineItemsFieldDefs]]' => $this->generateInlineItemsFieldDefs($inlineItems),
            '[[confirmCheckboxBlock]]' => $confirmCheckboxBlock,
            '[[viewEnabledForCreateRedirect]]' => $viewEnabledForCreateRedirect,
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
            '[[draftContextProp]]'        => $draftBlocks['draftContextProp'],
        ]);

        $filePath = PathManager::getFrontendModulePath($this->moduleGroup, $this->moduleName)
            . "/Components/{$this->moduleName}CreateForm.vue";

        return $this->writeFile($filePath, $content);
    }
}

