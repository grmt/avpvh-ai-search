<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Raised when a remote store write is refused because the store belongs to another site.
 */
class StoreSiteMismatchException extends \RuntimeException {}

/**
 * Binds Gemini File Search stores to the site that created or first used them.
 *
 * The store setting and the per-post document names live in the database, so a
 * cloned site (staging copy, migration target) starts out pointing at the source
 * site's store. Without a guard, saving or deleting a post on the clone would
 * delete or replace documents in the source site's store. Writes (uploads,
 * document deletes, store deletes) are only allowed for stores bound to this site.
 */
class StoreSiteBinding {
    public const OPTION_BINDINGS = 'geweb_aisearch_gemini_store_sites';
    private const ACTION_CONFIRM = 'geweb_confirm_store_site';
    private const NONCE_CONFIRM = 'geweb_confirm_store_site';

    public static function registerHooks(): void {
        add_action('admin_notices', [self::class, 'renderMismatchNotice']);
        add_action('admin_post_' . self::ACTION_CONFIRM, [self::class, 'handleConfirm']);
    }

    /**
     * Current site identity: home URL without scheme or trailing slash.
     */
    public static function currentSite(): string {
        $home = strtolower(untrailingslashit((string) home_url()));
        return (string) preg_replace('#^https?://#', '', $home);
    }

    public static function bind(string $storeName): void {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return;
        }

        $bindings = self::getBindings();
        $bindings[$storeName] = self::currentSite();
        update_option(self::OPTION_BINDINGS, $bindings, false);
    }

    public static function getBoundSite(string $storeName): string {
        $bindings = self::getBindings();
        return (string) ($bindings[trim($storeName)] ?? '');
    }

    public static function isBoundToCurrentSite(string $storeName): bool {
        $boundSite = self::getBoundSite($storeName);
        return $boundSite !== '' && $boundSite === self::currentSite();
    }

    /**
     * Whether this site may write to the store. A store without a binding (created
     * before bindings existed) is claimed by the first site that writes to it.
     */
    public static function isWritable(string $storeName): bool {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return true;
        }

        if (self::getBoundSite($storeName) === '') {
            self::bind($storeName);
            return true;
        }

        return self::isBoundToCurrentSite($storeName);
    }

    /**
     * @throws StoreSiteMismatchException
     */
    public static function assertWritable(string $storeName): void {
        if (self::isWritable($storeName)) {
            return;
        }

        throw new StoreSiteMismatchException(sprintf(
            'Gemini store %s belongs to %s, not to this site (%s). Create a new store for this site, or confirm in the admin notice that this site replaces %s.',
            $storeName,
            self::getBoundSite($storeName),
            self::currentSite(),
            self::getBoundSite($storeName)
        ));
    }

    public static function renderMismatchNotice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $storeName = self::getConfiguredStoreName();
        if ($storeName === '' || self::getBoundSite($storeName) === '' || self::isBoundToCurrentSite($storeName)) {
            return;
        }

        $boundSite = self::getBoundSite($storeName);
        echo '<div class="notice notice-warning"><p><strong>AV-PvH AI Search:</strong> ';
        echo esc_html(sprintf(
            'The configured Gemini store belongs to %1$s, not to this site (%2$s). This site will not upload to or delete from that store. Create a new store in the plugin settings, or, if this site replaces %1$s (for example after a domain move), confirm it below.',
            $boundSite,
            self::currentSite()
        ));
        echo '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CONFIRM) . '">';
        wp_nonce_field(self::NONCE_CONFIRM);
        echo '<button type="submit" class="button">' . esc_html(sprintf('This site replaces %s: use this store here', $boundSite)) . '</button>';
        echo '</p></form></div>';
    }

    public static function handleConfirm(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Insufficient permissions'), 403);
        }
        check_admin_referer(self::NONCE_CONFIRM);

        self::bind(self::getConfiguredStoreName());
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private static function getConfiguredStoreName(): string {
        try {
            return trim(ProviderFactory::make()->getStoreData());
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @return array<string,string>
     */
    private static function getBindings(): array {
        $bindings = get_option(self::OPTION_BINDINGS, []);
        return is_array($bindings) ? $bindings : [];
    }
}
