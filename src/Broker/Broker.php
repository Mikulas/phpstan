<?php declare(strict_types = 1);

namespace PHPStan\Broker;

use PHPStan\Reflection\BrokerAwareClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflectionFactory;
use ReflectionClass;

class Broker
{

	/** @var \PHPStan\Reflection\PropertiesClassReflectionExtension[] */
	private $propertiesClassReflectionExtensions;

	/** @var \PHPStan\Reflection\MethodsClassReflectionExtension[] */
	private $methodsClassReflectionExtensions;

	/** @var \PHPStan\Type\DynamicMethodReturnTypeExtension[] */
	private $dynamicMethodReturnTypeExtensions = [];

	/** @var \PHPStan\Reflection\ClassReflection[] */
	private $classReflections = [];

	/** @var \PHPStan\Reflection\FunctionReflectionFactory */
	private $functionReflectionFactory;

	/** @var \PHPStan\Reflection\FunctionReflection[] */
	private $functionReflections = [];

	/**
	 * @param \PHPStan\Reflection\PropertiesClassReflectionExtension[] $propertiesClassReflectionExtensions
	 * @param \PHPStan\Reflection\MethodsClassReflectionExtension[] $methodsClassReflectionExtensions
	 * @param \PHPStan\Type\DynamicMethodReturnTypeExtension[] $dynamicMethodReturnTypeExtensions
	 * @param \PHPStan\Reflection\FunctionReflectionFactory $functionReflectionFactory
	 */
	public function __construct(
		array $propertiesClassReflectionExtensions,
		array $methodsClassReflectionExtensions,
		array $dynamicMethodReturnTypeExtensions,
		FunctionReflectionFactory $functionReflectionFactory
	)
	{
		$this->propertiesClassReflectionExtensions = $propertiesClassReflectionExtensions;
		$this->methodsClassReflectionExtensions = $methodsClassReflectionExtensions;
		foreach (array_merge($propertiesClassReflectionExtensions, $methodsClassReflectionExtensions) as $extension) {
			if ($extension instanceof BrokerAwareClassReflectionExtension) {
				$extension->setBroker($this);
			}
		}

		foreach ($dynamicMethodReturnTypeExtensions as $dynamicMethodReturnTypeExtension) {
			$this->dynamicMethodReturnTypeExtensions[$dynamicMethodReturnTypeExtension->getClass()][] = $dynamicMethodReturnTypeExtension;
		}

		$this->functionReflectionFactory = $functionReflectionFactory;
	}

	/**
	 * @param string $className
	 * @return \PHPStan\Type\DynamicMethodReturnTypeExtension[]
	 */
	public function getDynamicMethodReturnTypeExtensionsForClass(string $className): array
	{
		$extensions = [];
		$class = $this->getClass($className);
		foreach (array_merge([$className], $class->getParentClassesNames()) as $extensionClassName) {
			if (!isset($this->dynamicMethodReturnTypeExtensions[$extensionClassName])) {
				continue;
			}

			$extensions = array_merge($extensions, $this->dynamicMethodReturnTypeExtensions[$extensionClassName]);
		}

		return $extensions;
	}

	public function getClass(string $className): \PHPStan\Reflection\ClassReflection
	{
		if (!$this->hasClass($className)) {
			throw new \PHPStan\Broker\ClassNotFoundException($className);
		}

		if (!isset($this->classReflections[$className])) {
			$this->classReflections[$className] = $this->getClassFromReflection(new ReflectionClass($className));
		}

		return $this->classReflections[$className];
	}

	public function getClassFromReflection(\ReflectionClass $reflectionClass): \PHPStan\Reflection\ClassReflection
	{
		return new ClassReflection(
			$this,
			$this->propertiesClassReflectionExtensions,
			$this->methodsClassReflectionExtensions,
			$reflectionClass
		);
	}

	public function hasClass(string $className): bool
	{
		try {
			return class_exists($className) || interface_exists($className) || trait_exists($className);
		} catch (\Throwable $t) {
			throw new \PHPStan\Broker\ClassAutoloadingException(
				$className,
				$t
			);
		}
	}

	public function getFunction(string $functionName): \PHPStan\Reflection\FunctionReflection
	{
		if (!$this->hasFunction($functionName)) {
			throw new \PHPStan\Broker\FunctionNotFoundException($functionName);
		}

		$lowerCasedFunctionName = strtolower($functionName);
		if (!isset($this->functionReflections[$lowerCasedFunctionName])) {
			$this->functionReflections[$lowerCasedFunctionName] = $this->functionReflectionFactory->create(new \ReflectionFunction($lowerCasedFunctionName));
		}

		return $this->functionReflections[$lowerCasedFunctionName];
	}

	public function hasFunction(string $functionName): bool
	{
		return function_exists($functionName);
	}

}
