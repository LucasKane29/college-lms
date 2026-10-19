<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename app_user to users while preserving existing accounts.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user RENAME TO users');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users RENAME TO app_user');
    }
}
