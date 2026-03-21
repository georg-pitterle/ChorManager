<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RememberLogin extends Model
{
    protected $table = 'remember_logins';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'selector',
        'token_hash',
        'expires_at',
        'created_at',
        'last_used_at',
        'user_agent',
        'ip_address'
    ];
}
