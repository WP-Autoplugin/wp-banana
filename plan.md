# WP Banana Command Palette Implementation Plan

## Goal

Add WordPress Command Palette support to WP Banana so users can reach the plugin's main workflows with `Cmd/Ctrl + K`, starting with safe navigation commands and optionally expanding to in-place actions on screens where WP Banana already injects UI.

## Research Summary

### What the API supports

- The public registration API lives in `@wordpress/commands`.
- Static commands can be registered with:
  - `dispatch( commandsStore ).registerCommand( { ... } )`
  - `useCommand( { ... } )`
  - `useCommands( [ ... ] )`
- Dynamic results can be registered with `useCommandLoader( { name, hook } )`.
- Command objects support `name`, `label`, `icon`, `callback`, optional `category`, optional `context`, and optional `keywords`.
- The command store also exposes `open` and `close`, so commands can open or close the palette programmatically if needed.

### WordPress version reality

- WordPress 6.3 introduced the Command Palette API.
- The Developer Blog article for WordPress 6.4 states the palette was only available in the Post Editor and Site Editor at that time.
- The current `@wordpress/core-commands` handbook says it contains reusable WordPress admin commands for multiple WP Admin pages.
- The WordPress Developer Blog "What's new for developers? (October 2025)" notes that WordPress 6.9 expands the Command Palette across the admin and moves navigation commands into `@wordpress/core-commands`.

### Implication for WP Banana

- WP Banana already hard-requires WordPress 6.6 at runtime, so there is no need to support pre-6.6 installs.
- Admin-wide WP Banana commands should be treated as a WordPress 6.9+ feature.
- On WordPress 6.6 to 6.8, the plugin can either:
  - do nothing, or
  - optionally register editor-only commands on screens where the palette exists.
- Recommended initial scope: ship admin-wide support only on 6.9+ and keep 6.6 to 6.8 as a no-op. This is simpler and avoids confusing partial behavior.

## Recommended Command Set

### Phase 1: Safe, high-value navigation commands

These are low-risk because they reuse existing admin screens and capabilities.

1. `wp-banana/open-generate-page`
   - Label: `Generate Image`
   - Category: `view`
   - Action: navigate to `upload.php?page=wp-banana-generate`
   - Capability: `Caps::GENERATE`
   - Keywords: `banana`, `ai`, `image`, `generate`, `media`

2. `wp-banana/open-settings`
   - Label: `WP Banana Settings`
   - Category: `view`
   - Action: navigate to `options-general.php?page=wp-banana`
   - Capability: `manage_options`
   - Keywords: `banana`, `settings`, `providers`, `openai`, `gemini`, `replicate`

3. `wp-banana/open-settings-api`
   - Label: `WP Banana API Setup`
   - Category: `view`
   - Action: navigate to settings page with `#wp-banana-api-setup`
   - Capability: `manage_options`
   - Requires adding stable anchor IDs to the settings page

4. `wp-banana/open-settings-defaults`
   - Label: `WP Banana Defaults`
   - Category: `view`
   - Action: navigate to settings page with `#wp-banana-defaults`
   - Capability: `manage_options`

5. `wp-banana/open-settings-advanced`
   - Label: `WP Banana Advanced Settings`
   - Category: `view`
   - Action: navigate to settings page with `#wp-banana-advanced`
   - Capability: `manage_options`

6. `wp-banana/open-logs`
   - Label: `WP Banana API Logs`
   - Category: `view`
   - Action: navigate to `admin.php?page=wp-banana-logs`
   - Capability: `manage_options`
   - Optional: only register if logging UI is enabled, but it is acceptable to always register and let the page show its own notice

### Phase 2: Screen-aware shortcut commands

These should only exist where the underlying UI already exists.

