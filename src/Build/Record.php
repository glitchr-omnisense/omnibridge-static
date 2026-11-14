<?php

namespace Omnibridge\Static\Build;

/**
 * What the last export that filled a cache depended on, kept beside its
 * answers (<cache>/omnibridge-static.build.json, restored with them): the
 * package's code, the environment (Build\Environment), the PHP files the
 * process loaded with their SHA-256, the fingerprint line of each declared
 * input, and each address rendered or reused with its fingerprint and its
 * declared inputs.
 *
 * The next export trusts the cache only when this record is there, of this
 * format and this package's code, made in the same environment, and when
 * none of the PHP files it lists changed or went: otherwise that export is a
 * full one (distrust()). The export's own folder never holds the record, so
 * a full and an incremental export write the same files.
 */
final class Record
{
    public const FILE = 'omnibridge-static.build.json';
    public const FORMAT = 1;

    /**
     * @param array{php: string, extensions: array<string, string>, packages: array<string, string>} $environment
     * @param array<string, string|null>                                                              $loaded      PHP file => SHA-256 (null: gone at the end of the export)
     * @param array<string, string>                                                                   $inputs      declared input => SHA-256 of its fingerprint line
     * @param list<array{path: string, role: string, fingerprint: string|null, inputs: list<string>|null}> $addresses every address rendered or reused, sorted by path and role
     */
    public function __construct(
        public readonly string $code,
        public readonly array $environment,
        public readonly array $loaded,
        public readonly array $inputs,
        public readonly array $addresses,
    ) {
    }

    /** The record a cache folder holds, or null: none, unreadable, or of another format. */
    public static function read(string $dir): ?self
    {
        $json = @file_get_contents($dir.'/'.self::FILE);
        if (false === $json) {
            return null;
        }
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || self::FORMAT !== ($data['format'] ?? null) || !\is_string($data['code'] ?? null)) {
            return null;
        }
        $environment = $data['environment'] ?? null;
        if (!\is_array($environment) || !\is_string($environment['php'] ?? null) || null === $extensions = self::strings($environment['extensions'] ?? null)) {
            return null;
        }
        if (null === $packages = self::strings($environment['packages'] ?? null)) {
            return null;
        }
        if (!\is_array($data['loaded'] ?? null) || !\is_array($data['addresses'] ?? null)) {
            return null;
        }
        $loaded = [];
        foreach ($data['loaded'] as $file => $hash) {
            if (!\is_string($file) || !(null === $hash || \is_string($hash))) {
                return null;
            }
            $loaded[$file] = $hash;
        }
        if (null === $inputs = self::strings($data['inputs'] ?? null)) {
            return null;
        }
        $addresses = [];
        foreach ($data['addresses'] as $address) {
            if (!\is_array($address) || !\is_string($address['path'] ?? null) || !\is_string($address['role'] ?? null)) {
                return null;
            }
            $fingerprint = $address['fingerprint'] ?? null;
            $declared = $address['inputs'] ?? null;
            if (!(null === $fingerprint || \is_string($fingerprint)) || !(null === $declared || \is_array($declared) && array_is_list($declared) && $declared === array_filter($declared, \is_string(...)))) {
                return null;
            }
            /** @var list<string>|null $declared */
            $addresses[] = ['path' => $address['path'], 'role' => $address['role'], 'fingerprint' => $fingerprint, 'inputs' => $declared];
        }

        return new self($data['code'], ['php' => $environment['php'], 'extensions' => $extensions, 'packages' => $packages], $loaded, $inputs, $addresses);
    }

    /** Removes a cache folder's record: until a new one is written, the cache is not trusted. */
    public static function forget(string $dir): void
    {
        if (is_file($dir.'/'.self::FILE) && !unlink($dir.'/'.self::FILE)) {
            throw new \RuntimeException(\sprintf('The build record "%s" cannot be removed.', $dir.'/'.self::FILE));
        }
    }

    /** Written aside, then renamed: the record is whole or absent. */
    public function write(string $dir): void
    {
        $json = json_encode([
            'generator' => 'omnibridge/static',
            'format' => self::FORMAT,
            'code' => $this->code,
            'environment' => [
                'php' => $this->environment['php'],
                'extensions' => (object) $this->environment['extensions'],
                'packages' => (object) $this->environment['packages'],
            ],
            'loaded' => (object) $this->loaded,
            'inputs' => (object) $this->inputs,
            'addresses' => $this->addresses,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
        $temporary = $dir.'/'.self::FILE.'.'.getmypid().'.tmp';
        if (false === file_put_contents($temporary, $json) || !rename($temporary, $dir.'/'.self::FILE)) {
            @unlink($temporary);
            throw new \RuntimeException(\sprintf('The build record "%s" cannot be written.', $dir.'/'.self::FILE));
        }
    }

    /**
     * Why the cache this record describes cannot be trusted by an export made with this code in this
     * environment, or null when it can: another version of the package, another environment, or a PHP
     * file the earlier export loaded that changed or went (those $ignore matches aside).
     *
     * @param array{php: string, extensions: array<string, string>, packages: array<string, string>} $environment
     * @param list<string>                                                                            $ignore
     */
    public function distrust(string $code, array $environment, array $ignore = []): ?string
    {
        if ($code !== $this->code) {
            return 'the cache was filled by another version of omnibridge/static';
        }
        if (null !== $difference = Environment::difference($this->environment, $environment)) {
            return $difference;
        }
        foreach ($this->loaded as $file => $hash) {
            if (Environment::ignored($file, $ignore)) {
                continue;
            }
            $now = is_file($file) ? hash_file('sha256', $file) : null;
            if ($now !== $hash) {
                return \sprintf('a PHP file the earlier export loaded %s: %s', null === $now ? 'is gone' : (null === $hash ? 'is back' : 'changed'), $file);
            }
        }

        return null;
    }

    /**
     * The declared inputs whose fingerprint line differs from this record's (new ones aside), sorted.
     *
     * @param array<string, string> $inputs declared input => SHA-256 of its fingerprint line, of this export
     *
     * @return list<string>
     */
    public function changedInputs(array $inputs): array
    {
        $changed = [];
        foreach ($inputs as $input => $hash) {
            if (isset($this->inputs[$input]) && $this->inputs[$input] !== $hash) {
                $changed[] = (string) $input;
            }
        }
        sort($changed, \SORT_STRING);

        return $changed;
    }

    /** @return array<string, string>|null */
    private static function strings(mixed $values): ?array
    {
        if (!\is_array($values)) {
            return null;
        }
        $strings = [];
        foreach ($values as $key => $value) {
            if (!\is_string($value)) {
                return null;
            }
            $strings[(string) $key] = $value;
        }

        return $strings;
    }
}
