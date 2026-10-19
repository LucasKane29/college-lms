<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create groups owned by curators and allow each student to belong to one group.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `groups` (id INT AUTO_INCREMENT NOT NULL, curator_id INT NOT NULL, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_GROUP_CURATOR (curator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `groups` ADD CONSTRAINT FK_GROUP_CURATOR FOREIGN KEY (curator_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE users ADD group_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_USER_GROUP ON users (group_id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_USER_GROUP FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_USER_GROUP');
        $this->addSql('DROP INDEX IDX_USER_GROUP ON users');
        $this->addSql('ALTER TABLE users DROP group_id');
        $this->addSql('DROP TABLE `groups`');
    }
}
