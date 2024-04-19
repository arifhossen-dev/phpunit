<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Util\PHP;

use const PHP_BINARY;
use const PHP_SAPI;
use function array_keys;
use function array_merge;
use function assert;
use function fclose;
use function file_put_contents;
use function fwrite;
use function ini_get_all;
use function is_array;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use SebastianBergmann\Environment\Runtime;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final readonly class DefaultPhpJobRunner implements PhpJobRunner
{
    /**
     * @psalm-return array{stdout: string, stderr: string}
     *
     * @throws PhpProcessException
     */
    public function run(PhpJob $job): array
    {
        $temporaryFile = null;

        if ($job->hasInput()) {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'phpunit_');

            if ($temporaryFile === false ||
                file_put_contents($temporaryFile, $job->code()) === false) {
                throw new PhpProcessException(
                    'Unable to write temporary file',
                );
            }

            $job = new PhpJob(
                $job->input(),
                $job->phpSettings(),
                $job->environmentVariables(),
                $job->arguments(),
                null,
                $job->redirectErrors(),
            );
        }

        assert($temporaryFile !== '');

        return $this->runProcess($job, $temporaryFile);
    }

    /**
     * @psalm-param ?non-empty-string $temporaryFile
     *
     * @psalm-return array{stdout: string, stderr: string}
     *
     * @throws PhpProcessException
     */
    private function runProcess(PhpJob $job, ?string $temporaryFile): array
    {
        $environmentVariables = null;

        if ($job->hasEnvironmentVariables()) {
            $environmentVariables = $_SERVER ?? [];

            unset($environmentVariables['argv'], $environmentVariables['argc']);

            $environmentVariables = array_merge($environmentVariables, $job->environmentVariables());

            foreach ($environmentVariables as $key => $value) {
                if (is_array($value)) {
                    unset($environmentVariables[$key]);
                }
            }

            unset($key, $value);

        }

        $pipeSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($job->redirectErrors()) {
            $pipeSpec[2] = ['redirect', 1];
        }

        $process = proc_open(
            $this->buildCommand($job, $temporaryFile),
            $pipeSpec,
            $pipes,
            null,
            $environmentVariables,
        );

        if (!is_resource($process)) {
            throw new PhpProcessException(
                'Unable to spawn worker process',
            );
        }

        fwrite($pipes[0], $job->code());
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';

        if (isset($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]);

            fclose($pipes[1]);
        }

        if (isset($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[2]);
        }

        proc_close($process);

        if ($temporaryFile !== null) {
            unlink($temporaryFile);
        }

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @psalm-return non-empty-list<string>
     */
    private function buildCommand(PhpJob $job, ?string $file): array
    {
        $runtime     = new Runtime;
        $command     = [PHP_BINARY];
        $phpSettings = $job->phpSettings();

        if ($runtime->hasPCOV()) {
            $phpSettings = array_merge(
                $phpSettings,
                $runtime->getCurrentSettings(
                    array_keys(ini_get_all('pcov')),
                ),
            );
        } elseif ($runtime->hasXdebug()) {
            $phpSettings = array_merge(
                $phpSettings,
                $runtime->getCurrentSettings(
                    array_keys(ini_get_all('xdebug')),
                ),
            );
        }

        $command = array_merge($command, $this->settingsToParameters($phpSettings));

        if (PHP_SAPI === 'phpdbg') {
            $command[] = '-qrr';

            if ($file === null) {
                $command[] = 's=';
            }
        }

        if ($file !== null) {
            $command[] = '-f';
            $command[] = $file;
        }

        if ($job->hasArguments()) {
            if ($file === null) {
                $command[] = '--';
            }

            foreach ($job->arguments() as $argument) {
                $command[] = trim($argument);
            }
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private function settingsToParameters(array $settings): array
    {
        $buffer = [];

        foreach ($settings as $setting) {
            $buffer[] = '-d';
            $buffer[] = $setting;
        }

        return $buffer;
    }
}