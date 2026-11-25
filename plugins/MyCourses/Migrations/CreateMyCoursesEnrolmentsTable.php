<?php

declare(strict_types=1);

namespace MyCourses\Migrations;

use Whity\Sdk\MigrationInterface;

/** Creates `mycourses_enrolments`: a contact's place on a course (WHIT-1426). */
final class CreateMyCoursesEnrolmentsTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS mycourses_enrolments (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                course VARCHAR(200) NOT NULL,
                lessons INTEGER NOT NULL DEFAULT 8,
                lessons_done INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mycourses_enrolments_contact ON mycourses_enrolments(tenant_id, contact_id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mycourses_enrolments');
    }
}
