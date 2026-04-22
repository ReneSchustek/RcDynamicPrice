<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * BRIEF21 Regression-Guard: im Admin-Backend sichtbare Labels duerfen keine technischen
 * Prefixe wie "Rc " oder "rc_" tragen und keinen Platzhalter "Custom Field"/"Custom Fields"
 * stehen lassen. Der Nutzer formulierte: "Bei allen Plugins sollte in den Backend-Feldern
 * auch nicht Rc Custom Fields stehen, sondern immer eine sprachlich passende Bezeichnung".
 *
 * Geprueft werden:
 * - `config.xml` (Card-Title, Input-Labels, HelpText, Placeholder, Option-Names)
 * - Alle Migrations (`label`/`helpText`-JSON-Maps in `custom_field_set` und `custom_field`)
 */
final class AdminLabelCleanlinessTest extends TestCase
{
    private const FORBIDDEN_PATTERNS = [
        '/^Rc\s/u',                    // "Rc Something"
        '/^rc_/u',                      // "rc_meter_price_..." (technische Schluessel)
        '/\bcustom\s+fields?\b/iu',    // "Custom Field", "Custom Fields"
    ];

    private const TRANSLATABLE_CONFIG_TAGS = ['title', 'label', 'helpText', 'placeholder'];

    public function testConfigXmlLabelsAreHumanReadable(): void
    {
        $path = \dirname(__DIR__, 3) . '/src/Resources/config/config.xml';
        $document = new \DOMDocument();
        self::assertTrue($document->load($path), 'config.xml muss ladbar sein.');

        $violations = [];

        foreach (self::TRANSLATABLE_CONFIG_TAGS as $tag) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $violations = array_merge(
                    $violations,
                    $this->findPatternsIn($node->textContent, \sprintf('<%s> Zeile %d', $tag, $node->getLineNo()))
                );
            }
        }

        foreach ($document->getElementsByTagName('option') as $option) {
            if (!$option instanceof \DOMElement) {
                continue;
            }
            foreach ($option->getElementsByTagName('name') as $name) {
                if (!$name instanceof \DOMElement) {
                    continue;
                }
                $violations = array_merge(
                    $violations,
                    $this->findPatternsIn($name->textContent, \sprintf('<option><name> Zeile %d', $name->getLineNo()))
                );
            }
        }

        self::assertSame([], $violations, \sprintf(
            "Unzulaessige technische Labels in config.xml:\n%s",
            implode("\n", $violations)
        ));
    }

    public function testMigrationLabelsAreHumanReadable(): void
    {
        $directory = \dirname(__DIR__, 3) . '/src/Migration';
        $files = glob($directory . '/*.php') ?: [];
        self::assertNotEmpty($files, 'Migration-Verzeichnis darf nicht leer sein.');

        $violations = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, \sprintf('Migration %s unlesbar.', $file));

            foreach ($this->extractLabelStrings($source) as $line => $value) {
                $violations = array_merge(
                    $violations,
                    $this->findPatternsIn($value, \sprintf('%s:%d', basename($file), $line))
                );
            }
        }

        self::assertSame([], $violations, \sprintf(
            "Unzulaessige technische Labels in Migrations:\n%s",
            implode("\n", $violations)
        ));
    }

    /**
     * @return list<string>
     */
    private function findPatternsIn(string $text, string $location): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $violations = [];
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $violations[] = \sprintf('%s: "%s" verletzt Regel %s', $location, $text, $pattern);
            }
        }

        return $violations;
    }

    /**
     * Extrahiert Strings, die in den Migrations als Labels oder HelpTexts auftauchen.
     * Liest nur die Werte aus Locale-Maps (`['de-DE' => '...', 'en-GB' => '...']`),
     * nicht die technischen Feldnamen wie `rc_meter_price_active` (die sind API).
     *
     * @return iterable<int, string>
     */
    private function extractLabelStrings(string $source): iterable
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
            if ($map === null) {
                continue;
            }

            foreach ($map as $value) {
                yield $token->line => $value;
            }
        }
    }

    /**
     * @param array<int, \PhpToken> $tokens
     *
     * @return array<string, string>|null
     */
    private function readFollowingLocaleMap(array $tokens, int $startIndex): ?array
    {
        $count = \count($tokens);
        $i = $startIndex + 1;

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
