<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Parser;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Exception\ParseException;
use TextAtAnyCost\Parser\RtfParser;

/**
 * Tests for RtfParser.
 *
 * RTF is text-based so we can craft inputs directly without binary fixtures.
 */
final class RtfParserTest extends TestCase
{
    private RtfParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RtfParser();
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testExtractTextThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->extractText('/nonexistent/file.rtf');
    }

    public function testParseStringThrowsOnEmptyInput(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->parseString('');
    }

    // -------------------------------------------------------------------------
    // Basic text extraction
    // -------------------------------------------------------------------------

    public function testSimplePlainText(): void
    {
        $rtf = '{\\rtf1\\ansi Hello World}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('Hello World', $result);
    }

    public function testParagraphBreakProducesNewline(): void
    {
        $rtf = '{\\rtf1 First\\par Second}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString("\n", $result);
        $this->assertStringContainsString('First', $result);
        $this->assertStringContainsString('Second', $result);
    }

    public function testFontTableIsSkipped(): void
    {
        $rtf = '{\\rtf1{\\fonttbl{\\f0 Arial;}}Hello}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringNotContainsString('Arial', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function testColorTableIsSkipped(): void
    {
        $rtf = '{\\rtf1{\\colortbl;\\red255\\green0\\blue0;}Text}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringNotContainsString('red', $result);
        $this->assertStringContainsString('Text', $result);
    }

    // -------------------------------------------------------------------------
    // Special characters
    // -------------------------------------------------------------------------

    public function testEmDash(): void
    {
        $rtf    = '{\\rtf1 A\\emdash B}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('—', $result);
    }

    public function testEnDash(): void
    {
        $rtf    = '{\\rtf1 A\\endash B}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('–', $result);
    }

    public function testTab(): void
    {
        $rtf    = '{\\rtf1 A\\tab B}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString("\t", $result);
    }

    public function testNonBreakingSpace(): void
    {
        $rtf    = '{\\rtf1 A\\~B}';
        $result = $this->parser->parseString($rtf);
        // \~ is a non-breaking space (U+00A0)
        $this->assertStringContainsString("\u{00A0}", $result);
    }

    // -------------------------------------------------------------------------
    // Unicode control word
    // -------------------------------------------------------------------------

    public function testUnicodeControlWord(): void
    {
        // \u233 = U+00E9 (é), followed by a replacement char (skipped)
        $rtf    = '{\\rtf1 \\u233?}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('é', $result);
    }

    // -------------------------------------------------------------------------
    // PR #4: stack underflow guard
    // -------------------------------------------------------------------------

    public function testStackDoesNotUnderflowOnExtraClosingBrace(): void
    {
        // Extra '}' should not crash — the parser should handle it gracefully
        $rtf    = '{\\rtf1 Text}}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('Text', $result);
    }

    public function testTextBeforeFirstOpeningBrace(): void
    {
        // Characters before the first '{' should not crash (j == -1 guard)
        $rtf    = 'ignored{\\rtf1 Inside}';
        // Should not throw
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('Inside', $result);
    }

    // -------------------------------------------------------------------------
    // Nested groups
    // -------------------------------------------------------------------------

    public function testNestedGroupsInheritState(): void
    {
        // Outer group text is visible; inner * group is skipped
        $rtf = '{\\rtf1 Outer{\\* hidden}visible}';
        $result = $this->parser->parseString($rtf);
        $this->assertStringContainsString('Outer', $result);
        $this->assertStringNotContainsString('hidden', $result);
        $this->assertStringContainsString('visible', $result);
    }

    // -------------------------------------------------------------------------
    // File round-trip (temp file)
    // -------------------------------------------------------------------------

    public function testExtractTextFromTempFile(): void
    {
        $rtf = '{\\rtf1 File content here}';
        $tmp = tempnam(sys_get_temp_dir(), 'taac_rtf_') . '.rtf';
        file_put_contents($tmp, $rtf);
        try {
            $result = $this->parser->extractText($tmp);
            $this->assertStringContainsString('File content here', $result);
        } finally {
            unlink($tmp);
        }
    }
}
