<?php

declare(strict_types=1);

/*
 * Bootstrap der Messungen. Lädt den Autoloader der Shopware-Installation, in der die Erweiterung
 * liegt, und die Umgebungsvariablen der Instanz. Den Kern startet erst der Messbestand
 * (`Support\ShopwareFixture`), damit der Start nicht in die Messung fällt.
 *
 * Gemessen wird gegen den Bestand dieser Instanz, nicht gegen drei Testzeilen: Wie lange das Auflösen
 * der Einstellungen dauert, hängt an der Kategoriekette des Artikels.
 */

use Symfony\Component\Dotenv\Dotenv;

$projectRoot = getenv('PROJECT_ROOT') ?: dirname(__DIR__, 4);
if (!is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Keine Shopware-Installation unter {$projectRoot}; PROJECT_ROOT setzen.\n");
    exit(1);
}

$classLoader = require $projectRoot . '/vendor/autoload.php';
$classLoader->addPsr4('Ruhrcoder\\RcDynamicPrice\\Benchmarks\\', __DIR__);
// Wie der Plugin-Lader des Kerns: Der Splitter wird ohne gestarteten Kern gemessen.
$classLoader->addPsr4('Ruhrcoder\\RcDynamicPrice\\', dirname(__DIR__) . '/src');

// Ohne Liste der Testumgebungen liest Dotenv auch `.env.local`; dort steht bei ddev die
// Datenbank. Die Umgebung des Kerns bestimmt der Messbestand selbst.
(new Dotenv())->loadEnv($projectRoot . '/.env', null, 'dev', []);

$GLOBALS['rcBenchmarkClassLoader'] = $classLoader;
$GLOBALS['rcBenchmarkProjectRoot'] = $projectRoot;
