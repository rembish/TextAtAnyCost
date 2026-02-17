<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Extracts plain text from PDF files.
 *
 * Two-pass approach:
 *   Pass 1 — iterate objects, decode streams, collect:
 *             (a) dirty text blocks (between BT/ET markers)
 *             (b) character-transformation tables (ToUnicode CMaps)
 *   Pass 2 — resolve hex/plain text using the collected transformations.
 */
final class PdfParser
{
    /**
     * @throws ParseException if the file cannot be read.
     */
    public function extractText(string $filename): string
    {
        $contents = @file_get_contents($filename);
        if ($contents === false) {
            throw new ParseException("Cannot read file: $filename");
        }

        $transformations = [];
        $texts           = [];

        // Collect all PDF objects
        preg_match_all('#obj(.*)endobj#ismU', $contents, $objects);
        $objects = $objects[1];

        foreach ($objects as $object) {
            if (!preg_match('#stream(.*)endstream#ismU', $object, $streamMatch)) {
                continue;
            }
            $stream  = ltrim($streamMatch[1]);
            $options = $this->getObjectOptions($object);

            // Skip font/embedded-file objects — they are not text streams
            if (!empty($options['Length1']) || !empty($options['Type']) || !empty($options['Subtype'])) {
                continue;
            }

            $data = $this->getDecodedStream($stream, $options);
            if ($data === '' || $data === false) {
                continue;
            }
            $data = (string) $data;

            if (preg_match_all('#BT(.*)ET#ismU', $data, $textContainers)) {
                $this->collectDirtyTexts($texts, $textContainers[1]);
            } else {
                $this->collectCharTransformations($transformations, $data);
            }
        }

        return $this->resolveTexts($texts, $transformations);
    }

    // -------------------------------------------------------------------------
    // Stream decoding
    // -------------------------------------------------------------------------

    /**
     * Parse the dictionary between << and >> for the current object, returning
     * an associative array of option name → value (or true for flag-only options).
     *
     * @return array<string, string|true>
     */
    private function getObjectOptions(string $object): array
    {
        if (!preg_match('#<<(.*)>>#ismU', $object, $match)) {
            return [];
        }

        $parts = explode('/', $match[1]);
        array_shift($parts); // remove the leading empty element

        $options = [];
        foreach ($parts as $part) {
            $part = preg_replace('#\s+#', ' ', trim($part)) ?? trim($part);
            if (str_contains($part, ' ')) {
                [$key, $value]  = explode(' ', $part, 2);
                $options[$key]  = $value;
            } else {
                $options[$part] = true;
            }
        }
        return $options;
    }

    /**
     * Decompress/decode a stream according to the Filter options declared in
     * its object dictionary.  Supported filters: ASCIIHexDecode, ASCII85Decode,
     * FlateDecode.  Encrypted (Crypt) streams are not supported.
     *
     * @param array<string, string|true> $options
     */
    private function getDecodedStream(string $stream, array $options): string|false
    {
        if (empty($options['Filter'])) {
            return $stream;
        }

        // Honour the declared byte length to avoid trailing garbage
        if (!empty($options['Length']) && !str_contains((string) $options['Length'], ' ')) {
            $stream = substr($stream, 0, (int) $options['Length']);
        }

        foreach ($options as $key => $value) {
            $stream = match ($key) {
                'ASCIIHexDecode' => $this->decodeAsciiHex($stream),
                'ASCII85Decode'  => $this->decodeAscii85($stream),
                'FlateDecode'    => $this->decodeFlate($stream),
                default          => $stream,
            };

            if ($stream === false) {
                return false;
            }
        }

        return $stream;
    }

    /** Decode ASCIIHex-encoded data (pairs of hex digits, terminated by '>') */
    private function decodeAsciiHex(string $input): string|false
    {
        $output    = '';
        $isOdd     = true;
        $codeHigh  = -1;
        $inComment = false;
        $len       = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $c = $input[$i];

            if ($c === '>') {
                // Odd final nibble: pad with zero
                if (!$isOdd) {
                    $output .= chr((int) $codeHigh * 16);
                }
                return $output;
            }

            if ($inComment) {
                if ($c === "\r" || $c === "\n") {
                    $inComment = false;
                }
                continue;
            }

            if ($c === '%') {
                $inComment = true;
                continue;
            }

            // Skip whitespace
            if ($c === "\0" || $c === "\t" || $c === "\r" || $c === "\f" || $c === "\n" || $c === ' ') {
                continue;
            }

            if (!ctype_xdigit($c)) {
                return false; // invalid hex digit
            }
            $code = hexdec($c);

            if ($isOdd) {
                $codeHigh = $code;
            } else {
                $output .= chr((int) $codeHigh * 16 + (int) $code);
            }
            $isOdd = !$isOdd;
        }

