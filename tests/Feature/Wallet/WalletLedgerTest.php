<?php

namespace Tests\Feature\Wallet;

use App\Models\Member;
use App\Models\Mess;
use App\Models\Payment;
use App\Models\User;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MealEntry;
use App\Services\PaymentService;
use App\Support\ExpenseKind;
use App\Support\MemberStatus;
use App\Support\PaymentMethod;
use App\Support\PaymentType;
use Carbon\Carbon;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::where('slug', 'manager')->first());

        return $admin;
    }

    public function test_manager_can_view_a_member_wallet(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['status' => MemberStatus::ACTIVE, 'name' => 'Karim']);

        $this->actingAs($admin)
            ->get(route('mess.members.wallet', $member))
            ->assertOk()
            ->assertSee(__('Wallet'))
            ->assertSee('Karim');
    }

    public function test_member_can_view_own_wallet(): void
    {
        $member = Member::factory()->create(['status' => MemberStatus::ACTIVE, 'name' => 'Self']);
        $user = User::factory()->create();
        $user->assignRole(Role::where('slug', 'mess-member')->first());
        $member->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.wallet'))
            ->assertOk()
            ->assertSee('Self');
    }

    public function test_wallet_lists_a_payment_and_the_settled_balance(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin); // PaymentService::create reads auth()->id()
        $member = Member::factory()->create(['status' => MemberStatus::ACTIVE]);

        app(PaymentService::class)->create([
            'member_id' => $member->id,
            'date' => now()->toDateString(),
            'amount' => 1500,
            'method' => PaymentMethod::CASH,
            'type' => PaymentType::ADVANCE_DEPOSIT,
        ]);

        $this->actingAs($admin)
            ->get(route('mess.members.wallet', $member))
            ->assertOk()
            // The 1500 advance deposit shows as a credit
            ->assertSee(number_format(1500, 2))
            // ...and the member is in credit by 1500
            ->assertSee(__('Credit'));
    }

    public function test_member_cannot_view_another_member_wallet_route(): void
    {
        // The my.wallet route always resolves the member from the auth user,
        // so there is no IDOR surface — every member sees only their own wallet.
        $memberA = Member::factory()->create(['status' => MemberStatus::ACTIVE, 'name' => 'Alpha']);
        $memberB = Member::factory()->create(['status' => MemberStatus::ACTIVE, 'name' => 'Bravo']);
        $userA = User::factory()->create();
        $userA->assignRole(Role::where('slug', 'mess-member')->first());
        $memberA->update(['user_id' => $userA->id]);

        $this->actingAs($userA)
            ->get(route('my.wallet'))
            ->assertOk()
            ->assertSee('Alpha')
            ->assertDontSee('Bravo');
    }

    public function test_wallet_shows_reconciled_due_after_advance_deposit(): void
    {
        // Quick-260724-jui Task B: a member who deposits an advance must see the
        // deposit offset the bill in a single reconciled "This month" summary
        // (Bill − payments − advance applied = Due), not a disconnected Credit
        // header next to a raw pending bill.
        $admin = $this->admin();
        $this->actingAs($admin); // PaymentService::create reads auth()->id()
        $member = Member::factory()->create(['status' => MemberStatus::ACTIVE, 'name' => 'Reconcile']);

        // 10 lunch meals this month (lunch weight = 1.0 ⇒ 10 meals).
        $start = Carbon::create(now()->year, now()->month, 1);
        for ($i = 0; $i < 10; $i++) {
            MealEntry::factory()->create([
                'mess_id' => Mess::activeId(),
                'member_id' => $member->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'breakfast' => false,
                'lunch' => true,
                'dinner' => false,
            ]);
        }

        // Bazar 654.20 over 10 meals ⇒ meal rate 65.42, meal cost 654.20 = bill.
        $bazar = ExpenseCategory::factory()->create(['kind' => ExpenseKind::BAZAR]);
        Expense::factory()->create([
            'mess_id' => Mess::activeId(),
            'expense_category_id' => $bazar->id,
            'amount' => 654.20,
            'date' => $start->toDateString(),
        ]);

        // 500 advance deposit ⇒ advance_applied = min(500, 654.20) = 500,
        // so Due = 654.20 − 0 − 500 = 154.20.
        app(PaymentService::class)->create([
            'member_id' => $member->id,
            'date' => now()->toDateString(),
            'amount' => 500,
            'method' => PaymentMethod::CASH,
            'type' => PaymentType::ADVANCE_DEPOSIT,
        ]);

        $this->actingAs($admin)
            ->get(route('mess.members.wallet', $member))
            ->assertOk()
            ->assertSee(__('This month'))
            ->assertSee(__('Advance applied'))
            ->assertSee(number_format(654.20, 2))   // Bill
            ->assertSee(number_format(500.00, 2))   // Advance applied
            ->assertSee(number_format(154.20, 2));  // Due
    }
}
