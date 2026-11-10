<?php

namespace App\Demo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A demo site visitor: their account (name, email, password) and the demo that is theirs
 * (sandbox_key). Not one of the platform's users: in their demo they are the
 * Administrator, the assessor or a movement's people in turn. Kept in the main schema
 * (connection "pgsql"), whatever demo a request is using.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $sandbox_key
 */
class DemoVisitor extends Authenticatable
{
    use Notifiable;

    protected $connection = 'pgsql';

    protected $table = 'demo_visitors';

    protected $fillable = ['name', 'email', 'password', 'sandbox_key'];

    protected $hidden = ['password', 'remember_token'];

    /** No "remember me" for visitors: their demo is remembered by its own cookie. */
    protected $rememberTokenName = '';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** The visitor whose demo this is, if any. */
    public static function forSandbox(?string $key): ?self
    {
        return $key === null ? null : self::query()->where('sandbox_key', $key)->first();
    }

    /** @param  Builder<self>  $query */
    public function scopeEmail(Builder $query, string $email): void
    {
        $query->where('email', mb_strtolower(trim($email)));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new DemoPasswordReset($token));
    }
}
