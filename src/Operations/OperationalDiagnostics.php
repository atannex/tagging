<?php

namespace Atannex\Tagging\Operations;

use Atannex\Tagging\Governance\AssignmentPolicy;
use Atannex\Tagging\Support\BinaryCollation;
use Atannex\Tagging\Support\Settings;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Taxonomy\Definition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OperationalDiagnostics
{
    public const TABLES = ['tagging_tags', 'tagging_names', 'tagging_assignments', 'tagging_space_locks', 'tagging_decisions', 'tagging_changes'];

    public function inspect(TagSpace $space): array
    {
        $issues = [];
        try {
            Definition::for($space);
            AssignmentPolicy::for($space);
            Settings::validate();
        } catch (\InvalidArgumentException $e) {
            $issues[] = ['code' => 'invalid_configuration', 'message' => $e->getMessage()];
        }
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = ['code' => 'missing_migration', 'table' => $table];
            }
        }
        if (! Schema::hasTable('tagging_tags') || ! Schema::hasTable('tagging_assignments')) {
            return $issues;
        }
        if (! Schema::hasColumn('tagging_tags', 'approved_at')) {
            $issues[] = ['code' => 'missing_column', 'table' => 'tagging_tags', 'column' => 'approved_at'];
        }
        foreach (['tagging_tags' => ['tagging_identity'], 'tagging_names' => ['tagging_name_identity'], 'tagging_assignments' => ['tagging_assignment_identity', 'tagging_subject', 'tagging_context_current', 'tagging_candidates']] as $table => $required) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $indexes = array_column(Schema::getIndexes($table), 'name');
            foreach ($required as $index) {
                if (! in_array($index, $indexes, true)) {
                    $issues[] = ['code' => 'missing_index', 'table' => $table, 'index' => $index];
                }
            }
        }
        $parentIndexed = false;
        foreach (Schema::getIndexes('tagging_tags') as $index) {
            if (($index['columns'][0] ?? null) === 'parent_id') {
                $parentIndexed = true;
            }
        }
        if (! $parentIndexed) {
            $issues[] = ['code' => 'missing_index', 'table' => 'tagging_tags', 'index' => 'parent_id supporting index'];
        }
        if ($binary = BinaryCollation::for()) {
            foreach (['tagging_tags' => ['tenant', 'taxonomy', 'slug'], 'tagging_assignments' => ['taggable_type', 'taggable_id', 'context']] as $table => $fields) {
                foreach (Schema::getColumns($table) as $column) {
                    if (in_array($column['name'], $fields, true) && ($column['collation'] ?? null) !== $binary) {
                        $issues[] = ['code' => 'invalid_scope_collation', 'table' => $table, 'column' => $column['name']];
                    }
                }
            }
        }
        DB::table('tagging_assignments')->whereIn('tag_id', $space->query()->select('id'))->select('taggable_type')->distinct()->orderBy('taggable_type')
            ->chunk(200, function ($rows) use (&$issues) {
                foreach ($rows as $row) {
                    $class = Relation::getMorphedModel($row->taggable_type) ?? $row->taggable_type;
                    if (! is_subclass_of($class, Model::class)) {
                        $issues[] = ['code' => 'unresolved_morph', 'type' => $row->taggable_type];
                    }
                }
            });

        return $issues;
    }
}
