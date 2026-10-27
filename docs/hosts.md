---
title: Hosts
order: 15
---

# Hosts

The plain command line and the hosts' commands share one `Console\Cli`. A host hands the site's file
what it offers; the file chooses what to use.

## The site file's closure

```php
// static.php
use Omnibridge\Static\Console\Cli;
use Omnibridge\Static\Route\RouteCollectionProvider;
use Omnibridge\Static\Site;

return static function (array $host): Site {
    return (new Site('https://example.org'))
        ->provide(new RouteCollectionProvider($host[Cli::HOST_ROUTES], parameters: [/* ... */], actions: ['App\Action\\']))
        ->renderWith($host[Cli::HOST_RENDERER])
        ->publicDir($host[Cli::HOST_PUBLIC_DIR])
        ->notFound('/404');
};
```

| Key | Constant | Symfony | Laravel | Plain command line |
|---|---|---|---|---|
| `host` | `Cli::HOST_NAME` | `symfony` | `laravel` | absent |
| `renderer` | `Cli::HOST_RENDERER` | a `RequestRenderer` over the kernel's `handle()` | a `RequestRenderer` over the HTTP kernel | absent |
| `routes` | `Cli::HOST_ROUTES` | the router's `RouteCollection` | given `--actions`, the routes omnibridge/laravel's `Routing\ActionRoutes` reads from the actions of those folders; absent otherwise | absent |
| `container` | `Cli::HOST_CONTAINER` | the kernel's container | the application | absent |
| `publicDir` | `Cli::HOST_PUBLIC_DIR` | `<project>/public` | `public_path()` | absent |

`Cli::load(string $file, array $host = []): Site` requires the file, calls the closure with the
offer, and refuses anything that is not a `Site` (`InvalidArgumentException`). From the plain command
line the offer is empty: the file loads its application's autoloader and boots what it needs itself.

## omnibridge/symfony: `bin/console omnibridge:static:build`

Registered by omnibridge/symfony's `config/services.php` when this package is installed
(`Omnibridge\Symfony\Static\BuildCommand`):

```sh
bin/console omnibridge:static:build static.php --out=var/static [--render-origin=https://example.org] [--verify] [--strict] [--quiet-progress]
```

`--out` defaults to `var/static`; `--render-origin` to the router's request context (scheme, host,
port unless 80 or 443); `--quiet-progress` is the command line's `--quiet`. There is no `--target`
option: a site for GitHub Pages calls `->githubPages()` in its file. omnibridge/symfony's manual has
the details.

## omnibridge/laravel: `php artisan omnibridge:static:build`

Registered by omnibridge/laravel's `OmnibridgeServiceProvider` when this package is installed
(`Omnibridge\Laravel\Static\BuildCommand`):

```sh
php artisan omnibridge:static:build static.php --out=storage/static --actions='App\Action\=src/Action' [--render-origin=...] [--verify] [--strict] [--quiet-progress]
```

| Argument or option | Default | Effect |
|---|---|---|
| `site` | `static.php` | relative to the application's base path unless absolute |
| `--out` | `storage/static` | relative to the base path unless absolute |
| `--render-origin` | `config('app.url')`, else `http://localhost` | the origin the pages are rendered at |
| `--actions=Namespace\=folder` | none (repeatable) | the actions whose `#[Route]` attributes give the offer's `routes` |
| `--verify`, `--strict`, `--quiet-progress` | off | as the command line's `--verify`, `--strict`, `--quiet` |

Each page goes through the HTTP kernel as `Illuminate\Http\Request::createFromBase()` of the
renderer's request. An invalid argument prints the message and exits with `INVALID` (2).

## Calling Cli yourself

```php
use Omnibridge\Static\Console\Cli;

$cli = new Cli(static fn (string $line) => print($line."\n"));   // where the lines go; STDERR by default
$code = $cli->build('static.php', ['out' => 'dist', 'strict' => true], $host);
$code = $cli->check('dist');
$code = $cli->run(['build', 'static.php', '--out=dist']);           // the command line's parsing
```

`build()` and `check()` throw `InvalidArgumentException` for what `run()` answers with exit code 2.
