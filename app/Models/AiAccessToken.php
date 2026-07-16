<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAccessToken extends Model
{
    protected $primaryKey = 'access_token_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['access_token_id', 'tenant_id', 'user_id', 'client_id'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
