<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['disk', 'path'])]
class Image extends Model
{
    /**
     * Get the model that owns the image.
     */
    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
