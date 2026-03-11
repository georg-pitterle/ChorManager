<?php
declare(strict_types = 1)
;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceGroup extends Model
{
    protected $table = 'voice_groups';
    public $timestamps = false;

    protected $fillable = [
        'name'
    ];

    public function subVoices()
    {
        return $this->hasMany(SubVoice::class , 'voice_group_id', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class , 'user_voice_groups', 'voice_group_id', 'user_id')
            ->withPivot('sub_voice_id');
    }
}
