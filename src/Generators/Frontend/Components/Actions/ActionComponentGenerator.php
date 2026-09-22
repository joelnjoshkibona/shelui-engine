<?php

namespace Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator;
use Illuminate\Support\Str;

class ActionComponentGenerator extends BaseComponentGenerator
{
    public function generate(): bool
    {
        return false;
    }

    public function generateAction(string $actionKey, array $action): bool
    {
        if (empty($action['hasUI'])) {
            return false;
        }

        $uiType = $action['uiType'] ?? 'modal';

        $actionName = Str::studly($action['name'] ?? $actionKey);
        $actionLabel = $action['label'] ?? $actionName;
        $actionRoute = Str::kebab($action['name'] ?? $actionKey);
        $moduleRoute = Str::kebab($this->moduleName);

        // Real fields[] + optional multi-step wizard -- previously a
        // permanently-empty form = ref({}) with a "bind them into form" HTML
        // comment (Section05's own "Frontend Config" tab never persisted
        // anything here either, see that fix). Reuses the SAME
        // generateFormFields()/generateWizardSteps() building blocks
        // Create/Edit already use -- see form.stub's own docblock for why
        // this form's script-setup state (isSubmitting/errors/
        // isFieldDisabled/props.hiddens) matches their naming.
        $rawFields = $action['fields'] ?? [];
        $mappedFields = !empty($rawFields) ? $this->mapNewFormFieldsToLegacy($rawFields) : [];

        $wizardConfig = $action['wizard'] ?? [];
        $isWizard = ($wizardConfig['enabled'] ?? false) === true && !empty($wizardConfig['steps']);
        // Sibling to `wizard`, not nested in it -- see CreateFormGenerator's
        // identical comment for the full default-resolution rationale
        // (ON for wizards, OFF for a flat action's fields, either
        // overridable). Applies even to the fieldless branch below -- a
        // destructive/sensitive fieldless action (e.g. ForceResetPassword's
        // shape) can still opt in to a plain "yes, proceed" checkbox.
        $confirmStepConfig = $action['confirm_step'] ?? [];
        $requiresConfirmation = ($confirmStepConfig['enabled'] ?? $isWizard) === true;
        // Merge the RESOLVED decision back in -- see CreateFormGenerator's
        // identical comment for why the raw (possibly-empty) config can't be
        // passed through a ternary gate.
        $confirmStepConfig['enabled'] = $requiresConfirmation;
        $actionConfirmCheckboxBlock = '';

        if ($isWizard) {
            // No inline_items concept on actions today -- pass an empty
            // list; a step's field_keys can only reference `fields[]`.
            [$actionFieldsBlock, , $hasFkFieldLabels] = $this->generateWizardSteps($wizardConfig, $mappedFields, [], $confirmStepConfig);
            // Actions have no draft mechanism (unlike Create/Edit) --
            // $hasDrafts=false means goNext() never calls the undefined
            // saveDraft().
            $actionWizardStateBlock = $this->generateWizardStateBlock($wizardConfig, false, $confirmStepConfig, $hasFkFieldLabels);
            $actionWizardNavButtons = "<Button v-if=\"currentStep > 0\" type=\"button\" variant=\"outline\" size=\"sm\" @click=\"goBack\" :disabled=\"isSubmitting\">\n"
                . "\t\t\t\t{{ \$t('common.back') }}\n"
                . "\t\t\t</Button>\n\t\t\t"
                . "<Button v-if=\"currentStep < wizardSteps.length - 1\" type=\"button\" size=\"sm\" @click=\"goNext\" :disabled=\"isSubmitting\">\n"
                . "\t\t\t\t{{ \$t('common.next') }}\n"
                . "\t\t\t</Button>\n\t\t\t";
            $actionSubmitVIf = ' v-else';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
        } elseif (!empty($mappedFields)) {
            $actionFieldsBlock = "<div class=\"grid grid-cols-1 md:grid-cols-2 gap-4\">\n"
                . $this->generateFieldsGrid($mappedFields)
                . "\n\t\t</div>";
            $actionWizardStateBlock = $requiresConfirmation ? "const confirmed = ref(false)\n" : '';
            $actionWizardNavButtons = '';
            $actionSubmitVIf = '';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
            if ($requiresConfirmation) {
                $actionConfirmCheckboxBlock = "\n" . $this->generateConfirmCheckbox($confirmStepConfig);
            }
        } else {
            // No fields configured at all -- unchanged from the original
            // hand-written-fields stub shape.
            $actionFieldsBlock = '<!-- Add your form fields here — bind them into `form`. -->';
            $actionWizardStateBlock = $requiresConfirmation ? "const confirmed = ref(false)\n" : '';
            $actionWizardNavButtons = '';
            $actionSubmitVIf = '';
            $actionSubmitDisabled = $requiresConfirmation ? 'isSubmitting || !confirmed' : 'isSubmitting';
            if ($requiresConfirmation) {
                $actionConfirmCheckboxBlock = "\n" . $this->generateConfirmCheckbox($confirmStepConfig);
            }
        }

        $actionFormFields = $this->generateFormFields(['fields' => $mappedFields]);
        $actionFormFieldImports = $this->generateFormFieldImports(['fields' => $mappedFields]);
        if ($isWizard) {
            $actionFormFieldImports .= "\nimport { Stepper } from '@/components/ui/stepper';";
        }
        if ($requiresConfirmation && !str_contains($actionFormFieldImports, 'CheckboxField')) {
            $actionFormFieldImports .= "\nimport CheckboxField from '@/components/form-fields/CheckboxField.vue';";
        }

        $replacements = [
            '[[ActionName]]' => $actionName,
            '[[ActionLabel]]' => $actionLabel,
            '[[actionRoute]]' => "/{$moduleRoute}/{$actionRoute}",
            '[[actionCancelLink]]' => "/{$moduleRoute}/list",
            '[[actionEndpointExpr]]' => $this->buildEndpointExpression($action, $moduleRoute, $actionRoute),
            // shelui-engine fork: this module's own record-identifier prop
            // name -- 'uuid' when ModuleConfigContract::hasUuid(), else 'id'
            // (see idParam()'s docblock, BaseComponentGenerator). form.stub/
            // page.stub used to hardcode a `uuid` prop/route-param
            // unconditionally; the backend's already-fixed
            // ActionServiceGenerator::routeKeyParam() established
            // `urlParams: ['id']` as the correct config convention for a
            // has_uuid: false module's action -- buildEndpointExpression()
            // below turns that into `${props.id}`, which only resolves if
            // the Form actually declares an `id` prop. This is the frontend
            // half of that same fix.
            '[[idParam]]' => $this->idParam(),
            '[[actionFieldsBlock]]' => $actionFieldsBlock,
            '[[actionConfirmCheckboxBlock]]' => $actionConfirmCheckboxBlock,
            '[[actionFormFields]]' => $actionFormFields,
            '[[actionFormFieldImports]]' => $actionFormFieldImports,
            '[[actionWizardStateBlock]]' => $actionWizardStateBlock,
            '[[actionWizardNavButtons]]' => $actionWizardNavButtons,
            '[[actionSubmitVIf]]' => $actionSubmitVIf,
            '[[actionSubmitDisabled]]' => $actionSubmitDisabled,
        ];

        // The Form is ALWAYS generated, whatever uiType says. It owns the
        // fields and the submit; the container is what uiType selects. Emitting
        // a separate Modal.vue and Page.vue meant the hand-written fields lived
        // in whichever file the current uiType happened to produce, so flipping
        // modal <-> page silently orphaned them and started from a blank stub.
        // This mirrors the CRUD convention: one {Module}CreateForm.vue with a
        // `modal` prop, rendered either inside an AppDialog or by a page shell.
        //
        // writeFileOnce(), not writeFile() -- same reasoning as
        // ActionServiceGenerator's own writeFileOnce() switch: an action's
        // form routinely needs hand-added logic (a custom sub-component for
        // a step's content, bespoke validation, etc) that today's fields[]/
        // wizard config can't express, and plain writeFile() force-overwrites
        // on every regenerate, silently discarding it.
        $formWritten = $this->writeFileOnce(
            "{$this->modulePath}/Components/{$this->moduleName}{$actionName}Form.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/form', 'frontend'), $replacements)
        );

        if ($uiType !== 'page') {
            return $formWritten;
        }

        // Page container: a thin shell around the same Form.
        $pageWritten = $this->writeFileOnce(
            "{$this->modulePath}/{$this->moduleName}{$actionName}Page.vue",
            $this->replacePlaceholders($this->getTemplateContent('features/action/page', 'frontend'), $replacements)
        );

        return $formWritten || $pageWritten;
    }

