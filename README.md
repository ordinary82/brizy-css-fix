# Brizy CSS Fix

A WordPress plugin that fixes broken layouts caused by Brizy 2.8.8+ updates by restoring missing CSS files and providing per-page compiled data management.

## The Problem

Starting with Brizy 2.8.8, plugin updates can remove `preview.min.css` and `preview.pro.min.css` files that previously compiled pages still reference. This causes pages to lose their styling and appear broken.

## How It Works

The plugin provides two layers of protection:

1. **CSS File Restoration** - Copies `main.base.min.css` to `preview.min.css` (and the Pro equivalent) so existing page references continue to work. The copy is re-applied automatically whenever Brizy is updated.

2. **Compiled Data Management** - Adds a "Brizy Compiled Data" meta box to the post editor sidebar for any Brizy-powered page, showing whether compiled data is current, stale, or missing. A button lets you clear stale compiled data so the page recompiles cleanly on the next editor save.

3. **Dashboard Warning** - Displays an admin notice when pages still reference outdated CSS files, so you know which pages should be resaved in the Brizy editor for a permanent fix.

## Installation

### From GitHub Releases

1. Download `brizy-css-fix.zip` from the [latest release](https://github.com/ordinary82/brizy-css-fix/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin** and upload the zip.
3. Activate the plugin.

### Manual

1. Clone or download this repository.
2. Copy the `brizy-css-fix` folder to `wp-content/plugins/`.
3. Activate the plugin in WordPress.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Brizy (free or Pro) installed

## Usage

Once activated, the plugin works automatically:

- CSS files are restored on activation and after each Brizy update.
- The meta box appears on any post/page that has Brizy data.
- Dashboard warnings show up when stale references are detected.

To permanently fix a page, open it in the Brizy editor and save. The plugin's CSS workaround keeps pages functional in the meantime.

## Deactivation

On deactivation, the plugin removes the copied CSS files and cleans up its options from the database. It does not modify any Brizy data.

## License

GPL v2 or later.
