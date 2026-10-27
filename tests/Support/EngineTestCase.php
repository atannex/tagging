<?php

namespace Atannex\Tagging\Tests\Support;

use Atannex\Tagging\Tagging;
use Atannex\Tagging\TagSpace;
use Atannex\Tagging\Tests\Fixtures\Record;
use Atannex\Tagging\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

abstract class EngineTestCase extends TestCase
{
    protected function defineSubjectTables(): void
    {
        Schema::create('engine_records', function (Blueprint $t) {
            $t->id();
            $t->string('tenant')->nullable();
            $t->timestamps();
        });
        Schema::create('race_counters', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('value')->default(0);
        });
    }

    protected function record(?string $tenant = null): Record
    {
        return Record::create(['tenant' => $tenant]);
    }

    protected function space(string $tenant = '', string $taxonomy = 'tags', string $context = 'default'): TagSpace
    {
        return app(Tagging::class)->for($tenant, $taxonomy, $context);
    }
}
