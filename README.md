# File Naming Plugin

This plugin provides comprehensive file naming and renaming functionality for Musicarr.

## Features

- **File Naming Patterns**: Create, edit, and manage custom file naming patterns
- **File Renaming**: Preview and rename files according to patterns
- **Pattern Preview**: Test patterns with sample data before applying
- **Batch Operations**: Rename multiple files at once
- **Async Processing**: File renaming operations are processed asynchronously

## Installation

1. Place this plugin in the `plugins/` directory
2. The plugin will be automatically detected and loaded by Musicarr
3. No additional configuration required

## Usage

### File Naming Patterns

- Navigate to **Configuration > Patterns** to manage file naming patterns
- Create new patterns using placeholders like `{{artist}}`, `{{album}}`, `{{title}}`, etc.
- Set patterns as active/inactive and default

### File Renaming

- Navigate to **File Management > Renaming** to access the renaming interface
- Select files that need renaming
- Choose a naming pattern
- Preview the changes before applying
- Execute the renaming operation

## Placeholders

The following placeholders are supported in naming patterns:

- `{{artist}}` - Artist name
- `{{album}}` - Album title
- `{{title}}` - Track title
- `{{trackNumber}}` - Track number
- `{{year}}` - Release year
- `{{extension}}` - File extension
- `{{quality}}` - Audio quality
- `{{format}}` - Audio format
- `{{medium}}` - Medium information

## Configuration

The plugin automatically integrates with Musicarr's plugin system and requires no manual configuration.

## Dependencies

- PHP 8.4+
- Symfony 6.0+
- Doctrine ORM
- Musicarr Core
