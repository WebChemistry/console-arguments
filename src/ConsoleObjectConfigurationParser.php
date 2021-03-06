<?php declare(strict_types = 1);

namespace WebChemistry\ConsoleArguments;

use WebChemistry\ConsoleArguments\Attribute\Argument;
use WebChemistry\ConsoleArguments\Attribute\DefaultProvider;
use WebChemistry\ConsoleArguments\Attribute\Description;
use WebChemistry\ConsoleArguments\Attribute\Shortcut;
use WebChemistry\ConsoleArguments\Extension\DefaultValuesProviderInterface;
use WebChemistry\ConsoleArguments\Extension\ValidateObjectInterface;
use WebChemistry\ConsoleArguments\Result\CommandResult;
use WebChemistry\ConsoleArguments\Result\OptionResult;
use LogicException;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use ReflectionClass;
use ReflectionProperty;
use ReflectionUnionType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WebChemistry\ConsoleArguments\Validator\ValidatorAccessor;

final class ConsoleObjectConfigurationParser
{

	private CommandResult $commandResult;
	
	private ValidatorAccessor $validator;

	public function __construct(
		private string $className,
	)
	{
		$this->validator = new ValidatorAccessor();
	}

	public function hydrate(InputInterface $input, OutputInterface $output): ?object
	{
		$reflection = new ReflectionClass($this->className);
		$object = $reflection->newInstanceWithoutConstructor();
		$result = $this->getCommandResult();

		try {
			$arguments = (new Processor())->process(
				Expect::from($object, $this->createSchema($input)),
				$this->getValues($input),
			);
		} catch (ValidationException $exception) {
			foreach ($exception->getMessageObjects() as $object) {
				$name = $object->path[0];
				$option = $result->options[$name];
				$output->writeln(
					sprintf(
						'<error>%s%s expects to be %s, %s given.</error>',
						$option->argument ? 'Argument ' : 'Option --',
						$option->name,
						$object->variables['expected'],
						(string) $object->variables['value'],
					)
				);
			}

			return null;
		}

		if ($arguments instanceof DefaultValuesProviderInterface) {
			$this->processDefaultValues($arguments, $arguments->provideDefaultValues());
		}

		if ($arguments instanceof ValidateObjectInterface) {
			$arguments->validate();
		}

		$this->validator->validateThrowOnError($arguments);

		return $arguments;
	}

	public function configure(Command $command): void
	{
		$result = $this->getCommandResult();

		$command->setDescription((string) ($result->description ?? $this->getDescription(new ReflectionClass($command))));

		foreach ($result->options as $option) {
			if (!$option->argument) {
				$command->addOption(
					$option->name,
					$option->shortcut,
					$option->getOptionMode(),
					(string) $option->description,
					$option->getDefault(),
				);
			} else {
				$command->addArgument(
					$option->name,
					$option->getArgumentMode(),
					(string) $option->description,
					$option->getDefault(),
				);
			}
		}
	}

	/**
	 * @return mixed[]
	 */
	private function getValues(InputInterface $input): array
	{
		$result = $this->getCommandResult();
		$values = [];

		foreach ($result->options as $option) {
			if ($option->isset($input)) {
				$values[$option->property] = $option->get($input);
			}
		}

		return $values;
	}

	/**
	 * @return mixed[]
	 */
	private function createSchema(InputInterface $input): array
	{
		$schema = [];
		$result = $this->getCommandResult();

		foreach ($result->options as $option) {
			if (!$option->isset($input)) {
				continue;
			}

			if ($option->type === 'int') {
				$type = Expect::type('numericint')->castTo('int');

				if ($option->allowsNull && $option->get($input) === null) {
					// hack
					$type = Expect::type('null');
				}

				$schema[$option->property] = $type;
			}
		}

		return $schema;
	}

	private function getCommandResult(): CommandResult
	{
		return $this->commandResult ??= $this->parse();
	}

	private function parse(): CommandResult
	{
		$reflection = new ReflectionClass($this->className);

		$commandResult = new CommandResult();
		$commandResult->reflection = $reflection;
		$commandResult->description = $this->getDescription($reflection);

		foreach ($reflection->getProperties() as $property) {
			if (!$property->isPublic()) {
				continue;
			}

			if (str_starts_with($property->getName(), '_')) {
				continue;
			}

			$type = $property->getType();
			if (!$type) {
				throw new LogicException(
					sprintf('Property %s::%s must have type.', $reflection->getName(), $property->getName())
				);
			}
			if ($type instanceof ReflectionUnionType) {
				throw new LogicException(
					sprintf('Property %s::%s must not be an union type.', $reflection->getName(), $property->getName())
				);
			}

			$option = $commandResult->options[$property->getName()] = new OptionResult();
			$option->name = preg_replace_callback(
				'#([A-Z])#',
				fn (array $matches) => '-' . strtolower($matches[1]),
				$property->getName(),
			);
			$option->description = $this->getDescription($property);
			$option->property = $property->getName();
			$option->default = $this->getDefaultValue($property);
			$option->type = $type->getName();
			$option->allowsNull = $type->allowsNull();
			$option->argument = $this->hasAttribute($property, Argument::class);
			$option->shortcut = $this->getAttribute($property, Shortcut::class)?->shortcut;
		}

		return $commandResult;
	}

	private function getDefaultValue(ReflectionProperty $property): mixed
	{
		$default = $this->getAttribute($property, DefaultProvider::class);
		if ($default) {
			$class = $property->getDeclaringClass();
			if (!$class->hasMethod($default->method)) {
				throw new LogicException(
					sprintf('%s::%s() method does not exist.', $class->getName(), $default->method)
				);
			}

			$method = $class->getMethod($default->method);
			if (!$method->isStatic() || !$method->isPublic()) {
				throw new LogicException(
					sprintf('%s::%s() method must be static and public.', $class->getName(), $default->method)
				);
			}

			return $method->invoke(null);
		}

		return $property->hasDefaultValue() ? $property->getDefaultValue() : null;
	}

	private function getDescription(ReflectionClass|ReflectionProperty $reflection): ?string
	{
		return $this->getAttribute($reflection, Description::class)?->description;
	}

	/**
	 * @template T
	 * @param class-string<T> $attributeName
	 * @return T
	 */
	private function getAttribute(ReflectionClass|ReflectionProperty $reflection, string $attributeName): ?object
	{
		$attrs = $reflection->getAttributes($attributeName);

		return $attrs ? $attrs[0]->newInstance() : null;
	}

	private function hasAttribute(ReflectionClass|ReflectionProperty $reflection, string $attributeName): bool
	{
		return (bool) $reflection->getAttributes($attributeName);
	}

	private function processDefaultValues(object $object, iterable $defaults): void
	{
		$command = $this->getCommandResult();

		foreach ($defaults as $key => $value) {
			$property = $command->reflection->getProperty($key);

			if ($property->isPublic() && $property->getDefaultValue() === $property->getValue($object)) {
				$property->setValue($object, $value);
			}
		}
	}

}
