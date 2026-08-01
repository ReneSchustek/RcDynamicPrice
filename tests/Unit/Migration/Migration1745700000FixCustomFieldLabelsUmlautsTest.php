<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Migration\Migration1745700000FixCustomFieldLabelsUmlauts;

/**
 * Verifiziert Heilungs-Migration über Connection-Mock — keine echte DB nötig. Drei Eigenschaften
 * werden geprüft: Korrektur, Locale-Schutz (en-GB unverändert), Idempotenz.
 */
final class Migration1745700000FixCustomFieldLabelsUmlautsTest extends TestCase
{
    public function testFixesDeDeStringsAndLeavesEnGbAlone(): void
    {
        $oldConfig = json_encode([
            'label' => [
                'de-DE' => 'Mindestlänge (mm)',
                'en-GB' => 'Minimum length (mm)',
            ],
            'helpText' => [
                'de-DE' => 'Mindestlaenge fuer alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
                'en-GB' => 'Minimum length for all products in this category, unless overridden at product level.',
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $captured = $this->captureUpdateForSingleRow(
            fieldName: 'rc_meter_price_cat_min_length',
            oldConfig: $oldConfig,
        );

        self::assertNotNull($captured, 'UPDATE muss ausgeführt werden, wenn Ersatzschreibweisen vorliegen.');

        /** @var array{label: array{de-DE: string, en-GB: string}, helpText: array{de-DE: string, en-GB: string}} $decoded */
        $decoded = json_decode($captured, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(
            'Mindestlänge für alle Produkte dieser Kategorie, sofern am Produkt nichts gesetzt ist.',
            $decoded['helpText']['de-DE'],
            'de-DE-HelpText muss korrigiert sein.'
        );
        self::assertSame(
            'Minimum length for all products in this category, unless overridden at product level.',
            $decoded['helpText']['en-GB'],
            'en-GB-HelpText darf nicht verändert werden.'
        );
        self::assertSame('Mindestlänge (mm)', $decoded['label']['de-DE']);
        self::assertSame('Minimum length (mm)', $decoded['label']['en-GB']);
    }

    public function testReplacesNestedOptionLabels(): void
    {
        $oldConfig = json_encode([
            'label' => [
                'de-DE' => 'Split-Modus fuer Langstuecke',
                'en-GB' => 'Split mode for long pieces',
            ],
            'options' => [
                ['value' => 'equal', 'label' => ['de-DE' => 'Gleichmaessig aufteilen', 'en-GB' => 'Split equally']],
                ['value' => 'max_rest', 'label' => ['de-DE' => 'Volle Stuecke plus Rest', 'en-GB' => 'Full pieces plus remainder']],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $captured = $this->captureUpdateForSingleRow(
            fieldName: 'rc_meter_price_split_mode',
            oldConfig: $oldConfig,
        );

        self::assertNotNull($captured);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($captured, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('Split-Modus für Langstücke', $decoded['label']['de-DE']);
        self::assertSame('Gleichmäßig aufteilen', $decoded['options'][0]['label']['de-DE']);
        self::assertSame('Volle Stücke plus Rest', $decoded['options'][1]['label']['de-DE']);
        self::assertSame('Split equally', $decoded['options'][0]['label']['en-GB']);
    }

    public function testIsIdempotentOnAlreadyCorrectedConfig(): void
    {
        $correctedConfig = json_encode([
            'label' => [
                'de-DE' => 'Mindestlänge (mm)',
                'en-GB' => 'Minimum length (mm)',
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $captured = $this->captureUpdateForSingleRow(
            fieldName: 'rc_meter_price_cat_min_length',
            oldConfig: $correctedConfig,
        );

        self::assertNull($captured, 'Bereits korrigierte Config darf kein UPDATE auslösen (Idempotenz).');
    }

    public function testSkipsMissingFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1745700000FixCustomFieldLabelsUmlauts())->update($connection);
    }

    public function testSkipsInvalidJsonConfig(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 'fake-id',
            'config' => '{invalid json',
        ]);
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1745700000FixCustomFieldLabelsUmlauts())->update($connection);
    }

    /**
     * Hilfsmethode: simuliert ein einzelnes betroffenes Feld in der DB. Die Migration iteriert über
     * mehrere Feldnamen — fetchAssociative liefert nur für den gesuchten Namen einen Treffer, alle
     * anderen liefern false. Liefert das `config`-Argument des einzigen executeStatement zurück
     * (oder null, wenn keiner ausgeführt wurde).
     */
    private function captureUpdateForSingleRow(string $fieldName, string $oldConfig): ?string
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array|false =>
                ($params['name'] ?? null) === $fieldName
                    ? ['id' => 'fake-id-bytes', 'config' => $oldConfig]
                    : false
        );

        $captured = null;
        $connection
            ->expects(self::atMost(1))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$captured): int {
                $captured = $params['config'];

                return 1;
            });

        (new Migration1745700000FixCustomFieldLabelsUmlauts())->update($connection);

        return \is_string($captured) ? $captured : null;
    }
}
