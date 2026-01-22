# Content Sync

![Image](https://raw.githubusercontent.com/jakobtrost/contentsync/519158cd78ace3fd450c5b1ff6e55041f5f6b8d3/assets/icon.svg)

Content Sync is a powerful WordPress plugin that allows you to synchronize content across multiple websites, saving you significant time in content management workflows. Perfect for agencies managing multiple client sites, multi-site networks, or anyone who needs to keep content consistent across multiple WordPress installations.

## ✨ Features

- 🔄 **Synchronize Content** - Sync posts, pages, and custom post types across multiple WordPress installations
- 🎯 **Central Management** - Manage content from a central location
- 🔁 **Automatic Sync** - Keep multiple sites in sync automatically
- ✅ **Review System** - Review and approve content before syncing
- 📊 **Sync History** - Track synchronization history and status
- 🔒 **Secure API** - Uses secure REST API connections for all synchronization

## 📦 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## 🚀 Installation

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

## 🛠️ Usage

1. Navigate to the Content Sync settings in your WordPress admin
2. Add remote sites and establish connections between them
3. Configure which content types to sync
4. Use the review system to approve content before syncing
5. Monitor synchronization status and history

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

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

### Version 0.1

- Initial release
- Basic content synchronization functionality
- Multi-site connection support
- Content review system
- REST API integration

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024 Jakob Trost

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## 👤 Author

**Jakob Trost**

- Website: [jakobtrost.de](https://jakobtrost.de)
- GitHub: [@jakobtrost](https://github.com/jakobtrost)

## 🔗 Links

- [Plugin Repository](https://github.com/jakobtrost/contentsync)
- [Issue Tracker](https://github.com/jakobtrost/contentsync/issues)

---

<div align="center">
  Made with ❤️ by <a href="https://jakobtrost.de">Jakob Trost</a>
</div>
