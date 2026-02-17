# Changelog

## [1.0.0] — 2026-02-17 — full modernisation of the 2009 codebase

This release is a ground-up rewrite that keeps the same document-parsing logic
but brings everything up to current PHP and software-engineering standards.

### What's new

- **Unified facade** — `TextExtractor::fromFile($path)` picks the right parser
  automatically based on the file extension.
- **Composer package** — install with `composer require rembish/text-at-any-cost`
  or straight from GitHub. PSR-4 autoloading, no global includes required.
- **Docker-based dev workflow** — `make test`, `make stan`, `make cs-fix` and
  friends; no local PHP installation needed.
- **Test suite** — 85 tests covering every parser, the RAR reader/writer, and
  the facade. Run with `make test`.
- **Parse RTF from a string** — new `RtfParser::parseString($rtf)` method, so
  you are no longer forced to write RTF to a file first.

### What changed

- **All parsers now output clean UTF-8 all the way through.** Previously the
  code accumulated text in various single-byte encodings and converted
  everything at the very end with `iconv`. This caused data corruption
  whenever a document mixed encodings (e.g. Unicode headings with
  Windows-1251 body text). Each character is now converted to UTF-8 the moment
  it is read.
- The RTF parser auto-detects the document codepage from the `\ansicpg` header
  field and uses it when decoding hex-escaped characters (`\'XX`). It also
  handles the `\u` Unicode control word correctly.
- Special RTF characters (`\emdash`, `\endash`, `\bullet`, smart quotes, …)
  now emit the actual Unicode characters instead of HTML entities.
- The PPT parser converts ANSI text frames with `mb_convert_encoding` instead
  of a blanket Windows-1251 → UTF-8 conversion applied to the whole output.
- The DOC parser uses `mb_chr()` to emit Unicode codepoints instead of an
  `html_entity_decode("&#x…;")` round-trip.
- Minimum PHP version bumped to **8.3**.
- All comments translated to English.

### Bugs fixed

- **RAR writer** — timestamps were always set to the current time and never
  used the value you passed in.
- **RAR writer** — archive header sizes were off by one when a field was
  empty (caused by `strlen(0) === 1` in PHP).
- **PDF parser** — `\n`, `\r` etc. inside the text-assembly code were written
  as two-character strings (`backslash + n`) instead of real control
  characters, so newlines never appeared in the output.
- **PDF parser** — referenced a constant (`FILE_BINARY`) that does not exist
  in PHP; this produced a fatal error on strict mode.
- **CFB parser** (used by .doc and .ppt) — a `while` loop over an empty array
  could spin forever.
- **CFB parser** — a leftover `echo "@"` debug statement in the middle of the
  parsing loop corrupted every document's output.
- **RTF parser** — closing a group (`}`) when the stack was already empty
  caused an underflow warning.
- **DOCX/ODT parser** — had `LIBXML_XINCLUDE` enabled, which allowed a
  specially crafted document to read arbitrary files from the server via XML
  `<xi:include>` (path traversal / XXE). Removed.
- **DOCX/ODT parser** — silently converted output to Windows-1250, losing any
  character that does not exist in that codepage.

### Removed

- The eight original flat PHP files (`cfb.php`, `doc.php`, `pdf.php`,
  `ppt.php`, `rtf.php`, `zipped-xml.php`, `rar-list.php`, `stored-rar.php`).
  The procedural wrapper functions (`doc2text()`, `pdf2text()`, etc.) are still
  available in the new class files for drop-in compatibility.
