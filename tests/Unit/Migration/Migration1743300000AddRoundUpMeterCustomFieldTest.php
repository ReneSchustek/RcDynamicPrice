<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1743300000AddRoundUpMeterCustomField;

/**
 * Die Migration fügt dem Produkt-Set den Haken „Auf vollen Meter aufrunden" hinzu. Geprüft wird an einer
 * nachgebildeten Datenbankverbindung, dass der Haken nur entsteht, wenn das Set da ist und der Haken noch fehlt.
 */
#[CoversClass(Migration1743300000AddRoundUpMeterCustomField::class)]
final class Migration1743300000AddRoundUpMeterCustomFieldTest extends TestCase
{
    public function testCustomFieldIsAddedIfSetExistsAndFieldDoesNotExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            'set-id', // das Set gibt es
            false,    // den Haken noch nicht
        );

        $connection->expects($this->once())->method('executeStatement');

        (new Migration1743300000AddRoundUpMeterCustomField())->update($connection);
    }

    public function testCustomFieldIsNotAddedIfSetDoesNotExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // kein Set

        $connection->expects($this->never())->method('executeStatement');

        (new Migration1743300000AddRoundUpMeterCustomField())->update($connection);
    }

    public function testCustomFieldIsNotAddedIfFieldAlreadyExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(
            'set-id', // das Set gibt es
            '1',      // den Haken auch
        );

        $connection->expects($this->never())->method('executeStatement');

        (new Migration1743300000AddRoundUpMeterCustomField())->update($connection);
    }

    public function testUpdateDestructiveDoesNothing(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects($this->never())->method('executeStatement');

        (new Migration1743300000AddRoundUpMeterCustomField())->updateDestructive($connection);
    }
}
