<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial administrator using ADMIN_EMAIL and ADMIN_PASSWORD.';
    }

    public function up(Schema $schema): void
    {
        $email = mb_strtolower(trim($this->environment('ADMIN_EMAIL')));
        $password = $this->environment('ADMIN_PASSWORD');

        $this->abortIf(false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180, 'Set ADMIN_EMAIL to a valid email address.');
        $this->abortIf(strlen($password) < 12 || strlen($password) > 72, 'Set ADMIN_PASSWORD to a password between 12 and 72 bytes.');
        $this->abortIf(false !== $this->connection->fetchOne('SELECT id FROM users WHERE email = ?', [$email]), 'ADMIN_EMAIL already belongs to an account. Choose an unused address; existing accounts will not be promoted.');

        $this->addSql('INSERT INTO users (email, full_name, roles, password, created_at) VALUES (?, ?, ?, ?, ?)', [
            $email,
            'Адміністратор',
            json_encode(['ROLE_USER', 'ROLE_ADMIN'], JSON_THROW_ON_ERROR),
            password_hash($password, PASSWORD_BCRYPT),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The administrator may own application data. Remove or demote the account explicitly instead.');
    }

    private function environment(string $name): string
    {
        return (string) ($_SERVER[$name] ?? $_ENV[$name] ?? (getenv($name) ?: ''));
    }
}
