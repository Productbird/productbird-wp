# PHP CodeSniffer Setup for WordPress Development

This guide explains how to set up and use PHP CodeSniffer (phpcs) and PHP Code Beautifier and Fixer (phpcbf) for WordPress development in this project.

## Quick Setup

Run the setup script:

```bash
./scripts/setup-phpcs.sh
```

## Manual Setup

1. Install dependencies:
   ```bash
   composer install
   ```

2. Verify installation:
   ```bash
   ./vendor/bin/phpcs --version
   ./vendor/bin/phpcbf --version
   ```

## Usage

### Check Code Standards

Run phpcs to check for coding standard violations:

```bash
composer run-script lint
```

Or directly:

```bash
./vendor/bin/phpcs
```

### Auto-fix Issues

Many issues can be automatically fixed using phpcbf:

```bash
composer run-script lint:fix
```

Or directly:

```bash
./vendor/bin/phpcbf
```

## VS Code / Cursor Integration

### Install Extensions

When you open the project in VS Code or Cursor, you'll be prompted to install recommended extensions. Accept to install:

- **PHP Intelephense** - PHP intellisense and code completion
- **PHP Sniffer** - Real-time phpcs integration
- **phpcbf formatter** - Format PHP files on save
- **phpcs** - Additional phpcs integration
- **PHP DocBlocker** - Generate PHP doc blocks
- **PHP Debug** - Debugging support

### Features

Once set up, you'll get:

- **Real-time linting**: See coding standard violations as you type
- **Auto-formatting**: PHP files are automatically formatted on save
- **Intellisense**: Get suggestions and autocompletion for WordPress functions
- **Quick fixes**: Fix many issues with a single click

### Configuration

The project includes pre-configured settings in `.vscode/settings.json` that:

- Enable phpcs and phpcbf
- Set paths to the local executables (no global installation needed)
- Configure auto-formatting on save
- Use the project's `phpcs.xml.dist` configuration

## Standards Applied

This project uses the following coding standards:

- **WordPress Coding Standards** (WPCS) - Main WordPress standards
- **WordPress-Extra** - Additional best practices
- **PHPCompatibility** - Ensures PHP 8.2+ compatibility

### Customizations

Some rules have been adjusted for this project:

- Short array syntax `[]` is allowed (instead of `array()`)
- File comments are not required
- Namespaces are allowed
- Custom file naming is allowed (for PSR-4 autoloading)

## Common Issues and Solutions

### "Command not found" errors

Make sure you're using the local executables:
```bash
./vendor/bin/phpcs   # Good
phpcs               # Bad (unless globally installed)
```

### Too many errors to fix

Start with auto-fix, then handle remaining issues manually:
```bash
composer run-script lint:fix
composer run-script lint
```

### Specific file or directory

Check a specific file:
```bash
./vendor/bin/phpcs src/Plugin.php
```

Fix a specific file:
```bash
./vendor/bin/phpcbf src/Plugin.php
```

## Ignoring Rules

If you need to ignore a specific rule for a line:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $sql );
```

Or for a block:

```php
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $sql1 );
$wpdb->query( $sql2 );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
```

## Contributing

Before submitting a PR:

1. Run `composer run-script lint:fix` to auto-fix issues
2. Run `composer run-script lint` to check for remaining issues
3. Fix any remaining issues manually
4. Commit your changes

The CI pipeline will also run these checks automatically.