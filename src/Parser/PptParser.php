<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Extracts plain text from Microsoft PowerPoint 97–2003 .ppt files.
 *
 * Navigation path:
 *   Current User → UserEditAtom(s) → PersistDirectory
 *   → DocumentContainer → SlideList → Slides → Drawing → Text atoms
 */
final class PptParser extends CfbParser
{
    protected function parse(): string
    {
        $this->parseCfb();

        // "Current User" stream: tells us where the most-recent edit is
        $cuId = $this->getStreamIdByName('Current User');
        if ($cuId === false) {
            throw new ParseException('"Current User" stream not found');
        }
        $cuStream = $this->getStreamById($cuId);

        // Offset 12: magic that must NOT equal 0xF3D1C4DF (that is a broken/legacy marker)
        if ($this->getLong(12, $cuStream) === 0xF3D1C4DF) {
            throw new ParseException('Invalid PowerPoint current-user magic');
        }
        $offsetToCurrentEdit = $this->getLong(16, $cuStream);

        // Load the main "PowerPoint Document" stream
        $ppdId = $this->getStreamIdByName('PowerPoint Document');
        if ($ppdId === false) {
            throw new ParseException('"PowerPoint Document" stream not found');
        }
        $ppdStream = $this->getStreamById($ppdId);

        // Walk the chain of UserEditAtoms (newest → oldest) to collect
        // PersistDirectory offsets in chronological order.
        $offsetPersistDirectory = [];
        $lastUserEditAtom       = null;
        $offsetLastEdit         = $offsetToCurrentEdit;

        do {
            $userEditAtom = $this->getRecord($ppdStream, $offsetLastEdit, 0x0FF5);
            if ($userEditAtom === false) {
                break;
            }
            $lastUserEditAtom = $userEditAtom;
            array_unshift($offsetPersistDirectory, $this->getLong(12, $userEditAtom));
            $offsetLastEdit = $this->getLong(8, $userEditAtom);
        } while ($offsetLastEdit !== 0x00000000);

        if ($lastUserEditAtom === null) {
            throw new ParseException('No UserEditAtom found in PowerPoint document');
        }

        // Build the PersistDirectory: persistId → byte offset in ppdStream
        $persistDirEntry = [];
        foreach ($offsetPersistDirectory as $pdOffset) {
            $rgPersistDirEntry = $this->getRecord($ppdStream, $pdOffset, 0x1772);
            if ($rgPersistDirEntry === false) {
                throw new ParseException('PersistDirectoryAtom not found');
            }

            for ($k = 0; $k < strlen($rgPersistDirEntry);) {
                $persist   = $this->getLong($k, $rgPersistDirEntry);
                $persistId = $persist & 0x000FFFFF;
                $cPersist  = (($persist & 0xFFF00000) >> 20) & 0x00000FFF;
                $k += 4;

                for ($i = 0; $i < $cPersist; $i++) {
                    $persistDirEntry[$persistId + $i] = $this->getLong($k + $i * 4, $rgPersistDirEntry);
                }
                $k += $cPersist * 4;
            }
        }

        // Navigate to DocumentContainer, then SlideList
        $docPersistIdRef   = $this->getLong(16, $lastUserEditAtom);
        $docOffset         = $persistDirEntry[$docPersistIdRef] ?? null;
        if ($docOffset === null) {
            throw new ParseException('DocumentContainer persist ID not found in directory');
        }
        $documentContainer = $this->getRecord($ppdStream, $docOffset, 0x03E8);
        if ($documentContainer === false) {
            throw new ParseException('DocumentContainer record not found');
        }

        // Skip mandatory and optional sub-records to reach the SlideList
        $offset = $this->skipDocumentContainerPreamble($documentContainer);
        $slideList = $this->getRecord($documentContainer, $offset, 0x0FF0);
        if ($slideList === false) {
            throw new ParseException('SlideList not found in DocumentContainer');
        }

        $out = '';
        for ($i = 0; $i < strlen($slideList);) {
            $block     = $this->getRecord($slideList, $i);
            $blockType = $this->getRecordType($slideList, $i);
            $blockLen  = $this->getRecordLength($slideList, $i);

            if ($block === false || $blockLen === false) {
                break;
            }

            switch ($blockType) {
                case 0x03F3: // RT_SlidePersistAtom — jump to the actual slide
                    $pid = $this->getLong(0, $block);
                    if (!isset($persistDirEntry[$pid])) {
                        break;
                    }
                    $slide = $this->getRecord($ppdStream, $persistDirEntry[$pid], 0x03EE);
                    if ($slide !== false) {
                        $out .= $this->extractTextFromSlide($slide, $persistDirEntry, $ppdStream);
                    }
                    break;

                case 0x0FA0: // RT_TextCharsAtom — raw UTF-16 text
                    $out .= $this->unicodeToUtf8($block) . ' ';
                    break;

                case 0x0FA8: // RT_TextBytesAtom — ANSI byte text (decoded immediately to UTF-8)
                    $out .= mb_convert_encoding($block, 'UTF-8', 'CP1252') . ' ';
                    break;
            }

            $i += $blockLen + 8;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Skip the fixed-layout preamble in a DocumentContainer and return the
     * offset of the SlideList record.
     */
    private function skipDocumentContainerPreamble(string $container): int
    {
        $offset = 40 + 8; // skip DocumentAtom (fixed size 40) + record header (8)

        $optionalRecords = [
            0x0409, // ExObjList
            0x03F2, // DocumentTextInfo (required — always present)
            0x07E4, // SoundCollection (optional)
            0x040B, // DrawingGroup (required)
            0x0FF0, // MasterList (required — but we are looking for the slide list, which comes AFTER)
            0x07D0, // DocInfoList (optional)
            0x0FD9, // SlideHF (optional)
            0x0FD9, // NotesHF (optional)
        ];

        foreach ($optionalRecords as $recType) {
            $rec = $this->getRecord($container, $offset, $recType);
            if ($rec !== false) {
                $offset += strlen($rec) + 8;
            }
        }

        return $offset;
    }

    /**
     * Extract text atoms from a single slide record.
     *
     * @param array<int, int> $persistDirEntry
     */
    private function extractTextFromSlide(string $slide, array $persistDirEntry, string $ppdStream): string
    {
        $out    = '';
        $offset = 32; // skip SlideAtom (fixed 32 bytes)

        // Skip optional slide sub-records before the Drawing
        foreach ([0x03F9, 0x0FD9, 0x3714] as $recType) {
            $rec = $this->getRecord($slide, $offset, $recType);
            if ($rec !== false) {
                $offset += strlen($rec) + 8;
            }
        }

        $drawing = $this->getRecord($slide, $offset, 0x040C);
        if ($drawing === false) {
            return '';
        }

        // Scan the Drawing binary for text-atom markers (0x0FA8 = plain, 0x0FA0 = Unicode)
        $from = 0;
        while (preg_match('/(\xA8|\xA0)\x0F/', $drawing, $pocket, PREG_OFFSET_CAPTURE, $from)) {
            $matchOffset = $pocket[1][1];
            $markerByte  = ord($pocket[1][0]);

            // Valid record headers have two zero bytes before the type word
            if (substr($drawing, $matchOffset - 2, 2) === "\x00\x00") {
                $headerOffset = $matchOffset - 2;
                if ($markerByte === 0xA8) {
                    // Plain text (ANSI byte text, decoded immediately to UTF-8)
                    $rec  = $this->getRecord($drawing, $headerOffset, 0x0FA8);
                    $out .= mb_convert_encoding($rec ?: '', 'UTF-8', 'CP1252') . ' ';
                } else {
                    // Unicode text
                    $rec  = $this->getRecord($drawing, $headerOffset, 0x0FA0);
                    $out .= $this->unicodeToUtf8($rec ?: '') . ' ';
                }
            }

            $from = $matchOffset + 2;
        }

        return $out;
    }

    /** Return the length (in bytes, excluding the 8-byte header) of the record at $offset. */
    private function getRecordLength(string $stream, int $offset, ?int $recType = null): int|false
    {
        if ($offset + 8 > strlen($stream)) {
            return false;
        }
        $header = substr($stream, $offset, 8);
        if ($recType !== null && $recType !== $this->getShort(2, $header)) {
            return false;
        }
        return $this->getLong(4, $header);
    }

    /** Return the record-type word from the 8-byte header at $offset. */
    private function getRecordType(string $stream, int $offset): int
    {
        return $this->getShort(2, substr($stream, $offset, 8));
    }

    /**
     * Return the body of the record at $offset (without its 8-byte header).
     * Returns false if the type at that offset does not match $recType.
     */
    private function getRecord(string $stream, int $offset, ?int $recType = null): string|false
    {
        $length = $this->getRecordLength($stream, $offset, $recType);
        if ($length === false) {
            return false;
        }
        return substr($stream, $offset + 8, $length);
    }
}

/**
 * Convenience function for users who prefer a procedural interface.
 */
function ppt2text(string $filename): string
{
    return (new PptParser())->extractText($filename);
}
