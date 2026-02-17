<?php

declare(strict_types=1);

namespace TextAtAnyCost\Archive;

use TextAtAnyCost\Exception\ParseException;

/**
 * Creates RAR archives using the "Store" (no-compression) method.
 *
 * Only the stored (0x30) compression method is supported; files are written
 * verbatim.  The output is compatible with WinRAR 2.0 and later.
 *
 * Bug fixes vs. the original:
 *   - getDateTime(): inverted null-check was always ignoring the $timestamp
 *     parameter and always returning the current time.
 *   - getBytes(): strlen(0) returns 1, not 0 — fixed with an explicit guard.
 *
 * Example:
 *   $rar = new RarWriter();
 *   $rar->create('archive.rar');
 *   $rar->addDirectory('docs/reports');
 *   $rar->addFile('/var/www/report.pdf', 'docs/reports');
 *   $rar->close();
 */
final class RarWriter
{
    /** @var resource|null Open file handle for the archive being written */
    private mixed $fh = null;

    /**
     * Directory tree used to avoid writing duplicate directory headers.
     * @var array<string, mixed>
     */
    private array $tree = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Create a new RAR archive at $filename and write the mandatory headers.
     *
     * @throws ParseException if the file cannot be opened for writing.
     */
    public function create(string $filename): void
    {
        $fh = @fopen($filename, 'wb');
        if ($fh === false) {
            throw new ParseException("Cannot create archive: $filename");
        }
        $this->fh   = $fh;
        $this->tree = [];

        $this->writeHeader(0x72, 0x1a21);                              // Mark head
        $this->writeHeader(0x73, 0x0000, [[0, 2], [0, 4]]); // Archive head
    }

    /**
     * Flush and close the archive.  Must be called after all entries are added.
     *
     * @throws ParseException if no archive is open.
     */
    public function close(): void
    {
        if ($this->fh === null) {
            throw new ParseException('No archive is open');
        }
        fclose($this->fh);
        $this->fh = null;
    }

    /**
     * Add a directory entry (and all intermediate parent directories) to the
     * archive.  Duplicate entries are silently skipped.
     *
     * @throws ParseException if no archive is open.
     */
    public function addDirectory(string $name): string
    {
        $this->assertOpen();

        // Normalise to backslash-separated, no leading/trailing separators
        $name = trim(str_replace('/', '\\', $name), '\\');

        $parts    = explode('\\', $name);
        $node     = &$this->tree;
        $cumPath  = '';
        $sep      = '';

        foreach ($parts as $part) {
            $cumPath .= $sep . $part;
            $sep      = '\\';

            if (!isset($node[$part])) {
                $node[$part] = [];
                $this->writeHeader(0x74, $this->setBits([5, 6, 7, 15]), [
                    [0, 4],                              // packed size  = 0
                    [0, 4],                              // unpacked size = 0
                    [0, 1],                              // host OS = MS-DOS
                    [0, 4],                              // CRC = 0
                    [$this->getDateTime(), 4],           // modification time
                    [20, 1],                             // min RAR version = 2.0
                    [0x30, 1],                           // method = Store
                    [strlen($cumPath), 2],               // name length
                    [0x10, 4],                           // attributes = Directory
                    $cumPath,                            // name
                ]);
            }

            $node = &$node[$part];
        }

        return $name;
    }

