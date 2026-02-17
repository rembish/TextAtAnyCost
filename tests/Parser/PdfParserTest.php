<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Parser;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Exception\ParseException;
use TextAtAnyCost\Parser\PdfParser;

/**
 * Tests for PdfParser.
 *
 * The decode methods are private; we exercise them indirectly through
 * extractText() with minimal synthetic PDF content, and directly via
 * a Reflection-based helper for thorough unit coverage.
 */
final class PdfParserTest extends TestCase
{
    private PdfParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PdfParser();
    }

    // -------------------------------------------------------------------------
    // File-level error handling
    // -------------------------------------------------------------------------

    public function testExtractTextThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->extractText('/nonexistent/file.pdf');
    }

    public function testExtractTextReturnsEmptyForEmptyPdf(): void
    {
        // A PDF with no objects produces no text
        $pdf = "%PDF-1.4\n%%EOF\n";
        $result = $this->callExtractOnString($pdf);
        $this->assertSame('', trim($result));
    }

    // -------------------------------------------------------------------------
    // ASCIIHex decode
    // -------------------------------------------------------------------------

    public function testDecodeAsciiHexBasic(): void
    {
        // "Hello" = 48 65 6C 6C 6F
        $this->assertSame('Hello', $this->decodeAsciiHex('48656c6c6f>'));
    }

    public function testDecodeAsciiHexWithSpaces(): void
    {
        $this->assertSame('AB', $this->decodeAsciiHex("41 42>"));
    }

    public function testDecodeAsciiHexOddNibble(): void
    {
        // Single trailing nibble '4' should decode as chr(0x40)
        $result = $this->decodeAsciiHex('4>');
        $this->assertSame(chr(0x40), $result);
    }

    public function testDecodeAsciiHexMissingTerminator(): void
    {
        $this->assertFalse($this->decodeAsciiHex('4142')); // no '>'
    }

    public function testDecodeAsciiHexInvalidChar(): void
    {
        $this->assertFalse($this->decodeAsciiHex('GG>')); // 'G' is not a hex digit
    }

    // -------------------------------------------------------------------------
    // ASCII85 decode
    // -------------------------------------------------------------------------

    public function testDecodeAscii85ZeroGroup(): void
    {
        // 'z' decodes as four zero bytes
        $result = $this->decodeAscii85('z~');
        $this->assertSame(str_repeat("\x00", 4), $result);
    }

    public function testDecodeAscii85KnownValue(): void
    {
        // "Man " encoded in ASCII-85 is "9jqo^"
        $result = $this->decodeAscii85('9jqo^~');
        $this->assertSame('Man ', $result);
    }

    public function testDecodeAscii85InvalidChar(): void
    {
        // '{' (0x7B) is above the valid range '!'–'u' (0x21–0x75) and is not 'z'
        $this->assertFalse($this->decodeAscii85('{~'));
    }

    // -------------------------------------------------------------------------
    // Flate decode
    // -------------------------------------------------------------------------

    public function testDecodeFlateRoundTrip(): void
    {
        $original   = 'The quick brown fox jumps over the lazy dog';
        $compressed = gzcompress($original);
        $this->assertIsString($compressed, 'gzcompress should not fail');
        $result     = $this->decodeFlate($compressed);
        $this->assertSame($original, $result);
    }

    public function testDecodeFlateInvalidData(): void
    {
        $this->assertFalse($this->decodeFlate('not compressed data'));
    }

    // -------------------------------------------------------------------------
    // getObjectOptions
    // -------------------------------------------------------------------------

    public function testGetObjectOptionsBasic(): void
    {
        // After splitting on '/', each bare word becomes a true flag.
        // '/Filter /FlateDecode /Length 100' → Filter=true, FlateDecode=true, Length='100'
        $object  = '1 0 obj<</Filter /FlateDecode /Length 100>>endobj';
        $options = $this->getObjectOptions($object);
        $this->assertSame(true, $options['Filter'] ?? null);
        $this->assertSame(true, $options['FlateDecode'] ?? null);
        $this->assertSame('100', $options['Length'] ?? null);
    }

    public function testGetObjectOptionsFlagOnly(): void
    {
        $object  = '1 0 obj<</FlateDecode>>endobj';
        $options = $this->getObjectOptions($object);
        $this->assertArrayHasKey('FlateDecode', $options);
    }

    public function testGetObjectOptionsEmpty(): void
    {
        $this->assertSame([], $this->getObjectOptions('no dictionary here'));
    }

    // -------------------------------------------------------------------------
    // End-to-end with a minimal synthetic PDF
    // -------------------------------------------------------------------------

    public function testExtractTextFromMinimalPdf(): void
    {
        // Build a minimal PDF that contains a BT...ET block with a TJ array.
        // The parser matches '[...] TJ' and '(...)' literals inside it.
        $btContent = "BT\n[(Hello PDF World)] TJ\nET";
        $pdf = implode("\n", [
            "%PDF-1.4",
            "1 0 obj",
            "<</Length " . strlen($btContent) . ">>",
            "stream",
            $btContent,
            "endstream",
            "endobj",
            "%%EOF",
        ]);

        $result = $this->callExtractOnString($pdf);
        $this->assertStringContainsString('Hello PDF World', $result);
    }

    // -------------------------------------------------------------------------
    // Helpers — call private methods via Reflection
    // -------------------------------------------------------------------------

    /**
     * Write $content to a temp file, run extractText on it, delete the file.
     */
    private function callExtractOnString(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'taac_pdf_');
        file_put_contents($tmp, $content);
        try {
            return $this->parser->extractText($tmp);
        } finally {
            unlink($tmp);
        }
    }

    private function decodeAsciiHex(string $input): string|false
    {
        return $this->callPrivate('decodeAsciiHex', $input);
    }

    private function decodeAscii85(string $input): string|false
    {
        return $this->callPrivate('decodeAscii85', $input);
    }

    private function decodeFlate(string $input): string|false
    {
        return $this->callPrivate('decodeFlate', $input);
    }

    /** @return array<string, string|true> */
    private function getObjectOptions(string $object): array
    {
        return $this->callPrivate('getObjectOptions', $object);
    }

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(PdfParser::class, $method);
        return $ref->invoke($this->parser, ...$args);
    }
}
