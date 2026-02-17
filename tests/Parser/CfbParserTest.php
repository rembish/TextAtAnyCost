<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Parser;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Exception\ParseException;

/**
 * Tests for the CFB binary-reading primitives exposed through a test subclass.
 *
 * Real .doc / .ppt integration tests live in DocParserTest / PptParserTest;
 * here we verify the low-level byte-reading logic in isolation.
 */
final class CfbParserTest extends TestCase
{
    private TestCfbParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TestCfbParser();
    }

    // -------------------------------------------------------------------------
    // getSomeBytes / getShort / getLong
    // -------------------------------------------------------------------------

    public function testGetSomeBytesLittleEndian(): void
    {
        // 0x01 0x00 0x00 0x00 = 1 in little-endian
        $this->parser->setData("\x01\x00\x00\x00", littleEndian: true);
        $this->assertSame(1, $this->parser->exposedGetSomeBytes(null, 0, 4));
    }

    public function testGetSomeBytesBigEndian(): void
    {
        // 0x00 0x00 0x00 0x01 = 1 in big-endian
        $this->parser->setData("\x00\x00\x00\x01", littleEndian: false);
        $this->assertSame(1, $this->parser->exposedGetSomeBytes(null, 0, 4));
    }

    public function testGetShortLittleEndian(): void
    {
        $this->parser->setData("\xFF\x00", littleEndian: true);
        $this->assertSame(0xFF, $this->parser->exposedGetShort(0));
    }

    public function testGetShortBigEndian(): void
    {
        $this->parser->setData("\x00\xFF", littleEndian: false);
        $this->assertSame(0xFF, $this->parser->exposedGetShort(0));
    }

    public function testGetLong(): void
    {
        // 0x78 0x56 0x34 0x12 in little-endian = 0x12345678
        $this->parser->setData("\x78\x56\x34\x12", littleEndian: true);
        $this->assertSame(0x12345678, $this->parser->exposedGetLong(0));
    }

    public function testGetShortFromBuffer(): void
    {
        $this->parser->setData('', littleEndian: true);
        $buf = "\xFE\xFF"; // little-endian 0xFFFE
        $this->assertSame(0xFFFE, $this->parser->exposedGetShort(0, $buf));
    }

    // -------------------------------------------------------------------------
    // Unicode → UTF-8 conversion
    // -------------------------------------------------------------------------

    public function testUnicodeToUtf8BasicAscii(): void
    {
        $this->parser->setData('', littleEndian: true);
        // 'A' as UTF-16LE = 0x41 0x00
        $in  = "\x41\x00";
        $out = $this->parser->exposedUnicodeToUtf8($in);
        $this->assertSame('A', $out);
    }

    public function testUnicodeToUtf8MultiChar(): void
    {
        $this->parser->setData('', littleEndian: true);
        // "Hi" as UTF-16LE
        $in  = "\x48\x00\x69\x00";
        $out = $this->parser->exposedUnicodeToUtf8($in);
        $this->assertSame('Hi', $out);
    }

    public function testUnicodeToUtf8NonBmp(): void
    {
        $this->parser->setData('', littleEndian: true);
        // U+00E9 (é) as UTF-16LE = 0xE9 0x00
        $in  = "\xE9\x00";
        $out = $this->parser->exposedUnicodeToUtf8($in);
        $this->assertSame('é', $out);
    }

    public function testUnicodeToUtf8CrLfNormalised(): void
    {
        $this->parser->setData('', littleEndian: true);
        // 0x0D 0x00 = CR → normalised to \n
        $in  = "\x0D\x00";
        $out = $this->parser->exposedUnicodeToUtf8($in);
        $this->assertSame("\n", $out);
    }

    public function testUnicodeToUtf8ControlBytesDropped(): void
    {
        $this->parser->setData('', littleEndian: true);
        // Byte 0x01 (SOH), which should be silently dropped
        $in  = "\x01\x00";
        $out = $this->parser->exposedUnicodeToUtf8($in);
        $this->assertSame('', $out);
    }

    // -------------------------------------------------------------------------
    // extractText — file-not-found path
    // -------------------------------------------------------------------------

    public function testExtractTextThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        $this->parser->extractText('/nonexistent/path/to/file.doc');
    }

    // -------------------------------------------------------------------------
    // parseCfb — invalid magic
    // -------------------------------------------------------------------------

    public function testParseCfbThrowsOnInvalidMagic(): void
    {
        $this->parser->setData(str_repeat("\x00", 512), littleEndian: true);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/invalid magic/i');
        $this->parser->exposedParseCfb();
    }
}

// ---------------------------------------------------------------------------
// Test subclass that exposes protected internals
// ---------------------------------------------------------------------------

use TextAtAnyCost\Parser\CfbParser;

final class TestCfbParser extends CfbParser
{
    public function setData(string $data, bool $littleEndian): void
    {
        $this->data = $data;
        // Reflection: set the private $isLittleEndian property
        $ref = new \ReflectionProperty(CfbParser::class, 'isLittleEndian');
        $ref->setValue($this, $littleEndian);
    }

    public function exposedGetSomeBytes(?string $data, int $from, int $count): int
    {
        return $this->getSomeBytes($data, $from, $count);
    }

    public function exposedGetShort(int $from, ?string $data = null): int
    {
        return $this->getShort($from, $data);
    }

    public function exposedGetLong(int $from, ?string $data = null): int
    {
        return $this->getLong($from, $data);
    }

    public function exposedUnicodeToUtf8(string $in, bool $strip = false): string
    {
        return $this->unicodeToUtf8($in, $strip);
    }

    public function exposedParseCfb(): void
    {
        $this->parseCfb();
    }

    // CfbParser is abstract; we need a concrete parse() implementation
    protected function parse(): string
    {
        return '';
    }
}
