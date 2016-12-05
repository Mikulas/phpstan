<?php declare(strict_types = 1);

namespace PHPStan\Rules\Arrays;

class AssertConditionedExpressionRuleTest extends \PHPStan\Rules\AbstractRuleTest
{

	protected function getRule(): \PHPStan\Rules\Rule
	{
		return new AssertConditionedExpressionRule();
	}

	public function testAppendedArrayItemType()
	{
		$this->analyse(
			[__DIR__ . '/data/rely-on-assert.php'],
			[
				[
					'Variable $b (:9) relies on assert being evaluated.',
					8,
				],
				[
					'Variable $matches (:13) relies on assert being evaluated.',
					12,
				],
			]
		);
	}

}
