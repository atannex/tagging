<?php

namespace Atannex\Tagging\Tests\Fixtures;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    use HasTags;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
