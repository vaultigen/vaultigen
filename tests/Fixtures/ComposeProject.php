<?php

namespace Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * A disposable Compose installation built from the customer files in
 * scripts/docker, using an already-built local image and loopback-only ports.
 * Like a customer installation, it keeps all state in its own directory
 * (data/, backups/). destroy() removes its containers, network, and directory.
 */
final class ComposeProject
{
    public readonly string $directory;

    public readonly string $name;

    public readonly int $httpPort;

    public readonly int $httpsPort;

    /** The image used for root helper containers that copy or remove container-owned files. */
    public readonly string $image;

    /**
     * @param  list<string>  $overrides  Additional Compose files from scripts/docker.
     * @param  array<string, string>  $environment  Extra .env settings.
     * @param  bool  $probe  Mount the runtime probe at /__container_probe.php.
     */
    public function __construct(string $suffix, array $overrides = [], array $environment = [], bool $probe = false)
    {
        $this->name = 'vaultigen-compose-'.$suffix.'-'.bin2hex(random_bytes(4));
        $this->directory = sys_get_temp_dir().'/'.$this->name;
        $this->httpPort = self::freePort();
        $this->httpsPort = self::freePort();
        $this->image = getenv('VAULTIGEN_TEST_IMAGE') ?: 'localhost/vaultigen:local';
        $umask = umask(0077);
        mkdir($this->directory.'/backups', 0700, true);
        umask($umask);

        $source = dirname(__DIR__, 2).'/scripts/docker';
        foreach (['compose.yaml', 'compose.proxy.yaml', 'compose.build.yaml'] as $file) {
            copy($source.'/'.$file, $this->directory.'/'.$file);
        }
        $ports = in_array('compose.proxy.yaml', $overrides, true)
            ? ["127.0.0.1:{$this->httpPort}:8080"]
            : ["127.0.0.1:{$this->httpPort}:8080", "127.0.0.1:{$this->httpsPort}:8443"];
        $probeVolume = $probe ? "    volumes:\n      - ".ContainerSandbox::probePath().":/app/public/__container_probe.php:ro\n" : '';
        file_put_contents($this->directory.'/compose.test.yaml', "services:\n  web:\n    ports: !override\n".implode('', array_map(fn (string $port): string => "      - \"{$port}\"\n", $ports)).$probeVolume);

        $settings = [
            'DB_PASSWORD' => bin2hex(random_bytes(32)),
            'VAULTIGEN_DOMAIN' => 'localhost',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_MAILER' => 'log',
            'COMPOSE_PROJECT_NAME' => $this->name,
            'COMPOSE_FILE' => implode(':', ['compose.yaml', ...$overrides, 'compose.build.yaml', 'compose.test.yaml']),
            'VAULTIGEN_LOCAL_IMAGE' => $this->image,
            ...$environment,
        ];
        copy($source.'/env.example', $this->directory.'/.env');
        $this->configure($settings);
    }

