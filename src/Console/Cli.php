<?php

namespace Omnibridge\Static\Console;

use Omnibridge\Static\Diagnostic;
use Omnibridge\Static\Exporter;
use Omnibridge\Static\Report;
use Omnibridge\Static\Site;

/**
 * The command line, plain PHP (bin/omnibridge-static), which the Symfony
 * command and the artisan command of omnibridge/symfony and omnibridge/laravel
 * call with their host:
 *
 *     omnibridge-static build static.php [--out=dist] [--verify] [--strict] [--quiet]
 *     omnibridge-static check dist
 *
 * build: the site the file declares (it returns a Site, or a closure that
 * takes the host's offer - Cli::HOST_* - and returns one) exported to --out;
 * the exit code is 1 when there is an error (with --strict, a warning too).
 * check: an export's files against its omnibridge-static.json (each there,
 * the same checksum, nothing more) - what a deployment step runs before it
 * publishes.
 */
final class Cli
{
    /** What a host hands the site's file: a renderer through its request handling. */
    public const HOST_RENDERER = 'renderer';
    /** ...its routes (a symfony/routing RouteCollection). */
    public const HOST_ROUTES = 'routes';
    /** ...its container (PSR-11). */
    public const HOST_CONTAINER = 'container';
    /** ...its public folder. */
    public const HOST_PUBLIC_DIR = 'publicDir';
    /** ...its name ("symfony", "laravel"). */
    public const HOST_NAME = 'host';

    /** @param \Closure(string): void $out where the lines go (STDERR by default) */
    public function __construct(private readonly ?\Closure $out = null)
    {
    }

    /**
     * The site a file declares.
     *
     * @param array<string, mixed> $host what the host offers (Cli::HOST_*), empty from the plain command line
     */
    public static function load(string $file, array $host = []): Site
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(\sprintf('No file "%s".', $file));
        }
        $declared = (static fn (string $file): mixed => require $file)($file);
        if ($declared instanceof \Closure) {
            $declared = $declared($host);
        }
        if (!$declared instanceof Site) {
            throw new \InvalidArgumentException(\sprintf('"%s" returns no %s (nor a closure that returns one).', $file, Site::class));
        }

        return $declared;
    }

    /**
     * @param list<string>         $arguments the command line's, without the script's name
     * @param array<string, mixed> $host      what the host offers (Cli::HOST_*)
     */
    public function run(array $arguments, array $host = []): int
    {
        $command = array_shift($arguments);
        $options = [];
        $positional = [];
        foreach ($arguments as $argument) {
            if (preg_match('~^--([a-z-]+)(?:=(.*))?$~', $argument, $match)) {
                $options[$match[1]] = $match[2] ?? true;
            } else {
                $positional[] = $argument;
            }
        }

        try {
            return match ($command) {
                'build' => $this->build($positional[0] ?? 'static.php', $options, $host),
                'check' => $this->check($positional[0] ?? 'dist'),
                default => $this->usage(),
            };
        } catch (\InvalidArgumentException $e) {
            $this->line($e->getMessage());

            return 2;
        }
    }

    /**
     * @param array<string, string|true> $options
     * @param array<string, mixed>       $host
     */
    public function build(string $file, array $options, array $host = []): int
    {
        $quiet = isset($options['quiet']);
        $site = self::load($file, $host);
        $out = \is_string($options['out'] ?? null) ? $options['out'] : 'dist';
        $report = (new Exporter($quiet ? null : fn (string $line) => $this->line($line)))->export($site, $out, isset($options['verify']));
        $this->summary($report, $quiet);

        return $report->hasErrors() || (isset($options['strict']) && $report->warnings()) ? 1 : 0;
    }

    public function check(string $folder): int
    {
        $folder = rtrim($folder, '/');
        $json = @file_get_contents($folder.'/'.Exporter::REPORT);
        if (false === $json) {
            throw new \InvalidArgumentException(\sprintf('"%s" holds no %s.', $folder, Exporter::REPORT));
        }
        $report = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $files = \is_array($report) && \is_array($report['files'] ?? null) ? $report['files'] : [];

        $problems = [];
        foreach ($files as $file => $expected) {
            $path = $folder.'/'.$file;
            if (!is_file($path)) {
                $problems[] = 'missing: '.$file;
            } elseif (!\is_array($expected) || hash_file('sha256', $path) !== ($expected['sha256'] ?? null)) {
                $problems[] = 'changed: '.$file;
            }
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $file = substr($entry->getPathname(), \strlen($folder) + 1);
                if (Exporter::REPORT !== $file && !isset($files[$file])) {
                    $problems[] = 'not in the report: '.$file;
                }
            }
        }
        sort($problems);
        foreach ($problems as $problem) {
            $this->line($problem);
        }
        $this->line(\sprintf('%d files checked, %d problems', \count($files), \count($problems)));

        return $problems ? 1 : 0;
    }

    private function summary(Report $report, bool $quiet): void
    {
        foreach ($report->diagnostics as $diagnostic) {
            if (!$quiet || Diagnostic::ERROR === $diagnostic->level) {
                $this->line((string) $diagnostic);
            }
        }
        foreach ($report->counts() as $code => $count) {
            $this->line(\sprintf('%5d %s', $count, $code));
        }
    }

    private function usage(): int
    {
        $this->line('omnibridge-static build <site.php> [--out=dist] [--verify] [--strict] [--quiet]');
        $this->line('omnibridge-static check <dist>');

        return 2;
    }

    private function line(string $line): void
    {
        if (null !== $this->out) {
            ($this->out)($line);

            return;
        }
        fwrite(\STDERR, $line."\n");
    }
}
