<?php

declare(strict_types=1);

/*
 * Test-Bootstrap. Geladen werden der Autoloader im eigenen `vendor/` der Erweiterung und der der
 * umgebenden Shopware-Installation vier Ebenen über `tests/`. Fehlen beide, lädt der eigene
 * PSR-4-Loader unten die Klassen von `Ruhrcoder\\RcDynamicPrice\\`.
 *
 * So laufen die Unit-Tests eigenständig mit `vendor/bin/phpunit` und ebenso in einer Instanz.
 */

$pluginAutoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($pluginAutoloader)) {
    require_once $pluginAutoloader;
}

$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcDynamicPrice\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcDynamicPrice\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, $length)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/*
 * Die Shopware-Installation suchen. Der Kern wird nur gestartet, wenn die Erweiterung in einer
 * Installation getestet wird.
 *
 * `class_exists(TestBootstrapper::class)` taugt dafür nicht: `shopware/core` ist eine
 * `require`-Abhängigkeit, die Klasse gibt es also auch in einer eigenständigen Prüfkopie. Dort
 * versuchte der Bootstrap einen Shop zu starten, den es nicht gibt, und bräche mit „Could not find
 * plugin: RcDynamicPrice" ab, bevor ein einziger Unit-Test läuft.
 *
 * Geprüft werden in dieser Reihenfolge das Aufrufverzeichnis, weil die Integrationstests aus der
 * Installation gestartet werden (`vendor/bin/phpunit -c custom/plugins/…`), und das Verzeichnis
 * vier Ebenen über `tests/`, das bei `custom/plugins/<Erweiterung>/tests/` trifft, solange der Pfad
 * kein symbolischer Link ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    // `getcwd()` kann false liefern, deshalb die Prüfung auf eine Zeichenkette. Leer wird sie nie.
    if (\is_string($candidate) && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// `KernelTestBehaviour` und `IntegrationTestBehaviour` erwarten einen vorbereiteten Kern, bevor
// der erste Test läuft. Ohne Shopware-Installation entfällt das; die Unit-Tests brauchen nur den
// Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // In den ddev-Instanzen steht in `.env.test` `KERNEL_CLASS=App\Kernel`, eine Klasse, die es dort
    // nicht gibt; auch ein Aufruf, der den Backslash verschluckt, ergibt keine ladbare Klasse. Dann
    // gilt `Shopware\Core\Kernel`, die Vorgabe von `KernelFactory`. Gesetzt wird früh, weil Dotenv
    // vorhandene Werte nicht überschreibt.
    $currentKernelClass = getenv('KERNEL_CLASS') ?: ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        // Beim ersten Lauf installiert und aktiviert der TestBootstrapper die Erweiterung selbst.
        // `setForceInstallPlugins(true)` fehlt mit Absicht: Es deinstalliert vorher, und der
        // Deinstallationsweg braucht `database_connection`, das im Test-Kern anders heißt, und
        // ist nicht wiederholbar.
        ->addCallingPlugin();

    // `setProjectDir` allein reicht nicht: `KernelFactory::getProjectDir()` liest zuerst
    // `PROJECT_ROOT` und fällt sonst auf den Pfad seiner eigenen Klasse zurück. Liegt der Kern im
    // `vendor/` der Erweiterung, zeigte das auf die Erweiterung statt auf die Installation.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
