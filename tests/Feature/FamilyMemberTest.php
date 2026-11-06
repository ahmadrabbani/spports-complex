<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\ReconciliationAccount;
use App\Models\SportsComplex;
use App\Models\User;
use Database\Seeders\ReconciliationAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FamilyMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_and_portal_relationships_always_link_to_primary_member(): void
    {
        $complex = SportsComplex::create(['name' => 'Test', 'code' => 'TT']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'PRIMARY', 'full_name' => 'Primary', 'status' => 'active', 'reconciliation_account' => '260108']);
        $parent = $member->familyMembers()->create(['full_name' => 'Existing', 'relationship' => 'Son']);
        $admin = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $complex->id]);
        $owner = User::factory()->create(['role' => 'member', 'member_id' => $member->id]);
        foreach ([[$admin, '/api'], [$owner, '/api/portal']] as [$user, $prefix]) {
            $url = "$prefix/members/{$member->id}/family-members";
            foreach (['Son', 'Wife', 'Daughter', 'Son-in-law', 'Daughter-in-law'] as $relationship) {
                $this->actingAs($user)->postJson($url, ['full_name' => 'Synthetic relative', 'relationship' => $relationship])
                    ->assertCreated()->assertJsonPath('member_id', $member->id)->assertJsonPath('parent_family_member_id', null)->assertJsonPath('relationship', $relationship);
            }
            $this->postJson($url, ['full_name' => 'Invalid', 'relationship' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('relationship');
            $this->postJson($url, ['full_name' => 'Nested', 'relationship' => 'Son', 'parent_family_member_id' => $parent->id])->assertUnprocessable()->assertJsonValidationErrors('parent_family_member_id');
        }
    }

    public function test_individual_members_cannot_add_dependents_through_staff_or_portal_even_when_frozen(): void
    {
        $this->seed(ReconciliationAccountSeeder::class);
        $complex = SportsComplex::create(['name' => 'Test', 'code' => 'TT', 'slug' => 'test', 'city' => 'Lahore']);
        $admin = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $complex->id]);
        foreach (['260110', '260115'] as $code) {
            $category = MembershipCategory::whereHas('account', fn ($q) => $q->where('code', $code))->firstOrFail();
            $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => $code, 'full_name' => 'Individual', 'status' => 'active', 'membership_category_id' => $category->id]);
            $existing = $member->familyMembers()->create(['full_name' => 'Historical dependent', 'relationship' => 'Son']);
            $owner = User::factory()->create(['role' => 'member', 'member_id' => $member->id]);
            foreach ([false, true] as $frozen) {
                if ($frozen) {
                    $member->update(['status' => 'frozen', 'reconciliation_account' => '260111', 'original_reconciliation_account' => $code]);
                }
                foreach ([[$admin, '/api'], [$owner, '/api/portal']] as [$user, $prefix]) {
                    $url = "$prefix/members/{$member->id}/family-members";
                    $this->actingAs($user)->getJson($url.'?include_eligibility=1')->assertOk()->assertJsonPath('can_add_family_members', false)->assertJsonCount(1, 'members');
                    $this->postJson($url, ['full_name' => 'Blocked', 'relationship' => 'Son'])->assertUnprocessable()->assertJsonPath('code', 'FAMILY_MEMBERS_NOT_ALLOWED');
                }
            }
            $this->assertSame(1, $member->familyMembers()->count());
        }
    }

    public function test_legacy_individual_accounts_and_plans_are_blocked_and_family_categories_are_allowed(): void
    {
        $this->seed(ReconciliationAccountSeeder::class);
        $complex = SportsComplex::create(['name' => 'Test', 'code' => 'TT', 'slug' => 'test', 'city' => 'Lahore']);
        $admin = User::factory()->create(['role' => 'admin']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'LEGACY', 'full_name' => 'Legacy', 'status' => 'active', 'reconciliation_account' => '260110']);
        $url = "/api/members/{$member->id}/family-members";
        $payload = ['full_name' => 'Dependent', 'relationship' => 'Son'];
        $this->actingAs($admin)->postJson($url, $payload)->assertUnprocessable();
        $member->update(['reconciliation_account' => null]);
        $plan = MembershipPlan::create(['name' => 'Individual', 'joining_fee' => 0, 'recurring_fee' => 0, 'is_active' => true]);
        $membership = $member->memberships()->create(['membership_plan_id' => $plan->id, 'starts_on' => today(), 'status' => 'active']);
        $this->postJson($url, $payload)->assertUnprocessable();
        $membership->update(['status' => 'cancelled']);
        foreach (['260108', '260112'] as $code) {
            $category = MembershipCategory::whereHas('account', fn ($q) => $q->where('code', $code))->firstOrFail();
            $member->update(['membership_category_id' => $category->id]);
            $this->getJson($url.'?include_eligibility=1')->assertOk()->assertJsonPath('can_add_family_members', true);
            $this->postJson($url, $payload)->assertCreated();
        }
        // Remapping a category must not bypass its individual classification.
        $category = MembershipCategory::where('name', 'General Public Individual Membership')->firstOrFail();
        $category->update(['reconciliation_account_id' => ReconciliationAccount::where('code', '260108')->value('id')]);
        $member->update(['membership_category_id' => $category->id]);
        $this->postJson($url, $payload)->assertUnprocessable();
    }

    public function test_family_members_can_be_linked_and_cards_are_scoped(): void
    {
        $complex = SportsComplex::create(['name' => 'Test', 'code' => 'TT', 'slug' => 'test', 'city' => 'Lahore']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST1', 'full_name' => 'Sponsor', 'status' => 'active']);
        $other = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST2', 'full_name' => 'Other', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'member', 'member_id' => $member->id, 'must_change_password' => false]);
        $base = '/api/portal/members/'.$member->id.'/family-members';
        $first = $this->actingAs($user)->postJson($base, ['full_name' => 'Spouse', 'relationship' => 'Wife'])->assertCreated()->json('id');
        $this->postJson($base, ['full_name' => 'Child', 'relationship' => 'Daughter', 'parent_family_member_id' => $first])->assertUnprocessable()->assertJsonValidationErrors('parent_family_member_id');
        $child = $this->postJson($base, ['full_name' => 'Child', 'relationship' => 'Daughter'])->assertCreated()->assertJsonPath('parent_family_member_id', null)->json('id');
        $this->getJson($base)->assertOk()->assertJsonCount(2);
        $this->get('/family-members/'.$child.'/card')->assertOk()->assertSee('Child')->assertSee('FAMILY MEMBERSHIP CARD');
        $this->get('/family-members/'.$child.'/card.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $foreign = $other->familyMembers()->create(['full_name' => 'Foreign', 'relationship' => 'Son']);
        $this->postJson($base, ['full_name' => 'Bad', 'relationship' => 'Son', 'parent_family_member_id' => $foreign->id])->assertUnprocessable();
        $this->get('/family-members/'.$foreign->id.'/card.pdf')->assertForbidden();
        $this->postJson('/api/portal/members/'.$other->id.'/family-members', ['full_name' => 'Bad', 'relationship' => 'Son'])->assertForbidden();
        $this->get('/family-members/verify/'.$child)->assertForbidden();
        $this->get(URL::temporarySignedRoute('family.verify', now()->addDay(), ['familyMember' => $child]))->assertOk()->assertSee('Child');
        $staff = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $complex->id]);
        $this->actingAs($staff)->getJson('/api/members/'.$member->id.'/family-members')->assertOk();
        $staff->update(['sports_complex_id' => SportsComplex::create(['name' => 'Other', 'code' => 'OT', 'slug' => 'other', 'city' => 'Lahore'])->id]);
        $this->get('/family-members/'.$child.'/card')->assertForbidden();
    }
}
