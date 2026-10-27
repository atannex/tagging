<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class BulkUuid extends Model
{
    use HasTags;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
