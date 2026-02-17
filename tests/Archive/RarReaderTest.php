<?php

declare(strict_types=1);

namespace TextAtAnyCost\Tests\Archive;

use PHPUnit\Framework\TestCase;
use TextAtAnyCost\Archive\RarReader;
use TextAtAnyCost\Archive\RarWriter;
use TextAtAnyCost\Exception\ParseException;

/**
 * Tests for RarReader.
 *
 * Archives are created by RarWriter so no external fixtures are needed.
 */
final class RarReaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/taac_rar_read_' . uniqid();
        mkdir($this->tmpDir, 0o777, recursive: true);
    }

    protected function tearDown(): void
    {
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

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(ParseException::class);
        (new RarReader())->getFileList('/nonexistent/archive.rar');
    }

    public function testThrowsOnNonRarFile(): void
    {
        $tmp = $this->tmpDir . '/notrar.rar';
        file_put_contents($tmp, 'not a rar file at all');
        $this->expectException(ParseException::class);
        (new RarReader())->getFileList($tmp);
    }

    // -------------------------------------------------------------------------
    // getFileList
    // -------------------------------------------------------------------------

    public function testFileListContainsExpectedMetadata(): void
    {
        [$archive, $src] = $this->makeArchiveWithFile('data.txt', 'hello');

        $reader = new RarReader();
        $list   = $reader->getFileList($archive);

        $file = $this->findByFilename($list, 'data.txt');
        $this->assertNotNull($file, 'data.txt should be in the file list');
        $this->assertSame(5, $file['size_uncompressed']);
        $this->assertSame(5, $file['size_compressed']);
        $this->assertIsInt($file['crc']);
        $this->assertIsString($file['attributes']);
    }

    public function testFileListDirectoryHasDAttribute(): void
    {
        $archivePath = $this->tmpDir . '/dirs.rar';
        $writer      = new RarWriter();
        $writer->create($archivePath);
        $writer->addDirectory('mydir');
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);

        $entry = $this->findByFilename($list, 'mydir');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('d', $entry['attributes']);
    }

    public function testEmptyArchiveReturnsEmptyList(): void
    {
        $archivePath = $this->tmpDir . '/empty.rar';
        $writer      = new RarWriter();
        $writer->create($archivePath);
        $writer->close();

        $reader = new RarReader();
        $list   = $reader->getFileList($archivePath);
        $this->assertSame([], $list);
    }

    // -------------------------------------------------------------------------
    // getFileTree
    // -------------------------------------------------------------------------

    public function testFileTreeStructure(): void
    {
        $archivePath = $this->tmpDir . '/tree.rar';
        $src         = $this->tmpDir . '/report.txt';
        file_put_contents($src, 'report content');

        $writer = new RarWriter();
        $writer->create($archivePath);
        $writer->addFile($src, 'docs/reports');
        $writer->close();

        $reader = new RarReader();
        $tree   = $reader->getFileTree($archivePath);

        // File should be nested under /docs//reports/report.txt
        $this->assertArrayHasKey('/docs', $tree);
        $this->assertArrayHasKey('/reports', $tree['/docs']);
        $this->assertArrayHasKey('report.txt', $tree['/docs']['/reports']);
    }

    public function testFileTreeDoesNotContainDirectoryEntries(): void
    {
        $archivePath = $this->tmpDir . '/treenodirs.rar';
        $src         = $this->tmpDir . '/file.txt';
        file_put_contents($src, 'content');

        $writer = new RarWriter();
        $writer->create($archivePath);
        $writer->addFile($src, 'mydir');
        $writer->close();

        $reader = new RarReader();
        $tree   = $reader->getFileTree($archivePath);

        // The tree should not have a bare 'mydir' key — only '/mydir'
        $this->assertArrayNotHasKey('mydir', $tree);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{0: string, 1: string} [archivePath, srcFilePath] */
    private function makeArchiveWithFile(string $name, string $content): array
    {
        $src     = $this->tmpDir . '/' . $name;
        $archive = $this->tmpDir . '/archive.rar';
        file_put_contents($src, $content);

        $writer = new RarWriter();
        $writer->create($archive);
        $writer->addFile($src);
        $writer->close();

        return [$archive, $src];
    }

    /**
     * @param array<int, array{filename: string, size_compressed: int, size_uncompressed: int, crc: int, attributes: string}> $list
     * @return array{filename: string, size_compressed: int, size_uncompressed: int, crc: int, attributes: string}|null
     */
    private function findByFilename(array $list, string $name): ?array
    {
        foreach ($list as $entry) {
            if ($entry['filename'] === $name) {
                return $entry;
            }
        }
        return null;
    }
}
