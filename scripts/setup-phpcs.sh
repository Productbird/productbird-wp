#!/bin/bash

echo "Setting up PHPCS for WordPress development..."

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    echo "Error: Composer is not installed. Please install Composer first."
    exit 1
fi

# Install dependencies
echo "Installing composer dependencies..."
composer install

# Test phpcs
echo "Testing phpcs..."
./vendor/bin/phpcs --version

# Test phpcbf
echo "Testing phpcbf..."
./vendor/bin/phpcbf --version

echo ""
echo "Setup complete! You can now:"
echo "  - Run 'composer run-script lint' to check code standards"
echo "  - Run 'composer run-script lint:fix' to automatically fix issues"
echo ""
echo "For VS Code/Cursor users:"
echo "  - Install recommended extensions when prompted"
echo "  - PHP files will be automatically formatted on save"
echo ""