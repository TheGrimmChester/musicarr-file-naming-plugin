<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\TaskProcessor;

use App\Entity\Medium;
use App\Entity\Task;
use App\Entity\Track;
use App\Entity\TrackFile;
use App\File\FileSanitizer;
use App\Repository\TrackFileRepository;
use App\Task\Processor\TaskProcessorInterface;
use App\Task\Processor\TaskProcessorResult;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Musicarr\FileNamingPlugin\Entity\FileNamingPattern;
use Musicarr\FileNamingPlugin\Repository\FileNamingPatternRepository;
use Musicarr\FileNamingPlugin\Service\FileNaming;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AutoconfigureTag('app.task_processor')]
class RenameFilesTaskProcessor implements TaskProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrackFileRepository $trackFileRepository,
        private FileNamingPatternRepository $fileNamingPatternRepository,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private FileSanitizer $fileSanitizer,
        private FileNaming $fileNaming,
    ) {
    }

    public function process(Task $task): TaskProcessorResult
    {
        try {
            $metadata = $task->getMetadata() ?? [];
            $patternId = $metadata['pattern_id'] ?? null;
            $trackFileIds = $metadata['track_file_ids'] ?? $metadata['track_ids'] ?? []; // Support both old and new metadata format
            $maxRetries = $metadata['max_retries'] ?? 3;

            if (!$patternId || empty($trackFileIds)) {
                return TaskProcessorResult::failure('Missing pattern ID or track file IDs');
            }

            $this->logger->info('Processing rename files task', [
                'pattern_id' => $patternId,
                'track_file_count' => \count($trackFileIds),
            ]);

            // Get the pattern
            $pattern = $this->fileNamingPatternRepository->find($patternId);
            if (!$pattern) {
                return TaskProcessorResult::failure($this->translator->trans('message_handler.rename_files.pattern_not_found'));
            }

            // Get the track files
            $trackFiles = $this->trackFileRepository->findByIds($trackFileIds);
            if (empty($trackFiles)) {
                return TaskProcessorResult::failure($this->translator->trans('message_handler.rename_files.no_tracks_found'));
            }

            $successCount = 0;
            $failedCount = 0;
            $errors = [];
            $renamedFiles = [];

            foreach ($trackFiles as $trackFile) {
                try {
                    $result = $this->renameTrackFile($trackFile, $pattern);
                    if ($result['success']) {
                        ++$successCount;
                        $renamedFiles = array_merge($renamedFiles, $result['renamedFiles']);
                    } else {
                        ++$failedCount;
                    }
                } catch (Exception $e) {
                    ++$failedCount;
                    $errorMessage = \sprintf('TrackFile %d: %s', $trackFile->getId(), $e->getMessage());
                    $errors[] = $errorMessage;

                    $this->logger->error('Failed to rename track file', [
                        'track_file_id' => $trackFile->getId(),
                        'file_path' => $trackFile->getFilePath(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->entityManager->flush();

            $message = \sprintf(
                'File renaming completed: %d successful, %d failed',
                $successCount,
                $failedCount
            );

            // Add from|to trackfile information
            if (!empty($renamedFiles)) {
                $fileDetails = [];
                foreach ($renamedFiles as $file) {
                    $fileDetails[] = \sprintf('%s → %s', $file['from'], $file['to']);
                }
                $message .= \sprintf(' | Files: %s', implode(', ', $fileDetails));
            }

            if (!empty($errors)) {
                $message .= \sprintf(' (Errors: %s)', implode('; ', \array_slice($errors, 0, 3)));
                if (\count($errors) > 3) {
                    $message .= \sprintf(' and %d more...', \count($errors) - 3);
                }
            }

            $this->logger->info('Rename files task completed', [
                'pattern_id' => $patternId,
                'total_tracks' => \count($trackFileIds),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'errors_count' => \count($errors),
                'renamed_files_count' => \count($renamedFiles),
            ]);

            return TaskProcessorResult::success($message, [
                'patternId' => $patternId,
                'totalTracks' => \count($trackFileIds),
                'successCount' => $successCount,
                'failedCount' => $failedCount,
                'errorsCount' => \count($errors),
                'errors' => $errors,
                'renamedFiles' => $renamedFiles,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to process rename files task', [
                'pattern_id' => $task->getMetadata()['pattern_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return TaskProcessorResult::failure($e->getMessage());
        }
    }

    /**
     * Build the final path for a track file based on artist path and generated filename
     * This method is extracted for testing purposes.
     */
    public function buildFinalPath(Track $track, string $generatedFilename): string
    {
        $artist = $track->getAlbum()?->getArtist();
        if (!$artist || !$artist->getArtistFolderPath()) {
            throw new Exception('Artist path not available for track: ' . $track->getId());
        }

        $artistPath = $artist->getArtistFolderPath();

        return $artistPath . '/' . $generatedFilename;
    }

    private function renameTrackFile(TrackFile $trackFile, FileNamingPattern $pattern): ?array
    {
        $oldPath = $trackFile->getFilePath();
        $newPath = $this->fileNaming->generateFileName($trackFile->getTrack(), $pattern->getPattern(), $trackFile);

        // Créer les dossiers parents si nécessaire
        $newDir = \dirname($newPath);
        if (!is_dir($newDir)) {
            if (!mkdir($newDir, 0755, true)) {
                $errors[] = $this->translator->trans('message_handler.rename_files.cannot_create_directory', ['%dir%' => $newDir]);

                throw new Exception('Cannot create directory: ' . $newDir);
            }
        }

        if (file_exists($oldPath)) {
            if (rename($oldPath, $newPath)) {
                $trackFile->setFilePath($newPath);
                $trackFile->setNeedRename(false);
                $this->entityManager->persist($trackFile);

                $this->logger->info($this->translator->trans('message_handler.rename_files.file_renamed_successfully'), [
                    'track_id' => $trackFile->getTrack()->getId(),
                    'old_path' => basename($oldPath),
                    'new_path' => basename($newPath),
                ]);

                // Déplacer le fichier de paroles associé s'il existe
                $lyricsPath = $trackFile->getLyricsPath();
                if ($lyricsPath && file_exists($lyricsPath)) {
                    $lyricsExtension = pathinfo($lyricsPath, \PATHINFO_EXTENSION);
                    if (null === $lyricsExtension) {
                        $lyricsExtension = 'txt';
                    }

                    // Utiliser la même logique de sanitisation que pour les fichiers audio
                    $newPathFilename = pathinfo($newPath, \PATHINFO_FILENAME);
                    $newPathDirname = pathinfo($newPath, \PATHINFO_DIRNAME);
                    if (null === $newPathFilename || null === $newPathDirname) {
                        throw new Exception('Invalid path structure for lyrics file');
                    }
                    $newLyricsFileName = $newPathFilename . '.' . $lyricsExtension;
                    $newLyricsPath = $newPathDirname . '/' . $newLyricsFileName;

                    // Créer les dossiers parents pour les paroles si nécessaire
                    $newLyricsDir = \dirname($newLyricsPath);
                    if (!is_dir($newLyricsDir)) {
                        if (!mkdir($newLyricsDir, 0755, true)) {
                            throw new Exception('Cannot create directory for lyrics file: ' . $newLyricsDir);
                        }
                        if (rename($lyricsPath, $newLyricsPath)) {
                            $trackFile->setLyricsPath($newLyricsPath);
                            $this->entityManager->persist($trackFile);
                            $this->logger->info($this->translator->trans('message_handler.rename_files.lyrics_file_moved_successfully'), [
                                'track_id' => $trackFile->getTrack()->getId(),
                                'old_lyrics' => basename($lyricsPath),
                                'new_lyrics' => basename($newLyricsPath),
                            ]);
                        } else {
                            throw new Exception('Cannot move lyrics file: ' . $lyricsPath);
                        }
                    } else {
                        if (rename($lyricsPath, $newLyricsPath)) {
                            $trackFile->setLyricsPath($newLyricsPath);
                            $this->entityManager->persist($trackFile);
                            $this->logger->info($this->translator->trans('message_handler.rename_files.lyrics_file_moved_successfully'), [
                                'track_id' => $trackFile->getTrack()->getId(),
                                'old_lyrics' => basename($lyricsPath),
                                'new_lyrics' => basename($newLyricsPath),
                            ]);
                        } else {
                            throw new Exception('Cannot move lyrics file: ' . $lyricsPath);
                        }
                    }
                }
            } else {
                throw new Exception('Cannot rename file: ' . $oldPath);
            }
        } else {
            throw new Exception('Source file not found: ' . $oldPath);
        }

        return [
            'track_id' => $trackFile->getTrack()->getId(),
            'track_title' => $trackFile->getTrack()->getTitle(),
            'from' => basename($oldPath),
            'to' => basename($newPath),
            'old_path' => $oldPath,
            'new_path' => $newPath,
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        return $this->fileSanitizer->sanitizeFileName($filename);
    }

    /**
     * Get short medium name representation.
     */
    private function getShortMediumName(Medium $medium): string
    {
        $title = $medium->getTitle();
        $format = $medium->getFormat();
        $position = $medium->getPosition();

        // If medium has a specific title, use it
        if ($title) {
            return $title;
        }

        // If medium has a format, use it
        if ($format) {
            $formatLower = mb_strtolower($format);

            // Common format mappings
            $formatMap = [
                'cd' => 'CD',
                'vinyl' => 'Vinyl',
                'digital media' => 'Digital',
                'digital' => 'Digital',
                'cassette' => 'Cassette',
                'sacd' => 'SACD',
                'dvd' => 'DVD',
                'blu-ray' => 'Blu-ray',
            ];

            $shortFormat = $formatMap[$formatLower] ?? ucfirst($formatLower);

            // For multi-disc releases, add position number
            if ($medium->getAlbum() && $medium->getAlbum()->getMediums()->count() > 1) {
                return $shortFormat . $position;
            }

            return $shortFormat;
        }

        // Default fallback
        return 'Medium' . ($position > 1 ? $position : '');
    }

    public function getSupportedTaskTypes(): array
    {
        return [Task::TYPE_RENAME_FILES];
    }

    public function supports(Task $task): bool
    {
        return Task::TYPE_RENAME_FILES === $task->getType();
    }
}