    /**
     * @param  list<string>  $arguments
     * @param  string|resource|null  $input
     */
    public function compose(array $arguments, mixed $input = null, bool $mustSucceed = true): Process
    {
        $process = new Process(['docker', 'compose', ...$arguments], $this->directory, self::environment(), timeout: 900);
        $process->setInput($input);
        $process->run();
        if ($mustSucceed && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * Run setup and create the first administrator through piped input.
     */
    public function install(): Process
    {
        return $this->compose(['run', '--rm', '-T', '-e', 'SHELL_INTERACTIVE=1', 'setup', 'setup', '--email=admin@example.com'], "Zq7!vR2#mW9xLp4TbN8s\n");
    }

    /**
     * Pipe the drill data script into the web container, or into a one-off web
     * container while the stack is stopped, and decode its summary.
     *
     * @return array<string, mixed>
     */
    public function drill(string $mode, bool $oneOff = false): array
    {
        $command = $oneOff ? ['run', '--rm', '--no-deps', '-T', 'web'] : ['exec', '-T', 'web'];
        $output = $this->compose([...$command, 'php', '--', $mode], (string) file_get_contents(__DIR__.'/container-compose-data.php'))->getOutput();

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Set or replace .env values; Compose recreates affected services on the next up.
     *
     * @param  array<string, string>  $settings
     */
    public function configure(array $settings): void
    {
        $environment = (string) file_get_contents($this->directory.'/.env');
        foreach ($settings as $key => $value) {
            $line = $key.'='.$value;
            $environment = preg_match('/^'.$key.'=.*$/m', $environment) === 1
                ? (string) preg_replace_callback('/^'.$key.'=.*$/m', fn (): string => $line, $environment)
                : $environment.$line."\n";
        }
        file_put_contents($this->directory.'/.env', $environment);
    }

    /**
     * Take over another installation's .env, as a replacement server would,
     * keeping this project's own name and loopback ports.
     */
    public function adopt(self $original): void
    {
        copy($original->directory.'/.env', $this->directory.'/.env');
        $this->configure(['COMPOSE_PROJECT_NAME' => $this->name]);
    }

    /**
     * The image ID of a running service's container.
     */
    public function imageOf(string $service): string
    {
        $container = trim($this->compose(['ps', '-q', $service])->getOutput());

        return trim((new Process(['docker', 'inspect', '--format', '{{.Image}}', $container], env: self::environment()))->mustRun()->getOutput());
    }

    public function psql(string $sql): string
    {
        return trim($this->compose(['exec', '-T', 'database', 'psql', '-U', 'vaultigen', '-d', 'vaultigen', '-tAc', $sql])->getOutput());
    }

    /**
     * Copy paths from this installation's directory into another one with
     * owners and modes preserved, as an operator would with sudo cp -a.
     *
     * @param  list<string>  $paths  Relative to the installation directory.
     */
    public function copyTo(self $target, array $paths): void
    {
        $script = implode(' && ', array_map(fn (string $path): string => 'mkdir -p "/to/$(dirname '.escapeshellarg($path).')" && cp -a '.escapeshellarg('/from/'.$path).' '.escapeshellarg('/to/'.$path), $paths));
        $this->root(['-v', $this->directory.':/from:ro', '-v', $target->directory.':/to'], $script);
    }

    public function destroy(): void
    {
        $this->compose(['--profile', 'tools', 'down', '--remove-orphans'], mustSucceed: false);
        $this->root(['-v', $this->directory.':/project'], 'rm -rf /project/data /project/backups', mustSucceed: false);
        (new Process(['rm', '-rf', $this->directory]))->run();
    }

    /**
     * Run a shell script as root in a throwaway container of the test image.
     *
     * @param  list<string>  $mounts
     */
    private function root(array $mounts, string $script, bool $mustSucceed = true): void
    {
        $process = new Process(['docker', 'run', '--rm', '--user', '0:0', '--entrypoint', 'sh', ...$mounts, $this->image, '-c', $script], env: self::environment(), timeout: 600);
        $process->run();
        if ($mustSucceed && ! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * Only the variables the engine client needs. Shell variables override
     * .env during Compose interpolation, so the test runner's own settings
     * (DB_CONNECTION, APP_ENV, ...) must not reach it.
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $allowed = ['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CONTEXT', 'XDG_RUNTIME_DIR', 'TMPDIR'];
        $environment = [];
        foreach (array_merge(getenv(), $_ENV, $_SERVER) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $environment[$name] = in_array($name, $allowed, true) ? $value : false;
            }
        }

        return $environment;
    }

    /**
     * A free loopback port below the kernel's ephemeral range, so outgoing
     * connections cannot claim it before Docker binds it. Ports handed out
     * earlier in this run are never reused, since a torn-down project may
     * still hold them.
     */
    private static function freePort(): int
    {
        static $issued = [];

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $port = random_int(20000, 32000);
            if (isset($issued[$port])) {
                continue;
            }
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}");
            if ($socket !== false) {
                fclose($socket);
                $issued[$port] = true;

                return $port;
            }
        }

        throw new RuntimeException('No free loopback port is available for the Compose test project.');
    }
}
