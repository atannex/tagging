<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class GovernedRecord extends Model
{
    use HasTags;

    protected $guarded = [];
}
