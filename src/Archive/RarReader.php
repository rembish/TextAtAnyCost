<?php

declare(strict_types=1);

namespace TextAtAnyCost\Archive;

use TextAtAnyCost\Exception\ParseException;

/**
 * Reads the file list from a RAR archive without the PECL `rar` extension.
 *
 * Supports RAR 4.x format (not RAR5 / BLAKE2 archives).
 * Only reads metadata; does not decompress archive entries.
 */
final class RarReader
{
    /** RAR mark-head magic bytes */
    private const string MAGIC = '526172211a0700';

    /** File-block type byte */
    private const int FILE_BLOCK_TYPE = 0x74;

    /** Archive-header block type byte */
    private const int ARCHIVE_BLOCK_TYPE = 0x73;

    /** Flag: block carries an ADD_SIZE field after HEAD_SIZE */
    private const int FLAG_ADD_SIZE = 0x8000;

    // DOS file-attribute bit flags
    private const int ATTR_READONLY   = 0x01;
    private const int ATTR_HIDDEN     = 0x02;
    private const int ATTR_SYSTEM     = 0x04;
    private const int ATTR_DIRECTORY  = 0x10;
    private const int ATTR_DIRECTORY2 = 0x4000;
    private const int ATTR_ARCHIVED   = 0x20;

    /**
     * Return a flat list of every entry in the archive.
     *
     * Each element is an associative array:
     *   filename           — path stored in the archive (backslash-separated)
     *   size_compressed    — packed size in bytes
     *   size_uncompressed  — original file size in bytes
     *   crc                — stored CRC-32 value
     *   attributes         — string of applicable flags: r h s d a
     *
     * @return array<int, array{filename: string, size_compressed: int, size_uncompressed: int, crc: int, attributes: string}>
     * @throws ParseException on read errors or invalid format.
     */
    public function getFileList(string $filename): array
    {
        $fh = @fopen($filename, 'rb');
        if ($fh === false) {
            throw new ParseException("Cannot open file: $filename");
        }

        try {
            return $this->readFileList($fh);
        } finally {
            fclose($fh);
        }
    }

    /**
     * Return the file list as a nested directory tree.
     *
     * Directories are keys prefixed with '/' (e.g. '/subdir'), plain files
     * are keys with just the basename.  Each file entry contains the same
     * metadata as getFileList() minus 'filename'.
     *
     * @return array<string, mixed>
     * @throws ParseException
     */
    public function getFileTree(string $filename): array
    {
        $files = $this->getFileList($filename);
        /** @var array<string, mixed> $tree */
        $tree  = [];

        foreach ($files as $file) {
            // Skip directory entries — the tree is built implicitly
            if (str_contains($file['attributes'], 'd')) {
                continue;
            }

            // RAR stores paths with backslashes
            $parts = explode('\\', $file['filename']);
            $node  = &$tree;

            // Navigate / create intermediate directory nodes
            for ($i = 0, $depth = count($parts) - 1; $i < $depth; $i++) {
                $key = '/' . $parts[$i];
                if (!isset($node[$key])) {
                    $node[$key] = [];
                }
                $node = &$node[$key];
            }

            // Leaf: file entry without redundant 'filename' key
            $entry             = $file;
            unset($entry['filename']);
            $node[end($parts)] = $entry;
        }

        // Sort each level: directories (/-prefixed) sort before files
        $this->ksortTree($tree);

        return $tree;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param resource $fh
     * @return array<int, array{filename: string, size_compressed: int, size_uncompressed: int, crc: int, attributes: string}>
     * @throws ParseException
     */
    private function readFileList($fh): array
    {
        // Validate the RAR magic header
        $magic = fread($fh, 7);
        if ($magic === false || bin2hex($magic) !== self::MAGIC) {
            throw new ParseException('Not a valid RAR archive (invalid magic bytes)');
        }

        // Validate and skip the MAIN_HEAD block
        $mainHead = fread($fh, 7);
        if ($mainHead === false || strlen($mainHead) < 7) {
            throw new ParseException('Truncated RAR archive (missing MAIN_HEAD)');
        }
        if (ord($mainHead[2]) !== self::ARCHIVE_BLOCK_TYPE) {
            throw new ParseException('Invalid RAR archive (bad MAIN_HEAD type)');
        }
        $headSize = $this->readLeUint($mainHead, 5, 2);
        fseek($fh, $headSize - 7, SEEK_CUR);

        $files = [];

        while (!feof($fh)) {
            $blockHeader = fread($fh, 7);
            if ($blockHeader === false || strlen($blockHeader) < 7) {
                break;
            }

            $headSize = $this->readLeUint($blockHeader, 5, 2);
            if ($headSize <= 7) {
                break;
            }

            $rest  = fread($fh, $headSize - 7);
            if ($rest === false) {
                break;
            }
            $block = $blockHeader . $rest;

            if (ord($block[2]) === self::FILE_BLOCK_TYPE) {
                $packSize = $this->readLeUint($block, 7, 4);
                fseek($fh, $packSize, SEEK_CUR);

                $files[] = [
                    'filename'          => substr($block, 32, $this->readLeUint($block, 26, 2)),
                    'size_compressed'   => $packSize,
                    'size_uncompressed' => $this->readLeUint($block, 11, 4),
                    'crc'               => $this->readLeUint($block, 16, 4),
                    'attributes'        => $this->parseAttributes($this->readLeUint($block, 28, 4)),
                ];
            } else {
                // Non-file block: skip ADD_SIZE bytes if the flag is set
                $flags = $this->readLeUint($block, 3, 2);
                if ($flags & self::FLAG_ADD_SIZE) {
                    $addSize = $this->readLeUint($block, 7, 4);
                    fseek($fh, $addSize, SEEK_CUR);
                }
            }
        }

        return $files;
    }

    /**
     * Read $count bytes from $data at $offset as a little-endian unsigned integer.
     */
    private function readLeUint(string $data, int $offset, int $count): int
    {
        return (int) hexdec(bin2hex(strrev(substr($data, $offset, $count))));
    }

    /**
     * Convert a DOS attribute bitmap to a short string of flags.
     */
    private function parseAttributes(int $attr): string
    {
        $flags = '';
        if ($attr & self::ATTR_READONLY) {
            $flags .= 'r';
        }
        if ($attr & self::ATTR_HIDDEN) {
            $flags .= 'h';
        }
        if ($attr & self::ATTR_SYSTEM) {
            $flags .= 's';
        }
        if (($attr & self::ATTR_DIRECTORY) || ($attr & self::ATTR_DIRECTORY2)) {
            return 'd';
        }
        if ($attr & self::ATTR_ARCHIVED) {
            $flags .= 'a';
        }
        return $flags;
    }

    /**
     * Recursively sort an array by key so directories (/-prefixed) come first.
     *
     * @param array<string, mixed> $tree
     */
    private function ksortTree(array &$tree): void
    {
        ksort($tree);
        foreach ($tree as &$node) {
            if (is_array($node)) {
                $this->ksortTree($node);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Procedural convenience wrappers
// ---------------------------------------------------------------------------

/**
 * @return array<int, array{filename: string, size_compressed: int, size_uncompressed: int, crc: int, attributes: string}>|false
 */
function rar_getFileList(string $filename): array|false
{
    try {
        return (new RarReader())->getFileList($filename);
    } catch (ParseException) {
        return false;
    }
}

/**
 * @return array<string, mixed>|false
 */
function rar_getFileTree(string $filename): array|false
{
    try {
        return (new RarReader())->getFileTree($filename);
    } catch (ParseException) {
        return false;
    }
}
