<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Brings existing installs up to the authoritative schema defined in
 * {@see Install}.
 *
 * Because the plugin was never published, this migration consolidates all of
 * the earlier drifted migrations into a single corrective step:
 *
 *  - renames `formbuilder_*` tables to `easyform_*`
 *  - drops obsolete columns (`honeypotEnabled`, `isRead`)
 *  - adds promoted metadata + snapshot columns
 *  - makes `formId`/`siteId` nullable and rebuilds foreign keys as SET NULL
 *  - adds the compound indexes used by high-volume listing/export
 *
 * Every step is guarded so the migration is safe regardless of which prior
 * state an install happened to be in.
 */
class m260528_000000_rename_and_upgrade_schema extends Migration
{
    private const FORMS = '{{%easyform_forms}}';
    private const SUBMISSIONS = '{{%easyform_submissions}}';

    public function safeUp(): bool
    {
        // 1. Rename legacy tables ------------------------------------------------
        if ($this->db->tableExists('{{%formbuilder_forms}}') && !$this->db->tableExists(self::FORMS)) {
            $this->renameTable('{{%formbuilder_forms}}', self::FORMS);
        }
        if ($this->db->tableExists('{{%formbuilder_submissions}}') && !$this->db->tableExists(self::SUBMISSIONS)) {
            $this->renameTable('{{%formbuilder_submissions}}', self::SUBMISSIONS);
        }

        // Nothing to upgrade (e.g. a brand new install handled by Install.php).
        if (!$this->db->tableExists(self::FORMS) || !$this->db->tableExists(self::SUBMISSIONS)) {
            return true;
        }

        // 2. Forms: drop obsolete columns ---------------------------------------
        $this->efDropColumn(self::FORMS, 'honeypotEnabled');
        $this->efDropColumn(self::FORMS, 'isRead');

        // 3. Submissions: drop obsolete columns ---------------------------------
        $this->efDropColumn(self::SUBMISSIONS, 'isRead');

        // 4. Submissions: add new columns ---------------------------------------
        $this->efAddColumn(self::SUBMISSIONS, 'formHandle', $this->string(255)->after('formId'));
        $this->efAddColumn(self::SUBMISSIONS, 'formName', $this->string(255)->after('formHandle'));
        $this->efAddColumn(self::SUBMISSIONS, 'fieldSnapshot', $this->json()->after('data'));
        $this->efAddColumn(self::SUBMISSIONS, 'primaryEmail', $this->string(255)->after('fieldSnapshot'));
        $this->efAddColumn(self::SUBMISSIONS, 'primaryName', $this->string(255)->after('primaryEmail'));
        $this->efAddColumn(self::SUBMISSIONS, 'source', $this->string(255)->after('primaryName'));
        $this->efAddColumn(self::SUBMISSIONS, 'isTest', $this->boolean()->defaultValue(false)->notNull());
        $this->efAddColumn(self::SUBMISSIONS, 'dateDeleted', $this->dateTime()->null());

        // 5. Rebuild foreign keys as SET NULL + make referencing columns nullable.
        $this->alterColumn(self::SUBMISSIONS, 'formId', $this->integer()->null());
        $this->alterColumn(self::SUBMISSIONS, 'siteId', $this->integer()->null());
        $this->efDropForeignKeys(self::SUBMISSIONS);
        $this->addForeignKey(null, self::SUBMISSIONS, 'formId', self::FORMS, 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, self::SUBMISSIONS, 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, self::SUBMISSIONS, 'siteId', '{{%sites}}', 'id', 'SET NULL', 'CASCADE');

        // 6. Add compound + promoted indexes ------------------------------------
        $this->efCreateIndex(self::SUBMISSIONS, ['primaryEmail']);
        $this->efCreateIndex(self::SUBMISSIONS, ['dateDeleted']);
        $this->efCreateIndex(self::SUBMISSIONS, ['formId', 'dateCreated']);
        $this->efCreateIndex(self::SUBMISSIONS, ['formId', 'status', 'dateCreated']);
        $this->efCreateIndex(self::SUBMISSIONS, ['siteId', 'dateCreated']);
        $this->efCreateIndex(self::SUBMISSIONS, ['status', 'dateCreated']);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260528_000000_rename_and_upgrade_schema cannot be reverted.\n";
        return false;
    }

    // Helpers -------------------------------------------------------------------

    private function efDropColumn(string $table, string $column): void
    {
        if ($this->db->columnExists($table, $column)) {
            $this->dropColumn($table, $column);
        }
    }

    private function efAddColumn(string $table, string $column, $type): void
    {
        if (!$this->db->columnExists($table, $column)) {
            $this->addColumn($table, $column, $type);
        }
    }

    private function efDropForeignKeys(string $table): void
    {
        $schema = $this->db->getTableSchema($table, true);
        if ($schema === null) {
            return;
        }
        foreach (array_keys($schema->foreignKeys) as $name) {
            $this->dropForeignKey($name, $table);
        }
    }

    private function efCreateIndex(string $table, array $columns): void
    {
        $rawName = $this->db->getSchema()->getRawTableName($table);
        $existing = $this->db->getSchema()->getTableIndexes($rawName);
        foreach ($existing as $index) {
            if ($index->columnNames === $columns) {
                return;
            }
        }
        $this->createIndex(null, $table, $columns);
    }
}
