<?php

declare(strict_types=1);

namespace MusicarrFileNamingPluginDoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to insert default file naming pattern for the file naming plugin
 */
final class Version20250823062134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default file naming pattern for file naming plugin';
    }

    public function up(Schema $schema): void
    {
        // Insert the default file naming pattern
        $this->addSql(<<<'SQL'
            INSERT INTO file_naming_pattern (name, pattern, is_active, is_default, created_at, description) 
            VALUES (
                'Default Pattern',
                '{{artist_folder}}/{{album}}{% if quality %} [{{quality_full}}]{% endif %}/{% if mediums_count > 1 %}{{medium}}/{% endif %}{{trackNumber}} - {{title}}.{{extension}}',
                1,
                1,
                NOW(),
                'Default file naming pattern for the file naming plugin'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove the inserted file naming pattern
        $this->addSql(<<<'SQL'
            DELETE FROM file_naming_pattern 
            WHERE name = 'Default Pattern' AND is_default = 1
        SQL);
    }
}
