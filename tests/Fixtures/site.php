<?php

// A site's file as the command line reads it: a closure that takes what the host offers.
use Omnibridge\Static\Console\Cli;
use Omnibridge\Static\Tests\Fixtures\PlainApp;

require_once __DIR__.'/PlainApp.php';

return static fn (array $host) => PlainApp::site(is_string($host[Cli::HOST_PUBLIC_DIR] ?? null) ? $host[Cli::HOST_PUBLIC_DIR] : PlainApp::publicDir());
