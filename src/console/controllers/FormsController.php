<?php

namespace yannkost\easyform\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use yannkost\easyform\EasyForm;
use yii\console\ExitCode;

/**
 * Manages Easy Form forms from the command line.
 */
class FormsController extends Controller
{
    /**
     * @var bool Show what would change without writing anything.
     */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if ($actionID === 'resave') {
            $options[] = 'dryRun';
        }
        return $options;
    }

    /**
     * Re-saves every form with its field layout normalized to the current
     * schema version, baking any lazy (on-read) migrations into the stored
     * JSON. Lets you confidently ship structural schema updates — and, once all
     * forms are flushed forward, eventually retire old migration code.
     *
     * Idempotent: forms already at the current shape are left untouched.
     *
     * Usage:
     *   php craft easy-form/forms/resave
     *   php craft easy-form/forms/resave --dry-run
     */
    public function actionResave(): int
    {
        $forms = EasyForm::getInstance()->forms->getAllForms();
        $schema = EasyForm::getInstance()->formSchema;

        if (empty($forms)) {
            $this->stdout("No forms to resave.\n");
            return ExitCode::OK;
        }

        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($forms as $form) {
            $stored = $form->getFieldLayoutArray();
            $normalized = $schema->normalize($stored);

            // Loose comparison ignores key order, so a form is only rewritten
            // when its content actually differs from the normalized shape.
            if ($stored == $normalized) {
                $skipped++;
                continue;
            }

            $fromVersion = $schema->detectVersion($stored);
            $label = "“{$form->name}” (#{$form->id}, v{$fromVersion} → v" . $schema::CURRENT_VERSION . ')';

            if ($this->dryRun) {
                $this->stdout("[dry run] would resave {$label}\n");
                $migrated++;
                continue;
            }

            $form->fieldLayout = $normalized;

            // Skip validation: this is a forward-migration of already-stored
            // forms, which must succeed even if a legacy form would no longer
            // pass today's authoring rules.
            if (EasyForm::getInstance()->forms->saveForm($form, false)) {
                $this->stdout("Resaved {$label}\n", Console::FG_GREEN);
                $migrated++;
            } else {
                $this->stderr("Failed to resave {$label}\n", Console::FG_RED);
                $failed++;
            }
        }

        $verb = $this->dryRun ? 'would be resaved' : 'resaved';
        $this->stdout("\nDone. {$migrated} {$verb}, {$skipped} already current"
            . ($failed ? ", {$failed} failed" : '') . ".\n");

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
