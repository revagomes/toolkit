<?php

declare(strict_types=1);

namespace EcEuropa\Toolkit\TaskRunner\Commands;

use EcEuropa\Toolkit\TaskRunner\AbstractCommands;
use EcEuropa\Toolkit\Toolkit;
use Robo\Exception\TaskException;
use Robo\ResultData;
use Symfony\Component\Console\Input\InputOption;

/**
 * Commands to lint the source code and interact with ESLint.
 */
class LintCommands extends AbstractCommands
{

    /**
     * {@inheritdoc}
     */
    public function getConfigurationFile()
    {
        return Toolkit::getToolkitRoot() . '/config/commands/lint.yml';
    }

    /**
     * Setup the ESLint configurations and dependencies.
     *
     * @command toolkit:setup-eslint
     *
     * @option config      The eslint config file.
     * @option ignores     The patterns to ignore.
     * @option drupal-root The drupal root.
     * @option packages    The npm packages to install.
     * @option force       If true, the config file will be deleted.
     */
    public function toolkitSetupEslint(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'ignores' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'drupal-root' => InputOption::VALUE_REQUIRED,
        'packages' => InputOption::VALUE_REQUIRED,
        'force' => false,
    ])
    {
        Toolkit::ensureArray($options['ignores']);

        $actions = false;
        $config = $options['config'];
        if ($options['force'] && file_exists($config)) {
            $actions = true;
            $this->taskExec('rm')->arg($config)->run();
        }

        // Create a package.json if it doesn't exist.
        if (!file_exists('package.json')) {
            $actions = true;
            $this->taskExec('npm ini -y')->run();
            $this->taskExec("npm install --save-dev {$options['packages']} -y")->run();
        }

        // Check if the binary exists.
        try {
            $this->getNodeBin('eslint');
        } catch (TaskException $e) {
            $actions = true;
            $this->taskExec('npm install')->run();
        }

        if (!file_exists($config)) {
            $actions = true;
            $this->generateEslintConfigurations($config, $options);
        }

        // Ignore all yaml files for prettier.
        if (!file_exists('.prettierignore')) {
            $actions = true;
            $this->taskWriteToFile('.prettierignore')->text('*.yml')->run();
        }

        if (!$actions) {
            $this->say('No actions needed.');
        }

        return ResultData::EXITCODE_OK;
    }

    /**
     * Generate configurations for ESLint.
     *
     * @param string $config
     *   The path for the configuration file.
     * @param array $options
     *   The options passed to the command.
     */
    private function generateEslintConfigurations(string $config, array $options)
    {
        $data = [
            'ignorePatterns' => $options['ignores'],
            // The docker-compose file makes use of
            // empty mappings in env variables.
            'overrides' => [
                [
                    'files' => ['docker-compose*.yml'],
                    'rules' => ['yml/no-empty-mapping-value' => 'off'],
                ],
            ],
        ];

        // Check if we have a Drupal environment.
        $drupalCore = './' . $options['drupal-root'] . '/core';
        if (file_exists($drupalCore)) {
            // Add the drupal core eslint if it exists.
            $drupalEslint = './' . $options['drupal-root'] . '/core/.eslintrc.json';
            if (file_exists($drupalEslint)) {
                $data['extends'] = $drupalEslint;
            }

            // Copy the prettier configurations from Drupal or fallback to defaults.
            $prettier = './' . $options['drupal-root'] . '/core/.prettierrc.json';
            $prettier = file_exists($prettier)
                ? json_decode(file_get_contents($prettier), true)
                : ['singleQuote' => true, 'printWidth' => 80, 'semi' => true, 'trailingComma' => 'all'];
            $data['rules'] = [
                'prettier/prettier' => ['error', $prettier],
            ];
        }

        $this->collectionBuilder()->addCode(function () use ($config, $data) {
            $this->output()->writeln(" <fg=white;bg=cyan;options=bold>[File\Write]</> Writing to $config.<info></>");
            file_put_contents($config, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        })->run();
    }

    /**
     * Run lint YAML.
     *
     * @command toolkit:lint-yaml
     *
     * @option config     The eslint config file.
     * @option extensions The extensions to check.
     * @option options    Extra options for the command without -- (only options with no value).
     *
     * @aliases tk-yaml, tly
     *
     * @usage --extensions='.yml' --options='fix no-eslintrc'
     */
    public function toolkitLintYaml(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'extensions' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'options' => InputOption::VALUE_OPTIONAL,
    ])
    {
        Toolkit::ensureArray($options['extensions']);

        return $this->toolkitRunEsLint($options['config'], $options['extensions'], $options['options']);
    }

    /**
     * Run lint JS.
     *
     * @command toolkit:lint-js
     *
     * @option config     The eslint config file.
     * @option extensions The extensions to check.
     * @option options    Extra options for the command without -- (only options with no value).
     *
     * @aliases tk-js, tljs
     *
     * @usage --extensions='.js' --options='fix no-eslintrc'
     */
    public function toolkitLintJs(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'extensions' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'options' => InputOption::VALUE_OPTIONAL,
    ])
    {
        Toolkit::ensureArray($options['extensions']);

        return $this->toolkitRunEsLint($options['config'], $options['extensions'], $options['options']);
    }

    /**
     * Execute the eslint.
     *
     * @param string $config
     *   The eslint config file.
     * @param array $extensions
     *   The extensions to check.
     * @param string $options
     *   Extra options for the command.
     *
     * @see toolkitLintYaml()
     * @see toolkitLintJs()
     */
    private function toolkitRunEsLint(string $config, array $extensions, string $options)
    {
        $tasks = [];

        $tasks[] = $this->taskExec($this->getBin('run'))->arg('toolkit:setup-eslint');

        $opts = [
            'config' => $config,
            'ext' => implode(',', $extensions),
        ];

        if (!empty($options)) {
            $extra = array_fill_keys(explode(' ', $options), null);
            $opts = array_merge($opts, $extra);
        }

        $tasks[] = $this->taskExec($this->getNodeBinPath('eslint'))->options($opts)->arg('.');

        return $this->collectionBuilder()->addTaskList($tasks);
    }

    /**
     * Run lint PHP.
     *
     * @command toolkit:lint-php
     *
     * @option exclude    The eslint config file.
     * @option extensions The extensions to check.
     * @option options    Extra options for the command without -- (only options with no value).
     *
     * @aliases tk-php, tlp
     */
    public function toolkitLintPhp(array $options = [
        'extensions' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'exclude' => InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        'options' => InputOption::VALUE_OPTIONAL,
    ])
    {
        Toolkit::ensureArray($options['extensions']);
        Toolkit::ensureArray($options['exclude']);

        $task = $this->taskExec($this->getBin('parallel-lint'));
        foreach ($options['exclude'] as $exclude) {
            $task->option('exclude', $exclude);
        }
        if ($options['extensions']) {
            $task->option('-e', implode(',', $options['extensions']));
        }
        if (!empty($options['options'])) {
            $opts = explode(' ', $options['options']);
            foreach ($opts as $opt) {
                $task->option($opt);
            }
        }

        $result = $task->rawArg('.')->run();
        if ($result->getExitCode() === 254) {
            return ResultData::EXITCODE_OK;
        }

        return $result->getExitCode();
    }

    /**
     * Run lint CSS.
     *
     * @command toolkit:lint-css
     *
     * @option exclude The stylelint config file.
     * @option files   The files to check.
     *
     * @aliases tk-css
     */
    public function toolkitLintCss(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'files' => InputOption::VALUE_REQUIRED,
    ])
    {
        $tasks = [];

        // Make sure eslint is properly installed.
        $tasks[] = $this->taskExec($this->getBin('run'))->arg('toolkit:setup-eslint');

        // Make sure the stylelint-config-drupal and stylelint are installed.
        $tasks[] = $this->taskExecStack()
            ->exec('npm -v || npm i npm')
            ->exec('[ -f package.json ] || npm init -y --scope')
            ->exec('npm list stylelint-config-drupal && npm update stylelint-config-drupal || npm install stylelint-config-drupal -y');

        // Generate the config file if missing.
        if (!file_exists($options['config'])) {
            $data = ['extends' => 'stylelint-config-drupal'];
            $tasks[] = $this->taskWriteToFile($options['config'])
                ->text(json_encode($data, JSON_PRETTY_PRINT));
        }

        $tasks[] = $this->taskExec($this->getNodeBinPath('stylelint'))
            ->rawArg($options['files']);

        return $this->collectionBuilder()->addTaskList($tasks);
    }

    /**
     * Run lint CSpell.
     *
     * @command toolkit:lint-cspell
     *
     * @option config  The path to the config file.
     * @option files   The files to check.
     * @option options Extra options for the command.
     *
     * @aliases tk-cspell
     *
     * @usage --files='lib' --config='web/core/.cspell.json' --options='--gitignore'
     */
    public function toolkitLintCsPell(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'files' => InputOption::VALUE_REQUIRED,
        'options' => InputOption::VALUE_OPTIONAL,
    ])
    {
        $tasks = [];
        $bin = $this->getNodeBinPath('cspell');

        // Install dependencies if the bin is not present.
        if (!file_exists($bin)) {
            $tasks[] = $this->taskExecStack()
                ->exec('npm -v || npm i npm')
                ->exec('[ -f package.json ] || npm init -y --scope')
                ->exec('npm list cspell && npm update cspell || npm install cspell -y');
        }

        // Ensure the config file exists.
        if (!file_exists($options['config'])) {
            $tasks[] = $this->taskFilesystemStack()->copy(
                Toolkit::getToolkitRoot() . '/resources/cspell/.project-cspell.json',
                '.cspell.json'
            );
        }

        $command = $bin . ' ' . $options['files'] . ' --config=' . $options['config'];
        $tasks[] = $this->taskExec($command . ' ' . $options['options']);
        return $this->collectionBuilder()->addTaskList($tasks);
    }

    /**
     * Run lint Behat.
     *
     * @command toolkit:lint-behat
     *
     * @option config  The path to the config file.
     * @option files   The files to check.
     *
     * @aliases tk-lbehat
     *
     * @usage --files='tests/features' --config='gherkinlint.json'
     */
    public function toolkitLintBehat(array $options = [
        'config' => InputOption::VALUE_REQUIRED,
        'files' => InputOption::VALUE_REQUIRED,
    ])
    {
        $tasks = [];
        $bin = $this->getBinPath('gherkinlint');

        // Ensure the config file exists.
        if (!file_exists($options['config'])) {
            $this->output->writeln('Could not find the config file, the default will be created in the project root.');
            $tasks[] = $this->taskFilesystemStack()->copy(
                Toolkit::getToolkitRoot() . '/resources/gherkinlint.json',
                $options['config']
            );
        }

        $tasks[] = $this->taskExec($bin . ' lint ' . $options['files']);
        return $this->collectionBuilder()->addTaskList($tasks);
    }

}
