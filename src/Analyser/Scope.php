<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\Cast\Double;
use PhpParser\Node\Expr\Cast\Int_;
use PhpParser\Node\Expr\Cast\Object_;
use PhpParser\Node\Expr\Cast\Unset_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\Broker\Broker;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

class Scope
{

	/**
	 * @var \PHPStan\Broker\Broker
	 */
	private $broker;

	/**
	 * @var \PhpParser\PrettyPrinter\Standard
	 */
	private $printer;

	/**
	 * @var string
	 */
	private $file;

	/**
	 * @var bool
	 */
	private $declareStrictTypes;

	/**
	 * @var string|null
	 */
	private $class;

	/**
	 * @var string|null
	 */
	private $function;

	/**
	 * @var string|null
	 */
	private $namespace;

	/**
	 * @var \PHPStan\Type\Type[]
	 */
	private $variableTypes;

	/**
	 * @var bool
	 */
	private $inClosureBind;

	/**
	 * @var \PHPStan\Reflection\ClassReflection
	 */
	private $anonymousClass;

	/**
	 * @var string|null
	 */
	private $inFunctionCallName;

	/**
	 * @var \PHPStan\Type\Type[]
	 */
	private $moreSpecificTypes;

	/**
	 * @var string[]
	 */
	private $currentlyAssignedVariables = [];

	public function __construct(
		Broker $broker,
		\PhpParser\PrettyPrinter\Standard $printer,
		string $file,
		bool $declareStrictTypes = false,
		string $class = null,
		string $function = null,
		string $namespace = null,
		array $variablesTypes = [],
		bool $inClosureBind = false,
		ClassReflection $anonymousClass = null,
		string $inFunctionCallName = null,
		array $moreSpecificTypes = [],
		array $currentlyAssignedVariables = []
	)
	{
		if ($class === '') {
			$class = null;
		}

		if ($function === '') {
			$function = null;
		}

		if ($namespace === '') {
			$namespace = null;
		}

		$this->broker = $broker;
		$this->printer = $printer;
		$this->file = $file;
		$this->declareStrictTypes = $declareStrictTypes;
		$this->class = $class;
		$this->function = $function;
		$this->namespace = $namespace;
		$this->variableTypes = $variablesTypes;
		$this->inClosureBind = $inClosureBind;
		$this->anonymousClass = $anonymousClass;
		$this->inFunctionCallName = $inFunctionCallName;
		$this->moreSpecificTypes = $moreSpecificTypes;
		$this->currentlyAssignedVariables = $currentlyAssignedVariables;
	}

	public function getFile(): string
	{
		return $this->file;
	}

	public function isDeclareStrictTypes(): bool
	{
		return $this->declareStrictTypes;
	}

