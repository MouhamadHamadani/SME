<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    /** Pre-tenant: a lead has no tenant_id. See the create_leads_table migration. */
    protected $fillable = [
        'name',
        'business_name',
        'phone',
        'email',
        'business_type',
        'currently_using',
        'locale',
    ];
}
