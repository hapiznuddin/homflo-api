<?php

namespace App\Policies;

use App\Models\BillRecurrence;
use App\Models\HouseholdMember;
use App\Models\User;

class BillRecurrencePolicy
{
    /**
     * Determine whether the user can change the schedule definition.
     *
     * Owner-only: schedule edits retarget unpaid periods of a shared
     * household series (D-07 routes all schedule changes through this
     * model; there is no GET/DELETE recurrence endpoint in the MVP —
     * disable a schedule with is_active=false instead).
     */
    public function update(User $user, BillRecurrence $recurrence): bool
    {
        $membership = HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $recurrence->household_id)
            ->first();

        return $membership !== null && $membership->role === 'owner';
    }
}
