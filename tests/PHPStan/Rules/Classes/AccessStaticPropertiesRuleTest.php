<?php declare(strict_types = 1);

namespace PHPStan\Rules\Classes;

class AccessStaticPropertiesRuleTest extends \PHPStan\Rules\AbstractRuleTest
{

	protected function getRule(): \PHPStan\Rules\Rule
	{
		return new AccessStaticPropertiesRule(
			$this->createBroker()
		);
	}

	public function testAccessStaticProperties()
	{
		$this->analyse([__DIR__ . '/data/access-static-properties.php'], [
			[
				'Access to an undefined static property FooAccessStaticProperties::$bar.',
				23,
			],
			[
				'Access to an undefined static property BarAccessStaticProperties::$bar.',
				24,
			],
			[
				'Access to an undefined static property FooAccessStaticProperties::$bar.',
				25,
			],
			[
				'Static access to instance property FooAccessStaticProperties::$loremIpsum.',
				26,
			],
			[
				'IpsumAccessStaticProperties::ipsum() accesses parent::$lorem but IpsumAccessStaticProperties does not extend any class.',
				41,
			],
			[
				'Access to protected static property $foo of class FooAccessStaticProperties.',
				43,
			],
		]);
	}

}
