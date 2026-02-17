<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Extracts plain text from RTF (Rich Text Format) files.
 *
 * Design principles (2026 edition):
 *   - All output is UTF-8 from the very first character; no end-of-document
 *     encoding conversion.
 *   - Hex-escaped bytes (\'XX) are converted to UTF-8 immediately using the
 *     codepage declared by \ansicpgNNNN (default: CP1252 / Western European).
 *   - The \u Unicode control word emits mb_chr() directly.
 *   - Special characters (em-dash, quotes, …) emit literal UTF-8 characters.
 *
 * Bug fixes applied vs. the original 2009 code:
 *   - PR #4: guard against stack underflow (j < 0 or missing stack entry).
 *   - PR #4: accept raw RTF string via parseString() in addition to a filename.
 *   - Single-quoted '\n'/'\r' escape sequences were literal two-char strings in
 *     the original; corrected to actual control characters throughout.
 */
final class RtfParser
{
    /**
     * Control-word names that indicate the current group is non-text content
     * (font table, colour table, embedded objects, stylesheet, etc.).
     */
    private const array NON_TEXT_GROUPS = [
        '*', 'fonttbl', 'colortbl', 'datastore',
        'themedata', 'stylesheet', 'info', 'picw', 'pich',
    ];

    /**
     * Mac Roman byte → Unicode codepoint map for a Czech/Latin subset.
     */
    private const array MAC_ROMAN_TABLE = [
        0x83 => 0x00c9, 0x84 => 0x00d1, 0x87 => 0x00e1, 0x8e => 0x00e9,
        0x92 => 0x00ed, 0x96 => 0x00f1, 0x97 => 0x00f3, 0x9c => 0x00fa,
        0xe7 => 0x00c1, 0xea => 0x00cd, 0xee => 0x00d3, 0xf2 => 0x00da,
    ];

    /**
     * Read an RTF file and return its plain-text content as UTF-8.
     *
     * @throws ParseException if the file cannot be read.
     */
    public function extractText(string $filename): string
    {
        $contents = @file_get_contents($filename);
        if ($contents === false) {
            throw new ParseException("Cannot read file: $filename");
        }
        return $this->parseRtf($contents);
    }

    /**
     * Parse an RTF string and return its plain-text content as UTF-8.
     *
     * @throws ParseException if the input is empty.
     */
    public function parseString(string $rtf): string
    {
        if ($rtf === '') {
            throw new ParseException('Empty RTF input');
        }
        return $this->parseRtf($rtf);
    }

    // -------------------------------------------------------------------------
    // Core parser
    // -------------------------------------------------------------------------

    private function parseRtf(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Detect the document's ANSI code page from \ansicpgNNNN (RTF header).
        // This governs how \'XX hex-escaped bytes are decoded.
        // Default 1252 = Windows Western European (most common RTF codepage).
        $codePage = 1252;
        if (preg_match('/\\\\ansicpg(\d+)/', $text, $cpMatch)) {
            $codePage = (int) $cpMatch[1];
        }

        // For very large files: strip long hex runs that are almost certainly
        // embedded binary data (images, embedded fonts, etc.)
        if (strlen($text) > 1024 * 1024) {
            $text = preg_replace('#[\r\n]#', '', $text) ?? $text;
            $text = preg_replace('#[0-9a-f]{128,}#is', '', $text) ?? $text;
        }

        // Normalise the common \'3f URL-encoding artifact
        $text = str_ireplace("\\'3f", '?', $text);

        $document = '';
        /** @var array<int, array<string, string|true>> $stack */
        $stack = [];
        $j     = -1;
        /** @var array<int, int> $fonts font-id → fcharset */
        $fonts = [];
        $len   = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];

            switch ($c) {
                // ----------------------------------------------------------
                case '\\':
                    if ($i + 1 >= $len) {
                        break;
                    }
                    $nc = $text[$i + 1];

                    if ($nc === '\\' && $this->isPlainText($stack, $j)) {
                        $document .= '\\';
                    } elseif ($nc === '~' && $this->isPlainText($stack, $j)) {
                        $document .= "\u{00A0}"; // non-breaking space
                    } elseif ($nc === '_' && $this->isPlainText($stack, $j)) {
                        $document .= '-'; // optional hyphen
                    } elseif ($nc === '*') {
                        if ($j >= 0) {
                            $stack[$j]['*'] = true;
                        }
                    } elseif ($nc === "'") {
                        // Hex-escaped character: \'XX → convert to UTF-8 now
                        $hex = substr($text, $i + 2, 2);
                        if ($this->isPlainText($stack, $j)) {
                            $document .= $this->decodeHexChar($hex, $stack, $j, $fonts, $codePage);
                        }
                        $i += 2;
                    } elseif (($nc >= 'a' && $nc <= 'z') || ($nc >= 'A' && $nc <= 'Z')) {
                        // Control word (letters + optional numeric parameter)
                        [$word, $param, $consumed] = $this->readControlWord($text, $i + 1, $len);
                        $i += $consumed - 1; // outer loop will still do $i++

                        // \u is the Unicode control word: emit the codepoint as
                        // a UTF-8 character and skip the ANSI fallback char(s).
                        if (strtolower($word) === 'u' && $param !== null) {
                            $codepoint = (int) $param;
                            if ($codepoint < 0) {
                                $codepoint += 65536; // RTF uses signed 16-bit for \u
                            }
                            if ($this->isPlainText($stack, $j)) {
                                $char = mb_chr($codepoint, 'UTF-8');
                                $document .= $char !== false ? $char : '';
                            }
                            // Skip ANSI fallback character(s) (\ucN, default 1)
                            $ucDelta = ($j >= 0 && isset($stack[$j]['uc'])) ? (int) $stack[$j]['uc'] : 1;
                            $i += $ucDelta;
                        } else {
                            $toText = $this->handleControlWord($word, $param, $stack, $j, $fonts);
                            if ($toText !== '' && $this->isPlainText($stack, $j)) {
                                $document .= $toText;
                            }
                        }
                    } else {
                        if ($this->isPlainText($stack, $j)) {
                            $document .= ' ';
                        }
                    }
                    $i++;
                    break;

                    // ----------------------------------------------------------
                case '{':
                    if ($j === -1) {
                        $stack[++$j] = [];
                    } else {
                        $parent      = $stack[$j] ?? [];
                        $stack[++$j] = $parent; // inherit parent state (PR #4)
                    }
                    break;

                    // ----------------------------------------------------------
                case '}':
                    if ($j >= 0) {
                        array_pop($stack);
                        $j--;
                    }
                    break;

                    // ----------------------------------------------------------
                case "\0":
                case "\r":
                case "\f":
                case "\b":
                case "\t":
                    break;

                case "\n":
                    $document .= ' '; // bare newlines in RTF source are not content
                    break;

                    // ----------------------------------------------------------
                default:
                    // PR #4: guard against j == -1
                    if ($j >= 0 && $this->isPlainText($stack, $j)) {
                        $document .= $c;
                    }
                    break;
            }
        }

        // All content is already UTF-8.  html_entity_decode resolves any
        // remaining &ent; tokens emitted by fromMacRoman() for unmapped bytes.
        return html_entity_decode($document, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // -------------------------------------------------------------------------
    // Control-word reader
    // -------------------------------------------------------------------------

    /**
     * Read a control word (and optional numeric parameter) starting at $pos.
     *
     * @return array{0: string, 1: string|null, 2: int}
     *                                                  [word, param|null, chars_consumed_from_pos]
     */
    private function readControlWord(string $text, int $pos, int $len): array
    {
        $word  = '';
        $param = null;
        $k     = $pos;

        for (; $k < $len; $k++) {
            $nc = $text[$k];
            if (($nc >= 'a' && $nc <= 'z') || ($nc >= 'A' && $nc <= 'Z')) {
                if ($param === null) {
                    $word .= $nc;
                } else {
                    break;
                }
            } elseif ($nc >= '0' && $nc <= '9') {
                $param = ($param ?? '') . $nc;
            } elseif ($nc === '-') {
                if ($param === null) {
                    $param = '-';
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        // Consume exactly one trailing space delimiter
        if ($k < $len && $text[$k] === ' ') {
            $k++;
        }

        return [$word, $param, $k - $pos];
    }

    // -------------------------------------------------------------------------
    // Control-word handler
    // -------------------------------------------------------------------------

    /**
     * Interpret a control word and return the UTF-8 text it should emit,
     * or an empty string if it only affects state.
     *
     * All visible characters are returned as literal UTF-8, not HTML entities.
     *
     * @param array<int, array<string, string|true>> $stack
     * @param array<int, int> $fonts
     */
    private function handleControlWord(
        string  $word,
        ?string $param,
        array   &$stack,
        int     &$j,
        array   &$fonts,
    ): string {
        return match (strtolower($word)) {
            // Paragraph / whitespace control
            'par', 'page', 'column', 'line', 'lbr' => "\n",
            'emspace', 'enspace', 'qmspace'         => ' ',
            'tab'                                   => "\t",

            // Date/time placeholders
            'chdate' => date('m.d.Y'),
            'chdpl'  => date('l, j F Y'),
            'chdpa'  => date('D, j M Y'),
            'chtime' => date('H:i:s'),

            // Special characters — literal UTF-8, no html_entity_decode needed
            'emdash'    => '—',
            'endash'    => '–',
            'bullet'    => '•',
            'lquote'    => "\u{2018}",  // '
            'rquote'    => "\u{2019}",  // '
            'ldblquote' => '«',
            'rdblquote' => '»',

            // Font charset registry (for hex-escape codepage decisions)
            'fcharset' => (function () use ($param, $j, $stack, &$fonts): string {
                if ($param !== null && $j >= 0 && isset($stack[$j]['f'])) {
                    $fonts[(int) $stack[$j]['f']] = (int) $param;
                }
                return '';
            })(),

            // Binary data: skip $param bytes (limitation: we cannot advance $i
            // from here; large binary blobs may leave garbage in the output).
            'bin' => '',

            // Everything else: accumulate into the current stack level
            default => (function () use ($word, $param, &$j, &$stack): string {
                if ($j >= 0) {
                    $stack[$j][strtolower($word)] = $param ?? true;
                }
                return '';
            })(),
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if the current stack level represents visible plain text.
     *
     * @param array<int, array<string, string|true>> $stack
     */
    private function isPlainText(array $stack, int $j): bool
    {
        if ($j < 0 || !isset($stack[$j])) {
            return false;
        }
        foreach (self::NON_TEXT_GROUPS as $marker) {
            if (!empty($stack[$j][$marker])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Decode a two-hex-digit escaped byte (\'XX) to UTF-8.
     *
     * Priority:
     *   1. Mac Roman charset (fcharset 77) → table lookup
     *   2. Font-level \ansicpg override from the stack
     *   3. Document-level $codePage (from \ansicpgNNNN in the header)
     *
     * @param array<int, array<string, string|true>> $stack
     * @param array<int, int> $fonts
     */
    private function decodeHexChar(string $hex, array $stack, int $j, array $fonts, int $codePage): string
    {
        if (!ctype_xdigit($hex)) {
            return '';
        }
        $byte = hexdec($hex);

        // Mac Roman (fcharset 77)
        if (
            ($j >= 0 && !empty($stack[$j]['mac'])) ||
            ($j >= 0 && isset($stack[$j]['f']) && ($fonts[(int) $stack[$j]['f']] ?? 0) === 77)
        ) {
            return $this->fromMacRoman((int) $byte);
        }

        // Effective codepage: stack override, then document default
        $effectiveCp = $codePage;
        if ($j >= 0 && isset($stack[$j]['ansicpg'])) {
            $effectiveCp = (int) $stack[$j]['ansicpg'];
        }

        // Convert the raw byte from the effective codepage to UTF-8 immediately
        $result = @mb_convert_encoding(chr((int) $byte), 'UTF-8', 'CP' . $effectiveCp);
        return $result !== false ? $result : '';
    }

    /**
     * Map a Mac Roman byte to its UTF-8 equivalent.
     * Falls back to a raw byte for unmapped values.
     */
    private function fromMacRoman(int $byte): string
    {
        if (isset(self::MAC_ROMAN_TABLE[$byte])) {
            $char = mb_chr(self::MAC_ROMAN_TABLE[$byte], 'UTF-8');
            return $char !== false ? $char : chr($byte);
        }
        return chr($byte);
    }
}

/**
 * Convenience function — accepts a filename, returns UTF-8 plain text.
 */
function rtf2text(string $filename): string
{
    return (new RtfParser())->extractText($filename);
}
