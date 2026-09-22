<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class EditServiceGenerator extends BaseServiceGenerator
{
    public function generate(): bool
    {
        $backendConfig = $this->config['features']['backend']['edit'] ?? null;
        if (empty($backendConfig)) {
            return false; // Feature not enabled
        }

        $content = $this->getTemplateContent('Features/edit/service', 'backend');

        // Combine legacy field-level processing with processor-array logic.
        // File-column uploads run first -- see the matching comment in
        // CreateServiceGenerator::generate() and
        // BaseServiceGenerator::generateFileColumnUploads()'s docblock for the
        // full wire-key contract and the edit-specific optional-reupload
        // behaviour (unset when no new file was sent, leaving the model's
        // existing media_id column untouched).
        $fileUploads = $this->generateFileColumnUploads(true);
        $beforeLegacy = $this->generateCustomFieldProcessing('edit', 'before');
        $beforeProcessors = $this->generateProcessorCalls('edit', 'before_save');
        $beforeUpdate = trim("{$fileUploads}\n{$beforeLegacy}\n{$beforeProcessors}", "\n");
        if (empty($beforeUpdate)) {
            $beforeUpdate = '// No custom field processing';
        }

        $afterLegacy = $this->generateCustomFieldProcessing('edit', 'after');
        $afterProcessors = $this->generateProcessorCalls('edit', 'after_save');
        $afterUpdate = trim("{$afterLegacy}\n{$afterProcessors}", "\n");
        if (empty($afterUpdate)) {
            $afterUpdate = '// No custom field processing';
        }

        $replacements = [
            '[[validationRules]]'    => $this->generateValidationRules(true),
            '[[validationMessages]]' => $this->generateValidationMessages(true),
            '[[updatedByAssignment]]' => $this->generateUpdatedByAssignment(),
            '[[beforeUpdate]]'       => $beforeUpdate,
            '[[afterUpdate]]'        => $afterUpdate,
            '[[inlineItemsExtract]]' => $this->generateInlineItemsExtract(),
            '[[inlineItemsSync]]'    => $this->generateInlineItemsSync(),
        ];

        $content = $this->replacePlaceholders($content, $replacements);

        $serviceName = $this->moduleName . 'EditService';
        $filePath = "{$this->modulePath}/Services/{$serviceName}.php";

        return $this->writeFile($filePath, $content);
    }

    /**
     * shelui-engine fork: mirrors CreateServiceGenerator::
     * generateCreatedByAssignment() — column name from
     * ModuleConfigContract::creatorUpdaterColumns(), and emits nothing at
     * all for a single-actor module (creator-only, no updated_by column —
     * assigning to a nonexistent column here would either 500 or be
     * silently dropped depending on the app's mass-assignment strictness).
     */
    private function generateUpdatedByAssignment(): string
    {
        if (!ModuleConfigContract::hasCreatorUpdater($this->config)) {
            return '';
        }

        $column = ModuleConfigContract::creatorUpdaterColumns($this->config)['updated'];
        if ($column === null) {
            return '';
        }

        return "\$validData['{$column}'] = Auth::id();";
    }

    private function generateInlineItemsSync(): string
    {
        $inlineItems = $this->config['inline_items'] ?? [];
        if (empty($inlineItems)) {
            return '';
        }

        $blocks = [];
        foreach ($inlineItems as $item) {
            $key         = $item['key'];
            $parentFk    = $item['parent_fk'];
            $childNs     = $this->buildChildNamespace($item['child_module']);
            $modelClass  = "\\{$childNs}\\{$item['child_module']}Model";
            // Separate arrays, not one shared $_inject: a row created here
            // (no uuid yet) needs created_by_id, while an existing row being
            // updated needs updated_by_id -- sharing one array would
            // silently overwrite created_by_id on every edit of an
            // already-existing child row.
            $createInject = $this->buildInlineInjectArray($item, 'created');
            $updateInject = $this->buildInlineInjectArray($item, 'updated');

            // A uuid in the payload names a row of THIS parent only. This used to be
            // `updateOrCreate(['uuid' => $_uuid], [... parent_fk => $model->id])`, which matched on the
            // uuid alone: a client that sent another parent's child uuid overwrote that row AND
            // re-parented it to the record being edited (an IDOR across parents, and across locations
            // when the parent is location-scoped). Now the lookup is scoped to the parent, and a uuid
            // that is not one of its rows is treated as a new row -- never adopted.
            $blocks[] = implode("\n        ", [
                "// Sync {$key}",
                "\$_existingUuids = collect(\$inlineData['{$key}'] ?? [])->pluck('uuid')->filter()->values()->all();",
                "{$modelClass}::where('{$parentFk}', \$model->id)->whereNotIn('uuid', \$_existingUuids)->delete();",
                "foreach (\$inlineData['{$key}'] ?? [] as \$inlineItem) {",
                "    \$_uuid = \$inlineItem['uuid'] ?? null;",
                "    unset(\$inlineItem['uuid']);",
                "    \$_child = \$_uuid ? {$modelClass}::where('uuid', \$_uuid)->where('{$parentFk}', \$model->id)->first() : null;",
                "    if (\$_child) {",
                "        \$_child->update(array_merge(\$inlineItem, {$updateInject}));",
                "    } else {",
                "        {$modelClass}::create(array_merge(\$inlineItem, {$createInject}));",
                "    }",
                "}",
            ]);
        }

        return implode("\n        ", $blocks);
    }
}

