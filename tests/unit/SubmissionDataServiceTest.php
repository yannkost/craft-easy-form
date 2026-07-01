<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\EasyForm;
use yannkost\easyform\models\Form;
use yannkost\easyform\services\SubmissionDataService;

final class SubmissionDataServiceTest extends TestCase
{
    private SubmissionDataService $service;

    protected function setUp(): void
    {
        $this->service = EasyForm::getInstance()->submissionData;
    }

    private function makeForm(string $policy = 'allowListed'): Form
    {
        $form = new Form();
        $form->handle = 'contact';
        $form->name = 'Contact';
        $form->layout = [
            'schemaVersion' => 3,
            'extraFieldPolicy' => $policy,
            'pages' => [['id' => 'p', 'rows' => [['id' => 'r', 'fields' => [
                ['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'handle' => 'name'],
                ['id' => 'f2', 'type' => 'email', 'label' => 'Email', 'handle' => 'email'],
            ]]]]],
            'frontendFields' => [
                ['handle' => 'utm_source', 'label' => 'UTM', 'type' => 'string'],
                ['handle' => 'products', 'label' => 'Products', 'type' => 'array', 'multiple' => true, 'maxItems' => 2],
            ],
            'promotedFields' => ['primaryEmail' => 'email', 'source' => 'utm_source'],
        ];

        return $form;
    }

    public function testAllowListedSplitsAndDropsUnknown(): void
    {
        $form = $this->makeForm('allowListed');
        $result = $this->service->build($form, [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'utm_source' => 'newsletter',
            'evilExtra' => 'tampered',
        ]);

        $data = $result['data'];
        $this->assertSame(['name' => 'Jane', 'email' => 'jane@example.com'], $data['values']);
        $this->assertSame('newsletter', $data['frontend']['utm_source']);
        $this->assertArrayNotHasKey('evilExtra', $data['frontend']);
        $this->assertSame('allowListed', $data['meta']['unknownFieldPolicy']);
        $this->assertSame(1, $data['schemaVersion']);
    }

    public function testStrictDropsFrontendFields(): void
    {
        $form = $this->makeForm('strict');
        $result = $this->service->build($form, [
            'name' => 'Jane',
            'utm_source' => 'newsletter',
        ]);

        $this->assertSame(['name' => 'Jane'], $result['data']['values']);
        $this->assertSame([], $result['data']['frontend']);
    }

    public function testOpenKeepsUnknownFields(): void
    {
        $form = $this->makeForm('open');
        $result = $this->service->build($form, [
            'name' => 'Jane',
            'arbitrary' => 'kept',
        ]);

        $this->assertSame('kept', $result['data']['frontend']['arbitrary']);
    }

    public function testHiddenKnownFieldsAreDiscarded(): void
    {
        $form = $this->makeForm('allowListed');
        // email is not in the visible set -> must be dropped
        $result = $this->service->build($form, [
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ], ['name']);

        $this->assertArrayHasKey('name', $result['data']['values']);
        $this->assertArrayNotHasKey('email', $result['data']['values']);
    }

    public function testFrontendListTrimsToMaxItems(): void
    {
        $form = $this->makeForm('allowListed');
        $result = $this->service->build($form, [
            'products' => ['12', '18', '44'], // 3 items, maxItems = 2
        ]);

        $products = $result['data']['frontend']['products'];
        // Trimmed to maxItems; values stored as-is (no element-relation coercion).
        $this->assertSame(['12', '18'], $products);
    }

    public function testPromotedMetadataExtraction(): void
    {
        $form = $this->makeForm('allowListed');
        $result = $this->service->build($form, [
            'email' => 'jane@example.com',
            'utm_source' => 'newsletter',
        ]);

        // 'source' => 'utm_source' in the fixture is migrated to the generic
        // searchCol2 key by FormSchemaService::normalize.
        $this->assertSame('jane@example.com', $result['promoted']['primaryEmail']);
        $this->assertSame('newsletter', $result['promoted']['searchCol2']);
        $this->assertNull($result['promoted']['searchCol1']);
    }

    public function testFieldSnapshotIncludesBuilderAndFrontendFields(): void
    {
        $form = $this->makeForm('allowListed');
        $snapshot = $this->service->build($form, ['name' => 'Jane'])['fieldSnapshot'];

        $this->assertSame('contact', $snapshot['formHandle']);
        $bySource = [];
        foreach ($snapshot['fields'] as $f) {
            $bySource[$f['handle']] = $f['source'];
        }
        $this->assertSame('builder', $bySource['name']);
        $this->assertSame('builder', $bySource['email']);
        $this->assertSame('frontend', $bySource['utm_source']);
        $this->assertSame('frontend', $bySource['products']);
    }

    public function testMetaRecordsHandles(): void
    {
        $form = $this->makeForm('allowListed');
        $meta = $this->service->build($form, [
            'name' => 'Jane',
            'utm_source' => 'newsletter',
        ])['data']['meta'];

        $this->assertSame(['name'], $meta['knownFieldHandles']);
        $this->assertSame(['utm_source'], $meta['frontendFieldHandles']);
        $this->assertSame(3, $meta['formSchemaVersion']);
    }

    public function testPromotedColumnsMapsConfiguredHandles(): void
    {
        $layout = ['promotedFields' => [
            'primaryEmail' => 'email',
            'searchCol1' => 'name',
            'searchCol2' => 'company',
            'searchCol3' => 'phone',
        ]];

        $result = $this->service->promotedColumns($layout, [
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'company' => 'Acme',
            'phone' => '555',
        ]);

        $this->assertSame('jane@example.com', $result['primaryEmail']);
        $this->assertSame('Jane', $result['searchCol1']);
        $this->assertSame('Acme', $result['searchCol2']);
        $this->assertSame('555', $result['searchCol3']);
    }

    public function testPromotedColumnsNullWhenUnmappedOrMissing(): void
    {
        // No promoted map at all → all columns null.
        $this->assertSame(
            ['primaryEmail' => null, 'searchCol1' => null, 'searchCol2' => null, 'searchCol3' => null],
            $this->service->promotedColumns([], ['email' => 'jane@example.com'])
        );

        // Mapped handle absent from the submitted values → null.
        $result = $this->service->promotedColumns(
            ['promotedFields' => ['primaryEmail' => 'email']],
            ['name' => 'Jane']
        );
        $this->assertNull($result['primaryEmail']);
    }

    public function testPromotedColumnsTakesFirstArrayValueAndTruncates(): void
    {
        $result = $this->service->promotedColumns(
            ['promotedFields' => ['primaryEmail' => 'email', 'searchCol1' => 'tags']],
            ['email' => str_repeat('x', 300), 'tags' => ['first', 'second']]
        );

        $this->assertSame(255, mb_strlen($result['primaryEmail']));
        $this->assertSame('first', $result['searchCol1']);
    }

    public function testPromotedColumnsNonScalarBecomesNull(): void
    {
        $result = $this->service->promotedColumns(
            ['promotedFields' => ['primaryEmail' => 'email']],
            ['email' => ['nested' => ['deep' => 1]]]
        );

        $this->assertNull($result['primaryEmail']);
    }
}
