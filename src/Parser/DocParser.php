<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Extracts plain text from Microsoft Word 97–2003 .doc files.
 *
 * The format is a Windows Compound Binary File (CFB) containing two key streams:
 *   - WordDocument  – holds the actual character data
 *   - [01]Table     – holds the CLX/pieceTable that maps character positions to offsets
 */
final class DocParser extends CfbParser
{
    protected function parse(): string
    {
        $this->parseCfb();

        // Locate the WordDocument stream (required)
        $wdId = $this->getStreamIdByName('WordDocument');
        if ($wdId === false) {
            throw new ParseException('WordDocument stream not found');
        }
        $wdStream = $this->getStreamById($wdId);

        // File Information Block (FIB): bit 9 of the flags word at 0x000A
        // tells us whether to use "0Table" or "1Table".
        $flags        = $this->getShort(0x000A, $wdStream);
        $useTable1    = (bool) ($flags & 0x0200);
        $tableName    = ($useTable1 ? '1' : '0') . 'Table';

        // CLX location and size inside the table stream
        $fcClx  = $this->getLong(0x01A2, $wdStream);
        $lcbClx = $this->getLong(0x01A6, $wdStream);

        // Character counts for each story (text, footnotes, headers, etc.)
        $ccpText    = $this->getLong(0x004C, $wdStream);
        $ccpFtn     = $this->getLong(0x0050, $wdStream);
        $ccpHdd     = $this->getLong(0x0054, $wdStream);
        $ccpMcr     = $this->getLong(0x0058, $wdStream);
        $ccpAtn     = $this->getLong(0x005C, $wdStream);
        $ccpEdn     = $this->getLong(0x0060, $wdStream);
        $ccpTxbx    = $this->getLong(0x0064, $wdStream);
        $ccpHdrTxbx = $this->getLong(0x0068, $wdStream);

        $lastCP = $ccpFtn + $ccpHdd + $ccpMcr + $ccpAtn + $ccpEdn + $ccpTxbx + $ccpHdrTxbx;
        $lastCP += ($lastCP !== 0 ? 1 : 0) + $ccpText;

        // Load the table stream
        $tId = $this->getStreamIdByName($tableName);
        if ($tId === false) {
            throw new ParseException("Table stream '$tableName' not found");
        }
        $tStream = $this->getStreamById($tId);
        $clx     = substr($tStream, $fcClx, $lcbClx);

        // Locate the PieceTable inside the CLX.
        // The PieceTable always starts with byte 0x02; we scan forward until
        // the declared size matches the remaining bytes.
        $pieceTable    = '';
        $lcbPieceTable = 0;
        $from          = 0;

        while (($pos = strpos($clx, "\x02", $from)) !== false) {
            $lcbPieceTable = $this->getLong($pos + 1, $clx);
            $pieceTable    = substr($clx, $pos + 5);
            if (strlen($pieceTable) === $lcbPieceTable) {
                break;
            }
            $from = $pos + 1;
        }

        // Build the character-position (CP) array from the PieceTable
        $cp = [];
        $i  = 0;
        while (($cp[] = $this->getLong($i, $pieceTable)) !== $lastCP) {
            $i += 4;
        }

        // Piece Descriptors follow the CP array; each is 8 bytes
        $pcd = str_split(substr($pieceTable, $i + 4), 8);

        $text = '';
        foreach ($pcd as $k => $descriptor) {
            if (!isset($cp[$k + 1])) {
                break;
            }

            // Bits[30] of the fc value indicate ANSI (1) vs. Unicode (0)
            $fcValue = $this->getLong(2, $descriptor);
            $isAnsi  = (bool) ($fcValue & 0x40000000);
            $fc      = $fcValue & 0x3FFFFFFF;

            $lcb = $cp[$k + 1] - $cp[$k];

            if ($isAnsi) {
                // ANSI: offset is halved, length in bytes == character count
                $fc = (int) ($fc / 2);
            } else {
                // Unicode: each character is 2 bytes
                $lcb *= 2;
            }

            $part = substr($wdStream, $fc, $lcb);

            if (!$isAnsi) {
                $part = $this->unicodeToUtf8($part);
            }

            $text .= $part;
        }

        // Strip embedded hyperlink / object markers inserted by Word
        $text = preg_replace('/HYPER13\s*(INCLUDEPICTURE|HTMLCONTROL).*?HYPER15/iU', '', $text) ?? $text;
        $text = preg_replace('/HYPER13.*?HYPER14(.*?)HYPER15/iU', '$1', $text) ?? $text;

        return $text;
    }

    /**
     * Override: DOC stores character data without hyperlink markers in the
     * stream itself (they are replaced by HYPER13/14/15 tokens above), so
     * the conversion is simpler and uses HYPER tokens in plain text mode.
     *
     * Fix (PR #9): mb_chr() replaces html_entity_decode() for proper
     * multi-byte Unicode output.
     */
    protected function unicodeToUtf8(string $in, bool $stripHyperlinks = false): string
    {
        $out = '';
        for ($i = 0, $len = strlen($in); $i + 1 < $len; $i += 2) {
            $lo = ord($in[$i]);
            $hi = ord($in[$i + 1]);

            if ($hi === 0) {
                if ($lo >= 32) {
                    // Emit as UTF-8; mb_chr handles both ASCII and Latin-1 supplement.
                    $char = mb_chr($lo, 'UTF-8');
                    $out .= $char !== false ? $char : '';
                }
                // Control character interpretation
                switch ($lo) {
                    case 0x0D:
                    case 0x07:
                        $out .= "\n";
                        break;
                    case 0x13:
                        $out .= 'HYPER13';
                        break;
                    case 0x14:
                        $out .= 'HYPER14';
                        break;
                    case 0x15:
                        $out .= 'HYPER15';
                        break;
                }
            } else {
                // Non-ASCII: emit the Unicode character directly as UTF-8
                $codepoint = $lo | ($hi << 8);
                $char      = mb_chr($codepoint, 'UTF-8');
                if ($char !== false) {
                    $out .= $char;
                }
            }
        }
        return $out;
    }
}

/**
 * Convenience function for users who prefer a procedural interface.
 */
function doc2text(string $filename): string
{
    return (new DocParser())->extractText($filename);
}
