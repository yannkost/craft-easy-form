<?php

declare(strict_types=1);

namespace yannkost\easyform\tests\unit;

use PHPUnit\Framework\TestCase;
use yannkost\easyform\services\ConditionEvaluator;

final class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    public function testNoConditionsIsAlwaysVisible(): void
    {
        $this->assertTrue($this->evaluator->isVisible([], []));
        $this->assertTrue($this->evaluator->isVisible(['conditions' => ['rules' => []]], []));
    }

    public function testShowActionEqualsOperator(): void
    {
        $item = ['conditions' => [
            'action' => 'show',
            'logic' => 'all',
            'rules' => [['field' => 'type', 'operator' => 'equals', 'value' => 'business']],
        ]];

        $this->assertTrue($this->evaluator->isVisible($item, ['type' => 'business']));
        $this->assertFalse($this->evaluator->isVisible($item, ['type' => 'personal']));
    }

    public function testHideActionInvertsResult(): void
    {
        $item = ['conditions' => [
            'action' => 'hide',
            'logic' => 'all',
            'rules' => [['field' => 'optOut', 'operator' => 'equals', 'value' => 'yes']],
        ]];

        $this->assertFalse($this->evaluator->isVisible($item, ['optOut' => 'yes']));
        $this->assertTrue($this->evaluator->isVisible($item, ['optOut' => 'no']));
    }

    public function testAllVsAnyLogic(): void
    {
        $rules = [
            ['field' => 'a', 'operator' => 'equals', 'value' => '1'],
            ['field' => 'b', 'operator' => 'equals', 'value' => '1'],
        ];

        $all = ['conditions' => ['action' => 'show', 'logic' => 'all', 'rules' => $rules]];
        $any = ['conditions' => ['action' => 'show', 'logic' => 'any', 'rules' => $rules]];

        $this->assertTrue($this->evaluator->isVisible($all, ['a' => '1', 'b' => '1']));
        $this->assertFalse($this->evaluator->isVisible($all, ['a' => '1', 'b' => '0']));

        $this->assertTrue($this->evaluator->isVisible($any, ['a' => '1', 'b' => '0']));
        $this->assertFalse($this->evaluator->isVisible($any, ['a' => '0', 'b' => '0']));
    }

    public function testOperators(): void
    {
        $make = fn(string $op, string $val) => ['conditions' => [
            'action' => 'show', 'logic' => 'all',
            'rules' => [['field' => 'x', 'operator' => $op, 'value' => $val]],
        ]];

        $this->assertTrue($this->evaluator->isVisible($make('notEquals', 'a'), ['x' => 'b']));
        $this->assertTrue($this->evaluator->isVisible($make('contains', 'ell'), ['x' => 'hello']));
        $this->assertTrue($this->evaluator->isVisible($make('notContains', 'zzz'), ['x' => 'hello']));
        $this->assertTrue($this->evaluator->isVisible($make('isEmpty', ''), ['x' => '']));
        $this->assertTrue($this->evaluator->isVisible($make('isNotEmpty', ''), ['x' => 'y']));
    }

    public function testArrayValueEqualsUsesMembership(): void
    {
        $item = ['conditions' => [
            'action' => 'show', 'logic' => 'all',
            'rules' => [['field' => 'interests', 'operator' => 'equals', 'value' => 'sports']],
        ]];

        // Checkbox-style array value
        $this->assertTrue($this->evaluator->isVisible($item, ['interests' => ['music', 'sports']]));
        $this->assertFalse($this->evaluator->isVisible($item, ['interests' => ['music']]));
    }

    public function testGetVisibleFieldHandlesSkipsHiddenFields(): void
    {
        $layout = ['pages' => [['id' => 'p', 'rows' => [['id' => 'r', 'fields' => [
            ['handle' => 'type'],
            ['handle' => 'companyName', 'conditions' => [
                'action' => 'show', 'logic' => 'all',
                'rules' => [['field' => 'type', 'operator' => 'equals', 'value' => 'business']],
            ]],
        ]]]]]];

        $visibleBusiness = $this->evaluator->getVisibleFieldHandles($layout, ['type' => 'business']);
        $this->assertContains('companyName', $visibleBusiness);

        $visiblePersonal = $this->evaluator->getVisibleFieldHandles($layout, ['type' => 'personal']);
        $this->assertNotContains('companyName', $visiblePersonal);
        $this->assertContains('type', $visiblePersonal);
    }

    public function testHiddenPageHidesAllItsFields(): void
    {
        $layout = ['pages' => [
            ['id' => 'p1', 'rows' => [['id' => 'r', 'fields' => [['handle' => 'gate']]]]],
            ['id' => 'p2', 'conditions' => [
                'action' => 'show', 'logic' => 'all',
                'rules' => [['field' => 'gate', 'operator' => 'equals', 'value' => 'open']],
            ], 'rows' => [['id' => 'r', 'fields' => [['handle' => 'secret']]]]],
        ]];

        $this->assertNotContains('secret', $this->evaluator->getVisibleFieldHandles($layout, ['gate' => 'closed']));
        $this->assertContains('secret', $this->evaluator->getVisibleFieldHandles($layout, ['gate' => 'open']));
    }

    // --- Per-rule site scope -------------------------------------------------

    private function siteHideRule(string $site): array
    {
        return ['conditions' => [
            'action' => 'hide',
            'logic' => 'all',
            'rules' => [['field' => 'role', 'operator' => 'equals', 'value' => 'vip', 'site' => $site]],
        ]];
    }

    public function testSiteScopedRuleAppliesOnlyOnItsSite(): void
    {
        $item = $this->siteHideRule('french');
        // On the French site the rule is active → matching data hides the item.
        $this->assertFalse($this->evaluator->isVisible($item, ['role' => 'vip'], 'french'));
        // On another site the rule is dropped → item stays visible.
        $this->assertTrue($this->evaluator->isVisible($item, ['role' => 'vip'], 'default'));
    }

    public function testAllScopedRuleAppliesEverywhere(): void
    {
        $item = $this->siteHideRule('all');
        $this->assertFalse($this->evaluator->isVisible($item, ['role' => 'vip'], 'french'));
        $this->assertFalse($this->evaluator->isVisible($item, ['role' => 'vip'], 'default'));
    }

    public function testNullSiteIgnoresScopeSoEveryRuleApplies(): void
    {
        $item = $this->siteHideRule('french');
        $this->assertFalse($this->evaluator->isVisible($item, ['role' => 'vip']));
    }

    public function testEmptyAfterFilterIsVisibleRegardlessOfAction(): void
    {
        // A hide-rule scoped to french, evaluated on default, leaves no rules.
        // The item must stay visible (not hidden) since nothing constrains it.
        $item = $this->siteHideRule('french');
        $this->assertTrue($this->evaluator->isVisible($item, ['role' => 'vip'], 'default'));
    }

    public function testMixedSiteRulesRespectLogic(): void
    {
        // logic=all: an all-site rule plus a french-only rule. On default only
        // the all-site rule survives, so it alone decides visibility.
        $item = ['conditions' => [
            'action' => 'show',
            'logic' => 'all',
            'rules' => [
                ['field' => 'a', 'operator' => 'equals', 'value' => '1', 'site' => 'all'],
                ['field' => 'b', 'operator' => 'equals', 'value' => '1', 'site' => 'french'],
            ],
        ]];
        // On default: only rule a matters.
        $this->assertTrue($this->evaluator->isVisible($item, ['a' => '1', 'b' => '0'], 'default'));
        // On french: both must match (logic=all).
        $this->assertFalse($this->evaluator->isVisible($item, ['a' => '1', 'b' => '0'], 'french'));
        $this->assertTrue($this->evaluator->isVisible($item, ['a' => '1', 'b' => '1'], 'french'));
    }
}
