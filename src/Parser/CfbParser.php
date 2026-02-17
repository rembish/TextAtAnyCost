<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Windows Compound Binary File Format (WCBFF) parser.
 *
 * Base class for all Microsoft compound-document formats: .doc, .xls, .ppt.
 * Implements the full sector/FAT/MiniFAT/directory machinery so subclasses
 * only need to navigate named streams.
 */
abstract class CfbParser
{
    // Entire file contents (binary-safe)
    protected string $data = '';

    // Sector sizes (as shift values: actual size = 1 << shift)
    protected int $sectorShift     = 9;   // 512 bytes for v3
    protected int $miniSectorShift = 6;   // 64 bytes
    protected int $miniSectorCutoff = 4096;

    /** @var array<int, int> FAT sector chain: index = current sector, value = next sector */
    protected array $fatChains = [];
    /** @var array<int, array{name: string, type: int, color: int, left: int, right: int, child: int, start: int, size: int}> */
    protected array $fatEntries = [];

    /** @var array<int, int> MiniFAT chain */
    protected array $miniFATChains = [];
    protected string $miniFAT      = '';

    private bool $isLittleEndian = true;

    private int $fDir    = 0;
    private int $fMiniFAT = 0;

    /** @var array<int, int> */
    private array $DIFAT  = [];
    private int   $cDIFAT = 0;
    private int   $fDIFAT = 0;

    private const int ENDOFCHAIN = 0xFFFFFFFE;
    private const int FREESECT   = 0xFFFFFFFF;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Read a file and extract its plain-text content.
     *
     * @throws ParseException if the file cannot be read or parsed.
     */
    public function extractText(string $filename): string
    {
        $contents = @file_get_contents($filename);
        if ($contents === false) {
            throw new ParseException("Cannot read file: $filename");
        }
        $this->data = $contents;
        return $this->parse();
    }

    // -------------------------------------------------------------------------
    // To be implemented by subclasses
    // -------------------------------------------------------------------------

    /**
     * Subclasses call $this->parseCfb() first, then extract format-specific text.
     */
    abstract protected function parse(): string;

    // -------------------------------------------------------------------------
    // CFB bootstrap (called by subclasses inside parse())
    // -------------------------------------------------------------------------

    /**
     * Parse the CFB envelope: header, DIFAT, FAT chains, MiniFAT, directory.
     *
     * @throws ParseException on any structural violation.
     */
    protected function parseCfb(): void
    {
        $magic = strtoupper(bin2hex(substr($this->data, 0, 8)));
        if ($magic !== 'D0CF11E0A1B11AE1' && $magic !== '0E11FC0DD0CF11E0') {
            throw new ParseException('Not a valid CFB file (invalid magic bytes)');
        }

        $this->readHeader();
        $this->readDIFAT();
        $this->readFATChains();
        $this->readMiniFATChains();
        $this->readDirectoryStructure();

        $rootId = $this->getStreamIdByName('Root Entry');
        if ($rootId === false) {
            throw new ParseException('Root Entry stream not found in CFB directory');
        }
        $this->miniFAT = $this->getStreamById($rootId, isRoot: true);

        unset($this->DIFAT);
    }

    // -------------------------------------------------------------------------
    // Stream access (used by subclasses)
    // -------------------------------------------------------------------------

    /**
     * Find a stream by name in the directory, starting from $from.
     *
     * @return int|false Directory index, or false if not found.
     */
    protected function getStreamIdByName(string $name, int $from = 0): int|false
    {
        $count = count($this->fatEntries);
        for ($i = $from; $i < $count; $i++) {
            if ($this->fatEntries[$i]['name'] === $name) {
                return $i;
            }
        }
        return false;
    }

    /**
     * Return the binary contents of a stream by its directory index.
     *
     * @param bool $isRoot Root Entry must always be read from the regular FAT,
     *                     even though its size is < miniSectorCutoff.
     */
    protected function getStreamById(int $id, bool $isRoot = false): string
    {
        $entry = $this->fatEntries[$id];
        $from  = $entry['start'];
        $size  = $entry['size'];

        $stream = '';

        if ($size < $this->miniSectorCutoff && !$isRoot) {
            // Small stream: read from mini-stream
            $ssize = 1 << $this->miniSectorShift;
            do {
                $start   = $from << $this->miniSectorShift;
                $stream .= substr($this->miniFAT, $start, $ssize);
                $from    = $this->miniFATChains[$from] ?? self::ENDOFCHAIN;
            } while ($from !== self::ENDOFCHAIN);
        } else {
            // Large stream: read from regular FAT sectors
            $ssize = 1 << $this->sectorShift;
            do {
                // Sector 0 starts at offset 512 (after the 512-byte header)
                $start   = ($from + 1) << $this->sectorShift;
                $stream .= substr($this->data, $start, $ssize);
                $from    = $this->fatChains[$from] ?? self::ENDOFCHAIN;
            } while ($from !== self::ENDOFCHAIN);
        }

        return substr($stream, 0, $size);
    }

