<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasTags, \Illuminate\Database\Eloquent\SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
