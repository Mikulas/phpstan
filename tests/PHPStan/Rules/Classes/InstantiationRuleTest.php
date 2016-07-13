<?php declare(strict_types = 1);

namespace PHPStan\Rules\Classes;

class InstantiationRuleTest extends \PHPStan\Rules\AbstractRuleTest
{

	protected function getRule(): \PHPStan\Rules\Rule
	{
		return new InstantiationRule(
			$this->createBroker(),
			$this->getFunctionCallParametersCheck()
		);
	}

	public function testInstantiation()
	{
		require_once __DIR__ . '/data/instantiation-classes.php';
		$this->analyse(
			[__DIR__ . '/data/instantiation.php'],
			[
				[
					'Class TestInstantiation\FooInstantiation does not have a constructor and must be instantiated without any parameters.',
					7,
				],
				[
					'Instantiated class TestInstantiation\FooBarInstantiation not found.',
					8,
				],
				[
					'Class TestInstantiation\BarInstantiation constructor invoked with 0 parameters, 1 required.',
					9,
				],
				[
					'Instantiated class TestInstantiation\LoremInstantiation is abstract.',
					10,
				],
				[
					'Cannot instantiate interface TestInstantiation\IpsumInstantiation.',
					11,
				],
			]
		);
	}

}
