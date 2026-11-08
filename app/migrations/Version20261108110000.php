<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support multiple discipline teachers using the approved discipline_teachers table; preserve existing assignments.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discipline_teachers (discipline_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_DT_DISCIPLINE (discipline_id), INDEX IDX_DT_TEACHER (teacher_id), PRIMARY KEY (discipline_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE discipline_teachers ADD CONSTRAINT FK_DT_DISCIPLINE FOREIGN KEY (discipline_id) REFERENCES disciplines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discipline_teachers ADD CONSTRAINT FK_DT_TEACHER FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('INSERT INTO discipline_teachers (discipline_id, teacher_id) SELECT id, teacher_id FROM disciplines');
        $this->addSql('ALTER TABLE disciplines DROP FOREIGN KEY FK_DISCIPLINE_TEACHER');
        $this->addSql('DROP INDEX IDX_DISCIPLINE_TEACHER ON disciplines');
        $this->addSql('ALTER TABLE disciplines DROP teacher_id');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(0 < (int) $this->connection->fetchOne('SELECT COUNT(*) FROM (SELECT d.id FROM disciplines d LEFT JOIN discipline_teachers dt ON dt.discipline_id = d.id GROUP BY d.id HAVING COUNT(dt.teacher_id) <> 1) incompatible'), 'Rollback requires exactly one teacher per discipline; multiple assignments would be lost.');
        $this->addSql('ALTER TABLE disciplines ADD teacher_id INT DEFAULT NULL');
        $this->addSql('UPDATE disciplines d JOIN discipline_teachers dt ON dt.discipline_id = d.id SET d.teacher_id = dt.teacher_id');
        $this->addSql('ALTER TABLE disciplines MODIFY teacher_id INT NOT NULL');
        $this->addSql('CREATE INDEX IDX_DISCIPLINE_TEACHER ON disciplines (teacher_id)');
        $this->addSql('ALTER TABLE disciplines ADD CONSTRAINT FK_DISCIPLINE_TEACHER FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('DROP TABLE discipline_teachers');
    }
}
