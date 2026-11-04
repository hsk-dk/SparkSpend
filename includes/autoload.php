<?php
/**
 * Class autoloader for includes/: a class `Foo` lives in includes/Foo.php (all classes
 * here follow that rule). Registered once from configuration.php, so no file has to
 * `require` the classes it uses.
 */
spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) return;   // no namespaces / paths
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) require_once $file;
});
