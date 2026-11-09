<?php

namespace App\Services\Imports;

use App\Contracts\ImportDriver;
use InvalidArgumentException;

/**
 * Resolves an import driver by its `source_format`.
 *
 * Drivers register by class-string so the registry never boots an importer it
 * does not need. The application registers sysPass at launch; later importers
 * add a driver here without changing the hub.
 */
final class ImportRegistry
{
    /** @var array<string, class-string<ImportDriver>> */
    private array $drivers = [];

    /**
     * @param  class-string<ImportDriver>  $driver
     */
    public function register(string $driver): void
    {
        $this->drivers[$driver::source()] = $driver;
    }

    public function has(string $source): bool
    {
        return array_key_exists($source, $this->drivers);
    }

    public function resolve(string $source): ImportDriver
    {
        if (! $this->has($source)) {
            throw new InvalidArgumentException("Unknown import source [{$source}].");
        }

        return app($this->drivers[$source]);
    }

    /**
     * The source => label option map for the hub.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];
        foreach (array_keys($this->drivers) as $source) {
            $options[$source] = $this->resolve($source)->label();
        }

        return $options;
    }
}
