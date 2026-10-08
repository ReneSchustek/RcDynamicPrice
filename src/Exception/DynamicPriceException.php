<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Exception;

/**
 * Die Exception der Erweiterung. Sie trägt einen festen `errorCode`, damit Protokollauswertung und
 * Support einen Fehler einordnen können, ohne den Meldungstext zu zerlegen. Jeder Fehler, den die
 * Erweiterung absichtlich wirft, ist eine Instanz dieser Klasse.
 *
 * Basis ist `\RuntimeException`, weil diese Fehler zur Laufzeit entstehen (Datenbestand, Grenzen der
 * Eingabe) und keine Programmierfehler sind.
 */
final class DynamicPriceException extends \RuntimeException
{
    public const CODE_INVALID_TOTAL_LENGTH = 'RC_DYNAMIC_PRICE__INVALID_TOTAL_LENGTH';
    public const CODE_TOTAL_LENGTH_EXCEEDS_MAXIMUM = 'RC_DYNAMIC_PRICE__TOTAL_LENGTH_EXCEEDS_MAXIMUM';
    public const CODE_INVALID_MIN_MAX_LENGTH = 'RC_DYNAMIC_PRICE__INVALID_MIN_MAX_LENGTH';
    public const CODE_NEGATIVE_MAX_PIECE_LENGTH = 'RC_DYNAMIC_PRICE__NEGATIVE_MAX_PIECE_LENGTH';
    public const CODE_BACKFILL_INCOMPLETE = 'RC_DYNAMIC_PRICE__MIGRATION_BACKFILL_INCOMPLETE';
    public const CODE_MISSING_CUSTOM_FIELD_SET = 'RC_DYNAMIC_PRICE__MISSING_CUSTOM_FIELD_SET';

    public function __construct(
        string $message,
        private readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidTotalLength(int $totalMm): self
    {
        return new self(
            \sprintf('Gesamtlänge muss positiv sein, erhalten: %d', $totalMm),
            self::CODE_INVALID_TOTAL_LENGTH,
        );
    }

    public static function totalLengthExceedsMaximum(int $totalMm, int $maxMm): self
    {
        return new self(
            \sprintf('Gesamtlänge %d überschreitet unterstütztes Maximum (%d mm)', $totalMm, $maxMm),
            self::CODE_TOTAL_LENGTH_EXCEEDS_MAXIMUM,
        );
    }

    public static function invalidMinMaxLength(int $min, int $max): self
    {
        return new self(
            \sprintf('minLength (%d) darf maxLength (%d) nicht überschreiten', $min, $max),
            self::CODE_INVALID_MIN_MAX_LENGTH,
        );
    }

    public static function negativeMaxPieceLength(int $maxPieceLength): self
    {
        return new self(
            \sprintf('maxPieceLength (%d) darf nicht negativ sein', $maxPieceLength),
            self::CODE_NEGATIVE_MAX_PIECE_LENGTH,
        );
    }

    public static function backfillIncomplete(string $field, int $leftovers): self
    {
        return new self(
            \sprintf(
                'Backfill für Custom-Field "%s" unvollständig: %d Produkte halten weiterhin bool-/int-Werte. '
                . 'Plugin-Migration abgebrochen, Datenkorrektur notwendig.',
                $field,
                $leftovers,
            ),
            self::CODE_BACKFILL_INCOMPLETE,
        );
    }

    public static function missingCustomFieldSet(string $setName, string $requiredMigration): self
    {
        return new self(
            \sprintf(
                'CustomFieldSet "%s" fehlt. Plugin-Installation scheint defekt — %s muss vorher erfolgreich gelaufen sein.',
                $setName,
                $requiredMigration,
            ),
            self::CODE_MISSING_CUSTOM_FIELD_SET,
        );
    }
}
