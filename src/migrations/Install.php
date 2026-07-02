<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Install migration.
 *
 * This is the authoritative final schema for Easy Form. New installs create
 * exactly this structure. Existing installs that still have the legacy
 * `formbuilder_*` tables are brought up to date by
 * {@see m260528_000000_rename_and_upgrade_schema}.
 */
class Install extends Migration
{
    public const FORMS_TABLE = '{{%easyform_forms}}';
    public const SUBMISSIONS_TABLE = '{{%easyform_submissions}}';
    public const EXPORTS_TABLE = '{{%easyform_exports}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // If the legacy tables exist, the upgrade migration is responsible for
        // transforming them. Don't recreate over the top of them here.
        if ($this->db->tableExists('{{%formbuilder_forms}}')) {
            return true;
        }

        if (!$this->db->tableExists(self::FORMS_TABLE)) {
            $this->createFormsTable();
        }

        if (!$this->db->tableExists(self::SUBMISSIONS_TABLE)) {
            $this->createSubmissionsTable();
            $this->createSubmissionsIndexes();
            $this->addForeignKeys();
        }

        if (!$this->db->tableExists(self::EXPORTS_TABLE)) {
            $this->createExportsTable();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // If opted in, erase uploaded files (filesystem files + asset-mode
        // Assets) before the submissions table is dropped. Best-effort: never
        // let file cleanup block the uninstall itself.
        try {
            $plugin = \yannkost\easyform\EasyForm::getInstance();
            if ($plugin && $plugin->getSettings()->deleteUploadedFilesOnUninstall) {
                $plugin->submissions->deleteAllUploadedFiles();
            }
        } catch (\Throwable $e) {
            \yannkost\easyform\EasyForm::log('Uninstall file cleanup skipped: ' . $e->getMessage(), 'warning');
        }

        $this->dropTableIfExists(self::EXPORTS_TABLE);
        $this->dropTableIfExists(self::SUBMISSIONS_TABLE);
        $this->dropTableIfExists(self::FORMS_TABLE);

        return true;
    }

