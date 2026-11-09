<?php

namespace Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Disposable containers, volumes, and a private network for exercising an
 * already-built local production image. Nothing is built, pulled for the
 * application, or published; every resource is removed by destroy().
 */
final class ContainerSandbox
{
    /** Test-only environment; production defaults stay enabled in the image. */
    public const ENVIRONMENT = [
        'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=/app/storage/app/private/test.sqlite',
        'VAULTIGEN_VERSION=must-not-override-source',
    ];

    public readonly string $prefix;

    public readonly string $image;

    /** @var list<string> */
    private array $containers = [];

    /** @var list<string> */
    private array $volumes = [];

    public function __construct(public readonly string $engine, string $image)
    {
        $this->prefix = 'vaultigen-image-'.bin2hex(random_bytes(6));
        $this->image = $this->output(['image', 'inspect', '--format', '{{.Id}}', $image]);
        $this->output(['network', 'create', $this->prefix]);
    }

    public static function fromEnvironment(): self
    {
        return new self(getenv('CONTAINER_ENGINE') ?: 'docker', getenv('VAULTIGEN_TEST_IMAGE') ?: 'vaultigen:local');
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments, bool $mustSucceed = true, ?string $input = null): Process
    {
        $process = new Process([$this->engine, ...$arguments], timeout: 900);
        $process->setInput($input);
        $process->run();

        if ($mustSucceed && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function output(array $arguments): string
    {
        return trim($this->run($arguments)->getOutput());
    }

    public function volume(string $suffix): string
    {
        $name = $this->prefix.'-'.$suffix;
        $this->output(['volume', 'create', $name]);
        $this->volumes[] = $name;

        return $name;
    }

    /**
     * Start a detached container on the private network and return its name.
     *
     * @param  list<string>  $arguments
     */
    public function start(string $suffix, array $arguments): string
    {
        $name = $this->prefix.'-'.$suffix;
        $this->containers[] = $name;
        $this->output(['run', '-d', '--name', $name, '--network', $this->prefix, ...$arguments]);

        return $name;
    }

    /**
     * Run a one-off production image command with a storage volume and the runtime probe mounted.
     *
     * @param  list<string>  $command
     * @param  list<string>|null  $environment
     */
    public function oneOff(string $storage, array $command, ?array $environment = null, bool $mustSucceed = true, ?string $input = null): Process
    {
        return $this->run([
            'run', '--rm', ...($input === null ? [] : ['-i']), '--network', $this->prefix,
            '-v', $storage.':/app/storage',
            '-v', self::probePath().':/checks/runtime.php:ro',
            ...self::environmentArguments($environment ?? self::ENVIRONMENT),
            $this->image, ...$command,
        ], $mustSucceed, $input);
    }

    /**
     * Create an initialized and migrated SQLite storage volume.
     */
    public function sqliteStorage(): string
    {
        $storage = $this->volume('sqlite');
        $this->oneOff($storage, ['php', '-r', 'touch("/app/storage/app/private/test.sqlite");']);
        $this->oneOff($storage, ['artisan', 'vaultigen:initialize', '--no-interaction']);
        $this->oneOff($storage, ['artisan', 'migrate', '--force', '--no-interaction']);

        return $storage;
    }

    /**
     * Stop a container with a grace period and return its exit code.
     */
    public function stop(string $name): string
    {
        $this->output(['stop', '--time', '15', $name]);

        return $this->output(['inspect', '--format', '{{.State.ExitCode}}', $name]);
    }

    public function waitUntil(callable $condition, int $seconds, string $message, ?string $container = null): void
    {
        for ($attempt = 0; $attempt < $seconds; $attempt++) {
            if ($condition()) {
                return;
            }
            sleep(1);
        }

        $logs = $container === null ? '' : "\n".$this->run(['logs', '--tail', '40', $container], false)->getErrorOutput();

        throw new RuntimeException($message.$logs);
    }

    public function destroy(): void
    {
        foreach (array_reverse($this->containers) as $name) {
            $this->run(['rm', '--force', '--volumes', $name], false);
        }
        foreach (array_reverse($this->volumes) as $name) {
            $this->run(['volume', 'rm', $name], false);
        }
        $this->run(['network', 'rm', $this->prefix], false);
    }

    public static function probePath(): string
    {
        return dirname(__DIR__).'/Fixtures/container-runtime.php';
    }

    /**
     * @param  list<string>  $environment
     * @return list<string>
     */
    public static function environmentArguments(array $environment): array
    {
        return array_merge(...array_map(fn (string $value): array => ['-e', $value], $environment));
    }
}
