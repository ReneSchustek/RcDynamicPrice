<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Service\LengthStage;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettings;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolverInterface;
use Ruhrcoder\RcDynamicPrice\Service\LengthStage\LengthStageReader;
use Ruhrcoder\RcDynamicPrice\Service\MeterProductHelperInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;

/**
 * Welche Stufe eine Variante ist: die Option ihrer Längengruppe, gelesen unter den Optionen der Geschwister.
 */
final class LengthStageReaderTest extends TestCase
{
    private const VARIANT = '0198d3a9c7c5715e8f2bb1a0a3b5c001';
    private const PARENT = '0198d3a9c7c5715e8f2bb1a0a3b5c000';
    private const GROUP = '0198d3a9c7c5715e8f2bb1a0a3b5c0a0';

    public function testTheVariantGetsItsStageAmongTheSiblings(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class, [static function (Criteria $criteria): PropertyGroupOptionCollection {
            self::assertCount(2, $criteria->getFilters());

            return new PropertyGroupOptionCollection([
                self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b1', '0,2 m'),
                self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b2', '1,0 m'),
                self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b3', '6,0 m'),
            ]);
        }]);

        $result = $this->reader($this->variant(['0198d3a9c7c5715e8f2bb1a0a3b5c0b1']), [self::GROUP], $options)->read(self::VARIANT, $this->context());

        self::assertNotNull($result);
        self::assertSame('0,2 m', $result['stage']->label);
        self::assertCount(3, $result['stages']);
        self::assertSame(1, $result['field']);
        self::assertFalse($result['required']);
    }

    /**
     * Was: Das Pflicht-Kennzeichen kommt aus dem geladenen Artikel.
     * Warum: An der gespeicherten Warenkorbposition fehlen die TMMS-Zusatzfelder (siehe LengthStageValidatorTest).
     */
    public function testTheRequiredFlagComesFromTheProduct(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class, [
            new PropertyGroupOptionCollection([self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b1', '0,2 m')]),
        ]);
        $variant = $this->variant(['0198d3a9c7c5715e8f2bb1a0a3b5c0b1']);
        $variant->setCustomFields(['tmms_customer_input_1_required' => true]);

        self::assertTrue($this->reader($variant, [self::GROUP], $options)->read(self::VARIANT, $this->context())['required'] ?? null);
    }

    public function testWithoutLengthFieldThereIsNothingToCheck(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class);

        self::assertNull($this->reader($this->variant([]), [self::GROUP], $options, null)->read(self::VARIANT, $this->context()));
    }

    public function testTheResultIsKeptForTheRequest(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class, [
            new PropertyGroupOptionCollection([self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b1', '0,2 m')]),
        ]);
        $reader = $this->reader($this->variant(['0198d3a9c7c5715e8f2bb1a0a3b5c0b1']), [self::GROUP], $options);

        self::assertNotNull($reader->read(self::VARIANT, $this->context()));
        // Ein zweiter Aufruf fragte die leere Liste des Stubs und scheiterte.
        self::assertNotNull($reader->read(self::VARIANT, $this->context()));

        $reader->reset();
        $this->expectException(\RuntimeException::class);
        $reader->read(self::VARIANT, $this->context());
    }

    public function testWithoutParentOrLengthGroupThereIsNothingToCheck(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class);
        $withoutParent = $this->variant([]);
        $withoutParent->setParentId(null);

        self::assertNull($this->reader($withoutParent, [self::GROUP], $options)->read(self::VARIANT, $this->context()));
        self::assertNull($this->reader($this->variant([]), [], $options)->read(self::VARIANT, $this->context()));
        self::assertNull($this->reader(null, [self::GROUP], $options)->read(self::VARIANT, $this->context()));
    }

    public function testAnUnreadableOwnOptionGivesNoStage(): void
    {
        $options = StaticEntityRepository::of(PropertyGroupOptionCollection::class, [
            new PropertyGroupOptionCollection([
                self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b1', 'Sonderlänge'),
                self::option('0198d3a9c7c5715e8f2bb1a0a3b5c0b2', '1,0 m'),
            ]),
        ]);

        self::assertNull($this->reader($this->variant(['0198d3a9c7c5715e8f2bb1a0a3b5c0b1']), [self::GROUP], $options)->read(self::VARIANT, $this->context()));
    }

    /**
     * @param list<string> $groupIds
     * @param StaticEntityRepository<PropertyGroupOptionCollection> $options
     */
    private function reader(?ProductEntity $product, array $groupIds, StaticEntityRepository $options, ?int $field = 1): LengthStageReader
    {
        $helper = $this->createMock(MeterProductHelperInterface::class);
        $helper->method('loadProduct')->willReturn($product);
        $resolver = $this->createMock(LengthSettingsResolverInterface::class);
        $resolver->method('forProduct')->willReturn(new LengthSettings(false, true, false, $groupIds, $field));

        return new LengthStageReader($helper, $resolver, $options);
    }

    /**
     * @param list<string> $optionIds
     */
    private function variant(array $optionIds): ProductEntity
    {
        $product = new ProductEntity();
        $product->setId(self::VARIANT);
        $product->setParentId(self::PARENT);
        $product->setOptionIds($optionIds);

        return $product;
    }

    private static function option(string $id, string $name): PropertyGroupOptionEntity
    {
        $option = new PropertyGroupOptionEntity();
        $option->setId($id);
        $option->setName($name);

        return $option;
    }

    private function context(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannelId')->willReturn('0198d3a9c7c5715e8f2bb1a0a3b5c0ff');

        return $context;
    }
}
