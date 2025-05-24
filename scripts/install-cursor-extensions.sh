#!/bin/bash

echo "Installing recommended extensions for PHP development in Cursor..."

# Array of extensions to install
extensions=(
    "bmewburn.vscode-intelephense-client"  # PHP Intelephense - Main PHP intellisense
    "wongjn.php-sniffer"                   # PHP Sniffer - Real-time PHPCS linting
    "persoderlind.vscode-phpcbf-formatter" # PHPCBF formatter
    "shevaua.phpcs"                        # Additional PHPCS support
    "neilbrayfield.php-docblocker"         # PHP DocBlocker
    "xdebug.php-debug"                     # PHP Debug
)

# Check if cursor CLI is available
if ! command -v cursor &> /dev/null; then
    echo "Error: Cursor CLI is not installed or not in PATH"
    echo "Please ensure Cursor CLI is installed: https://cursor.sh/docs/cli"
    exit 1
fi

# Install each extension
for extension in "${extensions[@]}"; do
    echo "Installing $extension..."
    cursor --install-extension "$extension"
done

echo ""
echo "Installation complete! Please restart Cursor for changes to take effect."
echo ""
echo "The PHP Sniffer extension (wongjn.php-sniffer) will show linting errors in real-time."
echo "Make sure to reload the window after installation (Cmd+R in Cursor)."