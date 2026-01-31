<?php declare(strict_types = 1);

namespace Contributte\Messenger\DI\Pass;

use Contributte\Messenger\Logger\MessengerLogger;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class LoggerPass extends AbstractPass
{

	/**
	 * Register services
	 */
	public function loadPassConfiguration(): void
	{
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->prefix('logger.logger'))
			->setFactory(MessengerLogger::class)
			->setAutowired(false);
	}

	/**
	 * Decorate services
	 */
	public function beforePassCompile(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$existingLogger = $builder->getByType(LoggerInterface::class);

		$httpLogger = $this->createLogger(
			'logger.httpLogger',
			$config->logger->httpLogger,
			$existingLogger,
			NullLogger::class
		);

		$consoleLogger = $this->createLogger(
			'logger.consoleLogger',
			$config->logger->consoleLogger,
			$existingLogger,
			new Statement(ConsoleLogger::class, [
				new Statement(ConsoleOutput::class, [OutputInterface::VERBOSITY_VERY_VERBOSE]),
			])
		);

		/** @var ServiceDefinition $loggerDef */
		$loggerDef = $builder->getDefinition($this->prefix('logger.logger'));
		$loggerDef->setArguments([$httpLogger, $consoleLogger]);
	}

	private function createLogger(
		string $name,
		string|Statement|null $configLogger,
		?string $existingLogger,
		string|Statement $fallback
	): ServiceDefinition {
		$builder = $this->getContainerBuilder();

		$factory = $configLogger
			?? ($existingLogger !== null ? '@' . $existingLogger : null)
			?? $fallback;

		return $builder->addDefinition($this->prefix($name))
			->setFactory($factory)
			->setAutowired(false);
	}

}
