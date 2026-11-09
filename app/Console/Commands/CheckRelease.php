<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;

class CheckRelease extends Command
{
    /**
     * How far the release date may be from the day the release is tagged.
     */
    private const MAX_DAYS_FROM_TAG = 7;

    protected $signature = 'vaultigen:release:check
        {tag : The release tag, for example v1.2.0}
        {--env-template=scripts/docker/env.example : The Docker Compose environment template, absolute or relative to the application root}';

    protected $description = 'Verify that the version, release date, and Docker Compose image tag match a release tag';

    public function handle(): int
    {
        $tag = (string) $this->argument('tag');
        $version = (string) config('vaultigen.version');
        $releaseDate = config('vaultigen.release_date');
        $errors = [];

        if (preg_match('/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $tag, $matches) !== 1) {
            $errors[] = "The tag {$tag} is not a release tag like v1.2.0.";
        } elseif ($matches[1] !== $version) {
            $errors[] = "The tag {$tag} does not match the version {$version} in config/vaultigen.php.";
        }

        $date = is_string($releaseDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate) : false;
        if ($date === false || $date->format('Y-m-d') !== $releaseDate) {
            $errors[] = 'Set release_date in config/vaultigen.php to the release day (YYYY-MM-DD). Licenses are checked against it.';
        } elseif (abs(CarbonImmutable::instance($date)->diffInDays(CarbonImmutable::today())) > self::MAX_DAYS_FROM_TAG) {
            $errors[] = "The release_date {$releaseDate} is more than ".self::MAX_DAYS_FROM_TAG.' days from today.';
        }

        $imageTagError = $this->imageTagError($version);
        if ($imageTagError !== null) {
            $errors[] = $imageTagError;
        }

        foreach ($errors as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->info("Release {$tag} ({$releaseDate}) is consistent.");

        return self::SUCCESS;
    }

    /**
     * Customers download the environment template from the release tag, so it must preselect that release's image.
     */
    private function imageTagError(string $version): ?string
    {
        $template = (string) $this->option('env-template');
        $path = str_starts_with($template, DIRECTORY_SEPARATOR) ? $template : base_path($template);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return "The Docker Compose environment template {$template} is missing.";
        }

        if (preg_match('/^VAULTIGEN_IMAGE_TAG=(.*)$/m', $contents, $matches) !== 1 || trim($matches[1]) !== $version) {
            return "Set VAULTIGEN_IMAGE_TAG={$version} in {$template}.";
        }

        return null;
    }
}
