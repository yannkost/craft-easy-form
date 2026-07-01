<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\services\FormSchemaService;

final class FormSchemaServiceTest extends TestCase
{
    private FormSchemaService $schema;

    protected function setUp(): void
    {
        $this->schema = new FormSchemaService();
    }

    public function testDetectVersion(): void
    {
        $this->assertSame(1, $this->schema->detectVersion(['rows' => []]));
        $this->assertSame(1, $this->schema->detectVersion(['fields' => []]));
        $this->assertSame(2, $this->schema->detectVersion(['schemaVersion' => 2, 'pages' => []]));
        $this->assertSame(3, $this->schema->detectVersion(['schemaVersion' => 3]));
        // Empty/unknown defaults to 2 so it gets upgraded.
        $this->assertSame(2, $this->schema->detectVersion([]));
    }

    public function testNormalizeV1ToV3WrapsRowsIntoPages(): void
    {
        $layout = ['rows' => [['id' => 'row_1', 'fields' => [
            ['id' => 'f1', 'type' => 'text', 'handle' => 'name'],
        ]]]];

        $normalized = $this->schema->normalize($layout);

        $this->assertSame(3, $normalized['schemaVersion']);
        $this->assertSame(FormSchemaService::DEFAULT_POLICY, $normalized['extraFieldPolicy']);
        $this->assertArrayHasKey('frontendFields', $normalized);
        $this->assertArrayHasKey('promotedFields', $normalized);
        $this->assertCount(1, $normalized['pages']);
        $this->assertSame('name', $normalized['pages'][0]['rows'][0]['fields'][0]['handle']);
    }

    public function testNormalizeV2ToV3AddsContractKeys(): void
    {
        $layout = ['schemaVersion' => 2, 'pages' => [['id' => 'page_1', 'rows' => []]]];
        $normalized = $this->schema->normalize($layout);

        $this->assertSame(3, $normalized['schemaVersion']);
        $this->assertSame('allowListed', $normalized['extraFieldPolicy']);
        $this->assertSame([], $normalized['frontendFields']);
        $this->assertSame([], $normalized['promotedFields']);
    }

    public function testNormalizeCanonicalizesPhoneToTel(): void
    {
        $layout = ['schemaVersion' => 3, 'pages' => [['id' => 'p', 'rows' => [
            ['id' => 'r', 'fields' => [['id' => 'f', 'type' => 'phone', 'handle' => 'tel']]],
        ]]]];

        $normalized = $this->schema->normalize($layout);
        $this->assertSame('tel', $normalized['pages'][0]['rows'][0]['fields'][0]['type']);
        $this->assertSame('tel', $this->schema->normalizeFieldType('phone'));
        $this->assertSame('text', $this->schema->normalizeFieldType('text'));
    }

    public function testNormalizePolicyFallsBackToDefault(): void
    {
        $this->assertSame('strict', $this->schema->normalizePolicy('strict'));
        $this->assertSame('open', $this->schema->normalizePolicy('open'));
        $this->assertSame('allowListed', $this->schema->normalizePolicy('bogus'));
        $this->assertSame('allowListed', $this->schema->normalizePolicy(null));
    }

    public function testFrontendFieldNormalization(): void
    {
        $layout = [
            'schemaVersion' => 3,
            'pages' => [],
            'frontendFields' => [
                // valid, but empty maxItems/maxLength and missing label
                ['handle' => 'utm', 'type' => 'string', 'maxItems' => '', 'maxLength' => '0'],
                // invalid type -> coerced to string; multiple/required cast to bool
                ['handle' => 'products', 'type' => 'bogus', 'multiple' => '1', 'required' => '1'],
                // no handle -> dropped
                ['type' => 'string'],
            ],
        ];

        $fields = $this->schema->getFrontendFields($this->schema->normalize($layout));
        $this->assertCount(2, $fields);

        $this->assertSame('utm', $fields[0]['handle']);
        $this->assertSame('utm', $fields[0]['label']); // label falls back to handle
        $this->assertNull($fields[0]['maxItems']);
        $this->assertNull($fields[0]['maxLength']);
        $this->assertTrue($fields[0]['export']);        // defaults on
        $this->assertTrue($fields[0]['notifications']);

        $this->assertSame('string', $fields[1]['type']); // bogus -> string
        $this->assertTrue($fields[1]['multiple']);
        $this->assertTrue($fields[1]['required']);
    }

    public function testHandleHelpers(): void
    {
        $layout = $this->schema->normalize([
            'schemaVersion' => 3,
            'pages' => [['id' => 'p', 'rows' => [['id' => 'r', 'fields' => [
                ['id' => 'f1', 'type' => 'text', 'handle' => 'name'],
                ['id' => 'f2', 'type' => 'email', 'handle' => 'email'],
            ]]]]],
            'frontendFields' => [['handle' => 'utm', 'type' => 'string']],
            'promotedFields' => ['primaryEmail' => 'email'],
        ]);

        $this->assertSame(['name', 'email'], $this->schema->getKnownHandles($layout));
        $this->assertSame(['utm'], $this->schema->getFrontendHandles($layout));
        $this->assertSame(['name', 'email', 'utm'], $this->schema->getAcceptedHandles($layout));
        $this->assertSame(['primaryEmail' => 'email'], $this->schema->getPromotedFields($layout));
    }

    public function testNormalizeMigratesLegacyPromotedKeys(): void
    {
        $layout = $this->schema->normalize([
            'schemaVersion' => 3,
            'pages' => [['id' => 'p', 'rows' => [['id' => 'r', 'fields' => [
                ['id' => 'f1', 'type' => 'text', 'handle' => 'name'],
            ]]]]],
            'promotedFields' => ['primaryEmail' => 'email', 'primaryName' => 'name', 'source' => 'utm'],
        ]);

        // Legacy primaryName/source keys are reframed as generic search columns;
        // primaryEmail is unchanged.
        $this->assertSame(
            ['primaryEmail' => 'email', 'searchCol1' => 'name', 'searchCol2' => 'utm'],
            $layout['promotedFields'],
        );
    }
}
