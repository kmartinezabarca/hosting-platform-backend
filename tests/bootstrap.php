<?php

/*
|--------------------------------------------------------------------------
| Test Bootstrap
|--------------------------------------------------------------------------
|
| We load the Composer autoloader from vendor (which may be a junction/symlink
| to a shared vendor directory) and then re-register the project-local
| namespaces so that they resolve to THIS worktree's directories, not
| the parent project's directories.
|
*/

$loader = require __DIR__ . '/../vendor/autoload.php';

$base = dirname(__DIR__);

// The vendor directory may be a junction/symlink to a shared vendor of another project.
// autoload_static.php contains a hardcoded classmap that resolves App\, Database\Factories\,
// etc. to the parent project's directories. We override those entries here so that the
// classes resolve to THIS worktree's directories instead.

// 1. Override PSR-4 prefixes (prepends, so these take priority)
$loader->addPsr4('App\\',                   $base . '/app/');
$loader->addPsr4('Database\\Factories\\',   $base . '/database/factories/');
$loader->addPsr4('Database\\Seeders\\',     $base . '/database/seeders/');
$loader->addPsr4('Tests\\',                 $base . '/tests/');

// 2. Override classmap entries that the static autoloader pre-registers.
//    The classmap is checked BEFORE PSR-4, so we must patch it directly.
$classMap = $loader->getClassMap();

// The vendor directory is a junction/symlink to the shared vendor of the parent project.
// Resolve it to find the parent project root.
$vendorRealPath = realpath($base . '/vendor');    // resolves junction → main-repo/vendor
$parentBase     = str_replace('\\', '/', dirname($vendorRealPath)) . '/';
$normalBase     = str_replace('\\', '/', $base) . '/';

// Classmap stores raw paths (with ../..) — resolve each one, then re-map those that
// belong to the parent project root to point here instead.
$rewritten = [];
foreach ($classMap as $class => $path) {
    $resolved = str_replace('\\', '/', (string) realpath($path));
    if ($resolved !== '' && str_starts_with($resolved, $parentBase)) {
        $rewritten[$class] = $normalBase . substr($resolved, strlen($parentBase));
    }
}
$loader->addClassMap($rewritten);
