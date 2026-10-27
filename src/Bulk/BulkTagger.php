<?php

namespace Atannex\Tagging\Bulk;

use Atannex\Tagging\Exceptions\PolicyRejected;
use Atannex\Tagging\Governance\PolicyViolation;
use Atannex\Tagging\Models\Tag;
use Atannex\Tagging\Support\SpaceTransactions;
use Atannex\Tagging\TagSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LengthException;

final class BulkTagger
{
    public function __construct(private readonly TagSpace $space) {}

    public function run(Builder $candidates, iterable $tags, string $operation = 'attach', array $attributes = [], bool $dryRun = false, bool $atomic = true): array
    {
        if (! in_array($operation, ['attach', 'sync', 'detach'], true)) {
            throw new InvalidArgumentException('Bulk operation must be attach, sync, or detach.');
        }
        if ($operation === 'detach' && $attributes) {
            throw new InvalidArgumentException('Detach does not accept assignment attributes.');
        }
        $inputs = [];
        foreach ($tags as $tag) {
            if ((! is_string($tag) && ! $tag instanceof Tag) || count($inputs) >= config('tagging.max_tags_per_operation', 100)) {
                throw new InvalidArgumentException('Invalid bulk tag or too many tags.');
            }
            $existing = $this->space->find($tag);
            if ($operation !== 'detach' && (($existing && ! $existing->active) || ($tag instanceof Tag && ! $existing))) {
                throw new InvalidArgumentException('A bulk tag is missing or inactive.');
            }
            $inputs[] = $tag;
        }
        $candidates = $this->space->scopeSubjects($candidates);
        $model = $candidates->getModel();
        if ($model->getConnection()->getName() !== (new Tag)->getConnection()->getName()) {
            throw new InvalidArgumentException('Bulk subjects must use the tagging connection.');
        }
        $query = (clone $candidates)->applyScopes()->withoutGlobalScopes();
        $base = $query->getQuery();
        if ($base->from !== $model->getTable() || $base->joins || $base->groups || $base->havings || $base->unions || $base->limit !== null || $base->offset !== null) {
            throw new InvalidArgumentException('Bulk operations need an unaliased model query without joins, grouping, unions, or pagination.');
        }
        $maximum = config('tagging.max_bulk_subjects', 1000);
        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException('Bulk subject limit must be a positive integer.');
        }
        // Snapshot a bounded key list before assignments can change the candidate filter.
        $keys = (clone $query)->reorder()->orderBy($model->getQualifiedKeyName())->limit($maximum + 1)
            ->pluck($model->getQualifiedKeyName())->all();
        if (count($keys) > $maximum) {
            throw new LengthException('Candidate count exceeds the configured bulk subject limit.');
        }
        $execute = function () use ($query, $keys, $inputs, $operation, $attributes, $dryRun, $atomic) {
            $report = [
                'operation' => $operation,
                'dry_run' => $dryRun,
                'atomic' => $atomic,
                'targeted' => count($keys),
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'applied' => 0,
                'rolled_back' => false,
                'results' => [],
            ];
            foreach ($keys as $key) {
                try {
                    $matched = app(SpaceTransactions::class)->run($this->space, function () use ($query, $key, $inputs, $operation, $attributes) {
                        $subject = (clone $query)->select($query->getModel()->qualifyColumn('*'))->whereKey($key)->lockForUpdate()->first();
                        if (! $subject) {
                            return false;
                        }
                        try {
                            if ($operation === 'detach') {
                                $this->space->detach($subject, $inputs);
                            } else {
                                $this->space->{$operation}($subject, $inputs, $attributes);
                            }
                        } catch (PolicyViolation $violation) {
                            throw new BulkSubjectFailure(['code' => 'policy_violation', 'violations' => $violation->violations], $violation);
                        } catch (PolicyRejected $exception) {
                            throw new BulkSubjectFailure(['code' => 'policy_rejected', 'message' => $exception->getMessage()], $exception);
                        } catch (InvalidArgumentException $exception) {
                            throw new BulkSubjectFailure(['code' => 'invalid_assignment', 'message' => $exception->getMessage()], $exception);
                        }

                        return true;
                    });
                    $status = $matched ? 'succeeded' : 'skipped';
                    $report[$status]++;
                    $report['results'][] = ['key' => (string) $key, 'status' => $status];
                } catch (BulkSubjectFailure $failure) {
                    $report['failed']++;
                    $report['results'][] = ['key' => (string) $key, 'status' => 'failed', ...$failure->details];
                }
            }
            $report['rolled_back'] = $dryRun || ($atomic && $report['failed'] > 0);
            $report['applied'] = $report['rolled_back'] ? 0 : $report['succeeded'];
            if ($report['rolled_back']) {
                throw new BulkRollback($report);
            }

            return $report;
        };
        try {
            if ($dryRun) {
                return Model::withoutEvents(fn () => app(SpaceTransactions::class)->run($this->space, $execute));
            }

            return $atomic ? app(SpaceTransactions::class)->run($this->space, $execute) : $execute();
        } catch (BulkRollback $rollback) {
            return $rollback->report;
        }
    }
}
