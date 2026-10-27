<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagging_space_locks', function (Blueprint $table) {
            $table->string('key', 64)->primary();
        });
        Schema::table('tagging_tags', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable();
        });
        Schema::create('tagging_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tagging_tags')->cascadeOnDelete();
            $table->string('taggable_type', 160);
            $table->string('taggable_id', 64);
            $table->string('context', 64);
            $table->string('decision', 16);
            $table->string('actor_id', 128)->nullable();
            $table->string('operation_id', 128)->nullable();
            $table->timestamps();
            $table->unique(['tag_id', 'taggable_type', 'taggable_id', 'context'], 'tagging_decision_identity');
        });
        Schema::create('tagging_changes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant', 64);
            $table->string('taxonomy', 64);
            $table->string('context', 64)->nullable();
            $table->string('kind', 64);
            $table->unsignedBigInteger('tag_id')->nullable();
            $table->string('taggable_type', 160)->nullable();
            $table->string('taggable_id', 64)->nullable();
            $table->string('actor_id', 128)->nullable();
            $table->string('operation_id', 128)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant', 'taxonomy', 'created_at'], 'tagging_history_scope');
            $table->index('created_at', 'tagging_history_retention');
        });
        Schema::table('tagging_assignments', function (Blueprint $table) {
            $table->index(['context', 'tag_id', 'expires_at'], 'tagging_context_current');
            $table->index(['taggable_type', 'context', 'taggable_id'], 'tagging_candidates');
        });
    }

    public function down(): void
    {
        Schema::table('tagging_assignments', function (Blueprint $table) {
            $table->dropIndex('tagging_context_current');
            $table->dropIndex('tagging_candidates');
        });
        Schema::dropIfExists('tagging_changes');
        Schema::dropIfExists('tagging_decisions');
        Schema::table('tagging_tags', function (Blueprint $table) {
            $table->dropColumn('approved_at');
        });
        Schema::dropIfExists('tagging_space_locks');
    }
};