    /**
     * Add a file from the local filesystem into the archive.
     *
     * @param string $localPath Absolute or relative path to the source file.
     * @param string|null $dir Destination directory inside the archive.
     *                         Created automatically if it does not exist.
     *
     * @throws ParseException if no archive is open or the source file cannot be read.
     */
    public function addFile(string $localPath, ?string $dir = null): void
    {
        $fh = $this->assertOpen();

        if (!file_exists($localPath)) {
            throw new ParseException("Source file not found: $localPath");
        }

        $node = &$this->tree;
        $archiveName = basename($localPath);

        if ($dir !== null) {
            $normalised  = $this->addDirectory($dir);
            $parts       = explode('\\', $normalised);
            foreach ($parts as $part) {
                $node = &$node[$part];
            }
            $archiveName = $normalised . '\\' . $archiveName;
        }

        // Deduplicate
        if (in_array($archiveName, $node, strict: true)) {
            return;
        }

        $data     = file_get_contents($localPath);
        if ($data === false) {
            throw new ParseException("Cannot read source file: $localPath");
        }
        $size     = strlen($data);
        $mtime    = filemtime($localPath);

        $this->writeHeader(0x74, $this->setBits([15]), [
            [$size, 4],                                       // packed size
            [$size, 4],                                       // unpacked size
            [0, 1],                                           // host OS = MS-DOS
            [crc32($data), 4],                                // CRC-32
            [$this->getDateTime($mtime !== false ? $mtime : null), 4],
            [20, 1],                                          // min RAR version
            [0x30, 1],                                        // method = Store
            [strlen($archiveName), 2],                        // name length
            [0x20, 4],                                        // attributes = Archived
            $archiveName,                                     // name
        ]);

        fwrite($fh, $data);
        $node[] = $archiveName;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @throws ParseException
     * @return resource
     */
    private function assertOpen(): mixed
    {
        if ($this->fh === null) {
            throw new ParseException('Archive has not been created; call create() first');
        }
        return $this->fh;
    }

    /**
     * Serialise and write one RAR block header to the open archive.
     *
     * @param int $headType Block type (0x72, 0x73, or 0x74)
     * @param int $headFlags Flags bitmap
     * @param array<int, array{0: int, 1: int}|string> $data Extra header fields
     */
    private function writeHeader(int $headType, int $headFlags, array $data = []): void
    {
        // Calculate total header size: 2 (CRC placeholder) + 1 (type) + 2 (flags) + 2 (size) + fields
        $headSize = 2 + 1 + 2 + 2;
        foreach ($data as $field) {
            $headSize += is_array($field) ? $field[1] : strlen($field);
        }

        $body   = $this->serializeFields(array_merge([[$headType, 1], [$headFlags, 2], [$headSize, 2]], $data));
        $prefix = $headType === 0x72 ? 'Ra' : $this->crc16($body);

        assert($this->fh !== null);
        fwrite($this->fh, $prefix . $body);
    }

    /**
     * Compute the 16-bit (little-endian) CRC of a string.
     * The CRC is truncated to the low 2 bytes of crc32().
     */
    private function crc16(string $data): string
    {
        $crc = crc32($data);
        return chr($crc & 0xFF) . chr(($crc >> 8) & 0xFF);
    }

    /**
     * Serialise an array of fields to a binary string.
     * Each field is either a [value, byteWidth] pair or a raw string.
     *
     * @param array<int, array{0: int, 1: int}|string> $fields
     */
    private function serializeFields(array $fields): string
    {
        $output = '';
        foreach ($fields as $field) {
            if (is_array($field)) {
                $output .= $this->intToLeBytes($field[0], $field[1]);
            } else {
                $output .= $field;
            }
        }
        return $output;
    }

    /**
     * Encode a (possibly negative) integer as $byteCount little-endian bytes.
     *
     * Bug fix: the original code did `$bytes = strlen($bytes)` when $bytes was
     * an integer 0, giving 1 instead of 0.  We now guard with an explicit check.
     */
    private function intToLeBytes(int $value, int $byteCount): string
    {
        if ($byteCount === 0) {
            return '';
        }
        $hex    = sprintf('%0' . ($byteCount * 2) . 'x', $value & ((1 << ($byteCount * 8)) - 1));
        $output = '';
        for ($i = 0; $i < strlen($hex); $i += 2) {
            $output = chr((int) hexdec(substr($hex, $i, 2))) . $output;
        }
        return $output;
    }

    /**
     * Set specific bit positions in a 16-bit integer.
     *
     * @param int[] $bits Bit positions to set (0-based)
     */
    private function setBits(array $bits): int
    {
        $out = 0;
        foreach ($bits as $bit) {
            $out |= 1 << $bit;
        }
        return $out;
    }

    /**
     * Encode a Unix timestamp as a 32-bit MS-DOS date/time value.
     *
     * Bug fix: the original had `if (!is_null($time)) $time = time();` which
     * discarded the provided timestamp entirely and always returned current time.
     * Corrected to: use $timestamp when provided, otherwise fall back to now.
     */
    private function getDateTime(?int $timestamp = null): int
    {
        $ts = $timestamp ?? time();
        $dt = getdate($ts);

        return $dt['seconds']
             | ($dt['minutes']          << 5)
             | ($dt['hours']            << 11)
             | ($dt['mday']             << 16)
             | ($dt['mon']              << 21)
             | (($dt['year'] - 1980)    << 25);
    }
}
