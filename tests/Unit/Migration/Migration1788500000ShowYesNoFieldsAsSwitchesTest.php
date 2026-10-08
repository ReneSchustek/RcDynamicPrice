<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1788500000ShowYesNoFieldsAsSwitches;

/**
 * Die Ja/Nein-Felder werden zu Schaltern, damit sie in der Verwaltung nicht aneinanderkleben. Geprüft an
 * einer nachgebildeten Verbindung: Ein Ankreuzfeld wird umgestellt samt Hilfetext, ein schon
 * umgestelltes Feld bleibt unberührt.
 */
final class Migration1788500000ShowYesNoFieldsAsSwitchesTest extends TestCase
{
    public function testACheckboxBecomesASwitch(): void
    {
        $updates = $this->migrate([
            'type' => 'checkbox',
            'customFieldType' => 'checkbox',
            'componentName' => 'sw-field',
            'customFieldPosition' => 8,
            'label' => ['de-DE' => 'Geführte Auswahl: erst die Länge', 'en-GB' => 'Guided selection: length first'],
            'helpText' => ['de-DE' => 'An einer Kategorie gesetzt, gilt der Haken für alle Produkte darunter.', 'en-GB' => 'Set on a category.'],
        ]);

        self::assertCount(1, $updates);
        $config = json_decode($updates[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('switch', $config['type']);
        self::assertSame('switch', $config['customFieldType']);
        self::assertSame('sw-field', $config['componentName']);
        self::assertSame(8, $config['customFieldPosition'], 'Die Reihenfolge bleibt.');
        self::assertSame('An einer Kategorie gesetzt, gilt der Schalter für alle Produkte darunter.', $config['helpText']['de-DE']);
        self::assertSame('Geführte Auswahl: erst die Länge', $config['label']['de-DE'], 'Umlaute bleiben echte Umlaute.');
    }

    public function testASwitchIsLeftAlone(): void
    {
        self::assertSame([], $this->migrate(['type' => 'switch', 'customFieldType' => 'switch', 'componentName' => 'sw-field']));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string> die geschriebenen Konfigurationen
     */
    private function migrate(array $config): array
    {
        $updates = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => 'field-id', 'config' => json_encode($config, \JSON_THROW_ON_ERROR)],
        ]);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$updates): int {
                $updates[] = $params['config'];

                return 1;
            },
        );

        (new Migration1788500000ShowYesNoFieldsAsSwitches())->update($connection);

        return $updates;
    }
}
