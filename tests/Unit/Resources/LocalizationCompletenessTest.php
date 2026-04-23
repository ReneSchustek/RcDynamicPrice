<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Stellt sicher, dass jedes übersetzbare Feld im Plugin-Schema sowohl de-DE als auch
 * en-GB pflegt — sonst fällt das Admin-Backend still auf den anderen Locale zurück.
 *
 * Shopware interpretiert Elemente in `config.xml` ohne `lang`-Attribut als en-GB.
 * Fehlt ein expliziter de-DE-Eintrag, sieht ein deutscher Admin englischen Text.
 * Die Custom-Field-Migrations speichern die Übersetzungen als JSON-Map
 * (`['de-DE' => ..., 'en-GB' => ...]`); beide Keys müssen vorhanden sein.
 */
final class LocalizationCompletenessTest extends TestCase
{
    private const REQUIRED_LOCALES = ['de-DE', 'en-GB'];
    private const CONFIG_TRANSLATABLE_TAGS = ['title', 'label', 'helpText', 'placeholder'];

    public function testConfigXmlHasBothLocalesForEveryTranslatableNode(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/config/config.xml';
        $document = new \DOMDocument();
        self::assertTrue($document->load($path), 'config.xml muss ladbar sein.');

        $missing = [];
        foreach (self::CONFIG_TRANSLATABLE_TAGS as $tag) {
            $missing = array_merge($missing, $this->findLocaleGapsForTag($document, $tag));
        }

        $missing = array_merge($missing, $this->findOptionNameGaps($document));

        self::assertSame([], $missing, \sprintf(
            "In config.xml fehlen Übersetzungen:\n%s",
            implode("\n", $missing)
        ));
    }

    public function testMigrationJsonPayloadsHaveBothLocalesForEveryLabelAndHelpText(): void
    {
        $directory = \dirname(__DIR__, 3) . '/src/Migration';
        $files = glob($directory . '/*.php') ?: [];
        self::assertNotEmpty($files, 'Migration-Verzeichnis darf nicht leer sein.');

        $missing = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, \sprintf('Migration %s unlesbar.', $file));

