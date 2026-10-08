<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;

/**
 * Welches Längen-Verhalten ein Artikel bekommt. Eine falsche Antwort trifft ein ganzes Sortiment: Die
 * Einstellung steht meist an einer Kategorie.
 */
final class LengthSettingsResolverTest extends TestCase
{
    private const GROUP_SIZES = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const GROUP_LENGTH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Das Längenfeld, wie es die Artikel im Bestand tragen.
     *
     * @return array<string, mixed>
     */
    private static function lengthField(int $number = 1): array
    {
        return [
            "tmms_customer_input_{$number}_active" => true,
            "tmms_customer_input_{$number}_title" => 'Wählen Sie bitte bei Zuschnitt die nächst größere Länge aus.',
            "tmms_customer_input_{$number}_placeholder" => 'Gewünschte Länge in mm',
        ];
    }

    public function testWithoutAnySettingEverythingStaysOff(): void
    {
        self::assertTrue(LengthSettingsResolver::resolve(self::lengthField(), [])->isEmpty());
    }

    /**
     * Der Fall Stangenmaterial: einmal an der Kategorie, mit zwei Längengruppen, weil die Artikel ihre
     * Längen mal „Maße", mal „Länge" nennen.
     */
    public function testEverythingInheritedFromTheCategory(): void
    {
        $settings = LengthSettingsResolver::resolve(self::lengthField(), [
            ['id' => 'eigene', 'customFields' => []],
            ['id' => 'stangenmaterial', 'customFields' => [
                DynamicPriceConstants::CAT_FIELD_LENGTH_VARIANT_SWITCH => true,
                DynamicPriceConstants::CAT_FIELD_LENGTH_ONLY => true,
                DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS => [self::GROUP_SIZES, self::GROUP_LENGTH],
            ]],
        ]);

        self::assertTrue($settings->lengthSwitch);
        self::assertTrue($settings->lengthOnly);
        self::assertFalse($settings->guidedSelection);
        self::assertSame([self::GROUP_SIZES, self::GROUP_LENGTH], $settings->lengthGroupIds);
        self::assertSame(1, $settings->lengthFieldNumber);
    }

    /**
     * Ein Haken an einer oberen Kategorie gilt, auch wenn die nähere ihn nicht setzt; ein Artikel hängt
     * oft in mehreren Unterkategorien.
     */
    public function testAnUnsetHookCloserDoesNotCancelOneFurtherUp(): void
    {
        $settings = LengthSettingsResolver::resolve(self::lengthField(), [
            ['id' => 'nah', 'customFields' => [DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION => false]],
            ['id' => 'oben', 'customFields' => [DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION => true]],
        ]);

        self::assertTrue($settings->guidedSelection);
    }

    public function testTheClosestLengthGroupsComeFirst(): void
    {
        $settings = LengthSettingsResolver::resolve(
            self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::GROUP_LENGTH]],
            [['id' => 'kategorie', 'customFields' => [
                DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS => [self::GROUP_SIZES, self::GROUP_LENGTH],
            ]]],
        );

        self::assertSame([self::GROUP_LENGTH, self::GROUP_SIZES], $settings->lengthGroupIds);
    }

    /**
     * Der Fall von Staging: „Balkongeländer" trägt „Länge", „Bausätze" darüber „Maße" und „Länge". Ein
     * Relinggeländer unter „Balkongeländer" hat nur „Maße"; nähme die nächste Stelle alles, bekäme es
     * keine Längengruppe, und geführte Auswahl und Längenschalter fielen aus.
     */
    public function testANearerCategoryDoesNotHideTheGroupsFurtherUp(): void
    {
        $settings = LengthSettingsResolver::resolve(self::lengthField(), [
            ['id' => 'balkongeländer', 'customFields' => [DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS => [self::GROUP_LENGTH]]],
            ['id' => 'bausätze', 'customFields' => [
                DynamicPriceConstants::CAT_FIELD_GUIDED_SELECTION => true,
                DynamicPriceConstants::CAT_FIELD_LENGTH_GROUPS => [self::GROUP_SIZES, self::GROUP_LENGTH],
            ]],
        ]);

        self::assertSame([self::GROUP_LENGTH, self::GROUP_SIZES], $settings->lengthGroupIds);
        self::assertTrue($settings->guidedSelection);
    }

    /**
     * Ältere Pflege und die Schnittstelle schreiben eine einzelne Kennung statt einer Liste.
     */
    public function testASingleGroupIdCountsAsAList(): void
    {
        $settings = LengthSettingsResolver::resolve(
            self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_GROUPS => strtoupper(self::GROUP_SIZES)],
            [],
        );

        self::assertSame([self::GROUP_SIZES], $settings->lengthGroupIds);
    }

    public function testInvalidGroupIdsAreDropped(): void
    {
        $settings = LengthSettingsResolver::resolve(
            self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_GROUPS => ['keine-kennung', 42, self::GROUP_SIZES, self::GROUP_SIZES]],
            [],
        );

        self::assertSame([self::GROUP_SIZES], $settings->lengthGroupIds);
    }

    /**
     * Die Konsole schreibt `1` oder `"1"` statt `true`.
     */
    public function testAHookAlsoAcceptsOneAsNumberOrText(): void
    {
        self::assertTrue(LengthSettingsResolver::resolve(self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => 1], [])->lengthSwitch);
        self::assertTrue(LengthSettingsResolver::resolve(self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => '1'], [])->lengthSwitch);
        self::assertFalse(LengthSettingsResolver::resolve(self::lengthField() + [DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => 'false'], [])->lengthSwitch);
    }

    /**
     * Die Kategorie schaltet den Längenschalter für alle ihre Artikel ein. Ein Zubehörteil darin ohne
     * Längenfeld darf ihn nicht bekommen: Er läse sonst ein Feld wie „Wandabstand" als Länge.
     */
    public function testWithoutLengthFieldTheSwitchStaysOff(): void
    {
        $settings = LengthSettingsResolver::resolve(
            ['tmms_customer_input_1_active' => true, 'tmms_customer_input_1_title' => 'Wandabstand'],
            [['id' => 'kategorie', 'customFields' => [
                DynamicPriceConstants::CAT_FIELD_LENGTH_VARIANT_SWITCH => true,
                DynamicPriceConstants::CAT_FIELD_LENGTH_ONLY => true,
            ]]],
        );

        self::assertFalse($settings->lengthSwitch);
        self::assertFalse($settings->lengthOnly);
        self::assertNull($settings->lengthFieldNumber);
    }
}
