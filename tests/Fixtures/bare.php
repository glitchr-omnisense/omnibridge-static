<?php

// Loads every class of src/ with nothing but the package's own classes (no Composer, no vendor/) and
// prints, as JSON, the classes it loaded and every class and file the process declared on the way.
$src = (string) realpath(__DIR__.'/../../src');
spl_autoload_register(static function (string $class) use ($src): void {
    if (str_starts_with($class, 'Omnibridge\\Static\\')) {
        $file = $src.'/'.str_replace('\\', '/', substr($class, strlen('Omnibridge\\Static\\'))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$loaded = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }
    $class = 'Omnibridge\\Static\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
    if (class_exists($class) || interface_exists($class)) {
        $loaded[] = $class;
    }
}
sort($loaded);

echo json_encode([
    'loaded' => $loaded,
    'symbols' => array_merge(get_declared_classes(), get_declared_interfaces()),
    'files' => get_included_files(),
]);