            foreach ($this->extractTranslatableMaps($source) as $line => $map) {
                foreach (self::REQUIRED_LOCALES as $locale) {
                    if (!\array_key_exists($locale, $map)) {
                        $missing[] = \sprintf(
                            '%s:%d — fehlender Locale "%s" in %s',
                            basename($file),
                            $line,
                            $locale,
                            json_encode($map, \JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "In Migrations fehlen Übersetzungen:\n%s",
            implode("\n", $missing)
        ));
    }

    /**
     * @return array<int, string>
     */
    private function findLocaleGapsForTag(\DOMDocument $document, string $tag): array
    {
        $gaps = [];
        foreach ($document->getElementsByTagName($tag) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $present = [];
            foreach ($node->parentNode?->childNodes ?? [] as $sibling) {
                if ($sibling instanceof \DOMElement && $sibling->tagName === $tag) {
                    $lang = $sibling->getAttribute('lang');
                    if ($lang !== '') {
                        $present[$lang] = true;
                    } else {
                        $present['__no-lang__'] = true;
                    }
                }
            }

            // Nur Prüfung pro Gruppe (erste Fundstelle reicht, Duplikate überspringen)
            $isFirstSibling = $this->isFirstOfTag($node, $tag);
            if (!$isFirstSibling) {
                continue;
            }

            if (isset($present['__no-lang__'])) {
                $gaps[] = \sprintf(
                    '<%s> ohne lang-Attribut in <%s> (Zeile %d) — wird als en-GB interpretiert, '
                    . 'bitte auf lang="de-DE"/lang="en-GB" umstellen',
                    $tag,
                    $node->parentNode?->nodeName ?? '?',
                    $node->getLineNo()
                );
                continue;
            }

            foreach (self::REQUIRED_LOCALES as $locale) {
                if (!isset($present[$locale])) {
                    $gaps[] = \sprintf(
                        '<%s lang="%s"> fehlt in <%s> (Zeile %d)',
                        $tag,
                        $locale,
                        $node->parentNode?->nodeName ?? '?',
                        $node->getLineNo()
                    );
                }
            }
        }

        return $gaps;
    }

    /**
     * @return array<int, string>
     */
    private function findOptionNameGaps(\DOMDocument $document): array
    {
        $gaps = [];
        foreach ($document->getElementsByTagName('option') as $option) {
            if (!$option instanceof \DOMElement) {
                continue;
            }

            $names = [];
            foreach ($option->getElementsByTagName('name') as $name) {
                if (!$name instanceof \DOMElement) {
                    continue;
                }
                $lang = $name->getAttribute('lang');
                if ($lang === '') {
                    $gaps[] = \sprintf(
                        '<option><name> ohne lang-Attribut (Zeile %d) — wird als en-GB interpretiert',
                        $name->getLineNo()
                    );
                    continue;
                }
                $names[$lang] = true;
            }

            foreach (self::REQUIRED_LOCALES as $locale) {
                if (!isset($names[$locale])) {
                    $id = '';
                    foreach ($option->getElementsByTagName('id') as $idNode) {
                        $id = $idNode->textContent;
                        break;
                    }
                    $gaps[] = \sprintf(
                        '<option id="%s"><name lang="%s"> fehlt (Zeile %d)',
                        $id,
                        $locale,
                        $option->getLineNo()
                    );
                }
            }
        }

        return $gaps;
    }

    private function isFirstOfTag(\DOMElement $node, string $tag): bool
    {
        $previous = $node->previousElementSibling;
        while ($previous instanceof \DOMElement) {
            if ($previous->tagName === $tag) {
                return false;
            }
            $previous = $previous->previousElementSibling;
        }

        return true;
    }

    /**
     * Parst Migrations-PHP und liefert alle als PHP-Array notierten Locale-Maps
     * (z. B. `['de-DE' => '...', 'en-GB' => '...']`) mit Zeilennummer.
     *
     * @return iterable<int, array<string, string>>
     */
    private function extractTranslatableMaps(string $source): iterable
    {
        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $key = trim($token->text, "'\"");
            if ($key !== 'label' && $key !== 'helpText') {
                continue;
            }

            $map = $this->readFollowingLocaleMap($tokens, $i);
            if ($map !== null) {
                yield $token->line => $map;
            }
        }
    }

    /**
     * Sucht nach dem Muster `'<key>' => [ 'de-DE' => '...', 'en-GB' => '...' ]` ab Token-Index.
     *
     * @param array<int, \PhpToken> $tokens
     *
     * @return array<string, string>|null
     */
    private function readFollowingLocaleMap(array $tokens, int $startIndex): ?array
    {
        $count = \count($tokens);
        $i = $startIndex + 1;

        // Erwartet: Whitespace, =>, Whitespace, [
        while ($i < $count && $tokens[$i]->id === \T_WHITESPACE) {
            $i++;
        }
        if ($i >= $count || $tokens[$i]->id !== \T_DOUBLE_ARROW) {
            return null;
        }
        $i++;
        while ($i < $count && $tokens[$i]->id === \T_WHITESPACE) {
            $i++;
        }
        if ($i >= $count || $tokens[$i]->text !== '[') {
            return null;
        }
        $i++;

        $map = [];
        $depth = 1;
        $pendingKey = null;

        while ($i < $count && $depth > 0) {
            $token = $tokens[$i];

            if ($token->text === '[') {
                $depth++;
            } elseif ($token->text === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $token->id === \T_CONSTANT_ENCAPSED_STRING) {
                $value = $this->decodeStringToken($token->text);
                if ($pendingKey === null) {
                    $pendingKey = $value;
                } else {
                    $map[$pendingKey] = $value;
                    $pendingKey = null;
                }
            } elseif ($depth === 1 && $token->text === ',') {
                $pendingKey = null;
            }

            $i++;
        }

        $isLocaleMap = $map !== [] && array_keys($map) === array_filter(
            array_keys($map),
            static fn (string $k): bool => (bool) preg_match('/^[a-z]{2}-[A-Z]{2}$/', $k)
        );

        return $isLocaleMap ? $map : null;
    }

    private function decodeStringToken(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        $inner = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }
}
