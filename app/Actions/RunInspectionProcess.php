<?php

namespace App\Actions;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class RunInspectionProcess
{
    /** @return array{state: string, output: string, exit_code: ?int} */
    public function handle(array $command, string $directory, array $environment = [], bool $includeStderr = false): array
    {
        $clean = [];
        foreach (array_keys(getenv()) as $key) {
            if (preg_match('/^(GIT_|NODE_|NPM_|npm_|COMPOSER|YARN_|PNPM_|COREPACK_|DYLD_|LD_|PHPRC$|PHP_INI_SCAN_DIR$|BASH_ENV$|ENV$)/', $key)) {
                $clean[$key] = false;
            }
        }
        $process = new Process($command, $directory, [...$clean, ...$environment], null, 3);
        $bytes = 0;
        $output = '';
        try {
            $process->run(function (string $type, string $chunk) use ($process, &$bytes, &$output, $includeStderr) {
                $bytes += strlen($chunk);
                if ($bytes > 65536) {
                    throw new RuntimeException('Output limit exceeded');
                }
                if ($type === Process::OUT || $includeStderr) {
                    $output .= $chunk;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });

            return ['state' => $process->isSuccessful() ? 'Current' : 'Command failed', 'output' => trim($output), 'exit_code' => $process->getExitCode()];
        } catch (ProcessTimedOutException) {
            return ['state' => 'Timed out', 'output' => '', 'exit_code' => null];
        } catch (Throwable) {
            return ['state' => $bytes > 65536 ? 'Output limit exceeded' : 'Executable unavailable', 'output' => '', 'exit_code' => null];
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }
}
