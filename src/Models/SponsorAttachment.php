<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsorAttachment extends Model
{
    protected $table = 'sponsor_attachments';
    public $timestamps = false;

    protected $fillable = [
        'sponsorship_id',
        'filename',
        'original_name',
        'mime_type',
        'file_content',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }
}
