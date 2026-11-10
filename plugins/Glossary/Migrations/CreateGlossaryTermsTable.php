<?php

declare(strict_types=1);

namespace Glossary\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * Creates `glossary_terms`.
 *
 * `term` and `definition` hold a translatable value as JSON text, the object
 * `LocalizedText::normalize()` returns. TEXT runs on PostgreSQL and SQLite
 * alike, which is what a plugin that also runs on the desktop host needs.
 */
final class CreateGlossaryTermsTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS glossary_terms (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                code VARCHAR(64) NOT NULL,
                term TEXT NOT NULL,
                definition TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_glossary_terms_tenant_id ON glossary_terms(tenant_id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS glossary_terms');
    }
}
