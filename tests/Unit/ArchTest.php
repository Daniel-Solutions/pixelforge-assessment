<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->laravel();

arch('no debug functions or env() outside config')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'env'])
    ->not->toBeUsed();

arch('app code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('enums live in App\Enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('actions expose a single handle method')
    ->expect('App\Actions')
    ->classes()
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\Fortify');

arch('money never touches floats')
    ->expect(['App\Money', 'App\Actions'])
    ->not->toUse(['floatval', 'round', 'floor', 'ceil', 'number_format']);

/**
 * @return array<string, array{string}>
 */
function sourceFiles(string $directory): array
{
    $files = [];
    $root = dirname(__DIR__, 2).'/app/'.$directory;

    if (! is_dir($root)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$directory.'/'.substr($file->getPathname(), strlen($root) + 1)] = [$file->getPathname()];
        }
    }

    return $files;
}

test('models declare neither $fillable nor $guarded', function (string $file): void {
    expect(file_get_contents($file))
        ->not->toMatch('/\$(fillable|guarded)\b|#\[(Fillable|Guarded)\b/');
})->with(fn (): array => sourceFiles('Models'));

test('controllers and actions never read raw request data', function (string $file): void {
    expect(file_get_contents($file))
        ->not->toMatch('/\$request->(all|input|except)\(/');
})->with(fn (): array => array_merge(sourceFiles('Http/Controllers'), sourceFiles('Actions')));

test('money and actions never cast to float', function (string $file): void {
    expect(file_get_contents($file))
        ->not->toMatch('/\(float\)|\bfloat\s+\$|:\s*\??float\b/');
})->with(fn (): array => array_merge(sourceFiles('Money'), sourceFiles('Actions')));
