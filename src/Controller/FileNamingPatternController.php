<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Musicarr\FileNamingPlugin\Entity\FileNamingPattern;
use Musicarr\FileNamingPlugin\Repository\FileNamingPatternRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsController]
#[Route('/file-naming-patterns')]
class FileNamingPatternController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FileNamingPatternRepository $fileNamingPatternRepository,
        private LoggerInterface $logger,
        private TranslatorInterface $translator
    ) {
    }

    #[Route('/', name: 'file_naming_patterns_index', methods: ['GET'])]
    public function index(): Response
    {
        $patterns = $this->fileNamingPatternRepository->findAll();

        return $this->render('@FileNamingPlugin/file_naming_pattern/index.html.twig', [
            'patterns' => $patterns,
        ]);
    }

    #[Route('/new', name: 'file_naming_pattern_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $patternText = $request->request->get('pattern');
            $description = $request->request->get('description');
            $isActive = $request->request->get('isActive', false);

            if (!$name || !$patternText) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_name_pattern_required'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            // Type validation
            if (!\is_string($name)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_name'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (!\is_string($patternText)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_pattern'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (null !== $description && !\is_string($description)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_description'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (!\is_bool($isActive)) {
                $isActive = (bool) $isActive;
            }

            $filePattern = new FileNamingPattern();
            $filePattern->setName($name);
            $filePattern->setPattern($patternText);
            $filePattern->setDescription($description);
            $filePattern->setIsActive($isActive);

            $this->entityManager->persist($filePattern);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('naming_pattern.pattern_created'));

            return $this->redirectToRoute('file_naming_patterns_index');
        }

        return $this->render('@FileNamingPlugin/file_naming_pattern/new.html.twig');
    }

    #[Route('/{id}/edit', name: 'file_naming_pattern_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, FileNamingPattern $pattern): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $patternText = $request->request->get('pattern');
            $description = $request->request->get('description');
            $isActive = $request->request->get('isActive', false);

            if (!$name || !$patternText) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_name_pattern_required'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            // Type validation
            if (!\is_string($name)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_name'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (!\is_string($patternText)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_pattern'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (null !== $description && !\is_string($description)) {
                $this->addFlash('error', $this->translator->trans('naming_pattern.error_invalid_description'));

                return $this->redirectToRoute('file_naming_patterns_index');
            }

            if (!\is_bool($isActive)) {
                $isActive = (bool) $isActive;
            }

            $pattern->setName($name);
            $pattern->setPattern($patternText);
            $pattern->setDescription($description);
            $pattern->setIsActive($isActive);
            $pattern->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('naming_pattern.pattern_updated'));

            return $this->redirectToRoute('file_naming_patterns_index');
        }

        return $this->render('@FileNamingPlugin/file_naming_pattern/edit.html.twig', [
            'pattern' => $pattern,
        ]);
    }

    #[Route('/{id}/delete', name: 'file_naming_pattern_delete', methods: ['DELETE'])]
    public function delete(FileNamingPattern $pattern): JsonResponse
    {
        try {
            $this->entityManager->remove($pattern);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('naming_pattern.pattern_deleted_success'),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors de la suppression du pattern: ' . $e->getMessage());

            return $this->json(['error' => $this->translator->trans('naming_pattern.error_deleting')], 500);
        }
    }

    #[Route('/{id}/toggle', name: 'file_naming_pattern_toggle', methods: ['POST'])]
    public function toggle(FileNamingPattern $pattern): JsonResponse
    {
        try {
            $pattern->setIsActive(!$pattern->isActive());
            $pattern->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('naming_pattern.pattern_status_updated'),
                'isActive' => $pattern->isActive(),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Erreur lors du changement de statut: ' . $e->getMessage());

            return $this->json(['error' => $this->translator->trans('naming_pattern.error_updating')], 500);
        }
    }

    #[Route('/preview', name: 'file_naming_pattern_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $pattern = $request->request->get('pattern');
        $artist = $request->request->get('artist');
        $album = $request->request->get('album');
        $title = $request->request->get('title');
        $trackNumber = $request->request->get('trackNumber');
        $extension = $request->request->get('extension', 'mp3');

        if (!$pattern) {
            return $this->json(['error' => 'Pattern requis'], 400);
        }

        if (!\is_string($pattern)) {
            return $this->json(['error' => 'Pattern invalide'], 400);
        }

        try {
            $preview = $this->generatePreview($pattern, [
                'artist' => $artist,
                'album' => $album,
                'title' => $title,
                'trackNumber' => $trackNumber,
                'extension' => $extension,
            ]);

            return $this->json([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (Exception $e) {
            return $this->json(['error' => $this->translator->trans('naming_pattern.error_generating_preview')], 500);
        }
    }

    // Routes API pour le JavaScript

    #[Route('/list', name: 'file_naming_pattern_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $patterns = $this->fileNamingPatternRepository->findAll();

        $data = [];
        foreach ($patterns as $pattern) {
            $data[] = [
                'id' => $pattern->getId(),
                'name' => $pattern->getName(),
                'pattern' => $pattern->getPattern(),
                'description' => $pattern->getDescription(),
                'isActive' => $pattern->isActive(),
                'isDefault' => $pattern->isDefault(),
                'createdAt' => $pattern->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updatedAt' => $pattern->getUpdatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json($data);
    }

    #[Route('/create', name: 'file_naming_pattern_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var array{name: string, pattern: string, description?: string, isActive?: bool} $data */
        $data = json_decode($request->getContent(), true);

        if (!$data['name'] || !$data['pattern']) {
            return $this->json([
                'success' => false,
                'error' => 'Name and pattern are required',
            ], 400);
        }

        try {
            $filePattern = new FileNamingPattern();
            $filePattern->setName($data['name']);
            $filePattern->setPattern($data['pattern']);
            $filePattern->setDescription($data['description'] ?? '');
            $filePattern->setIsActive($data['isActive'] ?? true);

            $this->entityManager->persist($filePattern);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Pattern created successfully',
                'pattern' => [
                    'id' => $filePattern->getId(),
                    'name' => $filePattern->getName(),
                    'pattern' => $filePattern->getPattern(),
                ],
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error creating pattern: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => 'Error creating pattern',
            ], 500);
        }
    }

    #[Route('/{id}', name: 'file_naming_pattern_get', methods: ['GET'])]
    public function get(FileNamingPattern $pattern): JsonResponse
    {
        return $this->json([
            'id' => $pattern->getId(),
            'name' => $pattern->getName(),
            'pattern' => $pattern->getPattern(),
            'description' => $pattern->getDescription(),
            'isActive' => $pattern->isActive(),
            'isDefault' => $pattern->isDefault(),
            'createdAt' => $pattern->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $pattern->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/{id}', name: 'file_naming_pattern_update', methods: ['PUT'])]
    public function update(Request $request, FileNamingPattern $pattern): JsonResponse
    {
        /** @var array{name: string, pattern: string, description?: string, isActive?: bool} $data */
        $data = json_decode($request->getContent(), true);

        if (!$data['name'] || !$data['pattern']) {
            return $this->json([
                'success' => false,
                'error' => 'Name and pattern are required',
            ], 400);
        }

        try {
            $pattern->setName($data['name']);
            $pattern->setPattern($data['pattern']);
            $pattern->setDescription($data['description'] ?? '');
            $pattern->setIsActive($data['isActive'] ?? true);
            $pattern->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Pattern updated successfully',
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error updating pattern: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => 'Error updating pattern',
            ], 500);
        }
    }

    #[Route('/{id}/preview', name: 'file_naming_pattern_preview_id', methods: ['GET'])]
    public function previewById(FileNamingPattern $pattern): JsonResponse
    {
        try {
            // Exemples de données pour le preview
            $examples = [
                [
                    'artist' => 'The Beatles',
                    'album' => 'Abbey Road',
                    'title' => 'Come Together',
                    'trackNumber' => '01',
                    'extension' => 'mp3',
                ],
                [
                    'artist' => 'Pink Floyd',
                    'album' => 'The Dark Side of the Moon',
                    'title' => 'Time',
                    'trackNumber' => '04',
                    'extension' => 'flac',
                ],
            ];

            $previewExamples = [];
            foreach ($examples as $example) {
                $patternText = $pattern->getPattern();
                if (null === $patternText) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Pattern text is null',
                    ], 500);
                }

                $previewExamples[] = [
                    'artist' => $example['artist'],
                    'album' => $example['album'],
                    'result' => $this->generatePreview($patternText, $example),
                ];
            }

            return $this->json([
                'success' => true,
                'examples' => $previewExamples,
            ]);
        } catch (Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error generating preview',
            ], 500);
        }
    }

    #[Route('/{id}/test', name: 'file_naming_pattern_test', methods: ['GET'])]
    public function test(FileNamingPattern $pattern): JsonResponse
    {
        try {
            // Test avec des données réelles
            $testData = [
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'title' => 'Test Track',
                'trackNumber' => '01',
                'extension' => 'mp3',
            ];

            $patternText = $pattern->getPattern();
            if (null === $patternText) {
                return $this->json([
                    'success' => false,
                    'error' => 'Pattern text is null',
                ], 500);
            }

            $result = $this->generatePreview($patternText, $testData);

            return $this->json([
                'success' => true,
                'results' => [
                    [
                        'original' => 'Test Artist - Test Album - 01 - Test Track.mp3',
                        'result' => $result,
                        'status' => 'ok',
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Error testing pattern',
            ], 500);
        }
    }

    #[Route('/{id}/set-default', name: 'file_naming_pattern_set_default', methods: ['POST'])]
    public function setDefault(FileNamingPattern $pattern): JsonResponse
    {
        try {
            // Désactiver tous les autres patterns par défaut
            $patterns = $this->fileNamingPatternRepository->findAll();
            foreach ($patterns as $p) {
                $p->setIsDefault(false);
            }

            // Activer ce pattern comme défaut
            $pattern->setIsDefault(true);
            $pattern->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Default pattern updated',
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error setting default pattern: ' . $e->getMessage());

            return $this->json([
                'success' => false,
                'error' => 'Error setting default pattern',
            ], 500);
        }
    }

    #[Route('/api/list', name: 'file_naming_pattern_api_list', methods: ['GET'])]
    public function apiList(): JsonResponse
    {
        $patterns = $this->fileNamingPatternRepository->findAll();

        $data = [];
        foreach ($patterns as $pattern) {
            $data[] = [
                'id' => $pattern->getId(),
                'name' => $pattern->getName(),
                'pattern' => $pattern->getPattern(),
                'description' => $pattern->getDescription(),
                'isActive' => $pattern->isActive(),
                'createdAt' => $pattern->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updatedAt' => $pattern->getUpdatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return $this->json([
            'success' => true,
            'patterns' => $data,
        ]);
    }

    private function generatePreview(string $pattern, array $variables): string
    {
        $result = $pattern;

        // Add default medium variables if not provided
        if (!isset($variables['medium'])) {
            $variables['medium'] = 'CD 1';
        }
        if (!isset($variables['medium_short'])) {
            $variables['medium_short'] = 'CD1';
        }

        // Remplacer les variables dans le pattern
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $result = str_replace($placeholder, $value ?? '', $result);
        }

        // Nettoyer le nom de fichier
        $result = preg_replace('/[<>:"\/\\|?*]/', '_', $result);
        if (null === $result) {
            $result = $pattern; // Fallback to original pattern
        }

        $result = preg_replace('/\s+/', ' ', $result);
        if (null === $result) {
            $result = $pattern; // Fallback to original pattern
        }

        $result = mb_trim($result);
        if ('' === $result) {
            $result = $pattern; // Fallback to original pattern
        }

        return $result;
    }
}
