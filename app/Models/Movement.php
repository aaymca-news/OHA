<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\MovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['slug', 'name', 'country', 'city', 'zone_id', 'membership_status_id', 'planned_assessment_label', 'planned_assessment_on'])]
#[RouteKey('slug')]
class Movement extends Model
{
    /** @use HasFactory<MovementFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return BelongsTo<MembershipStatus, $this>
     */
    public function membershipStatus(): BelongsTo
    {
        return $this->belongsTo(MembershipStatus::class);
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /**
     * Staff assigned to assess this movement.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assessors(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The movement's one user, its active Board Chairperson, who signs its ODP.
     * The database allows only one active Chairperson per movement.
     *
     * @return HasOne<User, $this>
     */
    public function chair(): HasOne
    {
        return $this->hasOne(User::class)->where('role', Role::Board)->where('active', true);
    }

    /**
     * The derived current status: latest score, band and next assessment date.
     *
     * @return HasOne<MovementStatus, $this>
     */
    public function status(): HasOne
    {
        return $this->hasOne(MovementStatus::class);
    }

    protected function casts(): array
    {
        return [
            'planned_assessment_on' => 'date',
        ];
    }
}