1. `wp-banana/open-upload-panel`
   - Label: `Open Generate Panel`
   - Category: `command`
   - Screen: `upload.php`
   - Capability: `Caps::GENERATE`
   - Action: open the existing inline generate panel created by [`assets/admin/media-toolbar.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/media-toolbar.tsx)

2. `wp-banana/focus-generate-prompt`
   - Label: `Focus Generate Prompt`
   - Category: `command`
   - Screen: `upload.php` or the standalone generate page
   - Action: move focus into the prompt textarea after opening the relevant panel if needed

3. `wp-banana/open-media-modal-generate-tab`
   - Label: `Open Media Modal Generate Tab`
   - Category: `command`
   - Screen: any admin/editor screen with an open WP media modal
   - Action: switch the current media modal to WP Banana's injected "Generate Image" tab
   - This should be deferred unless there is a clean event-driven implementation

### Phase 3: Deferred or optional commands

These are possible, but should not block the first release.

1. `Test provider connection`
   - Not recommended for v1 because it is a side-effecting admin action and the settings page currently owns that UI and status feedback.

2. `Generate image now`
   - Not recommended for v1 because the palette does not provide a rich prompt-entry form by itself.
   - Better UX is to route users into the existing generate page or existing inline panel.

3. Dynamic loaders for models/providers
   - Possible with `useCommandLoader`, but likely overkill for the first pass.

## Architecture Recommendation

### 1. Add a dedicated PHP integration class

Create a new admin service to own all Command Palette logic.

Proposed file:

- [`src/Admin/class-command-palette.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-command-palette.php)

Responsibilities:

- Gate feature by WordPress version, probably `>= 6.9`
- Gate by context: admin only
- Enqueue the command palette integration bundle on relevant screens
- Build and localize a small payload:
  - URLs for generate/settings/logs
  - booleans for capabilities
  - booleans for provider connection state
  - current screen identifier
  - optional flags for whether upload panel or media modal integrations are available
- Expose a filter for third-party extension, for example:
  - `wp_banana_command_palette_payload`
  - `wp_banana_command_palette_enabled`

### 2. Add a dedicated JS entry for command registration

Proposed files:

- [`assets/admin/entry-command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/entry-command-palette.tsx)
- [`assets/admin/command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/command-palette.tsx)

Responsibilities:

- Import `@wordpress/commands`, `@wordpress/data`, `@wordpress/i18n`, and icons
- Read the localized payload from `window.wpBananaCommandPalette`
- Register commands once per page load
- Hide commands the current user cannot access
- Hide commands that do not make sense on the current screen
- Prefer direct URLs for navigation commands

Recommended implementation style:

- Use `dispatch( commandsStore ).registerCommand()` for simple static commands
- Use `useCommands()` only if a React wrapper component becomes cleaner than imperative registration
- Avoid `useCommandLoader()` in the first iteration

### 3. Prefer events over DOM scraping for in-place actions

For Phase 2 commands, do not couple command callbacks to fragile selectors like `.page-title-action` or tab button class names if avoidable.

Instead:

- add custom events in existing screen integrations
- have those screens listen for events and open/focus their UI

Examples:

- `document.dispatchEvent( new CustomEvent( 'wp-banana:open-generate-panel' ) )`
- `document.dispatchEvent( new CustomEvent( 'wp-banana:focus-generate-prompt' ) )`
- `document.dispatchEvent( new CustomEvent( 'wp-banana:open-media-modal-generate-tab' ) )`

This keeps the command palette bundle independent from markup details.

## Detailed Implementation Steps

### Step 1. Introduce the new admin service

Files:

- [`src/class-plugin.php`](/home/balazs/.openclaw/workspace/wp-banana/src/class-plugin.php)
- [`src/Admin/class-command-palette.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-command-palette.php)

Work:

- Add `use WPBanana\Admin\Command_Palette;`
- Instantiate and register the new service inside the `is_admin()` block
- Keep it separate from `Media_Hooks` and `Generate_Page` so ownership stays clean

### Step 2. Enqueue the command bundle with version gating

Files:

- [`src/Admin/class-command-palette.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-command-palette.php)

Work:

