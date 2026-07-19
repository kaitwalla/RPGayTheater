<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcessedCommand extends Model
{
    use HasUuids;

    protected $primaryKey = 'command_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['command_id', 'aggregate_type', 'aggregate_id', 'response'];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }
}
