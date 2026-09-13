<?php

declare(strict_types=1);

/**
 * BVT — every `use Filament\...;` import in the shipped source must resolve.
 *
 * Filament v3 → v4 moved the layout components out of `Filament\Forms\Components`
 * and `Filament\Infolists\Components` into `Filament\Schemas\Components`, dropped
 * `Filament\Forms\Form` / `Filament\Infolists\Infolist` in favour of
 * `Filament\Schemas\Schema`, and kept the entries (`TextEntry`, `IconEntry`, ...)
 * in `Filament\Infolists\Components`. A stale import of a class that no longer
 * exists is invisible to a class-existence roll-call — PHP never resolves an
 * import that is not reached — and only fatals when the page or schema renders.
 * This walks the imports themselves so the defect cannot come back unnoticed.
 */
it('resolves every Filament class imported by the package source', function (): void {
    $src = realpath(__DIR__.'/../../src');

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    $unresolved = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        preg_match_all('/^use (Filament\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $contents, $matches);

        foreach ($matches[1] as $class) {
            // `use Filament\Forms;` and friends import a namespace, not a class.
            if (substr_count($class, '\\') < 2) {
                continue;
            }

            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                continue;
            }

            $unresolved[] = str_replace($src.DIRECTORY_SEPARATOR, '', $file->getPathname()).' => '.$class;
        }
    }

    expect($unresolved)->toBe([]);
});
