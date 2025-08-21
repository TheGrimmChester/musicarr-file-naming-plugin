<?php

declare(strict_types=1);

namespace Musicarr\FileNamingPlugin\Service;

use App\Entity\Artist;
use App\Entity\Medium;
use App\Entity\Track;
use App\Entity\TrackFile;
use App\File\FileSanitizer;
use Exception;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

class FileNaming
{
    private TwigEnvironment $twig;

    public function __construct(
        private TranslatorInterface $translator,
        private FileSanitizer $fileSanitizer,
        ?TwigEnvironment $twig = null
    ) {
        // Create a Twig environment for template rendering if not provided
        $this->twig = $twig ?: new TwigEnvironment(new ArrayLoader());
    }

    /**
     * Generate file name based on pattern and track information.
     */
    public function generateFileName(Track $track, string $pattern, ?TrackFile $file = null): string
    {
        if (!$file instanceof TrackFile) {
            // Use the first available file if no specific file is provided
            $files = $track->getFiles();
            $file = $files->isEmpty() ? null : ($files->first() ?: null);
        }

        $variables = $this->buildTemplateVariables($track, $file);
        $fileName = $this->replaceTemplateVariables($pattern, $variables);

        return $this->sanitizeFileName($fileName, $pattern);
    }

    /**
     * Build template variables for file naming.
     */
    private function buildTemplateVariables(Track $track, ?TrackFile $preferredFile): array
    {
        $filePath = $preferredFile?->getFilePath();
        $extension = $filePath ? pathinfo($filePath, \PATHINFO_EXTENSION) : 'mp3';

        $quality = $this->processQuality($preferredFile);
        $format = $this->processFormat($preferredFile);
        $bitrateInfo = $this->extractBitrateInfo($quality, $format);
        $qualityBadge = $this->createQualityBadge($preferredFile);

        // Get medium information
        $medium = $track->getMedium();
        $mediumName = $medium ? $medium->getDisplayName() : '';
        $mediumShortName = $medium ? $this->getShortMediumName($medium) : '';

        // Get album and medium count information
        $album = $track->getAlbum();
        $mediums = $album ? $album->getMediums()->count() : 1;

        // Base variables for Twig templates (without {{ }} wrappers)
        $baseVariables = [
            'artist' => $track->getAlbum()?->getArtist()?->getName() ?: $this->translator->trans('default.unknown_artist'),
            'artist_folder' => $this->extractArtistFolderName($track->getAlbum()?->getArtist()),
            'album' => $track->getAlbum()?->getTitle() ?: $this->translator->trans('default.unknown_album'),
            'title' => $track->getTitle() ?: $this->translator->trans('default.unknown_title'),
            'trackNumber' => $this->formatTrackNumber($track->getTrackNumber()),
            'year' => $track->getAlbum()?->getReleaseDate()?->format('Y') ?: '',
            'extension' => $extension,
            'quality' => $quality,
            'quality_badge' => $qualityBadge,
            'quality_full' => $qualityBadge,
            'format' => $format,
            'quality_short' => $this->getShortQuality($quality),
            'format_short' => $this->getShortFormat($format),
            'bitrate' => $bitrateInfo['bitrate'],
            'bitrate_short' => $bitrateInfo['bitrate_short'],
            'medium' => $mediumName,
            'medium_short' => $mediumShortName,
            'mediums_count' => $mediums, // Count of mediums in the album
            // Add the track object for full access in Twig templates
            'track' => $track,
        ];

        // Add legacy {{variable}} format for backward compatibility
        $legacyVariables = [];
        foreach ($baseVariables as $key => $value) {
            // Skip the track object for legacy variables (it's only for Twig)
            if ('track' !== $key) {
                $legacyVariables['{{' . $key . '}}'] = $value;
            }
        }

        // Merge both formats
        return array_merge($baseVariables, $legacyVariables);
    }

