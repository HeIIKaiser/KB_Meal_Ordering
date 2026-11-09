<?php

use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\Finder\Finder;

/*
 * Livewire 4 maps some plain names to its own magic actions in the browser
 * (e.g. wire:click="commit" runs $commit = just re-render). A component method with
 * such a name is silently never called — this broke "Uložiť" on both import pages.
 * Server-side tests (->call()) don't notice, so we check the names here.
 */
function livewireReservedNames(): array
{
    $js = file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.esm.js'));
    // the alias map: { "el": "$el", ..., "commit": "$commit", ... }
    preg_match_all('/^\s*"(\w+)": "\$\w+",?$/m', $js, $m);

    return array_values(array_unique($m[1]));
}

it('can read Livewire\'s reserved names', function () {
    expect(livewireReservedNames())->toContain('commit', 'set', 'upload', 'dispatch');
});

it('has no component action named like a Livewire magic action', function () {
    $reserved = livewireReservedNames();
    $bad = [];

    foreach ((new Finder)->files()->in([app_path('Livewire'), app_path('Filament')])->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getRealPath(), app_path().'/'));
        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === $class && in_array($method->name, $reserved, true)) {
                $bad[] = "$class::{$method->name}()";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('never calls a Livewire magic name from wire:click in our views', function () {
    $reserved = livewireReservedNames();
    $bad = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/wire:click(?:\.[\w.]+)?="(\w+)/', $file->getContents(), $m);
        foreach ($m[1] as $name) {
            if (in_array($name, $reserved, true)) {
                $bad[] = $file->getRelativePathname().': '.$name;
            }
        }
    }

    expect($bad)->toBe([]);
});
