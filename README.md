# TextAtAnyCost

Extract plain text from common document formats — no external programs or PECL extensions required.

## Supported formats

| Format | Extension | Notes |
|--------|-----------|-------|
| Microsoft Word 97–2003 | `.doc` | CFB/WCBFF, ANSI and Unicode |
| Microsoft PowerPoint 97–2003 | `.ppt` | CFB/WCBFF |
| Adobe PDF | `.pdf` | FlateDecode, ASCII-85, ASCII-Hex, ToUnicode CMaps |
| Rich Text Format | `.rtf` | Stack-based parser, Mac Roman + Windows-1251 |
| Word 2007+ (Open XML) | `.docx` | ZIP + XML |
| OpenDocument Text | `.odt` | ZIP + XML |
| RAR archives (read list) | `.rar` | RAR 4.x, no PECL required |
| RAR archives (write/store) | `.rar` | Store method only |

## Requirements

- PHP **8.3** or later
- Extensions: `mbstring`, `zlib`, `dom`, `zip` (all standard in PHP 8)

## Installation

### Via Composer (recommended)

```bash
composer require rembish/text-at-any-cost
```

### Directly from GitHub

```bash
composer require rembish/text-at-any-cost:dev-master
```

> **Packagist**: submit your GitHub URL at [packagist.org](https://packagist.org/packages/submit)
> once to enable tagged releases (`composer require rembish/text-at-any-cost:^1.0`).

## Usage

### Unified facade (auto-detects by extension)

```php
use TextAtAnyCost\TextExtractor;

$text = TextExtractor::fromFile('/path/to/document.docx');
```

### Individual parsers

```php
use TextAtAnyCost\Parser\DocParser;
use TextAtAnyCost\Parser\PdfParser;
use TextAtAnyCost\Parser\PptParser;
use TextAtAnyCost\Parser\RtfParser;
use TextAtAnyCost\Parser\ZippedXmlParser;

$text = (new DocParser())->extractText('report.doc');
$text = (new PdfParser())->extractText('report.pdf');
$text = (new PptParser())->extractText('slides.ppt');
$text = (new RtfParser())->extractText('memo.rtf');
$text = (new ZippedXmlParser())->extractDocx('report.docx');
$text = (new ZippedXmlParser())->extractOdt('report.odt');
```

### RTF from a string

```php
use TextAtAnyCost\Parser\RtfParser;

$text = (new RtfParser())->parseString($rtfString);
```

### RAR archives

```php
use TextAtAnyCost\Archive\RarReader;
use TextAtAnyCost\Archive\RarWriter;

// List files
$reader = new RarReader();
$files  = $reader->getFileList('archive.rar');
$tree   = $reader->getFileTree('archive.rar');

// Create a stored (no-compression) archive
$writer = new RarWriter();
$writer->create('output.rar');
$writer->addDirectory('docs/reports');
$writer->addFile('/var/www/report.pdf', 'docs/reports');
$writer->close();
```

### Procedural wrappers (backward-compatible)

Each parser file still exports a procedural function for drop-in compatibility:

```php
require 'vendor/autoload.php';

$text = doc2text('report.doc');
$text = pdf2text('report.pdf');
$text = ppt2text('slides.ppt');
$text = rtf2text('memo.rtf');
$text = docx2text('report.docx');
$text = odt2text('report.odt');
```

## Error handling

All parsers throw `TextAtAnyCost\Exception\ParseException` (extends `RuntimeException`)
on structural or I/O errors.  `TextExtractor::fromFile()` additionally throws
`\InvalidArgumentException` for unsupported extensions.

```php
use TextAtAnyCost\Exception\ParseException;
use TextAtAnyCost\TextExtractor;

try {
    $text = TextExtractor::fromFile($path);
} catch (ParseException $e) {
    // file unreadable or format invalid
} catch (\InvalidArgumentException $e) {
    // extension not supported
}
```

## Development

All development tasks run inside Docker — no local PHP installation required.

```bash
make install       # install Composer dependencies
make test          # run PHPUnit test suite
make stan          # PHPStan static analysis (level 8)
make cs            # check code style (PHP-CS-Fixer, dry-run)
make cs-fix        # apply code-style fixes
make lint          # PHP syntax check on all files
make test-coverage # HTML coverage report in coverage/
make shell         # interactive shell in the container
```

## Architecture

```
src/
├── Exception/
│   └── ParseException.php
├── Parser/
│   ├── CfbParser.php          # Abstract base: Windows Compound Binary File
│   ├── DocParser.php          # .doc  (extends CfbParser)
│   ├── PptParser.php          # .ppt  (extends CfbParser)
│   ├── PdfParser.php          # .pdf
│   ├── RtfParser.php          # .rtf
│   └── ZippedXmlParser.php    # .docx / .odt
├── Archive/
│   ├── RarReader.php          # RAR 4.x file listing
│   └── RarWriter.php          # RAR store-mode archive creation
└── TextExtractor.php          # Unified facade
```

## Changelog / Bug fixes

The following bugs from the original 2009 codebase were fixed during modernisation:

| File | Bug |
|------|-----|
| `stored-rar.php` | `getDateTime()`: inverted null-check always returned the current time, ignoring the provided timestamp |
| `stored-rar.php` | `getBytes()`: `strlen(0)` returns 1, not 0 — header size was off by one for zero-length fields |
| `pdf.php` | Single-quoted `'\n'`, `'\r'` etc. are literal two-character strings in PHP — text output contained backslash-n instead of actual newlines |
| `pdf.php` | `FILE_BINARY` constant does not exist in PHP; removed (the flag was silently ignored) |
| `cfb.php` | Dead code after `continue` including a debug `echo "@"` statement that would corrupt output |
| `cfb.php` | `while(...["type"] == 0) array_pop()` could loop forever on an empty array (PR #7) |
| `doc.php` | `html_entity_decode("&#x...;")` replaced with `mb_chr()` for correct multi-byte output (PR #9) |
| `zipped-xml.php` | `LIBXML_XINCLUDE` removed — it allowed XML `<xi:include>` to read arbitrary local files (XXE) |
| `zipped-xml.php` | Lossy `iconv("utf-8", "windows-1250")` conversion removed; output is now UTF-8 throughout |
| `rtf.php` | Stack underflow when `j < 0` or stack entry missing (PR #4) |

## License

BSD 3-Clause — see [LICENSE](LICENSE).
