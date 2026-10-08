<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\LengthField;

/**
 * Welche Kundeneingabe die Länge trägt. Ein falsches Feld hieße: Endkappen oder Wandabstand würden als
 * Länge gelesen und wählten die Größe.
 */
final class LengthFieldTest extends TestCase
{
    public function testTheLengthFieldIsFoundByItsTitle(): void
    {
        self::assertSame(1, LengthField::numberIn([
            'tmms_customer_input_1_active' => true,
            'tmms_customer_input_1_title' => 'Wählen Sie bitte bei Zuschnitt die nächst größere Länge aus.',
        ]));
    }

    /**
     * Im Bestand steht die Länge nicht immer an erster Stelle.
     */
    public function testTheLengthFieldNeedNotBeTheFirst(): void
    {
        self::assertSame(3, LengthField::numberIn([
            'tmms_customer_input_1_active' => true,
            'tmms_customer_input_1_title' => 'Wandabstand',
            'tmms_customer_input_2_active' => true,
            'tmms_customer_input_2_title' => 'Bitte wählen Sie Ihre Endkappen',
            'tmms_customer_input_3_active' => '1',
            'tmms_customer_input_3_title' => 'Zuschnitt',
            'tmms_customer_input_3_placeholder' => 'Gewünschte Längen in mm',
        ]));
    }

    public function testAnInactiveFieldDoesNotCount(): void
    {
        self::assertNull(LengthField::numberIn([
            'tmms_customer_input_1_active' => false,
            'tmms_customer_input_1_title' => 'Gewünschte Länge',
        ]));
    }

    public function testWithoutLengthFieldThereIsNone(): void
    {
        self::assertNull(LengthField::numberIn([]));
        self::assertNull(LengthField::numberIn([
            'tmms_customer_input_1_active' => true,
            'tmms_customer_input_1_title' => 'Höhe des Gartentores',
        ]));
    }
}
