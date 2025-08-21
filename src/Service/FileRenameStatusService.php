<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Service;

use App\Entity\Library;
use App\Entity\Track;
use App\Entity\TrackFile;
use App\Repository\LibraryRepository;
use App\Repository\TrackFileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Musicarr\FileNamingPlugin\Repository\FileNamingPatternRepository;
use Psr\Log\LoggerInterface;

/**
 * Service to calculate and update the needRename status for track files.
 */
class FileRenameStatusService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrackFileRepository $trackFileRepository,
        private FileNamingPatternRepository $patternRepository,
        private LibraryRepository $libraryRepository,
        private FileNaming $fileNamingService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Calculate and update the needRename status for a specific track file.
     */
    public function updateFileRenameStatus(TrackFile $trackFile): bool
    {
        try {
            $track = $trackFile->getTrack();
            if (!$track) {
                $this->logger->warning('TrackFile has no associated track', [
                    'trackFileId' => $trackFile->getId(),
                ]);

                return false;
            }

            // Get the first active naming pattern
            $patterns = $this->patternRepository->findBy(['isActive' => true]);
            $defaultPattern = $patterns[0] ?? null;

            if (!$defaultPattern) {
                $this->logger->warning('No active naming pattern found for rename status calculation');

                return false;
            }

            // Check if the file already has the correct format
            $pattern = $defaultPattern->getPattern();
            if (!$pattern) {
                $this->logger->warning('Pattern is null for naming pattern', [
                    'patternId' => $defaultPattern->getId(),
                ]);

                return false;
            }
            $hasCorrectFormat = $this->hasCorrectFormat($track, $pattern, $trackFile);

            // Update the needRename field
            $trackFile->setNeedRename(!$hasCorrectFormat);

            // Persist the change
            $this->entityManager->persist($trackFile);
            $this->entityManager->flush();

            $this->logger->info('Updated file rename status', [
                'track_file_id' => $trackFile->getId(),
                'old_status' => $trackFile->isNeedRename(),
                'new_status' => $trackFile->isNeedRename(),
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Error updating file rename status', [
                'trackFileId' => $trackFile->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update rename status for all files of a track.
     */
    public function updateTrackRenameStatus(int $trackId): int
    {
        try {
            $trackFiles = $this->trackFileRepository->findBy(['track' => $trackId]);
            $updatedCount = 0;

            foreach ($trackFiles as $trackFile) {
                if ($this->updateFileRenameStatus($trackFile)) {
                    ++$updatedCount;
                }
            }

            $this->logger->info('Updated rename status for track files', [
                'trackId' => $trackId,
                'totalFiles' => \count($trackFiles),
                'updatedFiles' => $updatedCount,
            ]);

            return $updatedCount;
        } catch (Exception $e) {
            $this->logger->error('Error updating track rename status', [
                'trackId' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Update rename status for all files in the system.
     */
    public function updateAllFilesRenameStatus(): int
    {
        try {
            $this->logger->info('Starting bulk update of file rename statuses');

            $totalFiles = $this->trackFileRepository->count([]);
            $updatedCount = 0;
            $batchSize = 100;
            $offset = 0;

            while ($offset < $totalFiles) {
                $trackFiles = $this->trackFileRepository->findBy([], [], $batchSize, $offset);

                foreach ($trackFiles as $trackFile) {
                    if ($this->updateFileRenameStatus($trackFile)) {
                        ++$updatedCount;
                    }
                }

                $offset += $batchSize;

                // Clear entity manager to free memory
                $this->entityManager->clear();

                $this->logger->info('Processed batch of files', [
                    'processed' => $offset,
                    'total' => $totalFiles,
                    'updated' => $updatedCount,
                ]);
            }

            $this->logger->info('Completed bulk update of file rename statuses', [
                'totalFiles' => $totalFiles,
                'updatedFiles' => $updatedCount,
            ]);

            return $updatedCount;
        } catch (Exception $e) {
            $this->logger->error('Error during bulk update of file rename statuses', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Check if a track file has the correct format based on the naming pattern.
     */
    private function hasCorrectFormat(Track $track, string $pattern, TrackFile $file): bool
    {
        if (!$file->getFilePath()) {
            return false;
        }

        // Find the library that contains this track file
        $library = $this->findLibraryForTrackFile($file);
        if (!$library) {
            $this->logger->warning('No library found for track file', [
                'trackFileId' => $file->getId(),
                'filePath' => $file->getFilePath(),
            ]);

            return false;
        }

        $libraryPath = $library->getPath();
        if (null === $libraryPath) {
            $this->logger->warning('Library path is null for rename status calculation', [
                'libraryId' => $library->getId(),
                'libraryName' => $library->getName(),
            ]);

            return false;
        }

        $this->logger->info('Using library for path comparison', [
            'library_id' => $library->getId(),
            'library_path' => $libraryPath,
            'file_path' => $file->getFilePath(),
        ]);

        $libraryRoot = mb_rtrim($libraryPath, '/');

        // Generate the expected filename using the FileNaming service
        $expectedFileName = $this->fileNamingService->generateFileName($track, $pattern, $file);

        // Build the expected full path
        $expectedPath = $libraryRoot . '/' . $expectedFileName;

        // Get the current file path
        $currentPath = $file->getFilePath();

        $this->logger->info('Path comparison details', [
            'file_path' => $file->getFilePath(),
            'library_path' => $libraryPath,
            'expected_path' => $expectedPath,
            'current_path' => $currentPath,
            'paths_match' => ($currentPath === $expectedPath),
        ]);

        // Check if the current path matches the expected path
        if ($currentPath === $expectedPath) {
            return true;
        }

        // If the paths don't match, it could be because:
        // 1. The filename is different
        // 2. The artist folder path has changed
        // 3. The file is in a different location

        // Log the mismatch for debugging
        $this->logger->info('File path mismatch detected', [
            'file_path' => $file->getFilePath(),
            'library_path' => $libraryPath,
            'expected_path' => $expectedPath,
            'current_path' => $currentPath,
            'paths_match' => false,
        ]);

        return false;
    }

    /**
     * Find the library that contains a given track file.
     */
    private function findLibraryForTrackFile(TrackFile $file): ?Library
    {
        $filePath = $file->getFilePath();
        if (!$filePath) {
            return null;
        }

        // Get all libraries and find the one that contains this file path
        $libraries = $this->libraryRepository->findAll();

        $this->logger->info('Searching for library containing file path', [
            'file_path' => $filePath,
            'libraries_count' => \count($libraries),
        ]);

        $bestMatch = null;
        $longestMatch = 0;

        foreach ($libraries as $library) {
            $libraryPath = $library->getPath();
            if ($libraryPath && str_starts_with($filePath, $libraryPath)) {
                $matchLength = mb_strlen($libraryPath);
                if ($matchLength > $longestMatch) {
                    $longestMatch = $matchLength;
                    $bestMatch = $library;
                }
            }
        }

        if ($bestMatch) {
            $this->logger->info('Found matching library', [
                'library_id' => $bestMatch->getId(),
                'library_name' => $bestMatch->getName(),
                'library_path' => $bestMatch->getPath(),
            ]);

            return $bestMatch;
        }

        $this->logger->warning('No library found for file path, falling back to library ID 1', [
            'filePath' => $filePath,
        ]);

        // Fallback to library ID 1 if no match found (for backward compatibility)
        return $this->libraryRepository->find(1);
    }

    /**
     * Check if a file needs renaming due to artist folder path changes
     * This is useful for detecting when files need to be moved to new artist folders.
     */
    public function needsRenamingDueToPathChange(Track $track, string $pattern, TrackFile $file): bool
    {
        if (!$file->getFilePath()) {
            return false;
        }

        // Find the library that contains this track file
        $library = $this->findLibraryForTrackFile($file);
        if (!$library || !$library->getPath()) {
            return false;
        }

        $libraryRoot = mb_rtrim($library->getPath(), '/');

        // Generate the expected filename
        $expectedFileName = $this->fileNamingService->generateFileName($track, $pattern, $file);

        // Build the expected full path
        $expectedPath = $libraryRoot . '/' . $expectedFileName;

        // Get the current file path
        $currentPath = $file->getFilePath();

        // Check if the current path matches the expected path
        if ($currentPath === $expectedPath) {
            return false; // File is in the correct location with correct name
        }

        // Check if the filename part is correct (relative to library root)
        $currentRelativePath = mb_substr($currentPath, mb_strlen($libraryRoot) + 1); // +1 for the slash
        $filenameCorrect = ($currentRelativePath === $expectedFileName);

        if ($filenameCorrect) {
            // Filename is correct, but path is wrong - file needs to be moved
            $this->logger->info('File needs to be moved due to path change', [
                'track_id' => $track->getId(),
                'track_title' => $track->getTitle(),
                'current_path' => $currentPath,
                'expected_path' => $expectedPath,
                'filename_correct' => true,
            ]);

            return true;
        }

        // Filename is incorrect, so it needs renaming
        return true;
    }

    /**
     * Get detailed analysis of whether a file needs renaming.
     */
    public function getRenameAnalysis(Track $track, string $pattern, TrackFile $file): array
    {
        if (!$file->getFilePath()) {
            return [
                'needsRename' => true,
                'reason' => 'no_file_path',
                'current_path' => null,
                'expected_path' => null,
                'filename_correct' => false,
                'path_correct' => false,
            ];
        }

        // Find the library that contains this track file
        $library = $this->findLibraryForTrackFile($file);
        if (!$library || !$library->getPath()) {
            return [
                'needsRename' => true,
                'reason' => 'library_not_found',
                'current_path' => $file->getFilePath(),
                'expected_path' => null,
                'filename_correct' => false,
                'path_correct' => false,
            ];
        }

        $libraryRoot = mb_rtrim($library->getPath(), '/');

        // Generate the expected filename and path
        $expectedFileName = $this->fileNamingService->generateFileName($track, $pattern, $file);
        $expectedPath = $libraryRoot . '/' . $expectedFileName;

        // Get current information
        $currentPath = $file->getFilePath();

        // Use the same logic as hasCorrectFormat method for consistency
        $pathCorrect = ($currentPath === $expectedPath);

        // For filename correctness, we need to compare the relative part after the library root
        $currentRelativePath = mb_substr($currentPath, mb_strlen($libraryRoot) + 1); // +1 for the slash
        $filenameCorrect = ($currentRelativePath === $expectedFileName);

        if ($filenameCorrect && $pathCorrect) {
            return [
                'needsRename' => false,
                'reason' => 'file_is_correct',
                'current_path' => $currentPath,
                'expected_path' => $expectedPath,
                'filename_correct' => true,
                'path_correct' => true,
            ];
        }

        if ($filenameCorrect) {
            // Filename is correct but path is wrong
            return [
                'needsRename' => true,
                'reason' => 'path_change_needed',
                'current_path' => $currentPath,
                'expected_path' => $expectedPath,
                'filename_correct' => true,
                'path_correct' => false,
                'suggestion' => 'File needs to be moved to new location',
            ];
        }

        // If we reach here, filename is incorrect
        return [
            'needsRename' => true,
            'reason' => 'filename_change_needed',
            'current_path' => $currentPath,
            'expected_path' => $expectedPath,
            'filename_correct' => false,
            'path_correct' => $pathCorrect,
            'suggestion' => 'File needs to be renamed',
        ];
    }

    /**
     * Get count of files that need renaming.
     */
    public function getFilesNeedingRenameCount(): int
    {
        return $this->trackFileRepository->count(['needRename' => true]);
    }

    /**
     * Get count of files that don't need renaming.
     */
    public function getFilesNotNeedingRenameCount(): int
    {
        return $this->trackFileRepository->count(['needRename' => false]);
    }
}