    // -------------------------------------------------------------------------
    // Header & chain readers (private)
    // -------------------------------------------------------------------------

    private function readHeader(): void
    {
        $byteOrder            = strtoupper(bin2hex(substr($this->data, 0x1C, 2)));
        $this->isLittleEndian = ($byteOrder === 'FEFF');

        $this->sectorShift     = $this->getShort(0x1E);
        $this->miniSectorShift = $this->getShort(0x20);
        $this->miniSectorCutoff = $this->getLong(0x38);

        $this->fDir     = $this->getLong(0x30);
        $this->fMiniFAT = $this->getLong(0x3C);
        $this->cDIFAT   = $this->getLong(0x48);
        $this->fDIFAT   = $this->getLong(0x44);
    }

    /**
     * DIFAT points to the sectors that hold FAT chain data.
     * The first 109 DIFAT entries live in the header; any overflow is chained.
     */
    private function readDIFAT(): void
    {
        $this->DIFAT = [];
        for ($i = 0; $i < 109; $i++) {
            $this->DIFAT[] = $this->getLong(0x4C + $i * 4);
        }

        if ($this->fDIFAT !== self::ENDOFCHAIN) {
            $size = 1 << $this->sectorShift;
            $from = $this->fDIFAT;
            $j    = 0;

            do {
                $start = ($from + 1) << $this->sectorShift;
                // Last 4 bytes of the sector are a pointer to the next DIFAT sector
                for ($i = 0; $i < $size - 4; $i += 4) {
                    $this->DIFAT[] = $this->getLong($start + $i);
                }
                $from = $this->getLong($start + $i);
            } while ($from !== self::ENDOFCHAIN && ++$j < $this->cDIFAT);
        }

        // Trim trailing unused (FREESECT) entries
        while ($this->DIFAT !== [] && $this->DIFAT[array_key_last($this->DIFAT)] === self::FREESECT) {
            array_pop($this->DIFAT);
        }
    }

    /** Turn DIFAT sector references into the actual FAT chain array. */
    private function readFATChains(): void
    {
        $this->fatChains = [];
        $size            = 1 << $this->sectorShift;

        foreach ($this->DIFAT as $difatSector) {
            $from = ($difatSector + 1) << $this->sectorShift;
            for ($j = 0; $j < $size; $j += 4) {
                $this->fatChains[] = $this->getLong($from + $j);
            }
        }
    }

    /** Build the MiniFAT chain the same way as the regular FAT chain. */
    private function readMiniFATChains(): void
    {
        $this->miniFATChains = [];
        $size = 1 << $this->sectorShift;
        $from = $this->fMiniFAT;

        while ($from !== self::ENDOFCHAIN) {
            $start = ($from + 1) << $this->sectorShift;
            for ($i = 0; $i < $size; $i += 4) {
                $this->miniFATChains[] = $this->getLong($start + $i);
            }
            $from = $this->fatChains[$from] ?? self::ENDOFCHAIN;
        }
    }

    /**
     * Parse the directory stream: each 128-byte entry describes one "file"
     * (stream, storage, or root entry) inside the compound document.
     *
     * Fix (PR #7): guard while-condition so an empty array never causes
     * infinite pop.
     */
    private function readDirectoryStructure(): void
    {
        $this->fatEntries = [];
        $size = 1 << $this->sectorShift;
        $from = $this->fDir;

        do {
            $start = ($from + 1) << $this->sectorShift;
            for ($i = 0; $i < $size; $i += 128) {
                $entry   = substr($this->data, $start + $i, 128);
                $nameLen = $this->getShort(0x40, $entry);

                $this->fatEntries[] = [
                    'name'  => $this->utf16ToAnsi(substr($entry, 0, $nameLen)),
                    'type'  => ord($entry[0x42]),
                    'color' => ord($entry[0x43]),
                    'left'  => $this->getLong(0x44, $entry),
                    'right' => $this->getLong(0x48, $entry),
                    'child' => $this->getLong(0x4C, $entry),
                    'start' => $this->getLong(0x74, $entry),
                    'size'  => $this->getSomeBytes($entry, 0x78, 8),
                ];
            }
            $from = $this->fatChains[$from] ?? self::ENDOFCHAIN;
        } while ($from !== self::ENDOFCHAIN);

        // Remove trailing empty/unused entries (PR #7: guard empty array)
        while ($this->fatEntries !== [] && $this->fatEntries[array_key_last($this->fatEntries)]['type'] === 0) {
            array_pop($this->fatEntries);
        }
    }

