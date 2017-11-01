<?php declare(strict_types=1);


namespace Shopware\Psh\Application;

use Khill\Duration\Duration;
use League\CLImate\CLImate;
use Shopware\Psh\Config\Config;
use Shopware\Psh\ConfigLoad\ConfigFileDiscovery;
use Shopware\Psh\Listing\Script;
use Shopware\Psh\Listing\ScriptFinder;
use Shopware\Psh\Listing\ScriptNotFoundException;
use Shopware\Psh\Listing\ScriptPathNotValidException;
use Shopware\Psh\ScriptRuntime\ExecutionErrorException;
use Shopware\Psh\ScriptRuntime\TemplateNotValidException;

/**
 * Main application entry point. moves the requested data around and outputs user information.
 */
class Application
{
    const RESULT_SUCCESS = 0;

    const RESULT_ERROR = 1;

    const MIN_PADDING_SIZE = 30;

    /**
     * @var CLImate
     */
    public $cliMate;

    /**
     * @var string
     */
    private $rootDirectory;

    /**
     * @var ApplicationFactory
     */
    private $applicationFactory;

    /**
     * @var Duration
     */
    private $duration;

    /**
     * @param string $rootDirectory
     */
    public function __construct(string $rootDirectory)
    {
        $this->rootDirectory = $rootDirectory;
        $this->applicationFactory = new ApplicationFactory();
        $this->cliMate = new CLImate();
        $this->duration = new Duration();
    }

    /**
     * Main entry point to execute the application.
     *
     * @param array $inputArgs
     * @return int exit code
     */
    public function run(array $inputArgs): int
    {
        try {
            $config = $this->applicationFactory
                ->createConfig($this->rootDirectory, $inputArgs);
        } catch (InvalidParameterException $e) {
            $this->notifyError($e->getMessage() . "\n");
            return self::RESULT_ERROR;
        }

        if (count($inputArgs) > 1 && $inputArgs[1] === 'bash_autocompletion_dump') {
            $this->showAutocompleteListing($config);
            return self::RESULT_SUCCESS;
        }

        $this->printHeader($config);

        $configFiles = $this->applicationFactory->getConfigFiles($this->rootDirectory);
        $this->printConfigFiles($configFiles);

        $scriptNames = $this->extractScriptNames($inputArgs);
        $scriptFinder = $this->applicationFactory->createScriptFinder($config);

        try {
            foreach ($scriptNames as $scriptName) {
                $executionExitCode = $this->execute($scriptFinder->findScriptByName($scriptName), $config);

                if ($executionExitCode !== self::RESULT_SUCCESS) {
                    return $executionExitCode;
                }
            }

            if (count($scriptNames)) {
                return self::RESULT_SUCCESS;
            }
        } catch (ScriptNotFoundException $e) {
            $this->showScriptNotFoundListing($inputArgs, $scriptNames, $scriptFinder);
            return self::RESULT_ERROR;
        }

        try {
            $this->showListing($scriptFinder->getAllScripts());
        } catch (ScriptPathNotValidException $e) {
            $this->notifyError($e->getMessage() . "\n");
            return self::RESULT_ERROR;
        }

        return self::RESULT_SUCCESS;
    }

    /**
     * @param Script[] $scripts
     */
    public function showListing(array $scripts)
    {
        $this->cliMate->green()->bold('Available commands:')->br();

        if (!count($scripts)) {
            $this->cliMate->yellow()->bold('-> Currently no scripts available');
        }

        $paddingSize = $this->getPaddingSize($scripts);
        $padding = $this->cliMate->padding($paddingSize)->char(' ');

        $scriptEnvironment = false;

        foreach ($scripts as $script) {
            if ($scriptEnvironment !== $script->getEnvironment()) {
                $scriptEnvironment = $script->getEnvironment();
                $this->cliMate->green()->br()->bold(($scriptEnvironment ?? 'default') . ':');
            }

            $padding
                ->label('<bold> - ' . $script->getName() . '</bold>')
                ->result('<dim>' . $script->getDescription() . '</dim>');
        }

        $this->cliMate->green()->bold("\n" . count($scripts) . " script(s) available\n");
    }

