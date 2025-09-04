<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $name
 */
class WebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    public function configurations(): BelongsToMany
    {
        return $this->belongsToMany(WebhookConfiguration::class, 'webhook_configurations_events', 'event_id', 'configuration_id');
    }
}