    // -------------------------------------------------------------------------
    // Character encoding helpers
    // -------------------------------------------------------------------------

    /**
     * Directory entry names are stored as UTF-16LE; convert to a plain ASCII
     * string (names are always ASCII in practice).
     */
    private function utf16ToAnsi(string $in): string
    {
        $out = '';
        for ($i = 0, $len = strlen($in); $i < $len; $i += 2) {
            $out .= chr($this->getShort($i, $in));
        }
        return trim($out);
    }

    /**
     * Convert a UTF-16LE binary string to UTF-8.
     *
     * When $stripHyperlinks is true, HYPER13…HYPER15 marker sequences used by
     * Word to embed hyperlinks are removed before conversion.
     *
     * Fix (PR #9): use mb_chr() for proper multi-byte character output instead
     *              of html_entity_decode() round-trips.
     */
    protected function unicodeToUtf8(string $in, bool $stripHyperlinks = false): string
    {
        if ($stripHyperlinks) {
            $in = $this->stripHyperlinkMarkers($in);
        }

        $out  = '';
        $skip = false;

        for ($i = 0, $len = strlen($in); $i + 1 < $len; $i += 2) {
            $lo = ord($in[$i]);
            $hi = ord($in[$i + 1]);

            if ($skip) {
                if ($hi === 0x15 || $lo === 0x15) {
                    $skip = false;
                }
                continue;
            }

            if ($hi === 0) {
                if ($lo >= 32) {
                    // Emit as UTF-8; mb_chr handles both ASCII and Latin-1 supplement.
                    $char = mb_chr($lo, 'UTF-8');
                    $out .= $char !== false ? $char : '';
                } elseif ($lo === 0x0A) {
                    $out .= "\n";
                } elseif ($lo === 0x0D || $lo === 0x07) {
                    $out .= "\n";
                } elseif ($lo === 0x13) {
                    $skip = true;
                }
                // Other control bytes are silently dropped
            } else {
                // Non-ASCII Unicode codepoint
                if ($hi === 0x13) {
                    $skip = true;
                    continue;
                }
                $codepoint = $lo | ($hi << 8);
                $char      = mb_chr($codepoint, 'UTF-8');
                if ($char !== false) {
                    $out .= $char;
                }
            }
        }

        return $out;
    }

    /**
     * Strip Word's HYPER13/HYPER15 embedded-hyperlink markers from a
     * UTF-16LE binary string.
     */
    private function stripHyperlinkMarkers(string $in): string
    {
        // Detect encoding by checking for null bytes
        $isUtf16 = strpos($in, "\x00") === 1;

        if ($isUtf16) {
            while (($pos = strpos($in, "\x13\x00")) !== false) {
                $end = strpos($in, "\x15\x00", $pos + 2);
                if ($end === false) {
                    break;
                }
                $in = substr_replace($in, '', $pos, $end - $pos + 2);
            }
        } else {
            while (($pos = strpos($in, "\x13")) !== false) {
                $end = strpos($in, "\x15", $pos + 1);
                if ($end === false) {
                    break;
                }
                $in = substr_replace($in, '', $pos, $end - $pos + 1);
            }
            $in = str_replace("\x00", '', $in);
            // Filter remaining non-printable bytes
            $filtered = '';
            for ($i = 0, $len = strlen($in); $i < $len; $i++) {
                if (ord($in[$i]) >= 32 || $in[$i] === "\n") {
                    $filtered .= $in[$i];
                }
            }
            return $filtered;
        }

        return $in;
    }

    // -------------------------------------------------------------------------
    // Binary reading primitives
    // -------------------------------------------------------------------------

    /**
     * Read $count bytes from $data at offset $from, treating them as a
     * little-endian (or big-endian) unsigned integer.
     *
     * @param string|null $data Source buffer; null → use $this->data.
     */
    protected function getSomeBytes(?string $data, int $from, int $count): int
    {
        $buf = substr($data ?? $this->data, $from, $count);
        if ($this->isLittleEndian) {
            $buf = strrev($buf);
        }
        return (int) hexdec(bin2hex($buf));
    }

    /** Read a 16-bit (2-byte) little-endian unsigned integer. */
    protected function getShort(int $from, ?string $data = null): int
    {
        return $this->getSomeBytes($data, $from, 2);
    }

    /** Read a 32-bit (4-byte) little-endian unsigned integer. */
    protected function getLong(int $from, ?string $data = null): int
    {
        return $this->getSomeBytes($data, $from, 4);
    }
}
