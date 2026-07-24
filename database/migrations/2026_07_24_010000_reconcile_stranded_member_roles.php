<?php

use HasinHayder\Tyro\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles member/manager roles after the `user`→`mess-member` and
 * `admin`→`manager` renames (commits d147b6a / 4e11098).
 *
 * WHY THIS EXISTS — the one-shot rename migration
 * (2026_07_23_020000_drop_user_role_reassign_to_mess_member) was shipped using
 * a hardcoded `role_user` pivot, but Tyro's pivot is `user_roles`. Commit 41d6ef3
 * fixed that migration to use Role relationships — but a migration only runs
 * once. On any database where the buggy version already executed, pre-existing
 * members were DROPPED from `user` without being re-attached to `mess-member`,
 * leaving them role-less. They can still log in (auth succeeds) but the
 * `roles:mess-member` middleware throws `ACCESS DENIED.` on `/my`.
 *
 * This reconciliation runs under a FRESH filename so it executes once on every
 * database regardless of whether the rename migration already ran. It is fully
 * idempotent: safe to re-run, safe on a clean DB, and its `down()` is a no-op
 * (a reconciliation must never un-assign roles).
 *
 * Uses Role->users()/Role relationships throughout — never a hardcoded pivot —
 * because Tyro's pivot table name is configurable (default `user_roles`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $messMember = Role::firstOrCreate(
            ['slug' => 'mess-member'],
            ['name' => 'Mess Member']
        );

        $manager = Role::firstOrCreate(
            ['slug' => 'manager'],
            ['name' => 'Manager']
        );

        // 1) LEGACY REASSIGN — if the old slugs still exist (rename migration
        //    has not run, or ran without deleting), move their users onto the
        //    new slug. Matches the rename migration's intent so no one is lost.
        foreach (['user' => $messMember, 'admin' => $manager] as $legacySlug => $targetRole) {
            $legacy = Role::where('slug', $legacySlug)->first();
            if (! $legacy) {
                continue;
            }

            foreach ($legacy->users as $legacyUser) {
                $legacyUser->roles()->syncWithoutDetaching([$targetRole->id]);
            }
        }

        // 2) SEMANTIC RECONCILIATION — every user that holds a `members` row IS
        //    a mess member by definition, so guarantee they have the
        //    `mess-member` role. This is the branch that rescues accounts left
        //    role-less by the buggy rename run: there is no `user` role to
        //    reassign from anymore, but the members row still identifies them.
        $memberUserIds = DB::table('members')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        if (! empty($memberUserIds)) {
            // Inverse of the User->roles() relation: attach every member-user
            // to the mess-member role in a single idempotent write.
            $messMember->users()->syncWithoutDetaching($memberUserIds);
        }
    }

    public function down(): void
    {
        // No-op. Reconciliations must be forward-only — rolling back must never
        // strip a role a real member depends on to reach /my.
    }
};