    /**
     * Replace template variables in pattern using Twig.
     */
    private function replaceTemplateVariables(string $pattern, array $variables): string
    {
        try {
            // Check if pattern contains Twig syntax
            if ($this->containsTwigSyntax($pattern)) {
                // Use Twig template rendering for advanced patterns
                return $this->renderTwigTemplate($pattern, $variables);
            } else {
                // Use legacy simple replacement for backward compatibility
                return $this->renderLegacyTemplate($pattern, $variables);
            }
        } catch (Exception $e) {
            // Fallback to legacy rendering if Twig fails
            return $this->renderLegacyTemplate($pattern, $variables);
        }
    }

    /**
     * Check if pattern contains Twig syntax.
     */
    private function containsTwigSyntax(string $pattern): bool
    {
        return 1 === preg_match('/\{%.*?%\}|\{\{(?!\{).*?(?<!\})\}\}/', $pattern);
    }

    /**
     * Render pattern using Twig template engine.
     */
    private function renderTwigTemplate(string $pattern, array $variables): string
    {
        // Create a unique template name
        $templateName = 'file_naming_' . md5($pattern);

        // Create array loader with the pattern as template
        $loader = new ArrayLoader([$templateName => $pattern]);
        $twig = new TwigEnvironment($loader);

        return $twig->render($templateName, $variables);
    }

    /**
     * Render pattern using legacy variable replacement.
     */
    private function renderLegacyTemplate(string $pattern, array $variables): string
    {
        $fileName = $pattern;

        // Convert variables back to {{variable}} format for legacy compatibility
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $fileName = str_replace($placeholder, $value ?? '', $fileName);
        }

