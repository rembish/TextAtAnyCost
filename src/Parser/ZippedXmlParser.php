<?php

declare(strict_types=1);

namespace TextAtAnyCost\Parser;

use TextAtAnyCost\Exception\ParseException;

/**
 * Extracts plain text from Open XML and ODF document formats.
 *
 * Both .docx (Office Open XML) and .odt (OpenDocument Text) store their
 * content as XML inside a ZIP archive.  This parser opens the archive,
 * reads the relevant XML entry, strips all markup, and returns UTF-8 text.
 *
 * Security note: LIBXML_XINCLUDE (which the original code used) is removed.
 *   It allowed the XML parser to follow <xi:include> directives and read
 *   arbitrary local files — a potential path-traversal / XXE vulnerability
 *   when processing untrusted documents.
 */
final class ZippedXmlParser
{
    /**
     * Extract text from a .docx file.
     *
     * @throws ParseException
     */
    public function extractDocx(string $filename): string
    {
        return $this->extractFromZippedXml($filename, 'word/document.xml');
    }

    /**
     * Extract text from an .odt file.
     *
     * @throws ParseException
     */
    public function extractOdt(string $filename): string
    {
        return $this->extractFromZippedXml($filename, 'content.xml');
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Open a ZIP archive, locate $contentFile inside it, parse as XML,
     * and return the text content of all element nodes as UTF-8.
     *
     * @throws ParseException if the archive or content file cannot be opened.
     */
    private function extractFromZippedXml(string $archiveFile, string $contentFile): string
    {
        $zip = new \ZipArchive();
        $result = $zip->open($archiveFile);
        if ($result !== true) {
            throw new ParseException(
                "Cannot open ZIP archive '$archiveFile' (ZipArchive error $result)"
            );
        }

        $index = $zip->locateName($contentFile);
        if ($index === false) {
            $zip->close();
            throw new ParseException("'$contentFile' not found inside '$archiveFile'");
        }

        $content = $zip->getFromIndex($index);
        $zip->close();

        if ($content === false) {
            throw new ParseException("Failed to read '$contentFile' from archive");
        }

        return $this->xmlToText($content);
    }

    /**
     * Parse an XML string and return all text nodes concatenated as UTF-8.
     *
     * LIBXML_NOENT  — substitute predefined XML entities (&amp; etc.)
     * LIBXML_NOERROR / LIBXML_NOWARNING — suppress parser warnings for
     *                                     documents with minor issues.
     *
     * LIBXML_XINCLUDE is intentionally omitted (security).
     *
     * @throws ParseException if the XML cannot be parsed at all.
     */
    private function xmlToText(string $xml): string
    {
        $dom = new \DOMDocument();
        $loaded = @$dom->loadXML(
            $xml,
            LIBXML_NOENT | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        if (!$loaded) {
            throw new ParseException('Failed to parse XML content');
        }

        // strip_tags on the serialised XML is the simplest cross-format approach
        $text = strip_tags($dom->saveXML() ?: '');

        // Normalise whitespace: collapse runs and trim
        return trim(preg_replace('/\s{2,}/u', ' ', $text) ?? $text);
    }
}

/**
 * Convenience functions for users who prefer a procedural interface.
 */
function docx2text(string $filename): string
{
    return (new ZippedXmlParser())->extractDocx($filename);
}

function odt2text(string $filename): string
{
    return (new ZippedXmlParser())->extractOdt($filename);
}
