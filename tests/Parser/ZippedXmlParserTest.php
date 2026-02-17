<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Parser;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Exception\ParseException;
use TextAtAnyCost\Parser\ZippedXmlParser;

/**
 * Tests for ZippedXmlParser.
 *
 * We build minimal in-memory ZIP archives so that no external fixture files
 * are required.
 */
final class ZippedXmlParserTest extends TestCase
{
    private ZippedXmlParser $parser;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not available');
        }
        $this->parser = new ZippedXmlParser();
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testExtractDocxThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->extractDocx('/nonexistent/file.docx');
    }

    public function testExtractOdtThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->extractOdt('/nonexistent/file.odt');
    }

    public function testExtractDocxThrowsWhenContentFileMissing(): void
    {
        $tmp = $this->createZipWithEntry('other.xml', '<root/>');
        try {
            $this->expectException(ParseException::class);
            $this->parser->extractDocx($tmp);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // DOCX extraction
    // -------------------------------------------------------------------------

    public function testExtractDocxReturnsText(): void
    {
        $xml = '<?xml version="1.0"?>
                <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                  <w:body><w:p><w:r><w:t>Hello DOCX World</w:t></w:r></w:p></w:body>
                </w:document>';

        $tmp = $this->createZipWithEntry('word/document.xml', $xml);
        try {
            $result = $this->parser->extractDocx($tmp);
            $this->assertStringContainsString('Hello DOCX World', $result);
        } finally {
            unlink($tmp);
        }
    }

    public function testExtractDocxStripsXmlTags(): void
    {
        $xml = '<?xml version="1.0"?><root><child attr="x">Content</child></root>';
        $tmp = $this->createZipWithEntry('word/document.xml', $xml);
        try {
            $result = $this->parser->extractDocx($tmp);
            $this->assertStringNotContainsString('<', $result);
            $this->assertStringContainsString('Content', $result);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // ODT extraction
    // -------------------------------------------------------------------------

    public function testExtractOdtReturnsText(): void
    {
        $xml = '<?xml version="1.0"?>
                <office:document-content
                    xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                    xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">
                  <office:body><office:text>
                    <text:p>Hello ODT World</text:p>
                  </office:text></office:body>
                </office:document-content>';

        $tmp = $this->createZipWithEntry('content.xml', $xml);
        try {
            $result = $this->parser->extractOdt($tmp);
            $this->assertStringContainsString('Hello ODT World', $result);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Security: LIBXML_XINCLUDE must not be active (no file inclusion)
    // -------------------------------------------------------------------------

    public function testXIncludeIsNotHonoured(): void
    {
        // If LIBXML_XINCLUDE were enabled, this would attempt to include /etc/passwd
        $xml = '<?xml version="1.0"?>
                <root xmlns:xi="http://www.w3.org/2001/XInclude">
                  <xi:include href="/etc/passwd" parse="text"/>
                  <child>SafeText</child>
                </root>';

        $tmp = $this->createZipWithEntry('word/document.xml', $xml);
        try {
            $result = $this->parser->extractDocx($tmp);
            // The xi:include tag should not be processed — output should NOT
            // contain /etc/passwd contents (we don't know its content, but the
            // xi:include element itself would be stripped as a tag).
            $this->assertStringNotContainsString('xi:include', $result);
            $this->assertStringContainsString('SafeText', $result);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a ZIP archive in a temp file with a single entry and return the path.
     */
    private function createZipWithEntry(string $entryName, string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'taac_zip_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($entryName, $content);
        $zip->close();
        return $tmp;
    }
}