- Add a method like `is_supported(): bool`
- Check global `$wp_version` or use `get_bloginfo( 'version' )`
- Require WordPress `6.9+` for admin-wide behavior
- Hook `admin_enqueue_scripts`
- Optionally also hook `enqueue_block_editor_assets` if you want editor coverage to be explicit
- Load `build/command-palette.js` only when supported

Payload shape should include:

- `urls.generatePage`
- `urls.settings`
- `urls.settingsApi`
- `urls.settingsDefaults`
- `urls.settingsAdvanced`
- `urls.logs`
- `capabilities.canGenerate`
- `capabilities.canManage`
- `flags.isConnected`
- `flags.canOpenUploadPanel`
- `screen.base`
- `screen.id`
- `screen.isUpload`
- `screen.isSettings`

### Step 3. Add the JS build entry

Files:

- [`webpack.config.js`](/home/balazs/.openclaw/workspace/wp-banana/webpack.config.js)
- generated build outputs:
  - `build/command-palette.js`
  - `build/command-palette.asset.php`

Work:

- Add a new webpack entry for the command palette bundle
- Rebuild assets with `npm run build`

### Step 4. Register Phase 1 commands

Files:

- [`assets/admin/command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/command-palette.tsx)

Work:

- Register static commands for:
  - Generate Image
  - WP Banana Settings
  - API Setup
  - Defaults
  - Advanced Settings
  - API Logs
- Use categories consistently:
  - navigation targets: `view`
  - in-place UI actions: `command`
- Add search keywords so users can find commands by provider or task name
- Always call `close()` before navigation

### Step 5. Add stable settings anchors

Files:

- [`src/Admin/class-settings-page.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-settings-page.php)

Work:

- Add IDs around major sections:
  - `wp-banana-api-setup`
  - `wp-banana-defaults`
  - `wp-banana-advanced`
- Keep the markup semantic and stable so command URLs can target these sections

### Step 6. Add event-driven hooks for Phase 2 commands

Files:

- [`assets/admin/media-toolbar.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/media-toolbar.tsx)
- [`assets/admin/generate-page.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/generate-page.tsx)
- optionally [`assets/admin/media-modal-generate-tab.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/media-modal-generate-tab.tsx)

Work:

- Add listeners for custom events instead of relying on command code to click buttons
- `media-toolbar.tsx`
  - respond to `wp-banana:open-generate-panel`
  - respond to `wp-banana:focus-generate-prompt`
- `generate-page.tsx`
  - optionally respond to `wp-banana:focus-generate-prompt`
- `media-modal-generate-tab.tsx`
  - only if worthwhile, respond to `wp-banana:open-media-modal-generate-tab`

Recommendation:

- Phase 2 should only ship after Phase 1 is stable
- If event wiring becomes messy, skip media-modal support in v1

### Step 7. Add screen-aware command registration

Files:

- [`assets/admin/command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/command-palette.tsx)

Work:

- Register `Open Generate Panel` only on `upload.php`
- Register `Focus Generate Prompt` only where a target exists
- Do not register commands that will no-op on the current screen

### Step 8. Document support and behavior

Files:

- [`README.md`](/home/balazs/.openclaw/workspace/wp-banana/README.md)
- [`readme.txt`](/home/balazs/.openclaw/workspace/wp-banana/readme.txt)

Work:

- Note that Command Palette integration is available on WordPress 6.9+
- Mention the current supported commands
- Clarify that older supported WP Banana installs continue working without this feature

## JavaScript Integration Details

### Dependencies expected in the bundle

- `@wordpress/commands`
- `@wordpress/data`
- `@wordpress/i18n`
- `@wordpress/icons`
- optionally `@wordpress/element` if a React wrapper is used
- optionally `@wordpress/plugins` if command registration is wrapped in a plugin render component

### CSS requirements

The `@wordpress/commands` docs say the package needs:

- `@wordpress/components/build-style/style.css`
- `@wordpress/commands/build-style/style.css`

For WP Banana, the recommended assumption is:

- on WordPress 6.9+ admin screens, core should already own Command Palette rendering and styling
- WP Banana should avoid shipping its own command-palette stylesheet unless testing proves a custom admin page is missing the core styles

