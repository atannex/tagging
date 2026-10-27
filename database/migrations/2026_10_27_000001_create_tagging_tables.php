<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tagging_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant', 64)->default('');
            $table->string('taxonomy', 64)->default('tags');
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->foreignId('parent_id')->nullable()->constrained('tagging_tags')->nullOnDelete();
            $table->json('translations')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant', 'taxonomy', 'slug'], 'tagging_identity');
        });
        // Canonical slugs and aliases share one unique namespace.
        Schema::create('tagging_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tagging_tags')->cascadeOnDelete();
            $table->string('tenant', 64);
            $table->string('taxonomy', 64);
            $table->string('slug', 160);
            $table->unique(['tenant', 'taxonomy', 'slug'], 'tagging_name_identity');
        });
        Schema::create('tagging_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tagging_tags')->cascadeOnDelete();
            $table->string('taggable_type', 160);
            // String keys support integers, UUIDs and ULIDs in the same table.
            $table->string('taggable_id', 64);
            $table->string('context', 64)->default('default');
            $table->double('weight')->default(1);
            $table->string('source', 64)->default('manual');
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tag_id', 'taggable_type', 'taggable_id', 'context'], 'tagging_assignment_identity');
            $table->index(['taggable_type', 'taggable_id', 'context'], 'tagging_subject');
            $table->index('expires_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('tagging_assignments');
        Schema::dropIfExists('tagging_names');
        Schema::dropIfExists('tagging_tags');
    }
};
