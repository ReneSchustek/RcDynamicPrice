<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Regression-Guard für die Sprach-Regel: in Quelltext, Kommentaren und Docblocks stehen
 * echte Umlaute (ä, ö, ü, Ä, Ö, Ü, ß) — niemals die Ersatzschreibweisen `ae`/`oe`/`ue`/`ss`.
 *
 * Warum dateisystem-basiert und nicht diff-basiert: `git diff` erfasst untracked Dateien
 * nicht. Genau dadurch sind Verstöße in neuen Dateien unbemerkt in ein Release gelangt.
 * Dieser Test scannt den Baum und sieht deshalb auch brandneue Dateien.
 *
 * Ergänzt den AdminLabelCleanlinessTest, der ausschließlich nutzersichtbare Admin-Labels
 * in `config.xml` und den Migrations prüft.
 */
final class SourceUmlautCleanlinessTest extends TestCase
{
    /**
     * Deutsche Wortstämme, in denen `ae`/`oe`/`ue`/`ss` sicher eine Ersatzschreibweise ist.
     *
     * Bewusst eine Wortstamm-Liste statt eines naiven `/ue/`: Letzteres würde `queue`,
     * `value` und `Neue` treffen. Englische Bezeichner und Framework-Begriffe bleiben so
     * unberührt.
     */
    private const FORBIDDEN_STEMS = [
        'laeng', 'fuer', 'ueber', 'stueck', 'gleichmaess', 'hoeher', 'prioritaet',
        'kuerz', 'aender', 'veraender', 'naechst', 'loesch', 'ausschliesslich',
        'frueher', 'ausgeloest', 'duerfen', 'pruef', 'zusaetz', 'muess', 'koenn',
        'schliess', 'zurueck', 'haeng', 'raeum', 'kuenftig', 'moeglich', 'ausser',
        'unveraendert', 'abhaengig', 'tatsaech', 'verkaeuf', 'vollstaendig', 'erhoeh',
        'massgeb', 'faellt', 'faelle', 'traegt', 'gruen', 'stoer', 'gemaess',
        'ausfuehr', 'urspruengl', 'schluessel', 'behaelt', 'laeuft', 'kaempf',
        'groess', 'waehr', 'standardmaessig',
    ];

    /**
     * Dateien, die die Ersatzschreibweisen als **Daten** tragen und deshalb nicht
     * korrigiert werden dürfen — eine Korrektur würde sie funktional zerstören.
     *
     * @var list<string>
     */
    private const ALLOWLIST = [
        // Trägt die verbotenen Wortstämme selbst als Regex-Muster.
        'tests/Unit/Resources/AdminLabelCleanlinessTest.php',
        // Dieser Test: die Stamm-Liste oben ist ebenfalls Daten.
        'tests/Unit/Resources/SourceUmlautCleanlinessTest.php',
        // Heilungs-Migration: Map-Schlüssel sind die falschen Schreibweisen
        // (z.B. 'Mindestlaenge' => 'Mindestlänge').
        'src/Migration/Migration1745700000FixCustomFieldLabelsUmlauts.php',
        'tests/Unit/Migration/Migration1745700000FixCustomFieldLabelsUmlautsTest.php',
    ];

    /** Verzeichnisse ohne eigenen Quellcode (Build-Artefakte, Fremdcode). */
    private const SKIPPED_DIRECTORIES = ['dist', 'node_modules', 'vendor'];

    /** @var list<string> */
    private const SCANNED_EXTENSIONS = ['php', 'js', 'mjs', 'scss', 'twig', 'xml', 'json'];

    public function testSourceFilesUseRealUmlauts(): void
    {
        $root = \dirname(__DIR__, 3);
        $violations = [];

        foreach ($this->collectFiles($root) as $absolutePath) {
            $relativePath = $this->toRelativePath($root, $absolutePath);

            if (\in_array($relativePath, self::ALLOWLIST, true)) {
                continue;
            }

            $contents = file_get_contents($absolutePath);
            self::assertIsString($contents, \sprintf('Datei nicht lesbar: %s', $relativePath));

            foreach ($this->findViolations($contents) as $lineNumber => $word) {
                $violations[] = \sprintf('%s:%d — "%s"', $relativePath, $lineNumber, $word);
            }
        }

        self::assertSame(
            [],
            $violations,
            \sprintf(
                "Ersatzschreibweisen statt Umlauten gefunden (%d):\n%s",
                \count($violations),
                implode("\n", $violations),
            ),
        );
    }

    public function testAllowlistedFilesStillExist(): void
    {
        // Eine Allowlist, die auf verschwundene Dateien zeigt, verdeckt stillschweigend
        // neue Verstöße. Sie muss deshalb selbst geprüft werden.
        $root = \dirname(__DIR__, 3);

        foreach (self::ALLOWLIST as $relativePath) {
            self::assertFileExists(
                $root . '/' . $relativePath,
                \sprintf('Allowlist-Eintrag "%s" existiert nicht mehr — Eintrag entfernen.', $relativePath),
            );
        }
    }

    /**
     * @return array<int, string> Zeilennummer => gefundenes Wort
     */
    private function findViolations(string $contents): array
    {
        $pattern = '/\b\w*(?:' . implode('|', self::FORBIDDEN_STEMS) . ')\w*\b/iu';
        $found = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                $found[$index + 1] = $matches[0];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function collectFiles(string $root): array
    {
        $files = [];

        foreach (['src', 'tests'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator(
                        $root . '/' . $directory,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                    static fn (\SplFileInfo $current): bool => !$current->isDir()
                        || !\in_array($current->getFilename(), self::SKIPPED_DIRECTORIES, true),
                ),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (\in_array(strtolower($file->getExtension()), self::SCANNED_EXTENSIONS, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function toRelativePath(string $root, string $absolutePath): string
    {
        return str_replace('\\', '/', substr($absolutePath, \strlen($root) + 1));
    }
}
