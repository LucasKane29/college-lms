<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create approved pairs and schedule tables.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pairs (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, weekday_start TIME NOT NULL, weekday_end TIME NOT NULL, weekend_start TIME NOT NULL, weekend_end TIME NOT NULL, UNIQUE INDEX UNIQ_PAIR_NUMBER (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE schedule (id INT AUTO_INCREMENT NOT NULL, group_id INT NOT NULL, pair_id INT NOT NULL, discipline_id INT NOT NULL, lesson_date DATE NOT NULL, INDEX IDX_SCHEDULE_GROUP (group_id), INDEX IDX_SCHEDULE_PAIR (pair_id), INDEX IDX_SCHEDULE_DISCIPLINE (discipline_id), UNIQUE INDEX UNIQ_SCHEDULE_SLOT (lesson_date, group_id, pair_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_SCHEDULE_GROUP FOREIGN KEY (group_id) REFERENCES `groups` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_SCHEDULE_PAIR FOREIGN KEY (pair_id) REFERENCES pairs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE schedule ADD CONSTRAINT FK_SCHEDULE_DISCIPLINE FOREIGN KEY (discipline_id) REFERENCES disciplines (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE schedule');
        $this->addSql('DROP TABLE pairs');
    }
}