        return $fileName;
    }

    /**
     * Check if track has correct format based on pattern.
     */
    public function hasCorrectFormat(Track $track, string $pattern, string $libraryPath): bool
    {
        if ($track->getFiles()->isEmpty()) {
            return false;
        }

        $libraryRoot = mb_rtrim($libraryPath, '/');

        // Check if at least one file has the correct format
        foreach ($track->getFiles() as $file) {
            $expectedFileName = $this->generateFileName($track, $pattern, $file);
            $expectedPath = $libraryRoot . '/' . $expectedFileName;
            $currentPath = $file->getFilePath();

            if (null === $currentPath) {
                continue; // Skip files without paths
            }

            // If one file has the correct format, consider the track correct
            if ($currentPath === $expectedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if specific file has correct format.
     */
    public function hasCorrectFormatForFile(Track $track, string $pattern, TrackFile $file, string $libraryPath): bool
    {
        $libraryRoot = mb_rtrim($libraryPath, '/');
        $expectedFileName = $this->generateFileName($track, $pattern, $file);
        $expectedPath = $libraryRoot . '/' . $expectedFileName;
        $currentPath = $file->getFilePath();

        if (null === $currentPath) {
            return false;
        }

        return $currentPath === $expectedPath;
    }

    /**
     * Process quality information.
     */
    private function processQuality(?TrackFile $file): string
    {
        if (!$file || !$file->getQuality()) {
            return '';
        }

        $quality = $file->getQuality();
        $quality = preg_replace('/[^a-zA-Z0-9\s]/', '', $quality);
        if (null === $quality) {
            $quality = '';
        }
        $quality = preg_replace('/\s+/', ' ', $quality);
        if (null === $quality) {
            $quality = '';
        }

        return mb_trim($quality);
    }

    /**
     * Process format information.
     */
    private function processFormat(?TrackFile $file): string
    {
        if (!$file || !$file->getFormat()) {
            return '';
        }

        return mb_strtoupper($file->getFormat());
    }

    /**
     * Extract bitrate information from quality string.
     */
    private function extractBitrateInfo(string $quality, string $format): array
    {
        $bitrate = '';
        $bitrateShort = '';

        if (!$quality) {
            return ['bitrate' => $bitrate, 'bitrate_short' => $bitrateShort];
        }

        $qualityLower = mb_strtolower($quality);
        $formatLower = mb_strtolower($format);

        // For lossless formats, look for numeric bitrate first
        if ('flac' === $formatLower || 'alac' === $formatLower
            || str_contains($qualityLower, 'flac')
            || str_contains($qualityLower, 'lossless')) {
            if (preg_match('/(\d+(?:\.\d+)?)\s*kbps/i', $quality, $matches)) {
                $bitrate = $matches[1] . 'kbps';
                $bitrateShort = preg_replace('/\..*/', '', $matches[1]);
            } else {
                $bitrate = 'Lossless';
                $bitrateShort = mb_strtoupper($formatLower);
            }

            return ['bitrate' => $bitrate, 'bitrate_short' => $bitrateShort];
        }

        if (preg_match('/(\d+)\s*kbps/i', $quality, $matches)) {
            $bitrate = $matches[1] . 'kbps';
            $bitrateShort = $matches[1];
        }

        return ['bitrate' => $bitrate, 'bitrate_short' => $bitrateShort];
    }

    /**
     * Create quality badge for file naming.
     */
    private function createQualityBadge(?TrackFile $file): string
    {
        if (!$file || !$file->getQuality()) {
            return '';
        }

        $qualityBadge = $file->getQuality();
        $qualityBadge = preg_replace('/[^a-zA-Z0-9\s\-\.]/', '_', $qualityBadge);
        if (null === $qualityBadge) {
            $qualityBadge = '';
        }
        $qualityBadge = preg_replace('/\s+/', ' ', $qualityBadge);
        if (null === $qualityBadge) {
            $qualityBadge = '';
        }

        return mb_trim($qualityBadge);
    }

    /**
     * Get short quality representation.
     */
    private function getShortQuality(string $quality): string
    {
        $qualityLower = mb_strtolower((string) $quality);

        // Common quality mappings
        $qualityMap = [
            'flac' => 'FLAC',
            'lossless' => 'FLAC',
            '320' => '320',
            '256' => '256',
            '192' => '192',
            '128' => '128',
            'v0' => 'V0',
            'v2' => 'V2',
        ];

        foreach ($qualityMap as $pattern => $short) {
            if (str_contains($qualityLower, $pattern)) {
                return $short;
            }
        }

        return $quality;
    }

    /**
     * Get short format representation.
     */
    private function getShortFormat(string $format): string
    {
        $formatLower = mb_strtolower($format);

        $formatMap = [
            'mp3' => 'MP3',
            'flac' => 'FLAC',
            'alac' => 'ALAC',
            'aac' => 'AAC',
            'ogg' => 'OGG',
            'wav' => 'WAV',
        ];

        return $formatMap[$formatLower] ?? mb_strtoupper($format);
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
                return $shortFormat . ' ' . $position;
            }

            return $shortFormat;
        }

        // Default fallback
        return 'Medium' . ($position > 1 ? $position : '');
    }

    /**
     * Extract artist folder name from an artist entity.
     */
    private function extractArtistFolderName(?Artist $artist): string
    {
        if (!$artist) {
            return '';
        }

        $folderPath = $artist->getArtistFolderPath();
        if ($folderPath) {
            return $folderPath;
        }

        $name = $artist->getName();

        return $name ?? '';
    }

    /**
     * Sanitize file name for filesystem compatibility.
     */
    private function sanitizeFileName(string $fileName, string $fallbackPattern): string
    {
        return $this->fileSanitizer->sanitizeFileName($fileName, $fallbackPattern);
    }

    /**
     * Format track number for file naming.
     */
    private function formatTrackNumber(string $trackNumber): string
    {
        // Handle vinyl record track numbers (A1, B1, etc.)
        if (preg_match('/^([A-Z])(\d+)$/', $trackNumber, $matches)) {
            return $matches[1] . \sprintf('%02d', (int) $matches[2]);
        }

        // Handle standard numeric track numbers
        if (is_numeric($trackNumber)) {
            return \sprintf('%02d', (int) $trackNumber);
        }

        // Handle track numbers with separators (e.g., "1/10", "10/10")
        if (str_contains($trackNumber, '/')) {
            $parts = explode('/', $trackNumber);
            if (2 === \count($parts) && is_numeric($parts[0])) {
                return \sprintf('%02d', (int) $parts[0]);
            }
        }

        // Return as-is for other formats
        return $trackNumber;
    }
}
