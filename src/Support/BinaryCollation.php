<?php

namespace Atannex\Tagging\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The binary, NO PAD collation the identity columns use on MySQL-family
 * servers: case, accents and trailing spaces all distinguish keys, as on
 * PostgreSQL and SQLite. MySQL 8 has `utf8mb4_0900_bin`; MariaDB (10.2+),
 * which lacks it, has `utf8mb4_nopad_bin`; older servers fall back to
 * `utf8mb4_bin`.
 */
final class BinaryCollation
{
    public const PREFERRED = ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin', 'utf8mb4_bin'];

    /** The best binary collation this server offers; null on other drivers. */
    public static function for(?Connection $connection = null): ?string
    {
        $connection ??= DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $available = collect($connection->select(
            'select COLLATION_NAME as name from information_schema.COLLATIONS where COLLATION_NAME in ('.implode(', ', array_fill(0, count(self::PREFERRED), '?')).')',
            self::PREFERRED,
        ))->map(fn ($row) => $row->name ?? $row->NAME ?? null)->filter()->all();

        foreach (self::PREFERRED as $collation) {
            if (in_array($collation, $available, true)) {
                return $collation;
            }
        }

        return 'utf8mb4_bin';
    }
}
