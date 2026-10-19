<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create teacher-owned disciplines and their many-to-many links to groups.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE disciplines (id INT AUTO_INCREMENT NOT NULL, teacher_id INT NOT NULL, name VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_DISCIPLINE_TEACHER (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE discipline_groups (discipline_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_DG_DISCIPLINE (discipline_id), INDEX IDX_DG_GROUP (group_id), PRIMARY KEY (discipline_id, group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE disciplines ADD CONSTRAINT FK_DISCIPLINE_TEACHER FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE discipline_groups ADD CONSTRAINT FK_DG_DISCIPLINE FOREIGN KEY (discipline_id) REFERENCES disciplines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discipline_groups ADD CONSTRAINT FK_DG_GROUP FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE discipline_groups');
        $this->addSql('DROP TABLE disciplines');
    }
}
