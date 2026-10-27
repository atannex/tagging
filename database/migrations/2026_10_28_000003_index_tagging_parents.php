<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may already have a supporting FK index; PostgreSQL/SQLite do not create one automatically.
        foreach (Schema::getIndexes('tagging_tags') as $index) {
            if (($index['columns'][0] ?? null) === 'parent_id') {
                return;
            }
        }
        Schema::table('tagging_tags', fn (Blueprint $table) => $table->index('parent_id', 'tagging_parent_lookup'));
    }

    public function down(): void
    {
        foreach (Schema::getIndexes('tagging_tags') as $index) {
            if ($index['name'] === 'tagging_parent_lookup') {
                Schema::table('tagging_tags', fn (Blueprint $table) => $table->dropIndex('tagging_parent_lookup'));

                return;
            }
        }
    }
};
