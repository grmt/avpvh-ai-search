# Browser tests

Playwright tests for the AI workspace (`/ai-search/`) and the admin screens. They replace the
`wp:test`, `wp:ipad-test` and `wp:mobile-test` modes of the old geweb-ai-search-devtools repo,
and they run in CI on every pull request.

| Project | Size | What runs |
|---|---|---|
| `desktop` | 1440×900 | everything |
| `narrow-desktop` | 412×925, mouse | workspace (`@layout`) |
| `mobile` | 412×925, touch | workspace, incl. tabs and swipe |
| `mobile-landscape` | 925×412, touch | workspace |
| `tablet` | iPad Pro 11 | workspace |

## Run locally (test WordPress in Docker)

```bash
npm ci
npm run e2e:install-browser   # once: Playwright's Chromium (asks for sudo for system libraries)
npm run e2e:env:start      # WordPress at http://localhost:8890, admin / password
npm run test:e2e           # all projects, headless
npm run test:e2e:headed    # watch it in a browser window
npm run test:e2e:ui        # Playwright's interactive runner
npm run e2e:env:stop
```

Run one size or one test: `npm run test:e2e -- --project=tablet` or `-- -g "swipe"`.
Slow a headed run down to follow it: `E2E_DEMO_DELAY_MS=1500 npm run test:e2e:headed`.

To use the installed Google Chrome instead of Playwright's Chromium:
`E2E_BROWSER_CHANNEL=chrome npm run test:e2e`.

## Run against a real site

Credentials come from your shell, never from the repository:

```bash
WP_BASE_URL=https://example.org WP_USERNAME=… WP_PASSWORD=… npm run test:e2e
```

- Tests that change settings (the optimistic-lock check) are skipped unless the site is local
  or `E2E_ALLOW_WRITES=1` is set.
- The log-out / other site / log-in check runs only with `WP_SESSION_CROSS_SITE_URL` set.
- `E2E_AI_PAGE_URL` overrides the AI page if it cannot be found from the admin page.
