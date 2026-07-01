<?php

namespace yannkost\easyform\migrations;

use craft\db\Migration;

/**
 * Adds the exports bookkeeping table (one row per queued CSV export). Kept
 * exports persist here until the user deletes them; ephemeral ones are
 * garbage-collected once past their dateExpires.
 */
class m260701_000000_create_exports_table extends Migration
{
    private const TABLE = '{{%easyform_exports}}';
    private const FORMS_TABLE = '{{%easyform_forms}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
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

        $this->createIndex(null, self::TABLE, 'token', true);
        $this->createIndex(null, self::TABLE, 'formId');
        $this->createIndex(null, self::TABLE, 'keep');
        $this->createIndex(null, self::TABLE, 'dateExpires');
        $this->createIndex(null, self::TABLE, 'createdBy');
        $this->createIndex(null, self::TABLE, 'dateCreated');

        $this->addForeignKey(null, self::TABLE, 'formId', self::FORMS_TABLE, 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, self::TABLE, 'createdBy', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
