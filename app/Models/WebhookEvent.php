<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $name
 */
class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    public function webhookConfigurationEvent(): BelongsToMany
    {
        return $this->belongsToMany(WebhookConfiguration::class, 'webhook_configurations_events', 'event_id', 'configuration_id');
    }
}
