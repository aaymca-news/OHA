<?php

namespace App\Rules;

use App\Exceptions\WorkflowRuleBroken;
use App\Support\Google\DriveLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link to one Google Drive file (the ODP), not to a folder or another site. What is
 * wrong is said in DriveLink's own words, under the link box. An empty value passes:
 * pair with required or nullable.
 */
final class GoogleDriveLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            DriveLink::fileId($value);
        } catch (WorkflowRuleBroken $e) {
            $fail($e->getMessage());
        }
    }
}
