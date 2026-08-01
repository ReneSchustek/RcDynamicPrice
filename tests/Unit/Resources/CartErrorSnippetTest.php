<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Warenkorb-Fehler werden in Shopware über **zwei** Wege übersetzt:
 *
 * - `StorefrontController::addCartErrors()` gibt sie als Flash-Message aus und sucht das Snippet
 *   unter `checkout.<messageKey>`.
 * - `cart-alerts.html.twig` rendert sie auf der Warenkorb-Seite und sucht unter `error.<messageKey>`.
 *
 * Fehlt einer der beiden Schlüssel, liest der Kunde den rohen Schlüssel — genau das ist im
 * Storefront-Smoke passiert („checkout.rc-dynamic-price-meter-price-unavailable"). Unit-Tests auf
 * die Fehlerklasse können das nicht sehen, deshalb dieser Guard auf die Snippet-Dateien.
 */
final class CartErrorSnippetTest extends TestCase
{
    private const MESSAGE_KEY = 'rc-dynamic-price-meter-price-unavailable';
    private const PARENTS = ['error', 'checkout'];

    /**
     * @return array<string, array{string}>
     */
    public static function snippetFileProvider(): array
    {
        return [
            'de-DE' => [__DIR__ . '/../../../src/Resources/snippet/de_DE/rc-dynamic-price.de-DE.json'],
            'en-GB' => [__DIR__ . '/../../../src/Resources/snippet/en_GB/rc-dynamic-price.en-GB.json'],
        ];
    }

    #[DataProvider('snippetFileProvider')]
    public function testMeterPriceErrorIsTranslatableUnderBothParents(string $file): void
    {
        self::assertFileExists($file);

        $raw = file_get_contents($file);
        self::assertIsString($raw);

        /** @var array<string, array<string, string>> $snippets */
        $snippets = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $texts = [];

        foreach (self::PARENTS as $parent) {
            self::assertArrayHasKey($parent, $snippets, \sprintf('Elternschlüssel "%s" fehlt in %s', $parent, basename($file)));
            self::assertArrayHasKey(
                self::MESSAGE_KEY,
                $snippets[$parent],
                \sprintf('"%s.%s" fehlt — der Kunde sieht sonst den rohen Schlüssel', $parent, self::MESSAGE_KEY),
            );

            $text = $snippets[$parent][self::MESSAGE_KEY];
            self::assertNotSame('', trim($text));
            self::assertStringNotContainsString(
                self::MESSAGE_KEY,
                $text,
                'Der Übersetzungstext darf nicht den technischen Schlüssel enthalten',
            );

            $texts[] = $text;
        }

        self::assertCount(1, array_unique($texts), 'Beide Elternschlüssel müssen denselben Text tragen');
    }
}
