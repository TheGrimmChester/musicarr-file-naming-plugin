<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Controller;

use App\Entity\Task;
use App\Entity\TrackFile;
use App\Repository\LibraryRepository;
use App\Repository\TrackFileRepository;
use App\Repository\TrackRepository;
use App\Task\TaskFactory;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Musicarr\FileNamingPlugin\Repository\FileNamingPatternRepository;
use Musicarr\FileNamingPlugin\Service\FileNaming;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
#[Route('/file-renaming')]
class FileRenamingController extends AbstractController
{
    public function __construct(
        private TrackRepository $trackRepository,
        private TrackFileRepository $trackFileRepository,
        private FileNamingPatternRepository $fileNamingPatternRepository,
        private LibraryRepository $libraryRepository,
        private TaskFactory $taskService,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private FileNaming $fileNamingService,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/api/tracks', name: 'file_renaming_api_tracks', methods: ['GET'])]
    public function apiTracks(Request $request): JsonResponse
    {
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 50);
        $search = (string) $request->query->get('search', '');
        $artistFilter = (string) $request->query->get('artist', '');
        $albumFilter = (string) $request->query->get('album', '');
        $titleFilter = (string) $request->query->get('title', '');

        // Get patterns to check for correct format
        $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);
        $defaultPattern = $patterns[0] ?? null;

        // Get filtered TrackFile entities with pagination
        $result = $this->trackFileRepository->findFilteredFilesForRenaming(
            $page,
            $limit,
            $search,
            $artistFilter,
            $albumFilter,
            $titleFilter
        );

