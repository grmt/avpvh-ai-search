<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves cache-busting versions for plugin assets based on file mtimes.
 */
class AssetVersion {
    /**
     * @param string $relativePath
     * @return string
     */
    public static function forRelativePath(string $relativePath): string {
        $normalizedPath = ltrim($relativePath, '/');
        $absolutePath = AVPVH_SRCH_PATH . $normalizedPath;

        if (!file_exists($absolutePath)) {
            return defined('AVPVH_SRCH_VERSION') ? (string) AVPVH_SRCH_VERSION : '1';
        }

        $mtime = filemtime($absolutePath);
        if ($mtime === false) {
            return defined('AVPVH_SRCH_VERSION') ? (string) AVPVH_SRCH_VERSION : '1';
        }

        return (string) $mtime;
    }
}
