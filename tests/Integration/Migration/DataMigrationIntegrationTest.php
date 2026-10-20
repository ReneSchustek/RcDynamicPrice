<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1743400000ReplaceRoundUpWithRoundingMode;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1745600000ConvertActiveFieldToTriState;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1788400000ReplaceLengthGroupWithLengthGroups;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Die drei Migrationen, die Werte in bestehenden Produkten und Kategorien umschreiben.
 *
 * Geprüft wird an der echten Datenbank, weil die Umzüge in MySQL-JSON-Funktionen stecken; eine nachgebildete
 * Verbindung prüfte nur, dass irgendein SQL abgeschickt wird. Die alten Werte stehen direkt in der
 * Übersetzungstabelle, wie die Verwaltung sie früher schrieb. Jeder Test läuft in einer Transaktion, die danach
 * zurückgerollt wird.
 */
class DataMigrationIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * Was: Der alte Haken „auf vollen Meter aufrunden" an einem Produkt, gesetzt und nicht gesetzt.
     * Warum: Ein gesetzter Haken muss weiter auf volle Meter runden, ein nicht gesetzter darf nichts erzeugen.
     * Erwartet: `full_m` beim gesetzten, kein Rundungswert beim anderen; der alte Schlüssel ist bei beiden weg.
     */
    public function testTheRoundUpCheckboxBecomesFullMetreRounding(): void
    {
        $checked = $this->createProduct(['rc_meter_price_round_up_meter' => true, 'other' => 'stays']);
        $unchecked = $this->createProduct(['rc_meter_price_round_up_meter' => false]);

        (new Migration1743400000ReplaceRoundUpWithRoundingMode())->update($this->connection());

        self::assertSame(['other' => 'stays', 'rc_meter_price_rounding' => 'full_m'], $this->productFields($checked));
        self::assertSame([], $this->productFields($unchecked));
    }

    /**
     * Was: Der alte Haken „Meterpreis aktiv" als Wahrheitswert, Zahl und Zeichenkette.
     * Warum: Jede Schreibweise, die Verwaltung, Schnittstelle oder Konsole erzeugt haben, muss ankommen; ein
     *        übrig gebliebener Wahrheitswert ließe die Migration abbrechen.
     * Erwartet: gesetzt wird `on`, nicht gesetzt `inherit`.
     */
    public function testTheActiveCheckboxBecomesTheThreeStateSelection(): void
    {
        $cases = [[true, 'on'], [1, 'on'], ['1', 'on'], [false, 'inherit'], [0, 'inherit'], ['0', 'inherit']];

        $ids = [];
        foreach ($cases as [$old, $expected]) {
            $ids[] = [$this->createProduct([DynamicPriceConstants::FIELD_METER_ACTIVE => $old]), $expected, var_export($old, true)];
        }

        (new Migration1745600000ConvertActiveFieldToTriState())->update($this->connection());

        foreach ($ids as [$id, $expected, $label]) {
            self::assertSame([DynamicPriceConstants::FIELD_METER_ACTIVE => $expected], $this->productFields($id), 'Alter Wert ' . $label);
        }
    }

    /**
     * Was: Die Längengruppe aus 1.23.0 als Einzelwert an Produkt und Kategorie, dazu ein Produkt, das schon die
     *      neue Liste trägt.
     * Warum: Der Einzelwert wird zur einelementigen Liste; eine schon gepflegte Liste darf er nicht überschreiben.
     * Erwartet: Liste mit dem alten Wert, alter Schlüssel weg; die gepflegte Liste bleibt unverändert.
     */
    public function testTheSingleLengthGroupBecomesAList(): void
    {
        $group = Uuid::randomHex();
        $kept = Uuid::randomHex();
        $product = $this->createProduct([DynamicPriceConstants::LEGACY_FIELD_GUIDED_LENGTH_GROUP => $group]);
        $maintained = $this->createProduct([
            DynamicPriceConstants::LEGACY_FIELD_GUIDED_LENGTH_GROUP => $group,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [$kept],
        ]);
        $category = $this->createCategory([DynamicPriceConstants::LEGACY_CAT_FIELD_GUIDED_LENGTH_GROUP => $group]);

        (new Migration1788400000ReplaceLengthGroupWithLengthGroups())->update($this->connection());

        self::assertSame([DynamicPriceConstants::FIELD_LENGTH_GROUPS => [$group]], $this->productFields($product));
        self::assertSame([DynamicPriceConstants::FIELD_LENGTH_GROUPS => [$kept]], $this->productFields($maintained));
        self::assertSame([DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS => [$group]], $this->categoryFields($category));
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @return EntityRepository<*>
     */
    private function repository(string $name): EntityRepository
    {
        $repository = static::getContainer()->get($name);
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function createProduct(array $customFields): string
    {
        $id = Uuid::randomHex();
        $this->repository('product.repository')->create([[
            'id' => $id,
            'productNumber' => 'rc-migration-' . $id,
            'stock' => 1,
            'name' => 'Migrationsprobe',
            'tax' => ['name' => 'Probe', 'taxRate' => 19],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 8.4, 'linked' => false]],
        ]], Context::createDefaultContext());

        $this->writeFields('product_translation', 'product_id', $id, $customFields);

        return $id;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function createCategory(array $customFields): string
    {
        $id = Uuid::randomHex();
        $this->repository('category.repository')->create([['id' => $id, 'name' => 'Migrationsprobe']], Context::createDefaultContext());

        $this->writeFields('category_translation', 'category_id', $id, $customFields);

        return $id;
    }

    /**
     * Am Datenbankzugriff vorbei, damit die Werte genau so dastehen, wie ältere Fassungen sie schrieben.
     *
     * @param array<string, mixed> $customFields
     */
    private function writeFields(string $table, string $idColumn, string $id, array $customFields): void
    {
        $this->connection()->executeStatement(
            \sprintf('UPDATE `%s` SET `custom_fields` = :fields WHERE `%s` = :id AND `language_id` = :language', $table, $idColumn),
            [
                'fields' => json_encode($customFields, \JSON_THROW_ON_ERROR),
                'id' => Uuid::fromHexToBytes($id),
                'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productFields(string $id): array
    {
        return $this->readFields('product_translation', 'product_id', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryFields(string $id): array
    {
        return $this->readFields('category_translation', 'category_id', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function readFields(string $table, string $idColumn, string $id): array
    {
        $raw = $this->connection()->fetchOne(
            \sprintf('SELECT `custom_fields` FROM `%s` WHERE `%s` = :id AND `language_id` = :language', $table, $idColumn),
            ['id' => Uuid::fromHexToBytes($id), 'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)],
        );
        self::assertIsString($raw);

        $fields = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);
        ksort($fields);

        return $fields;
    }
}
