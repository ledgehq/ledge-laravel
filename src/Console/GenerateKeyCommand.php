<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateKeyCommand extends Command
{
    protected $signature = 'ledge:generate-key {--show : Print the key without writing it to .env}';

    protected $description = 'Generate a Ledge secret key and save it to the .env file';

    public function handle(): int
    {
        $key = Str::random(32);

        if ($this->option('show')) {
            $this->line("LEDGE_SECRET_KEY={$key}");

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! $this->writeToEnv($path, $key)) {
            $this->error("Unable to save to {$path}");

            return self::FAILURE;
        }

        $this->info('Ledge secret key saved to '.$path);
        $this->line("LEDGE_SECRET_KEY={$key}");

        return self::SUCCESS;
    }

    private function writeToEnv(string $path, string $key): bool
    {
        $line = "LEDGE_SECRET_KEY={$key}";
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (preg_match('/^LEDGE_SECRET_KEY=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^LEDGE_SECRET_KEY=.*$/m', $line, $contents, 1);
        } else {
            $contents = rtrim($contents, "\r\n");
            $contents = ($contents === '' ? '' : $contents."\n\n").$line."\n";
        }

        return @file_put_contents($path, $contents) !== false;
    }
}