    /**
     * @param array $inputArgs
     * @return array
     */
    protected function extractScriptNames(array $inputArgs): array
    {
        if (!isset($inputArgs[1])) {
            return [];
        }

        return explode(',', $inputArgs[1]);
    }

    /**
     * @param Script $script
     * @param Config $config
     * @return int
     */
    protected function execute(Script $script, Config $config): int
    {
        $commands = $this->applicationFactory
            ->createCommands($script);

        $logger = new ClimateLogger($this->cliMate, $this->duration);
        $executor = $this->applicationFactory
            ->createProcessExecutor($script, $config, $logger, $this->rootDirectory);

        try {
            $executor->execute($script, $commands);
        } catch (ExecutionErrorException $e) {
            $this->notifyError("\nExecution aborted, a subcommand failed!\n");
            return self::RESULT_ERROR;
        } catch (TemplateNotValidException $e) {
            $this->notifyError("\n" . $e->getMessage() . "\n");
            return self::RESULT_ERROR;
        }

        $this->notifySuccess("All commands successfully executed!\n");

        return self::RESULT_SUCCESS;
    }

    /**
     * @param $string
     */
    public function notifySuccess($string)
    {
        $this->cliMate->bold()->green($string);
    }

    /**
     * @param $string
     */
    public function notifyError($string)
    {
        $this->cliMate->bold()->red($string);
    }

    /**
     * @param $config
     */
    protected function printHeader(Config $config)
    {
        $this->cliMate->green()->bold()->out("\n###################");

        if ($config->getHeader()) {
            $this->cliMate->out("\n" . $config->getHeader());
        }
    }

    protected function printConfigFiles(ConfigFileDiscovery $configFiles)
    {
        $template = 'Using %s ';
        $params[] = str_replace($this->rootDirectory . '/', '', $configFiles->getPrimaryFile());

        if ($configFiles->getOverrideFile()) {
            $template .= 'extended by %s ';
            $params[] = str_replace($this->rootDirectory . '/', '', $configFiles->getOverrideFile());
        }

        $template .= "\n";

        $this->cliMate->yellow()->out(vsprintf($template, $params));
    }

    /**
     * @param Script[] $scripts
     * @return Int
     */
    private function getPaddingSize(array $scripts): Int
    {
        $maxScriptNameLength = 0;
        foreach ($scripts as $script) {
            if (strlen($script->getName()) > $maxScriptNameLength) {
                $maxScriptNameLength = strlen($script->getName());
            }
        }
        return $maxScriptNameLength + self::MIN_PADDING_SIZE;
    }

    /**
     * @param $config
     */
    private function showAutocompleteListing(Config $config)
    {
        $scriptFinder = $this->applicationFactory
            ->createScriptFinder($config);

        $scripts = $scriptFinder->getAllScripts();

        $commands = array_map(function (Script $script) {
            return $script->getName();
        }, $scripts);

        $this->cliMate->out(implode(' ', $commands));
    }

    /**
     * @param array $inputArgs
     * @param array $scriptNames
     * @param ScriptFinder $scriptFinder
     */
    private function showScriptNotFoundListing(array $inputArgs, array $scriptNames, ScriptFinder $scriptFinder)
    {
        $this->notifyError("Script with name {$inputArgs[1]} not found\n");

        $scripts = [];
        foreach ($scriptNames as $scriptName) {
            $newScripts = $scriptFinder->findScriptsByPartialName($scriptName);
            $scripts = array_merge($scripts, $newScripts);
        }

        if (count($scripts) > 0) {
            $this->cliMate->yellow()->bold('Have you been looking for this?');
            $this->showListing($scripts);
        }
    }
}
