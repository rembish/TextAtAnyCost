<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TextAtAnyCost\TextExtractor;

/**
 * Tests for the TextExtractor facade.
 */
final class TextExtractorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isSupported()
    // -------------------------------------------------------------------------

    #[DataProvider('supportedExtensionProvider')]
    public function testIsSupportedReturnsTrueForKnownExtensions(string $filename): void
    {
        $this->assertTrue(TextExtractor::isSupported($filename));
    }

    /** @return array<string, array{string}> */
    public static function supportedExtensionProvider(): array
    {
        return [
            'doc'  => ['document.doc'],
            'pdf'  => ['document.pdf'],
            'ppt'  => ['presentation.ppt'],
            'rtf'  => ['document.rtf'],
            'docx' => ['document.docx'],
            'odt'  => ['document.odt'],
            'uppercase DOC' => ['document.DOC'],
            'uppercase PDF' => ['report.PDF'],
        ];
    }

    #[DataProvider('unsupportedExtensionProvider')]
    public function testIsSupportedReturnsFalseForUnknownExtensions(string $filename): void
    {
        $this->assertFalse(TextExtractor::isSupported($filename));
    }

    /** @return array<string, array{string}> */
    public static function unsupportedExtensionProvider(): array
    {
        return [
            'xlsx' => ['spreadsheet.xlsx'],
            'pptx' => ['presentation.pptx'],
            'txt'  => ['plain.txt'],
            'html' => ['page.html'],
            'zip'  => ['archive.zip'],
            'no extension' => ['noextension'],
        ];
    }

    // -------------------------------------------------------------------------
    // fromFile() — unsupported extension
    // -------------------------------------------------------------------------

    public function testFromFileThrowsOnUnsupportedExtension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported/i');
        TextExtractor::fromFile('document.xlsx');
    }

    // -------------------------------------------------------------------------
    // fromFile() — delegates to the correct parser (RTF smoke test)
    // -------------------------------------------------------------------------

    public function testFromFileExtractsRtfContent(): void
    {
        $rtf = '{\\rtf1 Facade Test}';
        $tmp = tempnam(sys_get_temp_dir(), 'taac_ext_') . '.rtf';
        file_put_contents($tmp, $rtf);
        try {
            $result = TextExtractor::fromFile($tmp);
            $this->assertStringContainsString('Facade Test', $result);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // fromFile() — delegates to the correct parser (DOCX smoke test)
    // -------------------------------------------------------------------------

    public function testFromFileExtractsDocxContent(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not available');
        }

        $xml = '<?xml version="1.0"?><root><w:t>Facade DOCX</w:t></root>';
        $tmp = tempnam(sys_get_temp_dir(), 'taac_ext_') . '.docx';

        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        try {
            $result = TextExtractor::fromFile($tmp);
            $this->assertStringContainsString('Facade DOCX', $result);
        } finally {
            unlink($tmp);
        }
    }
}