	public function enterDeclareStrictTypes(): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			true,
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$this->getVariableTypes(),
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes,
			$this->currentlyAssignedVariables
		);
	}

	/**
	 * @return null|string
	 */
	public function getClass()
	{
		return $this->class;
	}

	/**
	 * @return null|string
	 */
	public function getFunction()
	{
		return $this->function;
	}

	/**
	 * @return null|string
	 */
	public function getNamespace()
	{
		return $this->namespace;
	}

	/**
	 * @return \PHPStan\Type\Type[]
	 */
	public function getVariableTypes(): array
	{
		return $this->variableTypes;
	}

	public function hasVariableType(string $variableName): bool
	{
		return isset($this->variableTypes[$variableName]);
	}

	public function getVariableType(string $variableName): Type
	{
		if (!$this->hasVariableType($variableName)) {
			throw new \PHPStan\Analyser\UndefinedVariableException($this, $variableName);
		}

		return $this->variableTypes[$variableName];
	}

	public function isInClosureBind(): bool
	{
		return $this->inClosureBind;
	}

	public function isInAnonymousClass(): bool
	{
		return $this->anonymousClass !== null;
	}

	public function getAnonymousClass(): ClassReflection
	{
		return $this->anonymousClass;
	}

	/**
	 * @return string|null
	 */
	public function getInFunctionCallName()
	{
		return $this->inFunctionCallName;
	}

	public function getType(Node $node): Type
	{
		if (
			$node instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd
			|| $node instanceof \PhpParser\Node\Expr\BinaryOp\BooleanOr
			|| $node instanceof \PhpParser\Node\Expr\BooleanNot
			|| $node instanceof \PhpParser\Node\Expr\BinaryOp\LogicalXor
		) {
			return new BooleanType(false);
		}

		if (
			$node instanceof Node\Expr\UnaryMinus
			|| $node instanceof Node\Expr\UnaryPlus
		) {
			return $this->getType($node->expr);
		}

		if (
			$node instanceof Node\Expr\BinaryOp\Div
			|| $node instanceof Node\Expr\AssignOp\Div
		) {
			return new FloatType(false);
		}

		if ($node instanceof Node\Expr\BinaryOp\Mod) {
			return new IntegerType(false);
		}

		if (
			$node instanceof Node\Expr\BinaryOp\Plus
			|| $node instanceof Node\Expr\BinaryOp\Minus
			|| $node instanceof Node\Expr\BinaryOp\Mul
			|| $node instanceof Node\Expr\BinaryOp\Pow
			|| $node instanceof Node\Expr\AssignOp
		) {
			if ($node instanceof Node\Expr\AssignOp) {
				$left = $node->var;
				$right = $node->expr;
			} elseif ($node instanceof Node\Expr\BinaryOp) {
				$left = $node->left;
				$right = $node->right;
			} else {
				throw new \PHPStan\ShouldNotHappenException();
			}

			$leftType = $this->getType($left);
			$rightType = $this->getType($right);

			if ($leftType instanceof BooleanType) {
				$leftType = new IntegerType($leftType->isNullable());
			}

			if ($rightType instanceof BooleanType) {
				$rightType = new IntegerType($rightType->isNullable());
			}

			if ($leftType instanceof FloatType || $rightType instanceof FloatType) {
				return new FloatType(false);
			}

			if ($leftType instanceof IntegerType && $rightType instanceof IntegerType) {
				return new IntegerType(false);
			}
		}

		if ($node instanceof LNumber) {
			return new IntegerType(false);
		} elseif ($node instanceof ConstFetch) {
			$constName = (string) $node->name;
			if (in_array($constName, ['true', 'false'], true)) {
				return new BooleanType(false);
			}

			if ($constName === 'null') {
				return new NullType();
			}
		} elseif ($node instanceof String_) {
			return new StringType(false);
		} elseif ($node instanceof DNumber) {
			return new FloatType(false);
		} elseif ($node instanceof New_) {
			if ($node->class instanceof Name) {
				if (
					count($node->class->parts) === 1
				) {
					if ($node->class->parts[0] === 'static') {
						return new MixedType(false);
					} elseif ($node->class->parts[0] === 'self') {
						return new ObjectType($this->getClass(), false);
					}
				}

				return new ObjectType((string) $node->class, false);
			}
		} elseif ($node instanceof Array_) {
			return new ArrayType(false);
		} elseif ($node instanceof Int_) {
				return new IntegerType(false);
		} elseif ($node instanceof Bool_) {
			return new BooleanType(false);
		} elseif ($node instanceof Double) {
			return new FloatType(false);
		} elseif ($node instanceof \PhpParser\Node\Expr\Cast\String_) {
			return new StringType(false);
		} elseif ($node instanceof \PhpParser\Node\Expr\Cast\Array_) {
			return new ArrayType(false);
		} elseif ($node instanceof Object_) {
			return new ObjectType('stdClass', false);
		} elseif ($node instanceof Unset_) {
			return new NullType();
		} elseif ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Name) {
			$constantClass = (string) $node->class;
			if ($constantClass === 'self') {
				$constantClass = $this->getClass();
			}

			$constantName = $node->name;
			if ($this->broker->hasClass($constantClass)) {
				$constantClassReflection = $this->broker->getClass($constantClass);
				$constants = $constantClassReflection->getNativeReflection()->getConstants();
				if (array_key_exists($constantName, $constants)) {
					$constantValue = $constants[$constantName];
					if (is_int($constantValue)) {
						return new IntegerType(false);
					} elseif (is_float($constantValue)) {
						return new FloatType(false);
					} elseif (is_bool($constantValue)) {
						return new BooleanType(false);
					} elseif ($constantValue === null) {
						return new NullType();
					} elseif (is_string($constantValue)) {
						return new StringType(false);
					} elseif (is_array($constantValue)) {
						return new ArrayType(false);
					}
				}
			}
		}

		$exprString = $this->printer->prettyPrint([$node]);
		if (isset($this->moreSpecificTypes[$exprString])) {
			return $this->moreSpecificTypes[$exprString];
		}

		if ($node instanceof Variable && is_string($node->name)) {
			if (!$this->hasVariableType($node->name)) {
				return new MixedType(true);
			}

			return $this->getVariableType($node->name);
		}

		if ($node instanceof MethodCall && is_string($node->name)) {
			$methodCalledOnType = $this->getType($node->var);
			if (
				$methodCalledOnType->getClass() !== null
				&& $this->broker->hasClass($methodCalledOnType->getClass())
			) {
				$methodClassReflection = $this->broker->getClass(
					$methodCalledOnType->getClass()
				);
				if (!$methodClassReflection->hasMethod($node->name)) {
					return new MixedType(true);
				}

				$methodReflection = $methodClassReflection->getMethod($node->name);
				foreach ($this->broker->getDynamicMethodReturnTypeExtensionsForClass($methodCalledOnType->getClass()) as $dynamicMethodReturnTypeExtension) {
					if (!$dynamicMethodReturnTypeExtension->isMethodSupported($methodReflection)) {
						continue;
					}

					return $dynamicMethodReturnTypeExtension->getTypeFromMethodCall($methodReflection, $node, $this);
				}

				if ($methodReflection->getReturnType() instanceof StaticType) {
					if ($methodReflection->getReturnType()->isNullable()) {
						return $methodCalledOnType->makeNullable();
					}

					return $methodCalledOnType;
				}

				return $methodReflection->getReturnType();
			}
		}

		if ($node instanceof PropertyFetch && is_string($node->name)) {
			$propertyFetchedOnType = $this->getType($node->var);
			if (
				$propertyFetchedOnType->getClass() !== null
				&& $this->broker->hasClass($propertyFetchedOnType->getClass())
			) {
				$propertyClassReflection = $this->broker->getClass(
					$propertyFetchedOnType->getClass()
				);
				if (!$propertyClassReflection->hasProperty($node->name)) {
					return new MixedType(true);
				}

				return $propertyClassReflection->getProperty($node->name)->getType();
			}
		}

		if ($node instanceof FuncCall && $node->name instanceof Name) {
			$functionName = (string) $node->name;
			if (!$this->broker->hasFunction($functionName)) {
				return new MixedType(true);
			}

			return $this->broker->getFunction($functionName)->getReturnType();
		}

		// todo throw?
		return new MixedType(false);
	}

	public function enterClass(string $className): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$className,
			null,
			$this->getNamespace(),
			[
				'this' => new ObjectType($className, false),
			]
		);
	}

	public function enterFunction(
		ParametersAcceptor $functionReflection
	): self
	{
		$variableTypes = $this->getVariableTypes();
		foreach ($functionReflection->getParameters() as $parameter) {
			$variableTypes[$parameter->getName()] = $parameter->getType();
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$functionReflection->getName(),
			$this->getNamespace(),
			$variableTypes
		);
	}

	public function enterNamespace(string $namespaceName): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			null,
			null,
			$namespaceName
		);
	}

	public function enterClosureBind(): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$this->getVariableTypes(),
			true,
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes
		);
	}

	public function enterAnonymousClass(ClassReflection $anonymousClass): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			null,
			null,
			$this->getNamespace(),
			[
				'this' => new MixedType(false),
			],
			$this->isInClosureBind(),
			$anonymousClass,
			$this->getInFunctionCallName()
		);
	}

	/**
	 * @param \PhpParser\Node\Param[] $parameters
	 * @param \PhpParser\Node\Expr\ClosureUse[] $uses
	 * @return self
	 */
	public function enterAnonymousFunction(array $parameters, array $uses): self
	{
		$variableTypes = [];
		foreach ($parameters as $parameter) {
			$isNullable = false;
			if ($parameter->default instanceof ConstFetch && $parameter->default->name instanceof Name) {
				$isNullable = (string) $parameter->default->name === 'null';
			}
			if ($parameter->type === null) {
				$parameterType = new MixedType(true);
			} elseif ($parameter->type === 'string') {
				$parameterType = new StringType($isNullable);
			} elseif ($parameter->type === 'int') {
				$parameterType = new IntegerType($isNullable);
			} elseif ($parameter->type === 'bool') {
				$parameterType = new BooleanType($isNullable);
			} elseif ($parameter->type === 'float') {
				$parameterType = new FloatType($isNullable);
			} elseif ($parameter->type === 'callable') {
				$parameterType = new CallableType($isNullable);
			} elseif ($parameter->type === 'array') {
				$parameterType = new ArrayType($isNullable);
			} elseif ($parameter->type instanceof Name) {
				$className = (string) $parameter->type;
				if ($className === 'self') {
					$className = $this->getClass();
				}
				$parameterType = new ObjectType($className, $isNullable);
			} else {
				$parameterType = new MixedType($isNullable);
			}

			$variableTypes[$parameter->name] = $parameterType;
		}

		foreach ($uses as $use) {
			if (!$this->hasVariableType($use->var)) {
				if ($use->byRef) {
					$variableTypes[$use->var] = new MixedType(true);
				}
				continue;
			}
			$variableTypes[$use->var] = $this->getVariableType($use->var);
		}

		if ($this->getClass() !== null) {
			$variableTypes['this'] = new ObjectType($this->getClass(), false);
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName()
		);
	}

	public function enterForeach(string $valueName, string $keyName = null): self
	{
		$variableTypes = $this->getVariableTypes();
		$variableTypes[$valueName] = new MixedType(true);
		if ($keyName !== null) {
			$variableTypes[$keyName] = new MixedType(false);
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			null,
			$this->moreSpecificTypes
		);
	}

	public function enterCatch(string $exceptionClassName, string $variableName): self
	{
		$variableTypes = $this->getVariableTypes();
		$variableTypes[$variableName] = new ObjectType($exceptionClassName, false);

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			null,
			$this->moreSpecificTypes
		);
	}

	public function enterFunctionCall(string $functionName): self
	{
		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$this->getVariableTypes(),
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$functionName,
			$this->moreSpecificTypes
		);
	}

	public function enterVariableAssign(string $variableName): self
	{
		$currentlyAssignedVariables = $this->currentlyAssignedVariables;
		$currentlyAssignedVariables[] = $variableName;

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$this->getVariableTypes(),
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes,
			$currentlyAssignedVariables
		);
	}

	public function isInVariableAssign(string $variableName): bool
	{
		return in_array($variableName, $this->currentlyAssignedVariables, true);
	}

	public function assignVariable(
		string $variableName,
		Type $type = null
	): self
	{
		$variableTypes = $this->getVariableTypes();
		$variableTypes[$variableName] = $type !== null
			? $type
			: new MixedType(true);

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes
		);
	}

	public function unsetVariable(string $variableName): self
	{
		$this->getVariableType($variableName); // check if exists
		$variableTypes = $this->getVariableTypes();
		unset($variableTypes[$variableName]);

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes
		);
	}

	public function intersectVariables(Scope $otherScope): self
	{
		$ourVariableTypes = $this->getVariableTypes();
		$theirVariableTypes = $otherScope->getVariableTypes();
		$intersectedVariableTypes = [];
		foreach ($ourVariableTypes as $name => $variableType) {
			if (!isset($theirVariableTypes[$name])) {
				continue;
			}

			$intersectedVariableTypes[$name] = $variableType->combineWith($theirVariableTypes[$name]);
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$intersectedVariableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes
		);
	}

	public function addVariables(Scope $otherScope): self
	{
		$variableTypes = $this->getVariableTypes();
		foreach ($otherScope->getVariableTypes() as $name => $variableType) {
			$variableTypes[$name] = $variableType;
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$variableTypes,
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$this->moreSpecificTypes
		);
	}

	public function specifyObjectType(Node $expr, string $className): self
	{
		if ($expr instanceof Variable && is_string($expr->name)) {
			$variableName = $expr->name;

			$variableTypes = $this->getVariableTypes();
			$variableTypes[$variableName] = new ObjectType($className, false);

			return new self(
				$this->broker,
				$this->printer,
				$this->getFile(),
				$this->isDeclareStrictTypes(),
				$this->getClass(),
				$this->getFunction(),
				$this->getNamespace(),
				$variableTypes,
				$this->isInClosureBind(),
				$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
				$this->getInFunctionCallName(),
				$this->moreSpecificTypes
			);
		}

		$exprString = $this->printer->prettyPrint([$expr]);

		return $this->addMoreSpecificTypes([
			$exprString => new ObjectType($className, false),
		]);
	}

	private function addMoreSpecificTypes(array $types): self
	{
		$moreSpecificTypes = $this->moreSpecificTypes;
		foreach ($types as $exprString => $type) {
			$moreSpecificTypes[$exprString] = $type;
		}

		return new self(
			$this->broker,
			$this->printer,
			$this->getFile(),
			$this->isDeclareStrictTypes(),
			$this->getClass(),
			$this->getFunction(),
			$this->getNamespace(),
			$this->getVariableTypes(),
			$this->isInClosureBind(),
			$this->isInAnonymousClass() ? $this->getAnonymousClass() : null,
			$this->getInFunctionCallName(),
			$moreSpecificTypes
		);
	}

	public function canAccessProperty(PropertyReflection $propertyReflection): bool
	{
		return $this->canAccessClassMember($propertyReflection);
	}

	public function canCallMethod(MethodReflection $methodReflection): bool
	{
		return $this->canAccessClassMember($methodReflection);
	}

	private function canAccessClassMember(ClassMemberReflection $classMemberReflection): bool
	{
		if ($this->isInClosureBind()) {
			return true;
		}

		if ($classMemberReflection->isPublic()) {
			return true;
		}

		if ($this->getClass() === null) {
			return false;
		}

		$classReflectionName = $classMemberReflection->getDeclaringClass()->getName();
		if ($classMemberReflection->isPrivate()) {
			return $this->getClass() === $classReflectionName;
		}

		$currentClassReflection = $this->broker->getClass($this->getClass());

		// protected

		return $currentClassReflection->getName() === $classReflectionName
			|| $currentClassReflection->isSubclassOf($classReflectionName);
	}

}
