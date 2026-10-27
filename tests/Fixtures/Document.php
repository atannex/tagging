<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasTags;

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];
}
