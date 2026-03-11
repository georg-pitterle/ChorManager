<?php
declare(strict_types = 1)
;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubVoice extends Model
{
    protected $table = 'sub_voices';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'voice_group_id'
    ];

    public function voiceGroup()
    {
        return $this->belongsTo(VoiceGroup::class , 'voice_group_id', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class , 'user_voice_groups', 'sub_voice_id', 'user_id')
            ->withPivot('voice_group_id');
    }
}
