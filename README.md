![Image](https://raw.githubusercontent.com/jakobtrost/contentsync/519158cd78ace3fd450c5b1ff6e55041f5f6b8d3/assets/icon.svg)

# Content Sync

Content Sync is a powerful WordPress plugin that allows you to synchronize content across multiple websites, saving you significant time in content management workflows. Perfect for agencies managing multiple client sites, multi-site networks, or anyone who needs to keep content consistent across multiple WordPress installations.

## Features

-   **Synchronize Content** - Sync posts, pages, and custom post types across multiple WordPress installations
-   **Central Management** - Manage content from a central location
-   **Automatic Sync** - Keep multiple sites in sync automatically
-   **Review System** - Review and approve content before syncing
-   **Sync History** - Track synchronization history and status
-   **Secure API** - Uses secure REST API connections for all synchronization

## Requirements

-   WordPress 5.0 or higher
-   PHP 7.4 or higher

## Installation

### Via Composer

```bash
composer install
```

### Manual Installation

1. Download or clone this repository
2. Upload the `contentsync` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure the plugin settings to connect your sites
5. Start syncing your content!

## 🔧 Development

### Setup

```bash
# Install dependencies
composer install
npm install

# Run code style checks
composer phpcs

# Fix code style issues
composer phpcbf
```

## Changelog

### Version 0.1

-   Initial release

## License

This plugin is licensed under the GPL v2 or later.

See [license.txt](license.txt) for a full list of changes.

---

<div align="center">
  Made by <a href="https://jakobtrost.de">Jakob Trost</a>
</div>
