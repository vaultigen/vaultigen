<?php

namespace App\Services\Operations;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs external database backup tools without exposing credentials.
 *
 * Passwords are passed through the tool's own environment variable rather than
 * on the command line, so they cannot be read from the process list. Output is
 * streamed straight to or from a file; the snapshot is never held in memory.
 * Failures throw a sanitized exception that does not include the command line.
 */
class CommandRunner
{
    /**
     * Run a command and stream its standard output into a destination file.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function runToFile(array $command, array $environment, string $destination): void
    {
        $handle = @fopen($destination, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The database snapshot file could not be created.');
        }

        try {
            $process = new Process($command, null, $environment);
            $process->setTimeout(max(300, (int) config('vaultigen.backup_job_timeout')));
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    if (fwrite($handle, $buffer) !== strlen($buffer)) {
                        throw new RuntimeException('The snapshot could not be written.');
                    }
                }
            });
            $successful = $process->isSuccessful();
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($destination);

            throw new RuntimeException('The database snapshot tool could not be run.', previous: $exception);
        }

        fclose($handle);

        if (! $successful) {
            @unlink($destination);

            throw new RuntimeException('The database snapshot tool reported a failure.');
        }
    }

    /**
     * Run a command with a file as its standard input.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function runWithFileInput(array $command, array $environment, string $source): void
    {
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The database snapshot file could not be read.');
        }

        try {
            $process = new Process($command, null, $environment);
            $process->setTimeout(max(300, (int) config('vaultigen.backup_job_timeout')));
            $process->setInput($handle);
            $process->run();
            $successful = $process->isSuccessful();
        } catch (Throwable $exception) {
            fclose($handle);

            throw new RuntimeException('The database restore tool could not be run.', previous: $exception);
        }

        fclose($handle);

        if (! $successful) {
            throw new RuntimeException('The database restore tool reported a failure.');
        }
    }
}
