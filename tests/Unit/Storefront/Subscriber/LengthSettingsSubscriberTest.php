<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcDynamicPrice\DynamicPriceConstants;
use Ruhrcoder\RcDynamicPrice\Service\CategoryChainLoaderInterface;
use Ruhrcoder\RcDynamicPrice\Service\LengthSettingsResolver;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\GuidedSelectionStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Struct\LengthSettingsStruct;
use Ruhrcoder\RcDynamicPrice\Storefront\Subscriber\LengthSettingsSubscriber;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader;
use Shopware\Core\Content\Product\SalesChannel\Detail\AvailableCombinationResult;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was die Produktseite vom Längen-Verhalten erfährt: Längenschalter, „nur die Länge" und die Angaben der
 * geführten Auswahl. Eine Kombination zu viel bietet dem Kunden eine Variante an, die er nicht kaufen kann.
 */
final class LengthSettingsSubscriberTest extends TestCase
{
    private const LENGTH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const POSTS = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OTHER = 'cccccccccccccccccccccccccccccccc';

    private const LENGTH_FIELD = [
        'tmms_customer_input_1_active' => true,
        'tmms_customer_input_1_placeholder' => 'Gewünschte Länge in mm',
    ];

    public function testTheLengthGroupComesFirstAndTheOthersKeepTheirOrder(): void
    {
        self::assertSame([
            ['id' => self::LENGTH, 'optionIds' => ['m-1', 'm-2']],
            ['id' => self::POSTS, 'optionIds' => ['p-2', 'p-3']],
        ], LengthSettingsSubscriber::orderedGroups($this->pageGroups(), self::LENGTH));
    }

    /**
     * Was: Der Kern zeigt „Ausführung“ vor „Maße“.
     * Warum: Die Länge steht oben; der Kunde soll sie kennen, bevor er die Ausführung wählt.
     * Erwartet: Auf der Seite steht die Längengruppe zuerst, die übrigen in ihrer Reihenfolge.
     */
    public function testTheLengthGroupIsShownFirstOnThePage(): void
    {
        $page = $this->page([
            DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::LENGTH],
        ]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        self::assertSame([self::LENGTH, self::POSTS], array_values($page->getConfiguratorSettings()->getIds()));
    }

    /**
     * Was: Keine Längeneinstellung am Artikel oder an der Kategorie.
     * Warum: Ohne Längengruppe gibt es nichts nach vorn zu holen; die Seite bleibt, wie der Kern sie baut.
     * Erwartet: Reihenfolge unverändert.
     */
    public function testWithoutLengthSettingsTheGroupOrderStays(): void
    {
        $page = $this->page([]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        self::assertSame([self::POSTS, self::LENGTH], array_values($page->getConfiguratorSettings()->getIds()));
    }

    /**
     * Von zwei eingestellten Gruppen gilt die, die der Artikel hat; die Kategorie nennt beide, weil ihre
     * Artikel die Längen verschieden benennen.
     */
    public function testTheFirstConfiguredGroupOnThePageIsTheLengthGroup(): void
    {
        $page = $this->page([
            DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::OTHER, self::LENGTH],
        ]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        $settings = $page->getExtension(LengthSettingsSubscriber::EXTENSION_NAME);
        self::assertInstanceOf(LengthSettingsStruct::class, $settings);
        self::assertTrue($settings->isLengthSwitch());
        self::assertSame(self::LENGTH, $settings->getLengthGroupId());
        self::assertSame(1, $settings->getLengthFieldNumber());
    }

    public function testOnlyBuyableCombinationsAreHandedToThePage(): void
    {
        $combinations = new AvailableCombinationResult();
        $combinations->addCombination(['p-2', 'm-1'], true);
        $combinations->addCombination(['p-3', 'm-2'], true);
        // Nicht verfügbar: Der Kern zeigt sie durchgestrichen, die geführte Auswahl gar nicht.
        $combinations->addCombination(['p-2', 'm-2'], false);

        $page = $this->page([
            DynamicPriceConstants::FIELD_GUIDED_SELECTION => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::LENGTH],
        ]);
        $this->subscriber($combinations, 'eltern')->onProductPageLoaded($this->event($page));

        $extension = $page->getExtension(LengthSettingsSubscriber::GUIDED_EXTENSION_NAME);
        self::assertInstanceOf(GuidedSelectionStruct::class, $extension);
        self::assertSame(self::LENGTH, $extension->getLengthGroupId());
        self::assertSame([['p-2', 'm-1'], ['p-3', 'm-2']], $extension->getCombinations());
    }

