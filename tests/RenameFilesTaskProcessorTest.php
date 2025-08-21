<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Tests;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use Exception;
use Musicarr\FileNamingPlugin\TaskProcessor\RenameFilesTaskProcessor;
use PHPUnit\Framework\TestCase;

class RenameFilesTaskProcessorTest extends TestCase
{
    private RenameFilesTaskProcessor $processor;

    protected function setUp(): void
    {
        // Create a mock processor with minimal dependencies for testing
        $this->processor = $this->createPartialMock(
            RenameFilesTaskProcessor::class,
            [] // No methods to mock for this test
        );
    }

    public function testBuildFinalPathWithValidArtistPath(): void
    {
        // Create test entities
        $artist = new Artist();
        $artist->setArtistFolderPath('/music/artist_name');

        $album = new Album();
        $album->setArtist($artist);

        $track = new Track();
        $track->setAlbum($album);

        $generatedFilename = '01 - Track Title.mp3';

        // Test the extracted method
        $result = $this->processor->buildFinalPath($track, $generatedFilename);

        $this->assertEquals('/music/artist_name/01 - Track Title.mp3', $result);
    }

    public function testBuildFinalPathWithArtistPathWithoutTrailingSlash(): void
    {
        // Create test entities
        $artist = new Artist();
        $artist->setArtistFolderPath('/music/artist_name'); // No trailing slash

        $album = new Album();
        $album->setArtist($artist);

        $track = new Track();
        $track->setAlbum($album);

        $generatedFilename = 'track.mp3';

        // Test the extracted method
        $result = $this->processor->buildFinalPath($track, $generatedFilename);

        $this->assertEquals('/music/artist_name/track.mp3', $result);
    }

    public function testBuildFinalPathThrowsExceptionWhenArtistPathIsNull(): void
    {
        // Create test entities
        $artist = new Artist();
        $artist->setArtistFolderPath(null); // No path set

        $album = new Album();
        $album->setArtist($artist);

        $track = new Track();
        $track->setAlbum($album);

        $generatedFilename = 'track.mp3';

        // Test that exception is thrown
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Artist path not available for track: ');

        $this->processor->buildFinalPath($track, $generatedFilename);
    }

    public function testBuildFinalPathThrowsExceptionWhenArtistIsNull(): void
    {
        // Create test entities
        $album = new Album();
        $album->setArtist(null); // No artist

        $track = new Track();
        $track->setAlbum($album);

        $generatedFilename = 'track.mp3';

        // Test that exception is thrown
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Artist path not available for track: ');

        $this->processor->buildFinalPath($track, $generatedFilename);
    }

    public function testBuildFinalPathThrowsExceptionWhenAlbumIsNull(): void
    {
        // Create test entities
        $track = new Track();
        $track->setAlbum(null); // No album

        $generatedFilename = 'track.mp3';

        // Test that exception is thrown
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Artist path not available for track: ');

        $this->processor->buildFinalPath($track, $generatedFilename);
    }

    public function testBuildFinalPathWithComplexFilename(): void
    {
        // Create test entities
        $artist = new Artist();
        $artist->setArtistFolderPath('/var/music/My Artist');

        $album = new Album();
        $album->setArtist($artist);

        $track = new Track();
        $track->setAlbum($album);

        $generatedFilename = '01 - My Song (Remix) [Explicit].flac';

        // Test the extracted method
        $result = $this->processor->buildFinalPath($track, $generatedFilename);

        $this->assertEquals('/var/music/My Artist/01 - My Song (Remix) [Explicit].flac', $result);
    }
}
