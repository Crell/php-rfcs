<?php

declare(strict_types=1);

// General outline.

function lines($fp): \Generator
{
    while ($line = fgets($fp)) {
        yield $line;
    }
}

function decode_rot13($fp): \Generator
{
    while ($c = fgetc($fp)) {
        yield str_rot13($c);
    }
}

function lines_from_charstream(iterable $it): \Closure
{
    $buffer = '';
    return static function () use ($it, &$buffer) {
        foreach ($it as $c) {
            $buffer .= $c;
            while (($pos = strpos($buffer, PHP_EOL)) !== false) {
                yield substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos);
            }
        }
    };
}

function map(\Closure $c): \Closure
{
    return function (iterable $it) use ($c) : \Generator {
        foreach ($it as $k => $v) {
            yield $k => $c($v);
        }
    };
}

class Product
{
    public function __construct(
        private(set) string $name,
        private(set) string $color,
        private(set) int $available,
        private(set) string $department,
    ) {}

    // This exists because we cannot FCC the constructor.
    public static function create(...$args): self
    {
        return new self(...$args);
    }
}

class ProductRepo
{
    public function save(Product $product): void
    {
        print "Saved product $product\n";
    }
}

$repo = new ProductRepo();

fopen('pipes.md', 'rb') // No variable, so it will close automatically when GCed.
    |> decode_rot13(...)
    |> lines_from_charstream(...)
    |> map(str_getcsv(...))
    |> map(Product::create(...))
    |> map($repo->save(...))
;

/* Comparison of possible styles. */

// Simple callable.  Requires using a higher order function.  Not optimizable to remove the extra function call.

function map(\Closure $c): \Closure
{
    return function (iterable $it) use ($c) : \Generator {
        foreach ($it as $k => $v) {
            yield $k => $c($v);
        }
    };
}

// Auto-first-arg version.  Would be straightforward to optimize away into a single basic function call.

function map(iterable $it, \Closure $c): \Generator
{
    foreach ($it as $k => $v) {
        yield $k => $c($v);
    }
}

// Both would be called in what looks like the same way:

$foo |> map($some_callable);