        return false; // missing '>' terminator
    }

    /** Decode ASCII85-encoded data (base-85, terminated by '~>') */
    private function decodeAscii85(string $input): string|false
    {
        $output    = '';
        $inComment = false;
        $ords      = [];
        $state     = 0;
        $len       = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $c = $input[$i];

            if ($c === '~') {
                break; // end marker
            }

            if ($inComment) {
                if ($c === "\r" || $c === "\n") {
                    $inComment = false;
                }
                continue;
            }

            if ($c === '%') {
                $inComment = true;
                continue;
            }

            if ($c === "\0" || $c === "\t" || $c === "\r" || $c === "\f" || $c === "\n" || $c === ' ') {
                continue;
            }

            if ($c === 'z' && $state === 0) {
                $output .= str_repeat("\0", 4);
                continue;
            }

            if ($c < '!' || $c > 'u') {
                return false;
            }

            $ords[$state++] = ord($c) - ord('!');

            if ($state === 5) {
                $state = 0;
                $sum   = 0;
                for ($j = 0; $j < 5; $j++) {
                    $sum = $sum * 85 + $ords[$j];
                }
                for ($j = 3; $j >= 0; $j--) {
                    $output .= chr($sum >> ($j * 8) & 0xFF);
                }
            }
        }

        if ($state === 1) {
            return false; // single orphaned byte — invalid
        }

        if ($state > 1) {
            $sum = 0;
            for ($i = 0; $i < $state; $i++) {
                $sum += ($ords[$i] + ($i === $state - 1 ? 1 : 0)) * (int) round(85 ** (4 - $i));
            }
            for ($i = 0; $i < $state - 1; $i++) {
                $output .= chr($sum >> ((3 - $i) * 8) & 0xFF);
            }
        }

        return $output;
    }

    /** Decompress a zlib/Deflate-compressed stream. */
    private function decodeFlate(string $input): string|false
    {
        $result = @gzuncompress($input);
        return $result === false ? false : $result;
    }

    // -------------------------------------------------------------------------
    // Text collection (Pass 1)
    // -------------------------------------------------------------------------

    /**
     * Extract raw text segments from BT/ET container blocks.
     * Supports two PDF text operators:
     *   [...] TJ  — glyph array with spacing offsets
     *   Td (...) Tj — positioned string
     *
     * @param string[] $texts accumulator (passed by reference)
     * @param string[] $containers BT…ET content strings
     */
    private function collectDirtyTexts(array &$texts, array $containers): void
    {
        foreach ($containers as $container) {
            if (preg_match_all('#\[(.*)\]\s*TJ#ismU', $container, $parts)) {
                $texts = array_merge($texts, $parts[1]);
            } elseif (preg_match_all('#Td\s*(\(.*\))\s*Tj#ismU', $container, $parts)) {
                $texts = array_merge($texts, $parts[1]);
            }
        }
    }

    /**
     * Parse ToUnicode CMap streams and accumulate character transformations.
     * Supports both:
     *   beginbfchar / endbfchar   — individual code → Unicode mappings
     *   beginbfrange / endbfrange — range mappings (sequential and array forms)
     *
     * @param array<array-key, string> $transformations accumulator (passed by reference)
     * @param-out array<array-key, string> $transformations
     */
    private function collectCharTransformations(array &$transformations, string $stream): void
    {
        // Individual character mappings
        preg_match_all('#([0-9]+)\s+beginbfchar(.*)endbfchar#ismU', $stream, $chars, PREG_SET_ORDER);
        foreach ($chars as $charBlock) {
            $count   = (int) $charBlock[1];
            $lines   = explode("\n", trim($charBlock[2]));
            for ($k = 0; $k < $count && $k < count($lines); $k++) {
                if (preg_match('#<([0-9a-f]{2,4})>\s+<([0-9a-f]{4,512})>#is', trim($lines[$k]), $map)) {
                    $transformations[str_pad($map[1], 4, '0')] = $map[2];
                }
            }
        }

        // Range mappings
        preg_match_all('#([0-9]+)\s+beginbfrange(.*)endbfrange#ismU', $stream, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $rangeBlock) {
            $count = (int) $rangeBlock[1];
            $lines = explode("\n", trim($rangeBlock[2]));
            for ($k = 0; $k < $count && $k < count($lines); $k++) {
                $line = trim($lines[$k]);

                // Sequential range: <from> <to> <startUnicode>
                if (preg_match('#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+<([0-9a-f]{4})>#is', $line, $map)) {
                    $from   = (int) hexdec($map[1]);
                    $to     = (int) hexdec($map[2]);
                    $target = (int) hexdec($map[3]);
                    for ($m = $from, $n = 0; $m <= $to; $m++, $n++) {
                        $transformations[sprintf('%04X', $m)] = sprintf('%04X', $target + $n);
                    }
                    // Array range: <from> <to> [<u1> <u2> ...]
                } elseif (preg_match('#<([0-9a-f]{4})>\s+<([0-9a-f]{4})>\s+\[(.*)\]#ismU', $line, $map)) {
                    $from  = (int) hexdec($map[1]);
                    $to    = (int) hexdec($map[2]);
                    $parts = preg_split('#\s+#', trim($map[3])) ?: [];
                    foreach ($parts as $n => $hex) {
                        if ($from + $n > $to) {
                            break;
                        }
                        $transformations[sprintf('%04X', $from + $n)] = sprintf('%04X', (int) hexdec($hex));
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Text resolution (Pass 2)
    // -------------------------------------------------------------------------

    /**
     * Convert collected raw text segments into a UTF-8 string by applying
     * character transformations.
     *
     * PDF text may appear as:
     *   (plain text)   — literal string, may contain \octal or \\, \(, \) escapes
     *   <hexstring>    — 4-hex-digit codepoints, looked up in the transform table
     *
     * Fix: '\n', '\r' etc. inside single-quoted strings were literal two-char
     *      sequences in the original; corrected to actual control characters.
     *
     * @param string[] $texts
     * @param array<array-key, string> $transformations
     */
    private function resolveTexts(array $texts, array $transformations): string
    {
        $document = '';

        foreach ($texts as $segment) {
            $isHex   = false;
            $isPlain = false;
            $hex     = '';
            $plain   = '';
            $len     = strlen($segment);

            for ($j = 0; $j < $len; $j++) {
                $c = $segment[$j];

                switch ($c) {
                    case '<':
                        $hex   = '';
                        $isHex = true;
                        break;

                    case '>':
                        foreach (str_split($hex, 4) as $h) {
                            $h = str_pad($h, 4, '0');
                            if (isset($transformations[$h])) {
                                $h = $transformations[$h];
                            }
                            $codepoint = (int) hexdec($h);
                            $char      = mb_chr($codepoint, 'UTF-8');
                            $document .= $char !== false ? $char : '';
                        }
                        $isHex = false;
                        break;

                    case '(':
                        $plain   = '';
                        $isPlain = true;
                        break;

                    case ')':
                        $document .= $plain;
                        $isPlain   = false;
                        break;

                    case '\\':
                        if ($j + 1 >= $len) {
                            break;
                        }
                        $c2 = $segment[++$j];
                        if (in_array($c2, ['\\', '(', ')'], strict: true)) {
                            $plain .= $c2;
                        } elseif ($c2 === 'n') {
                            $plain .= "\n";
                        } elseif ($c2 === 'r') {
                            $plain .= "\r";
                        } elseif ($c2 === 't') {
                            $plain .= "\t";
                        } elseif ($c2 === 'b') {
                            $plain .= "\x08";
                        } elseif ($c2 === 'f') {
                            $plain .= "\f";
                        } elseif ($c2 >= '0' && $c2 <= '9') {
                            // Octal escape: up to 3 digits
                            $oct  = preg_replace('#[^0-7]#', '', substr($segment, $j, 3)) ?? '';
                            $j   += strlen($oct) - 1;
                            $codepoint = octdec($oct);
                            $char      = mb_chr((int) $codepoint, 'UTF-8');
                            $plain    .= $char !== false ? $char : '';
                        }
                        break;

                    default:
                        if ($isHex) {
                            $hex .= $c;
                        }
                        if ($isPlain) {
                            $plain .= $c;
                        }
                        break;
                }
            }

            $document .= "\n";
        }

        return $document;
    }
}

/**
 * Convenience function for users who prefer a procedural interface.
 */
function pdf2text(string $filename): string
{
    return (new PdfParser())->extractText($filename);
}
