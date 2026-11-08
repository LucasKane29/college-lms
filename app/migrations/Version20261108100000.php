<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the approved lessons topic catalog and link scheduled classes to topics.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lessons (id INT AUTO_INCREMENT NOT NULL, discipline_id INT NOT NULL, name VARCHAR(255) NOT NULL, INDEX IDX_LESSONS_DISCIPLINE (discipline_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE lessons ADD CONSTRAINT FK_LESSONS_DISCIPLINE FOREIGN KEY (discipline_id) REFERENCES disciplines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE schedule ADD topic_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_SCHEDULE_TOPIC FOREIGN KEY (topic_id) REFERENCES lessons (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_SCHEDULE_TOPIC ON schedule (topic_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP FOREIGN KEY FK_SCHEDULE_TOPIC');
        $this->addSql('DROP INDEX IDX_SCHEDULE_TOPIC ON schedule');
        $this->addSql('ALTER TABLE schedule DROP topic_id');
        $this->addSql('DROP TABLE lessons');
    }
}
