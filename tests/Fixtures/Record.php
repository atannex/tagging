<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    use HasTags;

    protected $table = 'engine_records';

    protected $guarded = [];
}
