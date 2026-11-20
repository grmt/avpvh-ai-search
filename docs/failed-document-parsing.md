# Reviewing and retrying failed document parsing

The plugin converts WordPress content and selected referenced files into Markdown before uploading content that needs conversion to Gemini File Search. This guide explains how to review that Markdown and handle failed files safely.

## Review generated Markdown

### Pages, posts, and media

1. Open the relevant WordPress list screen, such as **Pages** or **Posts**.
2. Find the **MD Cache** column.
3. Click the displayed cache size.
4. Use **Rendered** to inspect structure and **Raw** to inspect the exact Markdown sent for indexing.

### Referenced files

1. Open **AI Search → Files**.
2. Find the file and open its **MD Cache** entry.
3. Compare the rendered or raw Markdown with **Open original** when that action is available.

Markdown snapshots are stored in the WordPress database tables `*_geweb_ai_markdown_cache` and `*_geweb_ai_document_markdown_cache`. They are not normally written as `.md` files in the uploads directory.

## Retry workflow

1. Open **AI Search → Files** and filter or search for the failed filename.
2. Check the file type, inclusion checkbox, processing mode, and status.
3. Correct the processing mode or source file as described below.
4. Use the row's upload/retry action.
5. Confirm that the status changes to uploaded and review the resulting **MD Cache**.
6. Run a representative search for text that appears near line breaks, table-cell boundaries, or page breaks.

Do not delete an existing tracked document before confirming that the replacement can be parsed and uploaded. The normal row action should keep the operation scoped to one file.

## File-type guidance

### HTML, pages, and posts

The HTML-to-Markdown pipeline inserts boundaries between adjacent block elements and repairs conservative line-end hyphenation. Review the raw cache for failures such as `nochderden` or `dev-\nloper`. Regenerate the item after deploying a normalization change.

### JPG and PNG images

Gemini File Search does not reliably accept direct image uploads. Set the image-processing mode to **OCR** or **Describe** so the plugin converts the image to Markdown first, then retry it. Use OCR for text-heavy scans and Describe for visual content where a semantic description is more useful.

To batch-retry included JPG/JPEG and PNG files that are not yet uploaded, run a preview first and then execute:

```bash
./scripts/retry-failed-jpgs.sh --dry-run
./scripts/retry-failed-jpgs.sh
```

The script leaves already-uploaded, excluded, unreadable, and non-JPG files unchanged. It selects OCR, uploads the generated Markdown, reports each result, and removes its temporary authenticated endpoint automatically.

### XLSX spreadsheets

The plugin first tries a direct upload. If that fails, `XlsxMarkdownExtractor` reads the XLSX ZIP package and creates Markdown tables per worksheet. The extractor prefers PHP `ZipArchive`; its command-line `unzip` fallback requires an Info-ZIP-compatible `unzip` that supports `-Z1` and `-p`.

For a failed workbook:

1. Confirm that the extension is `.xlsx` and the file is a valid, unencrypted XLSX package.
2. Confirm that PHP has `ZipArchive` (`php -m` should list `zip`) or that compatible `unzip` is installed.
3. Retry the file from **AI Search → Files**.
4. Review the generated Markdown tables for row/column alignment and excessive output size.

Legacy `.xls`, macro-enabled `.xlsm`, encrypted files, and damaged workbooks may need conversion to a clean `.xlsx` or CSV before retrying.

### PPTX and audio

The current referenced-document pipeline does not convert PowerPoint or audio content to Markdown. Convert presentations to PDF or Markdown and audio to a transcript before indexing.

### Large text files

Large plain-text files can exceed the upload timeout. Split them at logical boundaries into smaller files, keep each chunk independently understandable, and retry the chunks. Preserve headings or source metadata so search results remain traceable.

## Verification checklist

- The item reports an uploaded/indexed status.
- **MD Cache** exists when conversion was required.
- Words separated by HTML blocks or source line breaks remain separate.
- Soft hyphens and line-end hyphenation do not create broken tokens.
- Tables preserve meaningful headers and cell boundaries.
- A search for a distinctive sentence retrieves the expected page or file.
