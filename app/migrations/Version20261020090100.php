<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020090100 extends AbstractMigration
{
    public function getDescription(): string { return 'Seed five 120-minute pairs starting at 08:30 for weekdays and weekends.'; }

    public function up(Schema $schema): void
    {
        for ($number = 1; $number <= 5; ++$number) {
            $start = (new \DateTimeImmutable('08:30'))->modify('+'.(($number - 1) * 120).' minutes')->format('H:i:s');
            $end = (new \DateTimeImmutable($start))->modify('+120 minutes')->format('H:i:s');
            $this->addSql('INSERT INTO pairs (number, weekday_start, weekday_end, weekend_start, weekend_end) SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM pairs WHERE number = ?)', [$number, $start, $end, $start, $end, $number]);
        }
    }

    public function down(Schema $schema): void
    {
        // Keep modified or referenced pairs; the schema rollback removes the remaining rows.
        for ($number = 1; $number <= 5; ++$number) {
            $start = (new \DateTimeImmutable('08:30'))->modify('+'.(($number - 1) * 120).' minutes')->format('H:i:s');
            $end = (new \DateTimeImmutable($start))->modify('+120 minutes')->format('H:i:s');
            $this->addSql('DELETE FROM pairs WHERE number = ? AND weekday_start = ? AND weekday_end = ? AND weekend_start = ? AND weekend_end = ? AND NOT EXISTS (SELECT 1 FROM schedule WHERE schedule.pair_id = pairs.id)', [$number, $start, $end, $start, $end]);
        }
    }
}
