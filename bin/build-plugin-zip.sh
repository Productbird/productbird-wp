#!/bin/bash

source "$(dirname "$0")/env.sh"
source "$(dirname "$0")/msg.sh"

# Exit if any command fails.
set -e

TYPE='PRODUCTION'

print_usage() {
	echo "build-plugin-zip - attempt to build a plugin"
	echo "By default this will build a clean production build and zip archive"
	echo "of the built plugin assets"
	echo " "
	echo "build-plugin-zip [arguments]"
	echo " "
	echo "options:"
	echo "-h          show brief help"
	echo "-d          build plugin in development mode"
	echo "-z          build zip only, skipping build commands"
	echo "-k          keep the zip-file directory after creating zip"
	echo " "
}

# get args
while getopts 'hdzk' flag; do
	case "${flag}" in
		h) print_usage ;;
		d) TYPE='DEV' ;;
		z) TYPE='ZIP_ONLY' ;;
		k) KEEP_DIR='true' ;;
		*)
			print_usage
			exit 1
			;;
	esac
done

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..

# Tool for grabbing version from package.json
get_version() {
	grep '\"version\"' ./package.json \
	| cut -d ':' -f 2 \
	| sed 's/"//g' \
	| sed 's/,//g' \
	| sed 's/ //g'
}

# Set version
VERSION=$(get_version)

status "💃 Time to build the Productbird WordPress plugin ZIP 🕺"

if [ -z "$NO_CHECKS" ]; then
	# Make sure there are no changes in the working tree. Release builds should be
	# traceable to a particular commit and reliably reproducible. (This is not
	# totally true at the moment because we download nightly vendor scripts).
	changed=
	# Check for unstaged changes, excluding package.json
	if ! git diff --quiet -- . ":(exclude)package.json"; then
		changed="file(s) modified (other than package.json)"
	# Check for staged changes, excluding package.json
	elif ! git diff --cached --quiet -- . ":(exclude)package.json"; then
		changed="file(s) staged (other than package.json)"
	fi
	if [ ! -z "$changed" ]; then
		git status
		error "ERROR: Cannot build plugin zip with dirty working tree. ☝️
		Commit your changes and try again."
		exit 1
	fi
fi

# Add version to plugin header
perl -i -pe "s/Version:.*$/Version: ${VERSION}/" productbird.php

# Update the PRODUCTBIRD_VERSION constant
perl -i -pe "s/define\('PRODUCTBIRD_VERSION',\s*'[^']*'\);/define('PRODUCTBIRD_VERSION', '${VERSION}');/" productbird.php

# Update the readme.txt Stable tag
perl -i -pe "s/Stable tag:.*$/Stable tag: ${VERSION}/" readme.txt

# Run the build.
if [ $TYPE = 'DEV' ]; then
	status "Installing dependencies... 👷‍♀️"
	status "==========================="
	composer install
	PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true pnpm install --frozen-lockfile
	status "==========================="
	status "Generating development build... (v${VERSION}) 👷‍♀️"
	status "==========================="
	pnpm build
	status "==========================="
elif [ $TYPE = 'ZIP_ONLY' ]; then
	composer install --no-dev
	composer dump-autoload
	status "Skipping build commands - using current built assets on disk for built archive...(v${VERSION}) 👷‍♀️"
	status "==========================="
else
	status "Installing dependencies... 📦"
	composer install --no-dev
	PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true pnpm install --frozen-lockfile
	status "==========================="
	status "Generating production build... (v${VERSION}) 👷‍♀️"
	status "==========================="
	pnpm build
	status "==========================="
fi

# Generate the plugin zip file.
status "Creating archive... 🎁"
rm -rf zip-file  # Remove existing zip-file directory
mkdir zip-file
mkdir zip-file/build
sh "$CURR_DIR/bin/copy-plugin-files.sh" "$CURR_DIR" "$CURR_DIR/zip-file"
cd "$(pwd)/zip-file"
zip -r ../productbird.zip ./
cd ..
if [ -z "$KEEP_DIR" ]; then
	rm -r zip-file
fi

# cleanup composer.json
git checkout -- composer.json
git checkout -- productbird.php

# regenerate classmap for development use
composer dump-autoload

success "Done. You've built Productbird (v${VERSION}) 🎉"
