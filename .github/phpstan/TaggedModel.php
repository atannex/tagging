<?php

namespace Atannex\Tagging\Ci;

use Atannex\Tagging\Concerns\HasTags;
use Illuminate\Database\Eloquent\Model;

/** Uses the trait so PHPStan analyses it; the test fixtures are not in this repository. */
class TaggedModel extends Model
{
    use HasTags;
}
