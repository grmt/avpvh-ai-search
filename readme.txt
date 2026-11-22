=== AV-PvH AI Search ===
Contributors: grmt
Tags: search, ai, gemini, artificial intelligence, semantic search
Requires at least: 7.1
Tested up to: 6.9
Requires PHP: 8.4
Stable tag: 3.0.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: avpvh-ai-search

Keep native WordPress search results, and add optional AI answers powered by Google Gemini.

== Description ==

AV-PvH AI Search adds an AI layer to your existing WordPress search powered by Google Gemini AI. Visitors can keep using the standard WordPress search results page from your theme, including excerpts and surrounding context, and optionally continue their search with AI for a direct, contextual answer with source links.

AV-PvH AI Search started as a fork of [Geweb AI Search](https://wordpress.org/plugins/geweb-ai-search/) by Evgen Gavrilov (GPLv2) and grew into a broader AI search workspace with chats, prompts, document handling, and source-grounded answers. It is not affiliated with or supported by the original author.

= Key Features =

* **AI-Powered Answers** — Uses Google Gemini File Search to find relevant content and generate natural-language answers
* **Conversation History** — Users can ask follow-up questions; the context is maintained across the session
* **Source Attribution** — Every AI answer includes links to the pages it was based on
* **Instant Autocomplete** — Traditional keyword search with live suggestions while typing
* **Automatic Indexing** — Posts are automatically uploaded to Gemini when published or updated
* **Bulk Library Generation** — Index all existing content with one click and a live progress indicator
* **Multiple AI Models** — Choose between Gemini 2.5 Flash, 2.5 Pro, and Gemini 3 models
* **Multiple Post Types** — Index any public post type: posts, pages, or custom post types
* **Secure API Key Storage** — API key is encrypted with libsodium before being stored in the database

= How It Works =

1. The plugin converts your WordPress posts to Markdown format (with URL and title in frontmatter)
2. Each document is uploaded to a Google Gemini File Search Store
3. When a user submits a search query, Gemini searches the indexed documents and generates an answer
4. Visitors can optionally open the AI assistant from the search form and get an answer with source links

= Third-Party Services =

This plugin connects to the **Google Gemini API** to index your content and answer user queries.

* API endpoint: https://generativelanguage.googleapis.com/
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

**Data sent to Google:**

* Your post content (title and body), converted to Markdown, is uploaded to Gemini for indexing
* User search queries are sent to Gemini to generate answers

By using this plugin you agree to Google's Terms of Service and Privacy Policy. You are responsible for the content you index.

= Requirements =

* PHP 7.2 or higher (libsodium is bundled with PHP 7.2+)
* WordPress 6.0 or higher
* Google Gemini API key (free tier available at https://aistudio.google.com/app/apikey)

== Installation ==

= Manual Installation =

1. Download the plugin ZIP file from https://github.com/grmt/avpvh-ai-search/releases
2. Go to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Activate the plugin

= Switching from Geweb AI Search =

This plugin uses the same settings, index, chats and database tables as Geweb AI Search, so nothing has to be migrated.

1. Deactivate Geweb AI Search. Do **not** click "Delete": its uninstaller removes the Gemini API key and the index settings.
2. Remove the `wp-content/plugins/geweb-ai-search` folder from the server (for example with SFTP or WP-CLI `rm`), not via the Plugins screen.
3. Install and activate AV-PvH AI Search.
4. Pages with `[geweb_ai_search]` keep working; new pages use `[avpvh_ai_search]`.

= Configuration =

1. Go to **Settings → AV-PvH AI Search**
2. Enter your Google Gemini API key — get one free at https://aistudio.google.com/app/apikey
3. Select the AI model (gemini-flash-recommended (3.7) selects gemini-3.7-flash; Google's moving gemini-flash-latest (3.5) alias currently selects Gemini 3.5 Flash)
4. Choose which post types to index
5. Click **Save Settings** — this will create a Gemini File Search Store automatically
6. Click **Generate Library** to index all existing published content
7. Your visitors can now use AI-powered search on your site

== Frequently Asked Questions ==

= Is it free? =

The plugin itself is free and open source (GPLv2). The Google Gemini API has a generous free tier — check current limits and pricing at https://ai.google.dev/pricing.

= What content is sent to Google? =

The title and body of your published posts and pages are converted to Markdown and uploaded to Google Gemini for indexing. User search queries are also sent to Gemini to generate answers. No personal user data is collected or sent.

= Does it work with WooCommerce products? =

Yes. Select "Products" in the post types settings and regenerate the library. Customers can then ask questions about your products and get AI-generated answers.

= Does it work with custom post types? =

Yes, any public post type registered in WordPress can be selected for indexing.

= What happens when I update a post? =

The plugin automatically re-indexes the post when it is saved as published. If the post is unpublished or deleted, the corresponding document is removed from Gemini.

= What happens if the API quota is exceeded? =

The AI search will return an error. The standard autocomplete search will continue to work normally since it uses WordPress's built-in search.

= Which Gemini models are supported? =

Recommended: the plugin displays gemini-3.7-flash as gemini-flash-recommended (3.7). Google's gemini-flash-latest (3.5) moving alias currently points to Gemini 3.5 Flash. Use gemini-3.5-flash-lite for high-volume, lower-cost processing. Gemini 3.6 Flash remains a stable option for everyday tasks.

== Customization ==

= How do I customize the AI behavior? =

You can modify the AI system instruction and available models:

`
// Customize AI system instruction
add_filter('geweb_aisearch_gemini_system_instruction', function($instruction) {
    return $instruction . "\nAlways respond in a friendly, conversational tone.";
});

// Limit available models in settings
add_filter('geweb_aisearch_gemini_models', function($models) {
    return ['gemini-3.7-flash', 'gemini-3.5-flash-lite', 'gemini-3.6-flash'];
});
`

Available filters:
* `geweb_aisearch_gemini_system_instruction` - Modify AI prompt/behavior
* `geweb_aisearch_gemini_models` - Customize available model list in settings

= How do I translate the interface texts? =

You can customize all search interface labels using filters in your theme's functions.php:

`
// Customize search placeholder
add_filter('geweb_aisearch_search_placeholder', function($text) {
    return 'What would you like to know?';
});

// Customize "Ask AI" button text
add_filter('geweb_aisearch_ask_ai_button_text', function($text) {
    return 'Ask Pythia';
});

// Customize AI modal title
add_filter('geweb_aisearch_ai_modal_title', function($text) {
    return 'Oracle Pythia';
});

// Customize AI textarea placeholder
add_filter('geweb_aisearch_ai_textarea_placeholder', function($text) {
    return 'Write your detailed question to Pythia';
});
`

Available filters:
* `geweb_aisearch_search_placeholder` - Main search input placeholder
* `geweb_aisearch_ask_ai_button_text` - "Ask AI" button label
* `geweb_aisearch_ai_modal_title` - AI chat modal header
* `geweb_aisearch_ai_textarea_placeholder` - AI question textarea placeholder

== Changelog ==

= 3.0.5 =
* Added: clickable setup checklist banner at the top of AV-PvH AI Search and compact admin notice on other wp-admin pages whenever required configuration is incomplete (API key, Gemini store, frontend user access, post types, and referenced/File List document uploads), with direct links to each setting anchor or tab.
* Improved: frontend 403 disabled message now names the exact setting and tab ("Share Gemini search configuration with frontend users" in AV-PvH AI Search → General).

= 3.0.4 =
* Added: AV-PvH File List (`avpvh-filelist`) support (`avpvh_flst_files_*` / `avpvh_flst_settings_*`, `[avpvh_filelist]` shortcode, per-list `FileListDir` path resolution, and *Show in File List* deep links), with legacy Simple File List (`eeSFL_*`) kept as a fallback.
* Changed: files attached to posts/pages that are not in File List are now marked neutrally (`Not in File List`) and filterable under **Referenced In**, instead of overriding **AI Indexed** with `Error`.
* Fixed: restored missing `buildSimpleFileListTargets()` helper for removing unreferenced File List entries, and fixed the **Referenced In** scope filter values.

= 3.0.3 =
* Added: chat archive (Skype chats as searchable documents) with Meilisearch sync, an indexable-content normalizer, and document/OCR fixes that had only been deployed to kb.rechtspreker.nl from a local copy.
* Added: dropdown labels show default, recommended (gemini-3.8-flash) and fast, low cost (gemini-3.5-flash-lite); recommended models are listed first.
* Changed: default model gemini-3.8-flash also replaces the earlier local defaults gemini-3.7-flash, 3.6-flash and 3.5-flash.

= 3.0.2 =
* Added: Gemini 4 models (gemini-4-carbon, gemini-4-argon, gemini-4-hydrogen). Model names without -flash or -pro were filtered out before; Gemini 4 models also get the longer Pro timeout.
* Changed: Google's moving "-latest" aliases (gemini-flash-latest, gemini-pro-latest) are no longer offered in model dropdowns, because Google can change their target model without notice. A site that already uses one keeps working and still sees it.
* Changed: default model is now gemini-3.8-flash instead of gemini-flash-latest (which pointed to the older 3.5 Flash).
* Changed: the model table on the General tab lists every available model (including Gemini 4 and 3.8 before their first test), newest first. The "Latest alias" column is replaced by "Note" (Selected, Default, Recommended), and the admin dropdown marks the default and recommended model. A selected -latest alias gets a warning.
* Added: model table columns Fast, Correct and Value (-- to +++), Price in / out, Released and Deprecated / shutdown, from Google's docs as of 2026-11-18 (GeminiModelCatalog). Deprecated or shut-down models are marked and left out of the dropdown unless selected.
* Added: model table filter: a search box (e.g. "flash" or "gemini-4") and a "Hide deprecated and shut-down models" checkbox, on by default. The selected model always stays visible.

= 3.0.1 =
* Fixed: activation failed on sites with refs tables from older versions ("Multiple primary key defined"); the old primary key is now converted first.
* Fixed: Easy Table of Contents' WP_DEBUG box no longer ends up in indexed content.
* Improved: model table column "Latest" is now "Latest alias", and an alias whose last test did not name its real model no longer shows itself as its own target.

= 3.0.0 =
* Renamed from Workspace AI Search (folder and slug geweb-ai-search) to AV-PvH AI Search (avpvh-ai-search), so WordPress no longer offers Geweb AI Search from wordpress.org as an "update" that would replace this plugin.
* Added: [avpvh_ai_search] shortcode; [geweb_ai_search] keeps working.
* Added: Update URI header, so WordPress never looks for updates of this plugin on wordpress.org.
* Added: stays inactive with an admin notice while Geweb AI Search is still active, instead of crashing.
* Settings, index, chats and database tables keep their names, so switching over needs no migration.

= 2.1.4.31 =
* Added: Persistent Markdown cache storage, inspection modal, and shared Markdown renderer
* Improved: HTML-to-Markdown conversion for WordPress tables, headings, anchors, and source reconstruction
* Added: OCR/describe support for selected media-library images used during indexing
* Improved: Gemini upload logging, model administration, and storage estimation tooling
* Improved: Admin and frontend workspace rendering, status feedback, and source-context display

= 2.1.4 =
* Fixed: Nonce compatibility with page caching plugins
* Updated: Default Gemini model to gemini-3.5-flash and kept Pro on gemini-3.1-pro-preview

= 2.1.4 =
* Fixed: Nonce compatibility with page caching plugins

= 2.1.3 =
* Improved: Modal overlay background opacity
* Added: Filters for customizing interface text labels
* Updated: Model gemini-3-pro-preview → gemini-3.1-pro-preview

= 2.1.2 =
* Improved: AI response display and formatting
* Changed: Restored native WordPress search results flow and made AI search optional from the search form

= 2.1.1 =
* Fixed: Changed method visibility from private to public for better extensibility

= 2.1.0 =
* Added: AI Indexed status column in post list for enabled post types
* Added: Re-upload button to manually re-index individual posts

= 2.0.0 =
* Complete rewrite with modern architecture
* Added PSR-4 namespace support (Geweb\AISearch)
* Replaced cURL calls with WordPress HTTP API (wp_remote_request)
* Added support for Gemini 3 models with structured JSON responses
* API key is now encrypted with libsodium before storage
* Improved document sync: auto-upload on save, auto-delete on unpublish/delete
* Bulk library generation with pagination and live progress indicator
* Conversation history support in AI chat modal
* Source attribution with links to indexed pages
* Improved error handling and logging

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Major rewrite. After upgrading, please go to Settings → AV-PvH AI Search and click "Generate Library" to re-index your content. Your API key and settings will be preserved.

== Third-Party Libraries ==

This plugin bundles the following open-source library:

**league/html-to-markdown**
* Version: 5.x
* Author: The League of Extraordinary Packages
* License: MIT License
* Repository: https://github.com/thephpleague/html-to-markdown
* Purpose: Converts WordPress post HTML content to Markdown for AI indexing

== Support ==

* GitHub: https://github.com/grmt/avpvh-ai-search
