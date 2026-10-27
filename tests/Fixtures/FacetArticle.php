<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FacetArticle extends Model
{
    use HasTags, SoftDeletes;

    protected $guarded = [];
}
