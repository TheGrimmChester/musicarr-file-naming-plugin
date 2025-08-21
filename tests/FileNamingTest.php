<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Tests;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Medium;
use App\Entity\Track;
use App\Entity\TrackFile;
use App\File\FileSanitizer;
use ArrayIterator;
use DateTime;
use Doctrine\Common\Collections\Collection;
use Musicarr\FileNamingPlugin\Service\FileNaming;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FileNamingTest extends TestCase
{
    private FileNaming $fileNaming;
    private TranslatorInterface $translator;
    private FileSanitizer $fileSanitizer;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->fileSanitizer = $this->createMock(FileSanitizer::class);

        // Create a real Twig environment for testing
        $loader = new ArrayLoader();
        $twig = new Environment($loader);

        $this->fileNaming = new FileNaming($this->translator, $this->fileSanitizer, $twig);
    }

    /**
     * Test basic file name generation with simple pattern.
     */
    public function testGenerateFileNameWithSimplePattern(): void
    {
        $track = $this->createTrackWithBasicData();
        $pattern = '{{artist}} - {{album}} - {{title}}.{{extension}}';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $expected = 'Test Artist - Test Album - Test Track.mp3';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with all template variables.
     */
    public function testGenerateFileNameWithAllVariables(): void
    {
        $track = $this->createTrackWithFullData();
        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}} ({{year}}) [{{quality}}] [{{format}}] [{{bitrate}}] [{{medium}}].{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $expected = 'Test Artist/Test Album/01 - Test Track (2023) [FLAC Lossless] [FLAC] [Lossless] [CD].flac';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with missing data (fallback to translations).
     */
    public function testGenerateFileNameWithMissingData(): void
    {
        $track = $this->createTrackWithMissingData();
        $pattern = '{{artist}} - {{album}} - {{title}}.{{extension}}';

        $this->translator->method('trans')
            ->willReturnMap([
                ['default.unknown_artist', [], null, null, 'Unknown Artist'],
                ['default.unknown_album', [], null, null, 'Unknown Album'],
                ['default.unknown_title', [], null, null, 'Unknown Title'],
            ]);

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $expected = 'Unknown Artist - Unknown Album - Unknown Title.mp3';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with custom TrackFile.
     */
    public function testGenerateFileNameWithCustomTrackFile(): void
    {
        $track = $this->createTrackWithBasicData();
        $customFile = $this->createTrackFile('custom/path/song.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}} [{{quality}}].{{extension}}';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern, $customFile);

        $expected = 'Test Artist - Test Track [320 kbps].mp3';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test hasCorrectFormat with correct format.
     */
    public function testHasCorrectFormatWithCorrectFormat(): void
    {
        $track = $this->createTrackWithFiles();
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->hasCorrectFormat($track, $pattern, $libraryPath);

        $this->assertTrue($result);
    }

    /**
     * Test hasCorrectFormat with incorrect format.
     */
    public function testHasCorrectFormatWithIncorrectFormat(): void
    {
        $track = $this->createTrackWithIncorrectFiles();
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->hasCorrectFormat($track, $pattern, $libraryPath);

        $this->assertFalse($result);
    }

    /**
     * Test hasCorrectFormat with empty files collection.
     */
    public function testHasCorrectFormatWithEmptyFiles(): void
    {
        $track = $this->createTrackWithNoFiles();
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $result = $this->fileNaming->hasCorrectFormat($track, $pattern, $libraryPath);

        $this->assertFalse($result);
    }

    /**
     * Test hasCorrectFormatForFile with correct format.
     */
    public function testHasCorrectFormatForFileWithCorrectFormat(): void
    {
        $track = $this->createTrackWithBasicData();
        $file = $this->createTrackFile('/music/library/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->hasCorrectFormatForFile($track, $pattern, $file, $libraryPath);

        $this->assertTrue($result);
    }

    /**
     * Test hasCorrectFormatForFile with incorrect format.
     */
    public function testHasCorrectFormatForFileWithIncorrectFormat(): void
    {
        $track = $this->createTrackWithBasicData();
        $file = $this->createTrackFile('/music/library/wrong_name.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->hasCorrectFormatForFile($track, $pattern, $file, $libraryPath);

        $this->assertFalse($result);
    }

    /**
     * Test hasCorrectFormatForFile with null file path.
     */
    public function testHasCorrectFormatForFileWithNullFilePath(): void
    {
        $track = $this->createTrackWithBasicData();
        $file = $this->createTrackFile(null, 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';
        $libraryPath = '/music/library';

        $result = $this->fileNaming->hasCorrectFormatForFile($track, $pattern, $file, $libraryPath);

        $this->assertFalse($result);
    }

    /**
     * Test quality processing with various quality strings.
     */
    public function testQualityProcessing(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['FLAC Lossless', 'FLAC Lossless'],
            ['320 kbps', '320 kbps'],
            ['V0 (VBR)', 'V0 VBR'],
            ['FLAC (Lossless)', 'FLAC Lossless'],
            ['MP3 128 kbps', 'MP3 128 kbps'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $file = $this->createTrackFile('/test.mp3', 'MP3', $input);
            $pattern = '{{quality}}';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test format processing.
     */
    public function testFormatProcessing(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['mp3', 'MP3'],
            ['flac', 'FLAC'],
            ['alac', 'ALAC'],
            ['aac', 'AAC'],
            ['ogg', 'OGG'],
            ['wav', 'WAV'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $file = $this->createTrackFile('/test.' . $input, $input, '320 kbps');
            $pattern = '{{format}}';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test bitrate extraction for lossless formats.
     */
    public function testBitrateExtractionForLosslessFormats(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['FLAC Lossless', 'flac', 'Lossless', 'FLAC'],
            ['ALAC Lossless', 'alac', 'Lossless', 'ALAC'],
            ['FLAC 1411 kbps', 'flac', '1411kbps', '1411'],
        ];

        foreach ($testCases as [$quality, $format, $expectedBitrate, $expectedBitrateShort]) {
            $file = $this->createTrackFile('/test.flac', $format, $quality);
            $pattern = '{{bitrate}} [{{bitrate_short}}]';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $expected = $expectedBitrate . ' [' . $expectedBitrateShort . ']';
            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test bitrate extraction for lossy formats.
     */
    public function testBitrateExtractionForLossyFormats(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['320 kbps', 'mp3', '320kbps', '320'],
            ['256 kbps', 'mp3', '256kbps', '256'],
            ['128 kbps', 'mp3', '128kbps', '128'],
        ];

        foreach ($testCases as [$quality, $format, $expectedBitrate, $expectedBitrateShort]) {
            $file = $this->createTrackFile('/test.mp3', $format, $quality);
            $pattern = '{{bitrate}} [{{bitrate_short}}]';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $expected = $expectedBitrate . ' [' . $expectedBitrateShort . ']';
            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test quality badge creation.
     */
    public function testQualityBadgeCreation(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['FLAC Lossless', 'FLAC Lossless'],
            ['320 kbps', '320 kbps'],
            ['V0 (VBR)', 'V0 _VBR_'],
            ['MP3 128 kbps', 'MP3 128 kbps'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $file = $this->createTrackFile('/test.mp3', 'MP3', $input);
            $pattern = '{{quality_badge}}';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test short quality representation.
     */
    public function testShortQualityRepresentation(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['FLAC Lossless', 'FLAC'],
            ['320 kbps', '320'],
            ['256 kbps', '256'],
            ['192 kbps', '192'],
            ['128 kbps', '128'],
            ['V0 (VBR)', 'V0'],
            ['V2 (VBR)', 'V2'],
            ['Unknown Quality', 'Unknown Quality'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $file = $this->createTrackFile('/test.mp3', 'MP3', $input);
            $pattern = '{{quality_short}}';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test short format representation.
     */
    public function testShortFormatRepresentation(): void
    {
        $track = $this->createTrackWithBasicData();
        $testCases = [
            ['mp3', 'MP3'],
            ['flac', 'FLAC'],
            ['alac', 'ALAC'],
            ['aac', 'AAC'],
            ['ogg', 'OGG'],
            ['wav', 'WAV'],
            ['unknown', 'UNKNOWN'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $file = $this->createTrackFile('/test.' . $input, $input, '320 kbps');
            $pattern = '{{format_short}}';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern, $file);

            $this->assertEquals($expected, $result);
        }
    }

    /**
     * Test medium name handling.
     */
    public function testMediumNameHandling(): void
    {
        $testCases = [
            ['CD', 'CD'],
            ['Vinyl', 'Vinyl'],
            ['Digital Media', 'Digital'],
            ['Digital', 'Digital'],
            ['Cassette', 'Cassette'],
            ['SACD', 'SACD'],
            ['DVD', 'DVD'],
            ['Blu-ray', 'Blu-ray'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $track = $this->createTrackWithMedium($input);
            $pattern = '{{medium}} [{{medium_short}}]';

            $this->fileSanitizer->method('sanitizeFileName')
                ->willReturnArgument(0);

            $result = $this->fileNaming->generateFileName($track, $pattern);

            $expectedResult = $input . ' [' . $expected . ']';
            $this->assertEquals($expectedResult, $result);
        }
    }

    /**
     * Test multi-disc medium handling.
     */
    public function testMultiDiscMediumHandling(): void
    {
        $track = $this->createTrackWithMultiDiscMedium();
        $pattern = '{{medium_short}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $this->assertEquals('CD 2', $result);
    }

    /**
     * Test medium with custom title.
     */
    public function testMediumWithCustomTitle(): void
    {
        $track = $this->createTrackWithCustomMediumTitle();
        $pattern = '{{medium_short}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $this->assertEquals('Bonus Disc', $result);
    }

    /**
     * Test file sanitization integration.
     */
    public function testFileSanitizationIntegration(): void
    {
        $track = $this->createTrackWithBasicData();
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $this->translator->method('trans')
            ->with('default.unknown_artist')
            ->willReturn('Unknown Artist');

        $this->fileSanitizer->method('sanitizeFileName')
            ->with('Test Artist - Test Track.mp3', $pattern)
            ->willReturn('Test_Artist_-_Test_Track.mp3');

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $this->assertEquals('Test_Artist_-_Test_Track.mp3', $result);
    }

    /**
     * Test edge cases with empty or null values.
     */
    public function testEdgeCasesWithEmptyValues(): void
    {
        $track = $this->createTrackWithEmptyValues();
        $pattern = '{{artist}} - {{album}} - {{title}} [{{quality}}] [{{format}}] [{{bitrate}}] [{{medium}}].{{extension}}';

        $this->translator->method('trans')
            ->willReturnMap([
                ['default.unknown_artist', [], null, null, 'Unknown Artist'],
                ['default.unknown_album', [], null, null, 'Unknown Album'],
                ['default.unknown_title', [], null, null, 'Unknown Title'],
            ]);

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $expected = 'Unknown Artist - Unknown Album - Unknown Title [] [] [] [].mp3';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test complex pattern with mixed variables.
     */
    public function testComplexPatternWithMixedVariables(): void
    {
        $track = $this->createTrackWithFullData();
        $pattern = '{{year}}/{{artist}}/{{album}}/{{trackNumber}} - {{title}} [{{quality_short}}] [{{format_short}}] [{{bitrate_short}}] [{{medium_short}}].{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        $expected = '2023/Test Artist/Test Album/01 - Test Track [FLAC] [FLAC] [FLAC] [CD].flac';
        $this->assertEquals($expected, $result);
    }

    // Helper methods to create test data

    private function createTrackWithBasicData(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);

        $file = $this->createTrackFile('/test.mp3', 'MP3', '320 kbps');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithFullData(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);
        $album->method('getReleaseDate')->willReturn(new DateTime('2023-01-01'));

        $medium = $this->createMedium('CD');

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);
        $track->method('getMedium')->willReturn($medium);

        $file = $this->createTrackFile('/test.flac', 'FLAC', 'FLAC Lossless');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithMissingData(): Track
    {
        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn(null);
        $track->method('getAlbum')->willReturn(null);

        $file = $this->createTrackFile('/test.mp3', 'MP3', '320 kbps');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithFiles(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);

        $file1 = $this->createTrackFile('/music/library/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $file2 = $this->createTrackFile('/music/library/wrong_name.mp3', 'MP3', '320 kbps');

        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('toArray')->willReturn([$file1, $file2]);
        $collection->method('getIterator')->willReturn(new ArrayIterator([$file1, $file2]));

        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithIncorrectFiles(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);

        $file = $this->createTrackFile('/music/library/wrong_name.mp3', 'MP3', '320 kbps');

        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('toArray')->willReturn([$file]);
        $collection->method('getIterator')->willReturn(new ArrayIterator([$file]));

        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithNoFiles(): Track
    {
        $track = $this->createMock(Track::class);

        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(true);

        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithMedium(string $format = 'CD'): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $medium = $this->createMedium($format);

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);
        $track->method('getMedium')->willReturn($medium);

        $file = $this->createTrackFile('/test.mp3', 'MP3', '320 kbps');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithMultiDiscMedium(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $medium = $this->createMedium('CD', 2);
        $medium->method('getAlbum')->willReturn($album);

        $mediumsCollection = $this->createMock(Collection::class);
        $mediumsCollection->method('count')->willReturn(2);
        $album->method('getMediums')->willReturn($mediumsCollection);

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);
        $track->method('getMedium')->willReturn($medium);

        $file = $this->createTrackFile('/test.flac', 'FLAC', 'FLAC Lossless');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithCustomMediumTitle(): Track
    {
        $artist = $this->createMock(Artist::class);
        $artist->method('getName')->willReturn('Test Artist');

        $album = $this->createMock(Album::class);
        $album->method('getTitle')->willReturn('Test Album');
        $album->method('getArtist')->willReturn($artist);

        $medium = $this->createMedium('CD', 1, 'Bonus Disc');

        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn('Test Track');
        $track->method('getTrackNumber')->willReturn('1');
        $track->method('getAlbum')->willReturn($album);
        $track->method('getMedium')->willReturn($medium);

        $file = $this->createTrackFile('/test.mp3', 'MP3', '320 kbps');
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackWithEmptyValues(): Track
    {
        $track = $this->createMock(Track::class);
        $track->method('getTitle')->willReturn(null);
        $track->method('getAlbum')->willReturn(null);
        $track->method('getMedium')->willReturn(null);

        $file = $this->createTrackFile('/test.mp3', null, null);
        $collection = $this->createMock(Collection::class);
        $collection->method('isEmpty')->willReturn(false);
        $collection->method('first')->willReturn($file);
        $track->method('getFiles')->willReturn($collection);

        return $track;
    }

    private function createTrackFile(?string $filePath, ?string $format, ?string $quality): TrackFile
    {
        $file = $this->createMock(TrackFile::class);
        $file->method('getFilePath')->willReturn($filePath);
        $file->method('getFormat')->willReturn($format);
        $file->method('getQuality')->willReturn($quality);

        return $file;
    }

    private function createMedium(string $format, int $position = 1, ?string $title = null): Medium
    {
        $medium = $this->createMock(Medium::class);
        $medium->method('getFormat')->willReturn($format);
        $medium->method('getPosition')->willReturn($position);
        $medium->method('getTitle')->willReturn($title);
        $medium->method('getDisplayName')->willReturn($title ?: $format);

        return $medium;
    }

    /**
     * Test file name generation with Twig conditional syntax.
     */
    public function testGenerateFileNameWithTwigConditionals(): void
    {
        $track = $this->createTrackWithMultiDiscMedium();
        $pattern = '{{artist}}/{{album}}/{% if mediums_count > 1 %}{{medium_short}}/{% endif %}{{trackNumber}} - {{title}}.{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        // Should include medium folder for multi-disc albums
        $expected = 'Test Artist/Test Album/CD 2/01 - Test Track.flac';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with Twig conditional for single disc.
     */
    public function testGenerateFileNameWithTwigConditionalsSingleDisc(): void
    {
        $track = $this->createTrackWithBasicData();
        $pattern = '{{artist}}/{{album}}/{% if mediums_count > 1 %}{{medium_short}}/{% endif %}{{trackNumber}} - {{title}}.{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        // Should NOT include medium folder for single disc albums
        $expected = 'Test Artist/Test Album/01 - Test Track.mp3';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with Twig conditional for quality info.
     */
    public function testGenerateFileNameWithTwigConditionalsQuality(): void
    {
        $track = $this->createTrackWithFullData();
        $pattern = '{{artist}}/{{album}} {% if quality %}[{{quality}}]{% endif %}/{{trackNumber}} - {{title}}.{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        // Should include quality info when available
        $expected = 'Test Artist/Test Album [FLAC Lossless]/01 - Test Track.flac';
        $this->assertEquals($expected, $result);
    }

    /**
     * Test file name generation with Twig track object access.
     */
    public function testGenerateFileNameWithTwigTrackObjectAccess(): void
    {
        $track = $this->createTrackWithFullData();
        $pattern = '{% if track.album.artist %}{{track.album.artist.name}}{% else %}Unknown{% endif %}/{{track.album.title}}/{{track.title}}.{{extension}}';

        $this->fileSanitizer->method('sanitizeFileName')
            ->willReturnArgument(0);

        $result = $this->fileNaming->generateFileName($track, $pattern);

        // Should access track object properties directly
        $expected = 'Test Artist/Test Album/Test Track.flac';
        $this->assertEquals($expected, $result);
    }
}
