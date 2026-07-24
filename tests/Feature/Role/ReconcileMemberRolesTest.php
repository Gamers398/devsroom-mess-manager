<?php

namespace Tests\Feature\Role;

use App\Models\Member;
use App\Models\Mess;
use App\Models\User;
use App\Support\MemberStatus;
use HasinHayder\Tyro\Models\Role;
use HasinHayder\Tyro\Support\TyroCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quick task 260724-jui — Task A.
 *
 * Verifies the reconciliation migration rescues members left role-less by the
 * buggy one-shot `user`→`mess-member` rename (which used the wrong pivot table
 * and so dropped members without re-attaching them). These members can still
 * log in but hit `ACCESS DENIED.` on `/my`.
 */
class ReconcileMemberRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTyroRoles();
        $mess = Mess::factory()->create();
        config(['mess.active_mess_id' => $mess->id]);
        Mess::forgetActiveIdCache();
    }

    /** Require the migration file and return its instance so we can re-run `up()`. */
    private function reconciliation(): object
    {
        return require database_path('migrations/2026_07_24_010000_reconcile_stranded_member_roles.php');
    }

    public function test_stranded_member_user_recovers_mess_member_role(): void
    {
        // A real pre-rename account: a members row exists, but NO role attached
        // (exactly the state the buggy rename migration left behind in prod).
        // NB: assert the precondition via the pivot, NOT hasRole() — calling
        // hasRole() here would populate Tyro's in-process slug cache with an
        // empty list, masking the reconciliation. (Prod is unaffected: the
        // migration runs in a separate artisan process.)
        $user = User::factory()->create();
        Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
            'user_id' => $user->id,
        ]);
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $user->id)->count(), 'precondition: user is stranded');

        $this->reconciliation()->up();
        TyroCache::forgetUser($user->id);

        $this->assertTrue($user->fresh()->hasRole('mess-member'), 'reconciliation restored member access');
    }

    public function test_legacy_user_role_is_reassigned_to_mess_member(): void
    {
        // Prod branch where the rename never ran and the `user` slug still exists.
        $userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$userRole->id]);
        Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
            'user_id' => $user->id,
        ]);

        $this->reconciliation()->up();

        $this->assertTrue($user->fresh()->hasRole('mess-member'));
    }

    public function test_legacy_admin_role_is_reassigned_to_manager(): void
    {
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        $this->reconciliation()->up();

        $this->assertTrue($user->fresh()->hasRole('manager'));
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $user = User::factory()->create();
        Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
            'user_id' => $user->id,
        ]);

        $this->reconciliation()->up();
        $this->reconciliation()->up();

        $count = $user->fresh()->roles()->where('slug', 'mess-member')->count();
        $this->assertSame(1, $count, 'syncWithoutDetaching must not duplicate the role');
    }

    public function test_recovered_member_can_reach_my_without_403(): void
    {
        $user = User::factory()->create(['password_changed_at' => now()]);
        Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
            'user_id' => $user->id,
        ]);

        $this->reconciliation()->up();

        // The route that 403'd before — `roles:mess-member` middleware.
        $response = $this->actingAs($user)->get(route('my'));
        $response->assertOk();
    }
}