    public function createFormsTable(): void
    {
        $this->createTable(self::FORMS_TABLE, [
            'id' => $this->primaryKey(),

            // Identification
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text(),

            // JSON configuration
            'fieldLayout' => $this->json()->notNull(),
            'settings' => $this->json(),
            'notificationSettings' => $this->json(),

            // Behavior
            'enabled' => $this->boolean()->defaultValue(true)->notNull(),
            'successMessage' => $this->text(),
            'redirectUrl' => $this->string(255),
            'hideFormOnSuccess' => $this->boolean()->defaultValue(false)->notNull(),
            'keepSuccessMessage' => $this->boolean()->defaultValue(true)->notNull(),
            'successMessageDuration' => $this->integer()->defaultValue(5)->notNull(),

            // Limits & spam
            'maxSubmissionsPerUser' => $this->integer(),
            'rateLimit' => $this->integer()->defaultValue(0)->notNull(),
            'rateLimitWindow' => $this->integer()->defaultValue(60)->notNull(),
            'saveSpamSubmissions' => $this->boolean()->defaultValue(false)->notNull(),
            'autoApprove' => $this->boolean()->defaultValue(true)->notNull(),
            'captchaProvider' => $this->string(64),
            'captchaScoreThreshold' => $this->decimal(3, 2),
            'rejectOnCaptchaFail' => $this->boolean()->defaultValue(false)->notNull(),

            // Behavior flags
            'allowUrlPrefill' => $this->boolean()->defaultValue(false)->notNull(),
            'showStepIndicator' => $this->boolean()->defaultValue(false)->notNull(),
            'validateSteps' => $this->boolean()->defaultValue(true)->notNull(),

            // Webhook
            'webhookUrl' => $this->string(500),
            'webhookPayload' => $this->string(16)->defaultValue('full')->notNull(),

            // Craft standard
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::FORMS_TABLE, 'handle', true);
        $this->createIndex(null, self::FORMS_TABLE, 'enabled');
    }

    public function createSubmissionsTable(): void
    {
        $this->createTable(self::SUBMISSIONS_TABLE, [
            'id' => $this->primaryKey(),

            // Form linkage + snapshot metadata (preserved after form deletion)
            'formId' => $this->integer()->null(),
            'formHandle' => $this->string(255),
            'formName' => $this->string(255),

            // Site
            'siteId' => $this->integer()->null(),

            // Canonical JSON payload + field snapshot
            'data' => $this->json()->notNull(),
            'fieldSnapshot' => $this->json(),

            // Promoted, searchable columns: the email field (also used for privacy
            // lookups) + three arbitrary, admin-mapped columns.
            'primaryEmail' => $this->string(255),
            'searchCol1' => $this->string(255),
            'searchCol2' => $this->string(255),
            'searchCol3' => $this->string(255),

            // User / request metadata
            'userId' => $this->integer(),
            'ipAddress' => $this->string(45),
            'userAgent' => $this->text(),

            // Status & spam
            'status' => $this->enum('status', ['pending', 'approved', 'spam', 'archived'])
                ->defaultValue('pending')
                ->notNull(),
            'spamScore' => $this->decimal(3, 2),
            'captchaScore' => $this->decimal(3, 2),
            'honeypotValue' => $this->string(255),
            'isTest' => $this->boolean()->defaultValue(false)->notNull(),

            // Craft standard + soft delete
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);
    }

    public function createSubmissionsIndexes(): void
    {
        $table = self::SUBMISSIONS_TABLE;

        $this->createIndex(null, $table, 'formId');
        $this->createIndex(null, $table, 'siteId');
        $this->createIndex(null, $table, 'userId');
        $this->createIndex(null, $table, 'status');
        $this->createIndex(null, $table, 'dateCreated');
        $this->createIndex(null, $table, 'primaryEmail');
        $this->createIndex(null, $table, 'searchCol1');
        $this->createIndex(null, $table, 'searchCol2');
        $this->createIndex(null, $table, 'searchCol3');
        $this->createIndex(null, $table, 'dateDeleted');

        // Compound indexes for common CP/export filters, sorted by dateCreated DESC, id DESC.
        $this->createIndex(null, $table, ['formId', 'dateCreated']);
        $this->createIndex(null, $table, ['formId', 'status', 'dateCreated']);
        $this->createIndex(null, $table, ['siteId', 'dateCreated']);
        $this->createIndex(null, $table, ['status', 'dateCreated']);
    }

    public function addForeignKeys(): void
    {
        $table = self::SUBMISSIONS_TABLE;

        $this->addForeignKey(null, $table, 'formId', self::FORMS_TABLE, 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, $table, 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, $table, 'siteId', '{{%sites}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function createExportsTable(): void
    {
        $this->createTable(self::EXPORTS_TABLE, [
            'id' => $this->primaryKey(),
            'token' => $this->string(64)->notNull(),
            'formId' => $this->integer()->null(),
            'formName' => $this->string(255),
            'filters' => $this->json()->null(),
            'filename' => $this->string(255)->notNull(),
            'rowCount' => $this->integer()->null(),
            'fileSize' => $this->bigInteger()->null(),
            'status' => $this->enum('status', ['queued', 'ready', 'failed'])->defaultValue('queued')->notNull(),
            'message' => $this->text(),
            'keep' => $this->boolean()->defaultValue(false)->notNull(),
            'dateExpires' => $this->dateTime()->null(),
            'createdBy' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::EXPORTS_TABLE, 'token', true);
        $this->createIndex(null, self::EXPORTS_TABLE, 'formId');
        $this->createIndex(null, self::EXPORTS_TABLE, 'keep');
        $this->createIndex(null, self::EXPORTS_TABLE, 'dateExpires');
        $this->createIndex(null, self::EXPORTS_TABLE, 'createdBy');
        $this->createIndex(null, self::EXPORTS_TABLE, 'dateCreated');

        $this->addForeignKey(null, self::EXPORTS_TABLE, 'formId', self::FORMS_TABLE, 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, self::EXPORTS_TABLE, 'createdBy', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }
}
