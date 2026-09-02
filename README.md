# unbash

Async, non-blocking shell command runner for [ReactPHP](https://reactphp.org/).

`unbash` is a small, promise-based alternative to blocking calls such as
`shell_exec()` / `exec()`. Each command runs as a child process on the ReactPHP
event loop, so many commands can run concurrently without blocking.

## Install

```bash
composer require reactphp-x/unbash
```

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ReactphpX\Unbash\Result;
use ReactphpX\Unbash\Unbash;

Unbash::run('echo hello world')->then(function (Result $result) {
    echo $result->stdout;          // "hello world\n"
    echo $result->exitCode;        // 0
    var_dump($result->isSuccessful()); // true
});
```

`Unbash::run()` returns a `React\Promise\PromiseInterface` that resolves with a
`Result` once the command exits:

- `Result::$exitCode` — the process exit code
- `Result::$stdout` — everything written to standard output
- `Result::$stderr` — everything written to standard error
- `Result::isSuccessful()` — `true` when the exit code is `0`

Because commands are non-blocking, several can be in flight at once:

```php
Unbash::run('sleep 1 && echo slow')->then(fn (Result $r) => print($r->stdout));
Unbash::run('echo fast')->then(fn (Result $r) => print($r->stdout));
// "fast" prints before "slow"
```

See [`examples/run.php`](examples/run.php) for a runnable demo.

## Development

```bash
composer install
composer test        # run the PHPUnit test suite
php examples/run.php # run the example
```

## License

MIT