If custom initialization becomes necessary on plugin pages:

- evaluate whether `@wordpress/core-commands.initializeCommandPalette()` must be called
- verify that the required styles are present on the standalone generate page

## PHP Backend Integration Details

No new REST endpoints are required for Phase 1.

Backend work is mainly:

- feature detection
- capability checks
- script registration
- payload localization
- stable admin URLs

Recommended capability mapping:

- `Caps::GENERATE` for generate-related commands
- `manage_options` for settings and logs

Recommended helper methods in `Command_Palette`:

- `is_supported()`
- `enqueue_assets( string $hook )`
- `get_payload(): array`
- `get_screen_flags(): array`
- `get_command_urls(): array`

## Dependencies and Version Requirements

### Required

- WordPress `6.9+` for admin-wide Command Palette support
- Existing WP Banana minimum remains WordPress `6.6+`
- Existing build pipeline is already sufficient because the plugin uses `@wordpress/scripts`

### Not required

- No Composer dependency changes
- No database schema changes
- No new REST routes for the initial release

### Optional fallback

If broader compatibility is desired later:

- support editor-only commands on WordPress `6.6` to `6.8`
- keep admin-wide commands gated to `6.9+`

That fallback increases QA cost and should be skipped unless there is a real product need.

## Proposed File Change List

### New files

- [`src/Admin/class-command-palette.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-command-palette.php)
- [`assets/admin/entry-command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/entry-command-palette.tsx)
- [`assets/admin/command-palette.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/command-palette.tsx)

### Modified files

- [`src/class-plugin.php`](/home/balazs/.openclaw/workspace/wp-banana/src/class-plugin.php)
- [`webpack.config.js`](/home/balazs/.openclaw/workspace/wp-banana/webpack.config.js)
- [`src/Admin/class-settings-page.php`](/home/balazs/.openclaw/workspace/wp-banana/src/Admin/class-settings-page.php)
- [`assets/admin/media-toolbar.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/media-toolbar.tsx)
- [`assets/admin/generate-page.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/generate-page.tsx)
- optionally [`assets/admin/media-modal-generate-tab.tsx`](/home/balazs/.openclaw/workspace/wp-banana/assets/admin/media-modal-generate-tab.tsx)
- optionally [`README.md`](/home/balazs/.openclaw/workspace/wp-banana/README.md)
- optionally [`readme.txt`](/home/balazs/.openclaw/workspace/wp-banana/readme.txt)

### Generated files after build

- `build/command-palette.js`
- `build/command-palette.asset.php`

## QA Plan

### Manual testing on WordPress 6.9+

1. Open `upload.php`, `options-general.php?page=wp-banana`, and a normal admin screen.
2. Trigger the Command Palette with `Cmd/Ctrl + K`.
3. Verify WP Banana commands appear only for users with matching capabilities.
4. Verify navigation commands land on the correct page and section.
5. Verify logs command works whether or not logs currently contain entries.
6. Verify upload-panel command only appears on the Media Library list screen.
7. Verify in-place commands open the expected UI and focus the prompt when applicable.

### Regression checks

1. Confirm no JS errors on unsupported versions where the feature is gated off.
2. Confirm generate page, media toolbar, and media modal still work without using the palette.
3. Confirm command registration does not duplicate when multiple WP Banana bundles load on the same screen.

## Recommended Delivery Order

1. Ship Phase 1 navigation commands.
2. Add settings anchors.
3. QA on WordPress 6.9.
4. Add Phase 2 event-based in-place commands only if the UX is clearly better and the event plumbing stays simple.

## Sources

- `@wordpress/commands` handbook: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-commands/
- `@wordpress/core-commands` handbook: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-core-commands/
- Developer Blog: Getting started with the Command Palette API: https://developer.wordpress.org/news/2023/11/getting-started-with-the-command-palette-api/
- Developer Blog: What's new for developers? (October 2025): https://developer.wordpress.org/news/2025/10/whats-new-for-developers-october-2025/
