<?php

use Atannex\Tagging\Exceptions\VocabularyCollision;
use Atannex\Tagging\Support\BinaryCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // utf8mb4_0900_bin on MySQL 8, utf8mb4_nopad_bin on MariaDB.
        if ($collation = BinaryCollation::for()) {
            $this->collation($collation);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            $collation = DB::selectOne('select @@collation_database as value')->value;
            // Never collapse newly distinct identities as an accidental side effect of a downgrade.
            foreach (['tagging_tags' => ['tenant', 'taxonomy', 'slug'], 'tagging_names' => ['tenant', 'taxonomy', 'slug'],
                'tagging_assignments' => ['tag_id', 'taggable_type', 'taggable_id', 'context'], 'tagging_decisions' => ['tag_id', 'taggable_type', 'taggable_id', 'context']] as $table => $fields) {
                $expressions = array_map(fn ($field) => $field === 'tag_id' ? '`tag_id`' : '`'.$field.'` COLLATE '.$collation, $fields);
                if (DB::table($table)->selectRaw('1')->groupByRaw(implode(', ', $expressions))->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new VocabularyCollision('Cannot restore case-insensitive keys: distinct identities would collide. Reconcile scope keys or restore a reviewed backup before downgrading.');
                }
            }
            $this->collation($collation);
        }
    }

    private function collation(string $collation): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        // A NO PAD binary collation also preserves trailing spaces, matching PostgreSQL/SQLite identity semantics
        // (utf8mb4_bin, the last fallback, pads; it still separates case and accents).
        $columns = [
            'tagging_tags' => ['tenant' => 64, 'taxonomy' => 64, 'slug' => 160],
            'tagging_names' => ['tenant' => 64, 'taxonomy' => 64, 'slug' => 160],
            'tagging_assignments' => ['taggable_type' => 160, 'taggable_id' => 64, 'context' => 64],
            'tagging_decisions' => ['taggable_type' => 160, 'taggable_id' => 64, 'context' => 64],
            'tagging_changes' => ['tenant' => 64, 'taxonomy' => 64, 'context' => 64, 'taggable_type' => 160, 'taggable_id' => 64],
        ];
        foreach ($columns as $name => $fields) {
            Schema::table($name, function (Blueprint $table) use ($name, $fields, $collation) {
                foreach ($fields as $field => $length) {
                    $column = $table->string($field, $length)->collation($collation);
                    if ($name === 'tagging_tags' && $field === 'tenant') {
                        $column->default('');
                    }
                    if ($name === 'tagging_tags' && $field === 'taxonomy') {
                        $column->default('tags');
                    }
                    if ($name === 'tagging_assignments' && $field === 'context') {
                        $column->default('default');
                    }
                    if ($name === 'tagging_changes' && in_array($field, ['context', 'taggable_type', 'taggable_id'], true)) {
                        $column->nullable();
                    }
                    $column->change();
                }
            });
        }
    }
};
