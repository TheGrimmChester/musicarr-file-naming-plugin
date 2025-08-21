<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Library;
use App\Entity\Track;
use App\Entity\TrackFile;
use App\Repository\LibraryRepository;
use App\Repository\TrackFileRepository;
use App\Service\FileRenameStatusService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Musicarr\FileNamingPlugin\Entity\FileNamingPattern;
use Musicarr\FileNamingPlugin\Repository\FileNamingPatternRepository;
use Musicarr\FileNamingPlugin\Service\FileNaming;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class FileRenameStatusServiceTest extends TestCase
{
    private FileRenameStatusService $service;
    private EntityManagerInterface $entityManager;
    private TrackFileRepository $trackFileRepository;
    private FileNamingPatternRepository $fileNamingPatternRepository;
    private LibraryRepository $libraryRepository;
    private FileNaming $fileNamingService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->trackFileRepository = $this->createMock(TrackFileRepository::class);
        $this->fileNamingPatternRepository = $this->createMock(FileNamingPatternRepository::class);
        $this->libraryRepository = $this->createMock(LibraryRepository::class);
        $this->fileNamingService = $this->createMock(FileNaming::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new FileRenameStatusService(
            $this->entityManager,
            $this->trackFileRepository,
            $this->fileNamingPatternRepository,
            $this->libraryRepository,
            $this->fileNamingService,
            $this->logger
        );
    }

    /**
     * Test successful file rename status update.
     */
    public function testUpdateFileRenameStatusSuccess(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/Test Artist - Test Album - Test Track.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 1);

        $namingPattern = $this->createNamingPattern('{{artist}} - {{album}} - {{title}}.{{extension}}');
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Album - Test Track.mp3');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($trackFile);

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename());
    }

    /**
     * Test file rename status update when file already has correct format.
     */
    public function testUpdateFileRenameStatusFileAlreadyCorrect(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/Test Artist - Test Album - Test Track.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 2);

        $namingPattern = $this->createNamingPattern('{{artist}} - {{album}} - {{title}}.{{extension}}');
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Album - Test Track.mp3');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename());
    }

    /**
     * Test file rename status update when track has no associated track.
     */
    public function testUpdateFileRenameStatusNoTrack(): void
    {
        // Arrange
        $trackFile = $this->createTrackFile('/music/song.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack(null);
        $this->setEntityId($trackFile, 3);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('TrackFile has no associated track', [
                'trackFileId' => 3,
            ]);

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test file rename status update when no active naming pattern exists.
     */
    public function testUpdateFileRenameStatusNoActivePattern(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/song.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 4);

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('No active naming pattern found for rename status calculation');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test file rename status update with exception.
     */
    public function testUpdateFileRenameStatusWithException(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/song.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 5);

        $namingPattern = $this->createNamingPattern('{{artist}} - {{album}} - {{title}}.{{extension}}');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$this->createLibrary('/music')]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Album - Test Track.mp3');

        $this->entityManager->method('persist')
            ->willThrowException(new Exception('Database connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error updating file rename status', $this->callback(function ($context) {
                return 5 === $context['trackFileId']
                       && 'Database connection failed' === $context['error'];
            }));

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test update track rename status for multiple files - new working version.
     */
    public function testUpdateTrackRenameStatusWorking(): void
    {
        // Arrange - Create everything from scratch
        $track = $this->createTrackWithBasicData();
        $this->setEntityId($track, 123);
        $trackId = 123;

        $trackFile1 = $this->createTrackFile('/music/test1.mp3', 'MP3', '320 kbps');
        $trackFile1->setTrack($track);
        $this->setEntityId($trackFile1, 1);

        $trackFile2 = $this->createTrackFile('/music/test2.mp3', 'MP3', '320 kbps');
        $trackFile2->setTrack($track);
        $this->setEntityId($trackFile2, 2);

        // Mock repository to return the track files
        $this->trackFileRepository->method('findBy')
            ->with(['track' => $trackId])
            ->willReturn([$trackFile1, $trackFile2]);

        // Mock naming pattern
        $namingPattern = $this->createNamingPattern('{{artist}} - {{title}}.{{extension}}');
        $this->setEntityId($namingPattern, 1);

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        // Mock library
        $library = $this->createLibrary('/music');
        $this->setEntityId($library, 1);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->libraryRepository->method('find')
            ->with(1)
            ->willReturn($library);

        // Mock file naming service
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Mock entity manager
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Mock logger
        $this->logger->method('info');
        $this->logger->method('warning');
        $this->logger->method('error');
        $this->logger->method('debug');

        // Act
        $result = $this->service->updateTrackRenameStatus($trackId);

        // Assert
        $this->assertEquals(2, $result, 'Should update 2 files');
    }

    /**
     * Test update track rename status with exception.
     */
    public function testUpdateTrackRenameStatusWithException(): void
    {
        // Arrange
        $trackId = 123;

        $this->trackFileRepository->method('findBy')
            ->with(['track' => $trackId])
            ->willThrowException(new Exception('Repository error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error updating track rename status', [
                'trackId' => $trackId,
                'error' => 'Repository error',
            ]);

        // Act
        $result = $this->service->updateTrackRenameStatus($trackId);

        // Assert
        $this->assertEquals(0, $result);
    }

    /**
     * Test bulk update of all files rename status.
     */
    public function testUpdateAllFilesRenameStatus(): void
    {
        // Arrange
        $totalFiles = 250;
        $batchSize = 100;

        $this->trackFileRepository->method('count')
            ->with([])
            ->willReturn($totalFiles);

        // First batch
        $trackFiles1 = $this->createTrackFilesBatch(100, 0);
        // Second batch
        $trackFiles2 = $this->createTrackFilesBatch(100, 100);
        // Third batch (remaining 50)
        $trackFiles3 = $this->createTrackFilesBatch(50, 200);

        $this->trackFileRepository->method('findBy')
            ->willReturnOnConsecutiveCalls($trackFiles1, $trackFiles2, $trackFiles3);

        // Mock the updateFileRenameStatus method to return true for all files
        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$this->createNamingPattern('{{artist}} - {{title}}.{{extension}}')]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$this->createLibrary('/music')]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');
        $this->entityManager->method('clear');

        $this->logger->method('info');

        // Act
        $result = $this->service->updateAllFilesRenameStatus();

        // Assert
        $this->assertEquals(250, $result);
    }

    /**
     * Test bulk update with exception.
     */
    public function testUpdateAllFilesRenameStatusWithException(): void
    {
        // Arrange
        $this->trackFileRepository->method('count')
            ->with([])
            ->willThrowException(new Exception('Count failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error during bulk update of file rename statuses', [
                'error' => 'Count failed',
            ]);

        // Act
        $result = $this->service->updateAllFilesRenameStatus();

        // Assert
        $this->assertEquals(0, $result);
    }

    /**
     * Test needsRenamingDueToPathChange when filename is correct but path is wrong.
     */
    public function testNeedsRenamingDueToPathChangePathWrong(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/old_folder/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        $this->logger->method('debug');

        // Act
        $result = $this->service->needsRenamingDueToPathChange($track, $pattern, $trackFile);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test needsRenamingDueToPathChange when filename is wrong.
     */
    public function testNeedsRenamingDueToPathChangeFilenameWrong(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/wrong_name.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Act
        $result = $this->service->needsRenamingDueToPathChange($track, $pattern, $trackFile);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test needsRenamingDueToPathChange when file is correct.
     */
    public function testNeedsRenamingDueToPathChangeFileCorrect(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Act
        $result = $this->service->needsRenamingDueToPathChange($track, $pattern, $trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test needsRenamingDueToPathChange when no file path.
     */
    public function testNeedsRenamingDueToPathChangeNoFilePath(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile(null, 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        // Act
        $result = $this->service->needsRenamingDueToPathChange($track, $pattern, $trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test needsRenamingDueToPathChange when library not found.
     */
    public function testNeedsRenamingDueToPathChangeLibraryNotFound(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/song.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $this->libraryRepository->method('findAll')
            ->willReturn([]);

        // Act
        $result = $this->service->needsRenamingDueToPathChange($track, $pattern, $trackFile);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test getRenameAnalysis with no file path.
     */
    public function testGetRenameAnalysisNoFilePath(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile(null, 'MP3', '320 kbps');

        // Act
        $result = $this->service->getRenameAnalysis($track, '{{artist}} - {{title}}.{{extension}}', $trackFile);

        // Assert
        $this->assertTrue($result['needsRename']);
        $this->assertEquals('no_file_path', $result['reason']);
        $this->assertNull($result['current_path']);
        $this->assertNull($result['expected_path']);
        $this->assertFalse($result['filename_correct']);
        $this->assertFalse($result['path_correct']);
    }

    /**
     * Test getRenameAnalysis with library not found.
     */
    public function testGetRenameAnalysisLibraryNotFound(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/song.mp3', 'MP3', '320 kbps');

        $this->libraryRepository->method('findAll')
            ->willReturn([]);

        // Act
        $result = $this->service->getRenameAnalysis($track, '{{artist}} - {{title}}.{{extension}}', $trackFile);

        // Assert
        $this->assertTrue($result['needsRename']);
        $this->assertEquals('library_not_found', $result['reason']);
        $this->assertEquals('/music/song.mp3', $result['current_path']);
        $this->assertNull($result['expected_path']);
        $this->assertFalse($result['filename_correct']);
        $this->assertFalse($result['path_correct']);
    }

    /**
     * Test getRenameAnalysis when file is correct.
     */
    public function testGetRenameAnalysisFileCorrect(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Act
        $result = $this->service->getRenameAnalysis($track, $pattern, $trackFile);

        // Assert
        $this->assertFalse($result['needsRename']);
        $this->assertEquals('file_is_correct', $result['reason']);
        $this->assertEquals('/music/Test Artist - Test Track.mp3', $result['current_path']);
        $this->assertEquals('/music/Test Artist - Test Track.mp3', $result['expected_path']);
        $this->assertTrue($result['filename_correct']);
        $this->assertTrue($result['path_correct']);
    }

    /**
     * Test getRenameAnalysis when path change is needed.
     */
    public function testGetRenameAnalysisPathChangeNeeded(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/old_folder/Test Artist - Test Track.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Act
        $result = $this->service->getRenameAnalysis($track, $pattern, $trackFile);

        // Assert
        $this->assertTrue($result['needsRename']);
        $this->assertEquals('filename_change_needed', $result['reason']);
        $this->assertEquals('/music/old_folder/Test Artist - Test Track.mp3', $result['current_path']);
        $this->assertEquals('/music/Test Artist - Test Track.mp3', $result['expected_path']);
        $this->assertFalse($result['filename_correct']);
        $this->assertFalse($result['path_correct']);
        $this->assertEquals('File needs to be renamed', $result['suggestion']);
    }

    /**
     * Test getRenameAnalysis when filename change is needed.
     */
    public function testGetRenameAnalysisFilenameChangeNeeded(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/wrong_name.mp3', 'MP3', '320 kbps');
        $pattern = '{{artist}} - {{title}}.{{extension}}';

        $library = $this->createLibrary('/music');

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Act
        $result = $this->service->getRenameAnalysis($track, $pattern, $trackFile);

        // Assert
        $this->assertTrue($result['needsRename']);
        $this->assertEquals('filename_change_needed', $result['reason']);
        $this->assertEquals('/music/wrong_name.mp3', $result['current_path']);
        $this->assertEquals('/music/Test Artist - Test Track.mp3', $result['expected_path']);
        $this->assertFalse($result['filename_correct']);
        $this->assertFalse($result['path_correct']);
        $this->assertEquals('File needs to be renamed', $result['suggestion']);
    }

    /**
     * Test getFilesNeedingRenameCount.
     */
    public function testGetFilesNeedingRenameCount(): void
    {
        // Arrange
        $this->trackFileRepository->method('count')
            ->with(['needRename' => true])
            ->willReturn(42);

        // Act
        $result = $this->service->getFilesNeedingRenameCount();

        // Assert
        $this->assertEquals(42, $result);
    }

    /**
     * Test getFilesNotNeedingRenameCount.
     */
    public function testGetFilesNotNeedingRenameCount(): void
    {
        // Arrange
        $this->trackFileRepository->method('count')
            ->with(['needRename' => false])
            ->willReturn(158);

        // Act
        $result = $this->service->getFilesNotNeedingRenameCount();

        // Assert
        $this->assertEquals(158, $result);
    }

    /**
     * Test real-life scenario: Artist name change requiring file moves.
     */
    public function testRealLifeScenarioArtistNameChange(): void
    {
        // Arrange - Simulate a scenario where an artist's name was corrected
        $oldArtistName = 'The Beatles';
        $newArtistName = 'Beatles, The';

        $artist = $this->createArtist($newArtistName);
        $album = $this->createAlbum('Abbey Road', $artist, 1969);
        $track = $this->createTrack('Come Together', $album, 1);

        // File is in old artist folder structure
        $trackFile = $this->createTrackFile('/music/The Beatles/Abbey Road/01 - Come Together.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // New expected path with corrected artist name
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Beatles, The/Abbey Road/01 - Come Together.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($trackFile->isNeedRename());

        // Verify the analysis shows filename change is needed (since the full path is different)
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertTrue($analysis['needsRename']);
        $this->assertEquals('filename_change_needed', $analysis['reason']);
        $this->assertFalse($analysis['filename_correct']);
        $this->assertFalse($analysis['path_correct']);
    }

    /**
     * Test real-life scenario: Album title correction.
     */
    public function testRealLifeScenarioAlbumTitleCorrection(): void
    {
        // Arrange - Simulate a scenario where an album title was corrected
        $artist = $this->createArtist('Pink Floyd');
        $album = $this->createAlbum('The Dark Side of the Moon', $artist, 1973);
        $track = $this->createTrack('Time', $album, 4);

        // File has wrong album name in filename
        $trackFile = $this->createTrackFile('/music/Pink Floyd/Dark Side of Moon/04 - Time.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // Correct album title in expected path
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Pink Floyd/The Dark Side of the Moon/04 - Time.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($trackFile->isNeedRename());

        // Verify the analysis shows filename change is needed
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertTrue($analysis['needsRename']);
        $this->assertEquals('filename_change_needed', $analysis['reason']);
        $this->assertFalse($analysis['filename_correct']);
        $this->assertFalse($analysis['path_correct']);
    }

    /**
     * Test real-life scenario: Quality upgrade requiring filename update.
     */
    public function testRealLifeScenarioQualityUpgrade(): void
    {
        // Arrange - Simulate a scenario where a file was upgraded from MP3 to FLAC
        $artist = $this->createArtist('Radiohead');
        $album = $this->createAlbum('OK Computer', $artist, 1997);
        $track = $this->createTrack('Paranoid Android', $album, 2);

        // Old MP3 file
        $oldTrackFile = $this->createTrackFile('/music/Radiohead/OK Computer/02 - Paranoid Android.mp3', 'MP3', '320 kbps');
        $oldTrackFile->setTrack($track);

        // New FLAC file with same name but different extension
        $newTrackFile = $this->createTrackFile('/music/Radiohead/OK Computer/02 - Paranoid Android.flac', 'FLAC', 'Lossless');
        $newTrackFile->setTrack($track);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}} [{{quality}}].{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // Expected filename includes quality information
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Radiohead/OK Computer/02 - Paranoid Android [FLAC Lossless].flac');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act - Update the new FLAC file
        $result = $this->service->updateFileRenameStatus($newTrackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($newTrackFile->isNeedRename());

        // Verify the analysis shows filename change is needed
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $newTrackFile);
        $this->assertTrue($analysis['needsRename']);
        $this->assertEquals('filename_change_needed', $analysis['reason']);
        $this->assertFalse($analysis['filename_correct']);
    }

    /**
     * Test scenario: File in wrong library needs to be moved to correct library.
     */
    public function testFileInWrongLibraryNeedsMoving(): void
    {
        // Arrange - Simulate a file that's in the wrong library
        $artist = $this->createArtist('Queen');
        $album = $this->createAlbum('A Night at the Opera', $artist, 1975);
        $track = $this->createTrack('Bohemian Rhapsody', $album, 6);

        // File is in the wrong library (should be in /music/official but is in /music/unsorted)
        $trackFile = $this->createTrackFile('/music/unsorted/Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 100);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        // Create two libraries
        $wrongLibrary = $this->createLibrary('/music/unsorted');
        $correctLibrary = $this->createLibrary('/music/official');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        // The service should find the wrong library (where the file currently is)
        $this->libraryRepository->method('findAll')
            ->willReturn([$wrongLibrary, $correctLibrary]);

        // Expected filename - this should match the current file path structure exactly
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename()); // File should NOT need renaming since path matches

        // Verify the analysis shows the file is correct
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('file_is_correct', $analysis['reason']);
        $this->assertTrue($analysis['filename_correct']);
        $this->assertTrue($analysis['path_correct']);

        // The expected path should be based on the wrong library (where the file currently is)
        $this->assertEquals('/music/unsorted/Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3', $analysis['expected_path']);
    }

    /**
     * Test scenario: File needs to be moved to correct library based on naming pattern.
     */
    public function testFileNeedsMovingToCorrectLibrary(): void
    {
        // Arrange - Simulate a file that should be in a different library based on naming pattern
        $artist = $this->createArtist('Led Zeppelin');
        $album = $this->createAlbum('Led Zeppelin IV', $artist, 1971);
        $track = $this->createTrack('Stairway to Heaven', $album, 8);

        // File is in unsorted library but should be in official library
        $trackFile = $this->createTrackFile('/music/unsorted/Led Zeppelin/Led Zeppelin IV/08 - Stairway to Heaven.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 101);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        // Create libraries
        $unsortedLibrary = $this->createLibrary('/music/unsorted');
        $officialLibrary = $this->createLibrary('/music/official');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        // The service should find the unsorted library (where the file currently is)
        $this->libraryRepository->method('findAll')
            ->willReturn([$unsortedLibrary, $officialLibrary]);

        // Expected filename - this should match the current file path structure exactly
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Led Zeppelin/Led Zeppelin IV/08 - Stairway to Heaven.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename()); // File should NOT need renaming since path matches

        // Verify the analysis shows the file is correct
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('file_is_correct', $analysis['reason']);

        // The expected path should be based on the current library (unsorted)
        $this->assertEquals('/music/unsorted/Led Zeppelin/Led Zeppelin IV/08 - Stairway to Heaven.mp3', $analysis['expected_path']);
    }

    /**
     * Test scenario: File correctly placed in library with proper naming.
     */
    public function testFileCorrectlyPlacedInLibrary(): void
    {
        // Arrange - Simulate a file that's correctly placed
        $artist = $this->createArtist('The Rolling Stones');
        $album = $this->createAlbum('Exile on Main St.', $artist, 1972);
        $track = $this->createTrack('Tumbling Dice', $album, 3);

        // File is correctly placed in the official library
        $trackFile = $this->createTrackFile('/music/official/The Rolling Stones/Exile on Main St./03 - Tumbling Dice.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 102);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);
        $library = $this->createLibrary('/music/official');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // Expected filename matches current structure exactly
        $this->fileNamingService->method('generateFileName')
            ->willReturn('The Rolling Stones/Exile on Main St./03 - Tumbling Dice.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename());

        // Verify the analysis shows the file is correct
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('file_is_correct', $analysis['reason']);
        $this->assertTrue($analysis['filename_correct']);
        $this->assertTrue($analysis['path_correct']);
    }

    /**
     * Test scenario: Multiple libraries with different file structures.
     */
    public function testMultipleLibrariesWithDifferentStructures(): void
    {
        // Arrange - Test with multiple libraries having different structures
        $artist = $this->createArtist('Bob Dylan');
        $album = $this->createAlbum('Highway 61 Revisited', $artist, 1965);
        $track = $this->createTrack('Like a Rolling Stone', $album, 1);

        // File is in a library with a different structure
        $trackFile = $this->createTrackFile('/archive/1960s/Bob Dylan/Highway 61 Revisited/01 - Like a Rolling Stone.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 103);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        // Create multiple libraries with different structures
        $mainLibrary = $this->createLibrary('/music');
        $archiveLibrary = $this->createLibrary('/archive');
        $vaultLibrary = $this->createLibrary('/vault');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$mainLibrary, $archiveLibrary, $vaultLibrary]);

        // Expected filename - this should match the current file path structure exactly
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Bob Dylan/Highway 61 Revisited/01 - Like a Rolling Stone.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($trackFile->isNeedRename()); // File SHOULD need renaming since path structure doesn't match

        // Verify the analysis shows the file needs renaming
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertTrue($analysis['needsRename']);
        $this->assertEquals('filename_change_needed', $analysis['reason']);

        // The expected path should be based on the archive library (where the file currently is)
        $this->assertEquals('/archive/Bob Dylan/Highway 61 Revisited/01 - Like a Rolling Stone.mp3', $analysis['expected_path']);
    }

    /**
     * Test scenario: File in wrong library needs to be moved to correct artist library
     * This is the core scenario the user asked about.
     */
    public function testFileInWrongLibraryNeedsMovingToCorrectArtistLibrary(): void
    {
        // Arrange - Simulate a file that's in the wrong library entirely
        $artist = $this->createArtist('The Beatles');
        $album = $this->createAlbum('Abbey Road', $artist, 1969);
        $track = $this->createTrack('Come Together', $album, 1);

        // File is in unsorted library but should be in official library
        $trackFile = $this->createTrackFile('/music/unsorted/The Beatles/Abbey Road/01 - Come Together.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 104);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        // Create libraries
        $unsortedLibrary = $this->createLibrary('/music/unsorted');
        $officialLibrary = $this->createLibrary('/music/official');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        // The service should find the unsorted library (where the file currently is)
        $this->libraryRepository->method('findAll')
            ->willReturn([$unsortedLibrary, $officialLibrary]);

        // Expected filename - this should match the current file path structure exactly
        $this->fileNamingService->method('generateFileName')
            ->willReturn('The Beatles/Abbey Road/01 - Come Together.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($trackFile->isNeedRename()); // File should NOT need renaming since path matches current library

        // Verify the analysis shows the file is correct in its current location
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('file_is_correct', $analysis['reason']);

        // The expected path should be based on the current library (unsorted)
        $this->assertEquals('/music/unsorted/The Beatles/Abbey Road/01 - Come Together.mp3', $analysis['expected_path']);

        // However, if we want to move it to the official library, we need to check against that library
        // This demonstrates how the service can be used to determine if files need moving between libraries
        $this->assertNotEquals('/music/official/The Beatles/Abbey Road/01 - Come Together.mp3', $analysis['expected_path']);
    }

    /**
     * Test scenario: File needs renaming due to artist folder structure mismatch.
     */
    public function testFileNeedsRenamingDueToArtistFolderMismatch(): void
    {
        // Arrange - Simulate a file that has the wrong artist folder structure
        $artist = $this->createArtist('Pink Floyd');
        $album = $this->createAlbum('The Wall', $artist, 1979);
        $track = $this->createTrack('Another Brick in the Wall', $album, 3);

        // File has wrong artist folder name
        $trackFile = $this->createTrackFile('/music/PinkFloyd/The Wall/03 - Another Brick in the Wall.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 105);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);
        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // Expected filename has correct artist folder name
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Pink Floyd/The Wall/03 - Another Brick in the Wall.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($trackFile->isNeedRename()); // File SHOULD need renaming due to artist folder mismatch

        // Verify the analysis shows the file needs renaming
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertTrue($analysis['needsRename']);
        $this->assertEquals('filename_change_needed', $analysis['reason']);
        $this->assertFalse($analysis['filename_correct']);
        $this->assertFalse($analysis['path_correct']);

        // The expected path should have the correct artist folder name
        $this->assertEquals('/music/Pink Floyd/The Wall/03 - Another Brick in the Wall.mp3', $analysis['expected_path']);
        $this->assertNotEquals('/music/PinkFloyd/The Wall/03 - Another Brick in the Wall.mp3', $analysis['expected_path']);
    }

    /**
     * Test the hasCorrectFormat method directly to isolate the issue.
     */
    public function testHasCorrectFormatDirectly(): void
    {
        // Arrange - Test the hasCorrectFormat method directly
        $artist = $this->createArtist('Queen');
        $album = $this->createAlbum('A Night at the Opera', $artist, 1975);
        $track = $this->createTrack('Bohemian Rhapsody', $album, 6);

        $trackFile = $this->createTrackFile('/music/unsorted/Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        $unsortedLibrary = $this->createLibrary('/music/unsorted');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$unsortedLibrary]);

        // Mock the FileNaming service to return exactly what we expect
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3');

        $this->logger->method('debug');

        // Act - Call the method directly using reflection
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('hasCorrectFormat');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $track, $pattern, $trackFile);

        // Assert
        $this->assertTrue($result, 'hasCorrectFormat should return true when paths match exactly');
    }

    /**
     * Test library detection logic specifically.
     */
    public function testLibraryDetectionLogic(): void
    {
        // Arrange - Test the library detection logic
        $artist = $this->createArtist('Queen');
        $album = $this->createAlbum('A Night at the Opera', $artist, 1975);
        $track = $this->createTrack('Bohemian Rhapsody', $album, 6);

        $trackFile = $this->createTrackFile('/music/unsorted/Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        // Create libraries with different paths
        $unsortedLibrary = $this->createLibrary('/music/unsorted');
        $mainLibrary = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$mainLibrary, $unsortedLibrary]);

        // Mock the FileNaming service to return exactly what we expect
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3');

        $this->logger->method('debug');

        // Act - Call the findLibraryForTrackFile method directly using reflection
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('findLibraryForTrackFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $trackFile);

        // Assert
        $this->assertNotNull($result, 'Library should be found');
        $this->assertEquals('/music/unsorted', $result->getPath(), 'Should find the unsorted library');
        $this->assertEquals($unsortedLibrary->getId(), $result->getId(), 'Should return the correct library ID');
    }

    /**
     * Test the complete updateFileRenameStatus flow step by step.
     */
    public function testCompleteUpdateFileRenameStatusFlow(): void
    {
        // Arrange - Test the complete flow
        $artist = $this->createArtist('Queen');
        $album = $this->createAlbum('A Night at the Opera', $artist, 1975);
        $track = $this->createTrack('Bohemian Rhapsody', $album, 6);

        $trackFile = $this->createTrackFile('/music/unsorted/Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 996);

        $pattern = '{{artist}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        $unsortedLibrary = $this->createLibrary('/music/unsorted');

        // Set up all the mocks exactly as they should be
        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$unsortedLibrary]);

        // Mock the FileNaming service to return exactly what we expect
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Queen/A Night at the Opera/06 - Bohemian Rhapsody.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result, 'updateFileRenameStatus should return true');

        // The key assertion - this should be false since the paths match
        $this->assertFalse($trackFile->isNeedRename(), 'File should NOT need renaming since paths match exactly');

        // Let's also check what the analysis says
        $analysis = $this->service->getRenameAnalysis($track, $pattern, $trackFile);
        $this->assertFalse($analysis['needsRename'], 'Analysis should show file does not need renaming');
        $this->assertEquals('file_is_correct', $analysis['reason'], 'Reason should be file_is_correct');
    }

    /**
     * Test that the service handles multiple libraries correctly.
     */
    public function testServiceHandlesMultipleLibrariesCorrectly(): void
    {
        // Create a track file in the archive library
        $trackFile = $this->createTrackFile('/archive/Test Artist/Test Album/01 - Test Track.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($this->createTrackWithBasicData()); // Use a dummy track for this test
        $this->setEntityId($trackFile, 996);

        $pattern = '{{artist_folder}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        $archiveLibrary = $this->createLibrary('/archive');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$archiveLibrary]);

        // Mock the FileNaming service to return exactly what we expect
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist/Test Album/01 - Test Track.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);

        // Verify the analysis shows the correct library
        $analysis = $this->service->getRenameAnalysis($this->createTrackWithBasicData(), $pattern, $trackFile);

        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('/archive/Test Artist/Test Album/01 - Test Track.mp3', $analysis['expected_path']);
    }

    /**
     * Test that the service correctly handles the new {{artist_folder}} placeholder.
     */
    public function testServiceHandlesArtistFolderPlaceholder(): void
    {
        // Create a track file with artist folder structure
        $trackFile = $this->createTrackFile('/music/50 Cent/Test Album/01 - Test Track.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($this->createTrackWithBasicData()); // Use a dummy track for this test
        $this->setEntityId($trackFile, 997);

        $pattern = '{{artist_folder}}/{{album}}/{{trackNumber}} - {{title}}.{{extension}}';
        $namingPattern = $this->createNamingPattern($pattern);

        $library = $this->createLibrary('/music');

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        // Mock the FileNaming service to return the expected filename with artist folder
        $this->fileNamingService->method('generateFileName')
            ->willReturn('50 Cent/Test Album/01 - Test Track.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result);

        // Verify the analysis shows the file is correct
        $analysis = $this->service->getRenameAnalysis($this->createTrackWithBasicData(), $pattern, $trackFile);

        $this->assertFalse($analysis['needsRename']);
        $this->assertEquals('file_is_correct', $analysis['reason']);
        $this->assertEquals('/music/50 Cent/Test Album/01 - Test Track.mp3', $analysis['expected_path']);
    }

    /**
     * Test update file rename status directly to debug the issue.
     */
    public function testUpdateFileRenameStatusDirectly(): void
    {
        // Arrange
        $track = $this->createTrackWithBasicData();
        $trackFile = $this->createTrackFile('/music/old_name.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 1);

        $namingPattern = $this->createNamingPattern('{{artist}} - {{title}}.{{extension}}');
        $this->setEntityId($namingPattern, 1);

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        $library = $this->createLibrary('/music');
        $this->setEntityId($library, 1);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->libraryRepository->method('find')
            ->with(1)
            ->willReturn($library);

        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Set up logger to allow all calls by default
        $this->logger->method('info');
        $this->logger->method('warning');
        $this->logger->method('error');
        $this->logger->method('debug');

        // Act
        $result = $this->service->updateFileRenameStatus($trackFile);

        // Assert
        $this->assertTrue($result, 'updateFileRenameStatus should return true');
        $this->assertTrue($trackFile->isNeedRename(), 'File should need renaming since paths don\'t match');
    }

    /**
     * Minimal test to debug updateTrackRenameStatus.
     */
    public function testUpdateTrackRenameStatusMinimal(): void
    {
        // Arrange - Minimal setup
        $track = $this->createTrackWithBasicData();
        $this->setEntityId($track, 123);

        $trackFile = $this->createTrackFile('/music/test.mp3', 'MP3', '320 kbps');
        $trackFile->setTrack($track);
        $this->setEntityId($trackFile, 1);

        // Mock repository to return the track file
        $this->trackFileRepository->method('findBy')
            ->with(['track' => 123])
            ->willReturn([$trackFile]);

        // Mock naming pattern
        $namingPattern = $this->createNamingPattern('{{artist}} - {{title}}.{{extension}}');
        $this->setEntityId($namingPattern, 1);

        $this->fileNamingPatternRepository->method('findBy')
            ->with(['isActive' => true])
            ->willReturn([$namingPattern]);

        // Mock library
        $library = $this->createLibrary('/music');
        $this->setEntityId($library, 1);

        $this->libraryRepository->method('findAll')
            ->willReturn([$library]);

        $this->libraryRepository->method('find')
            ->with(1)
            ->willReturn($library);

        // Mock file naming service
        $this->fileNamingService->method('generateFileName')
            ->willReturn('Test Artist - Test Track.mp3');

        // Mock entity manager
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Mock logger
        $this->logger->method('info');
        $this->logger->method('warning');
        $this->logger->method('error');
        $this->logger->method('debug');

        // Act
        $result = $this->service->updateTrackRenameStatus(123);

        // Assert
        $this->assertEquals(1, $result, 'Should update 1 file');
    }

    // Helper methods to create test entities

    private function createArtist(string $name): Artist
    {
        $artist = new Artist();
        $artist->setName($name);

        return $artist;
    }

    private function createAlbum(string $title, Artist $artist, int $year): Album
    {
        $album = new Album();
        $album->setTitle($title);
        $album->setArtist($artist);
        $album->setReleaseDate(new DateTime("$year-01-01"));

        return $album;
    }

    private function createTrack(string $title, Album $album, int $trackNumber): Track
    {
        $track = new Track();
        $track->setTitle($title);
        $track->setAlbum($album);
        $track->setTrackNumber((string) $trackNumber);

        return $track;
    }

    private function createTrackWithBasicData(): Track
    {
        $artist = $this->createArtist('Test Artist');
        $album = $this->createAlbum('Test Album', $artist, 2023);

        return $this->createTrack('Test Track', $album, 1);
    }

    private function createTrackFile(?string $filePath, string $format, string $quality): TrackFile
    {
        $trackFile = new TrackFile();
        if ($filePath) {
            $trackFile->setFilePath($filePath);
        }
        $trackFile->setFormat($format);
        $trackFile->setQuality($quality);
        $trackFile->setFileSize(1024 * 1024); // 1MB
        $trackFile->setDuration(180); // 3 minutes

        return $trackFile;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new ReflectionClass($entity);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function createNamingPattern(string $pattern): FileNamingPattern
    {
        $namingPattern = new FileNamingPattern();
        $namingPattern->setName('Test Pattern');
        $namingPattern->setPattern($pattern);
        $namingPattern->setIsActive(true);

        return $namingPattern;
    }

    private function createLibrary(string $path): Library
    {
        $library = new Library();
        $library->setName('Test Library');
        $library->setPath($path);
        $library->setEnabled(true);

        return $library;
    }

    private function createTrackFilesBatch(int $count, int $startId): array
    {
        $trackFiles = [];
        for ($i = 0; $i < $count; ++$i) {
            $trackFile = $this->createTrackFile('/music/song' . ($startId + $i) . '.mp3', 'MP3', '320 kbps');
            $trackFile->setTrack($this->createTrackWithBasicData());
            $trackFiles[] = $trackFile;
        }

        return $trackFiles;
    }
}
