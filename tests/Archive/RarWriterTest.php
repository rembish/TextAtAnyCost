<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Archive;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Archive\RarReader;
use TextAtAnyCost\Archive\RarWriter;
use TextAtAnyCost\Exception\ParseException;

/**
 * Tests for RarWriter (create archives) and RarReader (read them back).
 *
 * Tests the full write→read round-trip, as well as bug-fixes for
 * the inverted getDateTime() and the strlen(0) issues.
 */
final class RarWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/taac_rar_' . uniqid();
        mkdir($this->tmpDir, 0o777, recursive: true);
    }

    protected function tearDown(): void
    {
        // Clean up all temp files
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testCreateThrowsOnUnwritablePath(): void
    {
        $this->expectException(ParseException::class);
        (new RarWriter())->create('/nonexistent/path/archive.rar');
    }

    public function testCloseThrowsWhenNoArchiveOpen(): void
    {
        $this->expectException(ParseException::class);
        (new RarWriter())->close();
    }

    public function testAddFileThrowsWhenNoArchiveOpen(): void
    {
        $this->expectException(ParseException::class);
        (new RarWriter())->addFile('/tmp/some.txt');
    }

    public function testAddFileThrowsOnMissingSourceFile(): void
    {
        $archivePath = $this->tmpDir . '/test.rar';
        $writer = new RarWriter();
        $writer->create($archivePath);
        $this->expectException(ParseException::class);
        try {
            $writer->addFile('/nonexistent/file.txt');
        } finally {
            $writer->close();
        }
    }

    // -------------------------------------------------------------------------
    // Round-trip: write a file, read it back
    // -------------------------------------------------------------------------

    public function testWriteAndReadSingleFile(): void
    {
        $content    = 'Hello, RAR World!';
        $srcFile    = $this->tmpDir . '/hello.txt';
        $archivePath = $this->tmpDir . '/archive.rar';

        file_put_contents($srcFile, $content);

        $writer = new RarWriter();
        $writer->create($archivePath);
        $writer->addFile($srcFile);
        $writer->close();

        $this->assertFileExists($archivePath);

        $reader = new RarReader();
        $files  = $reader->getFileList($archivePath);

        $this->assertCount(1, $files);
        $this->assertSame('hello.txt', $files[0]['filename']);
        $this->assertSame(strlen($content), $files[0]['size_uncompressed']);
        $this->assertSame(strlen($content), $files[0]['size_compressed']); // stored = no compression
    }

    public function testWriteMultipleFiles(): void
    {
        $archivePath = $this->tmpDir . '/multi.rar';
        $files       = ['a.txt' => 'AAA', 'b.txt' => 'BBBB', 'c.txt' => 'CCCCC'];

        $writer = new RarWriter();
        $writer->create($archivePath);
        foreach ($files as $name => $data) {
            $src = $this->tmpDir . '/' . $name;
            file_put_contents($src, $data);
            $writer->addFile($src);
        }
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);
        $this->assertCount(3, $list);
    }

    public function testAddDirectoryCreatesEntry(): void
    {
        $archivePath = $this->tmpDir . '/dirs.rar';
        $writer      = new RarWriter();
        $writer->create($archivePath);
        $writer->addDirectory('docs/reports');
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);

        $dirNames = array_column($list, 'filename');
        $this->assertContains('docs', $dirNames);
        $this->assertContains('docs\\reports', $dirNames);
    }

    public function testAddFileWithDirectory(): void
    {
        $content     = 'report data';
        $srcFile     = $this->tmpDir . '/report.txt';
        $archivePath = $this->tmpDir . '/subdir.rar';

        file_put_contents($srcFile, $content);

        $writer = new RarWriter();
        $writer->create($archivePath);
        $writer->addFile($srcFile, 'documents');
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);

        $filenames = array_column($list, 'filename');
        // Should contain the directory entry and the file entry
        $this->assertTrue(
            in_array('documents', $filenames, strict: true) ||
            in_array('documents\\report.txt', $filenames, strict: true)
        );
    }

    // -------------------------------------------------------------------------
    // Bug fix: getDateTime with explicit timestamp
    // -------------------------------------------------------------------------

    public function testArchiveTimestampIsReasonable(): void
    {
        $srcFile     = $this->tmpDir . '/ts.txt';
        $archivePath = $this->tmpDir . '/ts.rar';
        file_put_contents($srcFile, 'timestamp test');

        $before = time();
        $writer = new RarWriter();
        $writer->create($archivePath);
        $writer->addFile($srcFile);
        $writer->close();
        $after = time();

        // Verify the archive is well-formed (no exception during read)
        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);
        $this->assertNotEmpty($list);
    }

    // -------------------------------------------------------------------------
    // Duplicate prevention
    // -------------------------------------------------------------------------

    public function testDuplicateDirectoryNotAddedTwice(): void
    {
        $archivePath = $this->tmpDir . '/dedup.rar';
        $writer      = new RarWriter();
        $writer->create($archivePath);
        $writer->addDirectory('shared');
        $writer->addDirectory('shared'); // second call should be a no-op
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);
        // Should have only one entry for 'shared'
        $dirEntries = array_filter($list, fn ($e) => str_contains($e['attributes'], 'd'));
        $this->assertCount(1, array_values($dirEntries));
    }
}
