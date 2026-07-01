<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Reframes the promoted submission columns as a generic, searchable set:
 *
 *   primaryEmail              — the form's email field (search + privacy lookups)
 *   primaryName  → searchCol1 ┐
 *   source       → searchCol2 ├ three arbitrary, admin-mapped, searchable columns
 *   (new)          searchCol3 ┘
 *
 * Existing data is preserved by renaming the two legacy columns; promoted-field
 * map keys are migrated in FormSchemaService::normalize (so saved forms keep
 * working without edits).
 */
class m260611_000000_generic_search_columns extends Migration
{
    private const SUBMISSIONS = '{{%easyform_submissions}}';

    public function safeUp(): bool
    {
        if ($this->db->columnExists(self::SUBMISSIONS, 'primaryName')
            && !$this->db->columnExists(self::SUBMISSIONS, 'searchCol1')) {
            $this->renameColumn(self::SUBMISSIONS, 'primaryName', 'searchCol1');
        }
        if ($this->db->columnExists(self::SUBMISSIONS, 'source')
            && !$this->db->columnExists(self::SUBMISSIONS, 'searchCol2')) {
            $this->renameColumn(self::SUBMISSIONS, 'source', 'searchCol2');
        }
        if (!$this->db->columnExists(self::SUBMISSIONS, 'searchCol3')) {
            $this->addColumn(self::SUBMISSIONS, 'searchCol3', $this->string(255)->after('searchCol2'));
        }

        // Index the generic columns so exact-match filtering is cheap.
        foreach (['searchCol1', 'searchCol2', 'searchCol3'] as $col) {
            if ($this->db->columnExists(self::SUBMISSIONS, $col)) {
                $this->createIndex(null, self::SUBMISSIONS, $col);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::SUBMISSIONS, 'searchCol3')) {
            $this->dropColumn(self::SUBMISSIONS, 'searchCol3');
        }
        if ($this->db->columnExists(self::SUBMISSIONS, 'searchCol2')
            && !$this->db->columnExists(self::SUBMISSIONS, 'source')) {
            $this->renameColumn(self::SUBMISSIONS, 'searchCol2', 'source');
        }
        if ($this->db->columnExists(self::SUBMISSIONS, 'searchCol1')
            && !$this->db->columnExists(self::SUBMISSIONS, 'primaryName')) {
            $this->renameColumn(self::SUBMISSIONS, 'searchCol1', 'primaryName');
        }
        return true;
    }
}