    /**
     * „Nur die Länge" blendet die Größen aus; eine geführte Auswahl über ausgeblendete Knöpfe wäre leer.
     */
    public function testLengthOnlyReplacesTheGuidedSelection(): void
    {
        $page = $this->page([
            DynamicPriceConstants::FIELD_GUIDED_SELECTION => true,
            DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true,
            DynamicPriceConstants::FIELD_LENGTH_ONLY => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::LENGTH],
        ]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        $settings = $page->getExtension(LengthSettingsSubscriber::EXTENSION_NAME);
        self::assertInstanceOf(LengthSettingsStruct::class, $settings);
        self::assertTrue($settings->isLengthOnly());
        self::assertFalse($page->hasExtension(LengthSettingsSubscriber::GUIDED_EXTENSION_NAME));
    }

    /**
     * Ohne Längengruppe auf der Seite gibt es keine Knöpfe, die sich ausblenden ließen.
     */
    public function testLengthOnlyNeedsALengthGroupOnThePage(): void
    {
        $page = $this->page([
            DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true,
            DynamicPriceConstants::FIELD_LENGTH_ONLY => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::OTHER],
        ]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        $settings = $page->getExtension(LengthSettingsSubscriber::EXTENSION_NAME);
        self::assertInstanceOf(LengthSettingsStruct::class, $settings);
        self::assertFalse($settings->isLengthOnly());
        self::assertNull($settings->getLengthGroupId());
    }

    public function testAProductWithoutVariantsGetsNothing(): void
    {
        $page = $this->page([DynamicPriceConstants::FIELD_LENGTH_VARIANT_SWITCH => true], parentId: null);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        self::assertFalse($page->hasExtension(LengthSettingsSubscriber::EXTENSION_NAME));
    }

    public function testWithoutAnySettingThePageStaysAsTheCoreBuildsIt(): void
    {
        $page = $this->page([]);
        $this->subscriber(new AvailableCombinationResult(), null)->onProductPageLoaded($this->event($page));

        self::assertFalse($page->hasExtension(LengthSettingsSubscriber::EXTENSION_NAME));
        self::assertFalse($page->hasExtension(LengthSettingsSubscriber::GUIDED_EXTENSION_NAME));
    }

    public function testWithoutAnyBuyableCombinationThereIsNoGuidedSelection(): void
    {
        $combinations = new AvailableCombinationResult();
        $combinations->addCombination(['p-2', 'm-1'], false);

        $page = $this->page([
            DynamicPriceConstants::FIELD_GUIDED_SELECTION => true,
            DynamicPriceConstants::FIELD_LENGTH_GROUPS => [self::LENGTH],
        ]);
        $this->subscriber($combinations, 'eltern')->onProductPageLoaded($this->event($page));

        self::assertFalse($page->hasExtension(LengthSettingsSubscriber::GUIDED_EXTENSION_NAME));
    }

    private function subscriber(AvailableCombinationResult $combinations, ?string $expectedParentId): LengthSettingsSubscriber
    {
        $chainLoader = $this->createMock(CategoryChainLoaderInterface::class);
        $chainLoader->method('loadChain')->willReturn([]);

        $loader = $this->createMock(AbstractAvailableCombinationLoader::class);
        $loader->expects($expectedParentId === null ? self::never() : self::once())
            ->method('loadCombinations')
            ->willReturnCallback(function (string $productId) use ($combinations, $expectedParentId): AvailableCombinationResult {
                self::assertSame($expectedParentId, $productId, 'Die Kombinationen gehören zum Elternprodukt, nicht zur gezeigten Variante.');

                return $combinations;
            });

        return new LengthSettingsSubscriber(new LengthSettingsResolver($chainLoader), $loader);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function page(array $customFields, ?string $parentId = 'eltern'): ProductPage
    {
        $product = new SalesChannelProductEntity();
        $product->setId('variante');
        $product->setParentId($parentId);
        $product->setChildCount(0);
        $product->setCustomFields(self::LENGTH_FIELD + $customFields);

        $page = new ProductPage();
        $page->setProduct($product);
        $page->setConfiguratorSettings($this->pageGroups());

        return $page;
    }

    private function event(ProductPage $page): ProductPageLoadedEvent
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('kanal');
        $context->method('getContext')->willReturn(new Context(new SystemSource()));

        return new ProductPageLoadedEvent($page, $context, new Request());
    }

    /**
     * Wie der Kern die Gruppen zeigt: „Ausführung" vor „Maße".
     */
    private function pageGroups(): PropertyGroupCollection
    {
        return new PropertyGroupCollection([
            $this->group(self::POSTS, ['p-2', 'p-3']),
            $this->group(self::LENGTH, ['m-1', 'm-2']),
        ]);
    }

    /**
     * @param list<string> $optionIds
     */
    private function group(string $id, array $optionIds): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();
        foreach ($optionIds as $optionId) {
            $option = new PropertyGroupOptionEntity();
            $option->setId($optionId);
            $options->add($option);
        }

        $group = new PropertyGroupEntity();
        $group->setId($id);
        $group->setOptions($options);

        return $group;
    }
}
