<?php

namespace Tests\Feature\Dashboard;

use App\Models\AdvanceBalance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MealEntry;
use App\Models\MealOffRequest;
use App\Models\Member;
use App\Models\Mess;
use App\Models\User;
use App\Support\ExpenseKind;
use App\Support\MealOffStatus;
use App\Support\MemberStatus;
use HasinHayder\Tyro\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerDashboardTest extends TestCase
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

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::where('slug', 'mess-member')->first());

        return $user;
    }

    public function test_home_shows_all_card_labels(): void
    {
        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertSee(__('Total Members'));
        $response->assertSee(__("Today's Meals"));
        $response->assertSee(__('Total Meals (this month)'));
        $response->assertSee(__('Current Meal Rate'));
        $response->assertSee(__('Monthly Expenses'));
        $response->assertSee(__('Total Credit (advance)'));
        $response->assertSee(__('Total Dues'));
        // The old single net-balance card was split into Credit + Dues.
        $response->assertDontSee(__('Member balances (net)'));
    }

    public function test_home_shows_split_credit_and_dues_from_balances(): void
    {
        $member = Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
        ]);
        // advance_balances is the running-total table the cards sum.
        AdvanceBalance::factory()->create([
            'member_id' => $member->id,
            'balance' => 300,       // credit (prepaid)
            'due_balance' => 120,   // dues (owed)
        ]);

        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertSee(number_format(300, 2));   // Total Credit
        $response->assertSee(number_format(120, 2));   // Total Dues
    }

    public function test_home_shows_pending_meal_off_banner_when_pending(): void
    {
        $member = Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
        ]);

        MealOffRequest::factory()->count(2)->create([
            'mess_id' => Mess::activeId(),
            'member_id' => $member->id,
            'status' => MealOffStatus::PENDING,
        ]);

        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertSee(route('mess.meal-off.index'));
        // trans_choice ":count pending meal off requests"
        $response->assertSee('pending meal off request');
    }

    public function test_home_hides_banner_when_none_pending(): void
    {
        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('pending meal off request');
    }

    public function test_home_renders_chart_init_with_data(): void
    {
        // Seed some data so charts have non-empty labels
        $member = Member::factory()->create([
            'mess_id' => Mess::activeId(),
            'status' => MemberStatus::ACTIVE,
        ]);
        $bazar = ExpenseCategory::factory()->create([
            'mess_id' => Mess::activeId(),
            'kind' => ExpenseKind::BAZAR,
        ]);
        Expense::factory()->create([
            'mess_id' => Mess::activeId(),
            'expense_category_id' => $bazar->id,
            'date' => now()->toDateString(),
            'amount' => 500,
        ]);
        MealEntry::factory()->create([
            'mess_id' => Mess::activeId(),
            'member_id' => $member->id,
            'date' => now()->toDateString(),
            'breakfast' => true,
            'lunch' => true,
            'dinner' => true,
        ]);

        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertSee("initDashboardChart('bazar-collection-chart'", false);
        $response->assertSee("initDashboardChart('expense-category-chart'", false);
    }

    public function test_zero_meal_rate_shows_hint(): void
    {
        $response = $this->actingAs($this->admin())->get(route('home'));

        $response->assertOk();
        $response->assertSee(__('no bazar recorded yet'));
    }

    public function test_member_role_forbidden(): void
    {
        $this->actingAs($this->member())
            ->get(route('home'))
            ->assertForbidden();
    }
}
