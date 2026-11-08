<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add lesson materials and automatically graded tests using approved attachments, tests, questions, answers and tries tables.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lessons ADD description LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE TABLE attachments (id INT AUTO_INCREMENT NOT NULL, lesson_id INT NOT NULL, title VARCHAR(255) NOT NULL, kind VARCHAR(10) NOT NULL, content LONGTEXT DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, storage_name VARCHAR(64) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_ATTACHMENT_LESSON (lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tests (id INT AUTO_INCREMENT NOT NULL, lesson_id INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, published TINYINT(1) NOT NULL, max_attempts INT NOT NULL, revision INT NOT NULL, lock_version INT NOT NULL, INDEX IDX_TEST_LESSON (lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE questions (id INT AUTO_INCREMENT NOT NULL, test_id INT NOT NULL, text LONGTEXT NOT NULL, kind VARCHAR(10) NOT NULL, points INT NOT NULL, position INT NOT NULL, INDEX IDX_QUESTION_TEST (test_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE answers (id INT AUTO_INCREMENT NOT NULL, question_id INT NOT NULL, text VARCHAR(2000) NOT NULL, correct TINYINT(1) NOT NULL, position INT NOT NULL, INDEX IDX_ANSWER_QUESTION (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tries (id INT AUTO_INCREMENT NOT NULL, test_id INT NOT NULL, student_id INT NOT NULL, number INT NOT NULL, score INT NOT NULL, max_score INT NOT NULL, responses JSON NOT NULL, test_title VARCHAR(255) NOT NULL, submitted_at DATETIME NOT NULL, INDEX IDX_TRY_TEST (test_id), INDEX IDX_TRY_STUDENT (student_id), UNIQUE INDEX UNIQ_TRY_NUMBER (test_id, student_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE attachments ADD CONSTRAINT FK_ATTACHMENT_LESSON FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tests ADD CONSTRAINT FK_TEST_LESSON FOREIGN KEY (lesson_id) REFERENCES lessons (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE questions ADD CONSTRAINT FK_QUESTION_TEST FOREIGN KEY (test_id) REFERENCES tests (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE answers ADD CONSTRAINT FK_ANSWER_QUESTION FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tries ADD CONSTRAINT FK_TRY_TEST FOREIGN KEY (test_id) REFERENCES tests (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE tries ADD CONSTRAINT FK_TRY_STUDENT FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tries'); $this->addSql('DROP TABLE answers'); $this->addSql('DROP TABLE questions');
        $this->addSql('DROP TABLE tests'); $this->addSql('DROP TABLE attachments');
        $this->addSql('ALTER TABLE lessons DROP description');
    }
}
