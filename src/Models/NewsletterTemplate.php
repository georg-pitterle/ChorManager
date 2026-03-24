<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterTemplate extends Model
{
    protected $table = 'newsletter_templates';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'content_html',
        'project_id',
        'category',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isGlobal(): bool
    {
        return $this->project_id === null;
    }
}