        // Format TrackFile entities for JSON response
        $formattedFiles = [];
        /** @var TrackFile $file */
        foreach ($result['files'] as $file) {
            $track = $file->getTrack();
            if (null === $track) {
                continue;
            }

            $album = $track->getAlbum();
            $artist = $album?->getArtist();

            $formattedFiles[] = [
                'id' => $file->getId(), // This is now the TrackFile ID
                'trackId' => $track->getId(),
                'title' => $track->getTitle(),
                'trackNumber' => $track->getTrackNumber(),
                'artist' => $artist?->getName() ?: 'Unknown Artist',
                'album' => $album?->getTitle() ?: 'Unknown Album',
                'hasLyrics' => $track->hasLyrics(),
                'filePath' => $file->getFilePath(),
                'quality' => $file->getQuality(),
                'format' => $file->getFormat(),
                'needRename' => $file->isNeedRename(),
                'fileSize' => $file->getFileSize(),
                'duration' => $file->getDuration(),
                'addedAt' => $file->getAddedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'tracks' => $formattedFiles, // Keep the key as 'tracks' for backward compatibility
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'hasNext' => $result['total'] > ($page * $limit),
                'totalPages' => ceil($result['total'] / $limit),
            ],
        ]);
    }

    #[Route('/', name: 'file_renaming_index', methods: ['GET'])]
    public function index(): Response
    {
        $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);

        return $this->render('@FileNamingPlugin/file_renaming/index.html.twig', [
            'patterns' => $patterns,
        ]);
    }

    #[Route('/preview', name: 'file_renaming_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $patternId = $request->request->get('pattern_id');
        $trackFileIds = $request->request->all('track_ids'); // Keep parameter name for backward compatibility

        if (!$patternId || empty($trackFileIds)) {
            return $this->json(['error' => $this->translator->trans('file_renaming.pattern_and_tracks_required')], 400);
        }

        $pattern = $this->fileNamingPatternRepository->find($patternId);
        if (!$pattern) {
            return $this->json(['error' => $this->translator->trans('file_renaming.pattern_not_found')], 404);
        }

        // Get TrackFile entities directly
        $trackFiles = $this->trackFileRepository->findBy(['id' => $trackFileIds]);
        $previews = [];

        foreach ($trackFiles as $file) {
            $track = $file->getTrack();
            if (null === $track) {
                continue;
            }

            // Check if the file actually needs renaming based on the database field
            if (!$file->isNeedRename()) {
                continue; // Skip files that don't need renaming
            }

            $newFileName = $this->fileNamingService->generateFileName($track, $pattern->getPattern() ?? '', $file);
            $previews[] = [
                'id' => $file->getId(), // Use TrackFile ID for preview element matching
                'track_id' => $track->getId(),
                'file_id' => $file->getId(),
                'current_name' => $file->getFilePath(),
                'new_name' => basename($newFileName),
                'new_full_path' => $newFileName,
                'artist' => $track->getAlbum()?->getArtist()?->getName(),
                'album' => $track->getAlbum()?->getTitle(),
                'title' => $track->getTitle(),
                'track_number' => $track->getTrackNumber(),
                'quality' => $file->getQuality(),
                'format' => $file->getFormat(),
                'needRename' => $file->isNeedRename(),
            ];
        }

        return $this->json([
            'success' => true,
            'previews' => $previews,
        ]);
    }

    #[Route('/preview-files', name: 'file_renaming_preview_files', methods: ['POST'])]
    public function previewFiles(Request $request): JsonResponse
    {
        $patternId = $request->request->get('pattern_id');
        $filesData = $request->request->all('files');

        if (!$patternId || empty($filesData)) {
            return $this->json(['error' => $this->translator->trans('file_renaming.pattern_and_files_required')], 400);
        }

        $pattern = $this->fileNamingPatternRepository->find($patternId);
        if (!$pattern) {
            return $this->json(['error' => $this->translator->trans('file_renaming.pattern_not_found')], 404);
        }

        $previews = [];

        foreach ($filesData as $fileData) {
            /** @var array{trackId: int, trackFileId: int, filePath: string, quality: string|null, format: string|null} $fileInfo */
            $fileInfo = json_decode($fileData, true);
            if (!$fileInfo) {
                continue;
            }

            $track = $this->trackRepository->find($fileInfo['trackId']);
            if (!$track) {
                continue;
            }

            // Find the actual TrackFile in the database to check needRename status
            $actualTrackFile = null;
            foreach ($track->getFiles() as $file) {
                if ($file->getId() === $fileInfo['trackFileId']) {
                    $actualTrackFile = $file;

                    break;
                }
            }

            if (!$actualTrackFile) {
                $this->logger->warning('TrackFile not found for preview', [
                    'trackFileId' => $fileInfo['trackFileId'],
                    'trackId' => $fileInfo['trackId'],
                ]);

                continue;
            }

            // Check if the file actually needs renaming based on the database field
            if (!$actualTrackFile->isNeedRename()) {
                continue; // Skip files that don't need renaming
            }

            // Create a temporary TrackFile for filename generation
            $tempFile = new TrackFile();
            $tempFile->setFilePath($fileInfo['filePath']);
            $tempFile->setQuality($fileInfo['quality']);
            $tempFile->setFormat($fileInfo['format']);

            $newFileName = $this->fileNamingService->generateFileName($track, $pattern->getPattern() ?? '', $tempFile);

            $previews[] = [
                'trackFileId' => $fileInfo['trackFileId'],
                'new_name' => basename($newFileName),
                'new_full_path' => $newFileName,
                'current_name' => $fileInfo['filePath'],
                'quality' => $fileInfo['quality'],
                'format' => $fileInfo['format'],
                'needRename' => $actualTrackFile->isNeedRename(),
                'currentNeedRename' => $actualTrackFile->isNeedRename(),
            ];
        }

        return $this->json([
            'success' => true,
            'previews' => $previews,
            'total_files_processed' => \count($filesData),
            'files_needing_rename' => \count($previews),
            'files_skipped' => \count($filesData) - \count($previews),
        ]);
    }

    #[Route('/rename-track', name: 'file_renaming_rename_track', methods: ['POST'])]
    public function renameTrack(Request $request): JsonResponse
    {
        // Handle both JSON and form data
        $contentType = $request->headers->get('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $data = json_decode($request->getContent(), true);
            $trackIds = $data['track_ids'] ?? [];
            $patternId = $data['pattern_id'] ?? null;
        } else {
            $trackIds = $request->request->all('track_ids');
            $patternId = $request->request->get('pattern_id');
        }

        if (empty($trackIds)) {
            return $this->json(['error' => $this->translator->trans('file_renaming.tracks_required')], 400);
        }

        // Log the received data for debugging
        $this->logger->info('renameTrack called with data', [
            'contentType' => $contentType,
            'trackIds' => $trackIds,
            'patternId' => $patternId,
            'rawContent' => $request->getContent(),
        ]);

        try {
            // Convert track IDs to track file IDs
            $trackFileIds = [];
            foreach ($trackIds as $trackId) {
                // Ensure trackId is an integer
                $trackId = (int) $trackId;
                if ($trackId <= 0) {
                    $this->logger->warning('Invalid track ID received', ['trackId' => $trackId]);

                    continue;
                }

                $track = $this->trackRepository->find($trackId);
                if (!$track) {
                    $this->logger->warning('Track not found', ['trackId' => $trackId]);

                    continue;
                }

                // Get all track files that need renaming for this track
                foreach ($track->getFiles() as $file) {
                    if ($file->isNeedRename()) {
                        $trackFileIds[] = $file->getId();
                    }
                }
            }

            if (empty($trackFileIds)) {
                return $this->json([
                    'success' => false,
                    'message' => $this->translator->trans('file_renaming.no_files_need_renaming'),
                ], 400);
            }

            // Use default pattern if none specified
            if (!$patternId) {
                $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);
                $patternId = $patterns[0]?->getId();

                if (!$patternId) {
                    return $this->json([
                        'success' => false,
                        'error' => $this->translator->trans('file_renaming.no_active_pattern'),
                    ], 400);
                }
            }

            // Create task for async file renaming
            $this->taskService->createTask(
                Task::TYPE_RENAME_FILES,
                null,
                null,
                $this->translator->trans('file_renaming.rename_task_description', ['%count%' => \count($trackFileIds), '%pattern%' => $patternId]),
                [
                    'pattern_id' => (int) $patternId,
                    'track_file_ids' => $trackFileIds,
                ],
                3
            );

            $this->logger->info($this->translator->trans('file_renaming.async_rename_message_sent'), [
                'pattern_id' => $patternId,
                'track_file_count' => \count($trackFileIds),
                'track_count' => \count($trackIds),
            ]);

            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('file_renaming.async_rename_started', ['%count%' => \count($trackIds)]),
            ]);
        } catch (Exception $e) {
            $this->logger->error($this->translator->trans('file_renaming.error_sending_rename_message') . ': ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => $this->translator->trans('file_renaming.error_starting_rename') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/rename-all', name: 'file_renaming_rename_all', methods: ['POST'])]
    public function renameAll(Request $request): JsonResponse
    {
        try {
            $tracks = $this->trackRepository->findAllWithFilesAndRelations();
            $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);
            $defaultPattern = $patterns[0] ?? null;

            if (!$defaultPattern) {
                return $this->json([
                    'success' => false,
                    'error' => $this->translator->trans('default.no_active_pattern'),
                ], 400);
            }

            $trackIds = [];
            foreach ($tracks as $track) {
                // Check if any files in this track need renaming
                $hasFilesNeedingRename = false;
                foreach ($track->getFiles() as $file) {
                    if ($file->isNeedRename()) {
                        $hasFilesNeedingRename = true;

                        break;
                    }
                }

                if ($hasFilesNeedingRename) {
                    $trackIds[] = $track->getId();
                }
            }

            if (empty($trackIds)) {
                return $this->json([
                    'success' => true,
                    'message' => $this->translator->trans('default.all_files_correct_format_message'),
                ]);
            }

            // Envoyer le message asynchrone pour le renommage
            $patternId = $defaultPattern->getId();
            if (null === $patternId) {
                return $this->json([
                    'success' => false,
                    'error' => $this->translator->trans('default.invalid_pattern'),
                ], 400);
            }

            $this->taskService->createTask(
                Task::TYPE_RENAME_FILES,
                null,
                null,
                \sprintf('Rename %d files with default pattern', \count($trackIds)),
                [
                    'pattern_id' => $patternId,
                    'track_ids' => $trackIds,
                ],
                3
            );

            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('default.renaming_process_started'),
                'track_count' => \count($trackIds),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error starting renaming process: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => $this->translator->trans('default.error_starting_renaming'),
            ], 500);
        }
    }

    #[Route('/preview-renaming', name: 'file_renaming_preview_renaming', methods: ['POST'])]
    public function previewRenaming(): JsonResponse
    {
        try {
            $tracks = $this->trackRepository->findAllWithFilesAndRelations();
            $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);
            $defaultPattern = $patterns[0] ?? null;

            if (!$defaultPattern) {
                return $this->json([
                    'success' => false,
                    'error' => $this->translator->trans('default.no_active_pattern'),
                ], 400);
            }

            $preview = [];
            foreach ($tracks as $track) {
                // Check if any files in this track need renaming
                $hasFilesNeedingRename = false;
                foreach ($track->getFiles() as $file) {
                    if ($file->isNeedRename()) {
                        $hasFilesNeedingRename = true;

                        break;
                    }
                }

                if (!$hasFilesNeedingRename) {
                    continue; // Skip tracks where no files need renaming
                }

                $artistName = $track->getAlbum()?->getArtist()?->getName() ?? $this->translator->trans('default.unknown_artist');
                $albumTitle = $track->getAlbum()?->getTitle() ?? $this->translator->trans('default.unknown_album');

                $preview[] = [
                    'currentPath' => $track->getFiles()->count() . ' files',
                    'newPath' => $this->fileNamingService->generateFileName($track, $defaultPattern->getPattern() ?? ''),
                    'status' => 'ok',
                ];
            }

            return $this->json([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error generating preview: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => 'Error generating preview',
            ], 500);
        }
    }

    #[Route('/rename-with-default', name: 'file_renaming_rename_with_default', methods: ['POST'])]
    public function renameWithDefault(Request $request): JsonResponse
    {
        try {
            $tracks = $this->trackRepository->findAllWithFilesAndRelations();
            $patterns = $this->fileNamingPatternRepository->findBy(['isActive' => true]);
            $defaultPattern = $patterns[0] ?? null;

            if (!$defaultPattern) {
                return $this->json([
                    'success' => false,
                    'error' => 'No active pattern found',
                ], 400);
            }

            $trackIds = [];
            foreach ($tracks as $track) {
                // Check if any files in this track need renaming
                $hasFilesNeedingRename = false;
                foreach ($track->getFiles() as $file) {
                    if ($file->isNeedRename()) {
                        $hasFilesNeedingRename = true;

                        break;
                    }
                }

                if ($hasFilesNeedingRename) {
                    $trackIds[] = $track->getId();
                }
            }

            if (empty($trackIds)) {
                return $this->json([
                    'success' => true,
                    'message' => 'All files already have correct format',
                ]);
            }

            // Envoyer le message asynchrone pour le renommage
            $patternId = $defaultPattern->getId();
            if (null === $patternId) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid pattern',
                ], 400);
            }

            $this->taskService->createTask(
                Task::TYPE_RENAME_FILES,
                null,
                null,
                \sprintf('Rename %d album files with default pattern', \count($trackIds)),
                [
                    'pattern_id' => $patternId,
                    'track_ids' => $trackIds,
                ],
                3
            );

            return $this->json([
                'success' => true,
                'message' => 'Renaming applied successfully',
                'track_count' => \count($trackIds),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error applying renaming: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => 'Error applying renaming',
            ], 500);
        }
    }
}
