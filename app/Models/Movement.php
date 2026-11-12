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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['slug', 'name', 'country', 'city', 'zone_id', 'membership_status_id', 'planned_assessment_label', 'planned_assessment_on',
    'follow_up_paused_until', 'follow_up_pause_reason', 'follow_up_paused_by'])]
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
     * The assessment under way, if any: opened and not yet finished (not yet closed). A
     * movement has at most one at a time.
     */
    public function assessmentUnderWay(): ?Assessment
    {
        return $this->assessments()->underWay()->latest('id')->first();
    }

    /**
     * The latest assessment done (closed), whose follow-ups are under way.
     */
    public function lastAssessmentDone(): ?Assessment
    {
        return $this->assessments()->done()->latest('assessed_on')->latest('id')->first();
    }

    /**
     * The movements with an assessment under way, for marking them on a list.
     *
     * @return array<int, true> keyed by movement id
     */
    public static function withAssessmentUnderWay(): array
    {
        $ids = Assessment::query()->underWay()->distinct()->pluck('movement_id')->map(fn ($id): int => (int) $id)->all();

        return array_fill_keys($ids, true);
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
     * The assessors still active, by name: the ones who are told, shown and asked.
     *
     * @return BelongsToMany<User, $this>
     */
    public function activeAssessors(): BelongsToMany
    {
        return $this->assessors()->where('users.active', true)->orderBy('users.name');
    }

    /**
     * The movement's active Board Chairperson, who signs its ODP. The database allows
     * only one active Chairperson per movement.
     *
     * @return HasOne<User, $this>
     */
    public function chair(): HasOne
    {
        return $this->holder(Role::Board);
    }

    /**
     * The movement's active National General Secretary, who supplies its evidence. The
     * database allows only one per movement.
     *
     * @return HasOne<User, $this>
     */
    public function generalSecretary(): HasOne
    {
        return $this->holder(Role::Ngs);
    }

    /**
     * The active holder of one of the movement's own roles.
     *
     * @return HasOne<User, $this>
     */
    public function holder(Role $role): HasOne
    {
        return $this->hasOne(User::class)->where('role', $role)->where('active', true);
    }

    /**
     * The evidence supplied for its assessments.
     *
     * @return HasManyThrough<Evidence, Assessment, $this>
     */
    public function evidence(): HasManyThrough
    {
        return $this->hasManyThrough(Evidence::class, Assessment::class);
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

    /**
     * Its follow-up is paused (an approved pause: elections, a crisis): no reminders, and
     * "Paused" shown instead of "Overdue", until the end date.
     */
    public function followUpPaused(): bool
    {
        // Paused up to and including its end date.
        return $this->follow_up_paused_until !== null && today()->lte($this->follow_up_paused_until);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_paused_by');
    }

    protected function casts(): array
    {
        return [
            'planned_assessment_on' => 'date',
            'follow_up_paused_until' => 'date',
        ];
    }
}
