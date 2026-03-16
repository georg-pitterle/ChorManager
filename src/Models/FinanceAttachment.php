<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAttachment extends Model
{
    protected $table = 'finance_attachments';
    public $timestamps = false;

    protected $fillable = [
        'finance_id',
        'filename',
        'mime_type',
        'file_content'
    ];
}
