<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\models\Form;
use yannkost\easyform\services\Submissions;

require_once dirname(__DIR__, 2) . '/src/services/Submissions.php';

/**
 * Guards the export column model: real form fields are offered (default on),
 * metadata is opt-in, and the promoted Email/Search copies never reappear as
 * columns (they only duplicate real fields).
 */
final class ExportColumnsTest extends TestCase
{
    private Submissions $service;

    protected function setUp(): void
    {
        $this->service = new Submissions();
    }

    private function makeForm(): Form
    {
        $form = new Form();
        $form->handle = 'contact';
        $form->name = 'Contact';
        $form->layout = [
            'schemaVersion' => 3,
            'extraFieldPolicy' => 'allowListed',
            'pages' => [['id' => 'p', 'rows' => [['id' => 'r', 'fields' => [
                ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'handle' => 'name'],
                ['id' => 'f2', 'type' => 'email', 'label' => 'Email', 'handle' => 'email'],
            ]]]]],
            'frontendFields' => [
                ['handle' => 'utm_source', 'label' => 'UTM', 'type' => 'string'],
                ['handle' => 'products', 'label' => 'Products', 'type' => 'array', 'multiple' => true, 'maxItems' => 2],
            ],
            // A promotion IS configured — the copies must still not be offered.
            'promotedFields' => ['primaryEmail' => 'email', 'searchCol1' => 'name'],
        ];

        return $form;
    }

    /** @return array<string,array{key:string,label:string,group:string,default:bool}> */
    private function columnsByKey(): array
    {
        $byKey = [];
        foreach ($this->service->exportColumns($this->makeForm()) as $c) {
            $byKey[$c['key']] = $c;
        }
        return $byKey;
    }

    public function testRealFieldsAreOfferedAndDefaultOn(): void
    {
        $byKey = $this->columnsByKey();

        foreach (['field:name', 'field:email', 'field:utm_source', 'field:products'] as $key) {
            $this->assertArrayHasKey($key, $byKey, "missing field column {$key}");
            $this->assertSame('fields', $byKey[$key]['group']);
            $this->assertTrue($byKey[$key]['default'], "{$key} should default on");
        }
    }

    public function testMetadataColumnsAreOptIn(): void
    {
        $byKey = $this->columnsByKey();

        foreach (['id', 'status', 'dateCreated', 'ip', 'extra'] as $key) {
            $this->assertArrayHasKey($key, $byKey, "missing meta column {$key}");
            $this->assertSame('meta', $byKey[$key]['group']);
            $this->assertFalse($byKey[$key]['default'], "{$key} should be opt-in");
        }
    }

    public function testPromotedCopiesAreNeverOffered(): void
    {
        $keys = array_column($this->service->exportColumns($this->makeForm()), 'key');

        foreach (['email', 'search1', 'search2', 'search3'] as $promoted) {
            $this->assertNotContains($promoted, $keys, "promoted copy '{$promoted}' must not be an export column");
        }
    }
}
