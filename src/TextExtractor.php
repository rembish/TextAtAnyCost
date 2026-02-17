<?php

declare(strict_types=1);

namespace TextAtAnyCost;

use TextAtAnyCost\Exception\ParseException;
use TextAtAnyCost\Parser\DocParser;
use TextAtAnyCost\Parser\PdfParser;
use TextAtAnyCost\Parser\PptParser;
use TextAtAnyCost\Parser\RtfParser;
use TextAtAnyCost\Parser\ZippedXmlParser;

/**
 * Unified facade for text extraction from common document formats.
 *
 * Supported formats and their detection method:
 *   .doc  — Microsoft Word 97–2003      (extension)
 *   .pdf  — Adobe PDF                   (extension)
 *   .ppt  — Microsoft PowerPoint 97-2003(extension)
 *   .rtf  — Rich Text Format            (extension)
 *   .docx — Word 2007+ (Open XML)       (extension)
 *   .odt  — OpenDocument Text           (extension)
 *
 * Usage:
 *   $text = TextExtractor::fromFile('/path/to/document.docx');
 */
final class TextExtractor
{
    /**
     * Detect the document type from the file extension and extract plain text.
     *
     * @throws ParseException if the file cannot be read or parsed.
     * @throws \InvalidArgumentException if the extension is not supported.
     */
    public static function fromFile(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'doc'  => (new DocParser())->extractText($filename),
            'pdf'  => (new PdfParser())->extractText($filename),
            'ppt'  => (new PptParser())->extractText($filename),
            'rtf'  => (new RtfParser())->extractText($filename),
            'docx' => (new ZippedXmlParser())->extractDocx($filename),
            'odt'  => (new ZippedXmlParser())->extractOdt($filename),
            default => throw new \InvalidArgumentException(
                "Unsupported file extension: .$ext"
            ),
        };
    }

    /**
     * Return true if the given file extension is handled by this library.
     */
    public static function isSupported(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['doc', 'pdf', 'ppt', 'rtf', 'docx', 'odt'], strict: true);
    }
}
