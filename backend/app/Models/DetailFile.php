<?php

namespace App\Models;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DetailFile extends Model
{
    protected $guarded = [];

    /** Disco privado donde viven los archivos (DETAILS_DISK). Todo se lee y escribe siempre a través de acá. */
    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(config('filesystems.details_disk'));
    }
}