    /**
     * Build the JS template-literal the component POSTs to.
     *
     * MUST mirror RoutesGenerator::generateActionRoutes(): the component used to
     * hard-code `/{module}/{action}/create`, a path the backend never
     * registers, so the emitted UI could not have worked even once it was
     * wired up. Laravel's `{param}` placeholders become `${props.param}` --
     * purely config-driven (`$action['urlParams']`), never a hardcoded 'uuid'
     * here. When this module's own record is the target, the config's
     * urlParams entry should name whatever $this->idParam() resolves to
     * ('uuid' or 'id' -- shelui-engine fork), matching the `[[idParam]]`
     * prop form.stub/page.stub now declare instead of always `props.uuid`.
     */
    protected function buildEndpointExpression(array $action, string $moduleRoute, string $actionRoute): string
    {
        $operations = $action['operations'] ?? [];
        $path = '';
        $matchedOp = null;

        foreach (['create', 'edit', 'view', 'delete', 'list'] as $op) {
            if (!empty($operations[$op]['enabled'])) {
                $matchedOp = $op;
                $path = $operations[$op]['endpoint']['path'] ?? '';
                break;
            }
        }

        if ($path === '' && $matchedOp !== null) {
            // MUST mirror RoutesGenerator::generateActionRoutes()'s own default
            // path shape ("/{module}/{action}/{params}/{op}"). This used to
            // independently derive "/{module}/{params}/{action}" — a different
            // segment order the backend never registers, guaranteeing a 404
            // for every action that leaves endpoint.path unset.
            $urlParams = $action['urlParams'] ?? [];
            $paramsPath = $urlParams === []
                ? ''
                : '/' . implode('/', array_map(static fn ($p): string => '{' . $p . '}', $urlParams));
            $path = "/{$moduleRoute}/{$actionRoute}{$paramsPath}/{$matchedOp}";
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return preg_replace('/\{(\w+)\}/', '\${props.$1}', $path);
    }
}
