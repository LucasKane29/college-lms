<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialize quiz optimistic-lock versions in MySQL; Doctrine omits the version column on INSERT.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tests ALTER COLUMN lock_version SET DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tests ALTER COLUMN lock_version DROP DEFAULT');
    }
}
