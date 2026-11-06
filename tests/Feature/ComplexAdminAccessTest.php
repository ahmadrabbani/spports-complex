<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\SportsComplex;
use App\Models\StaffAccessAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComplexAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $a = SportsComplex::create(['name' => 'Synthetic Alpha', 'code' => 'ALPHA', 'cost_center' => '21070001', 'profit_center' => '21070001']);
        $b = SportsComplex::create(['name' => 'Synthetic Beta', 'code' => 'BETA', 'cost_center' => '21040001', 'profit_center' => '21040001']);
        $admin = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $a->id, 'is_complex_admin' => true, 'password' => 'TestPassword!123', 'must_change_password' => false]);
        $attrs = ['full_name' => 'Synthetic A customer', 'cnic' => '3520200000001', 'phone' => '03000000001', 'address' => 'Test address', 'membership_category_id' => MembershipCategory::where('name', 'General Public Family Membership')->value('id')];
        $ma = Member::create($attrs + ['sports_complex_id' => $a->id, 'customer_no' => 'ALPHA-CUSTOMER']);
        $mb = Member::create(array_replace($attrs, ['full_name' => 'Synthetic B customer', 'sports_complex_id' => $b->id, 'customer_no' => 'BETA-CUSTOMER']));

        return [$admin, $a, $b, $ma, $mb];
    }

    public function test_complex_admin_logs_in_and_sees_only_assigned_customers_dashboard_and_references(): void
    {
        [$u,$a,$b,$ma,$mb] = $this->fixtures();
        MembershipPlan::create(['sports_complex_id' => $b->id, 'name' => 'Foreign private plan']);
        $this->postJson('/login', ['email' => $u->email, 'password' => 'TestPassword!123'])->assertOk()->assertJsonPath('user.sports_complex_id', $a->id)->assertJsonPath('user.sports_complex.name', $a->name)->assertJsonMissingPath('user.password');
        $this->getJson('/api/members')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ma->id);
        $this->getJson('/api/members?complex_id='.$b->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('metrics.members', 1)->assertJsonCount(1, 'complexes')->assertJsonPath('recent_members.0.id', $ma->id);
        $r = $this->getJson('/api/reference-data')->assertOk()->assertJsonCount(1, 'complexes')->assertJsonPath('complexes.0.id', $a->id);
        $this->assertNotContains('Foreign private plan', collect($r->json('plans'))->pluck('name'));
    }

    public function test_complex_admin_cannot_create_edit_move_or_read_foreign_customers(): void
    {
        [$u,$a,$b,$ma,$mb] = $this->fixtures();
        $this->actingAs($u);
        $data = ['sports_complex_id' => $a->id, 'customer_no' => 'NEW-A', 'full_name' => 'New Alpha Customer', 'status' => 'active', 'membership_category_id' => $ma->membership_category_id];
        $this->postJson('/api/members', $data)->assertForbidden();
        $this->putJson('/api/members/'.$ma->id, ['sports_complex_id' => $a->id, 'customer_no' => $ma->customer_no, 'full_name' => 'Updated Alpha', 'status' => 'active'])->assertForbidden();
        $data['sports_complex_id'] = $b->id;
        $data['customer_no'] = 'FOREIGN-CREATE';
        $this->postJson('/api/members', $data)->assertForbidden();
        $this->getJson('/api/members/'.$mb->id)->assertForbidden();
        $this->getJson('/api/members/'.$mb->id.'/ledger')->assertForbidden();
        $this->putJson('/api/members/'.$ma->id, ['sports_complex_id' => $b->id, 'customer_no' => $ma->customer_no, 'full_name' => 'Moved', 'status' => 'active'])->assertForbidden();
        $this->deleteJson('/api/members/'.$mb->id)->assertForbidden();
        $this->assertSame($a->id, $ma->fresh()->sports_complex_id);
        $this->assertFalse($mb->fresh()->trashed());
    }

    public function test_private_documents_family_photo_card_and_challan_urls_enforce_complex_scope(): void
    {
        [$u,$a,$b,$ma,$mb] = $this->fixtures();
        $f = $mb->familyMembers()->create(['full_name' => 'Synthetic dependent', 'relationship' => 'Son']);
        $c = Challan::create(['member_id' => $mb->id, 'sports_complex_id' => $b->id, 'challan_no' => 'BETA-CH', 'purpose' => 'Synthetic fee', 'amount' => 1000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'issued', 'sap_status' => 'posted']);
        $this->actingAs($u);
        foreach (['/api/members/'.$mb->id.'/documents', '/api/members/'.$mb->id.'/family-members', '/member-photos/'.$mb->id, '/family-members/'.$f->id.'/card', '/members/'.$mb->id.'/membership-card', '/challans/'.$c->id.'/print', '/api/challans/'.$c->id.'/adjustments'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/api/members/'.$mb->id.'/photo', [])->assertForbidden();
        $this->getJson('/api/challans')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/challans/'.$c->id.'/adjust', ['reason' => 'Foreign'])->assertForbidden();
        $this->postJson('/api/members/'.$mb->id.'/freeze', ['reason' => 'Foreign', 'starts_on' => today()->toDateString()])->assertForbidden();
    }

    public function test_only_central_admin_can_create_update_or_list_staff_and_created_accounts_are_scoped(): void
    {
        [$scoped,$a,$b] = $this->fixtures();
        $central = User::factory()->create(['role' => 'admin']);
        $this->actingAs($central);
        $r = $this->postJson('/api/staff-users', ['name' => 'Beta Administrator', 'email' => 'Beta.Admin@Sports.Local', 'sports_complex_id' => $b->id])->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonPath('user.is_complex_admin', true)->assertJsonPath('user.sports_complex_id', $b->id)->assertJsonPath('user.must_change_password', true)->assertHeader('Cache-Control', 'no-store, private');
        $user = User::find($r->json('user.id'));
        $this->assertTrue(Hash::check($r->json('temporary_password'), $user->password));
        $this->assertSame('beta.admin@sports.local', $user->email);
        $this->getJson('/api/staff-users')->assertOk()->assertJsonMissingPath('users.0.password')->assertJsonMissingPath('users.0.temporary_password');
        $this->putJson('/api/staff-users/'.$user->id, ['sports_complex_id' => null])->assertUnprocessable();
        $this->putJson('/api/staff-users/'.$central->id, ['is_active' => false])->assertForbidden();
        $this->putJson('/api/staff-users/'.$user->id, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('staff_access_audits', ['user_id' => $user->id, 'actor_id' => $central->id, 'action' => 'created']);
        $this->actingAs($scoped)->getJson('/api/staff-users')->assertForbidden();
        $this->postJson('/api/staff-users', ['name' => 'Escalation', 'email' => 'other@sports.local', 'sports_complex_id' => $b->id])->assertForbidden();
        $this->putJson('/api/staff-users/'.$scoped->id, ['sports_complex_id' => $b->id])->assertForbidden();
    }

    public function test_first_login_requires_password_change_and_validates_current_and_strong_new_password(): void
    {
        [$u,$a] = $this->fixtures();
        $u->update(['must_change_password' => true]);
        $this->actingAs($u);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('must_change_password', true);
        $this->getJson('/api/members')->assertConflict()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->putJson('/api/staff/password', ['current_password' => 'wrong', 'password' => 'NewStrongPassword!123', 'password_confirmation' => 'NewStrongPassword!123'])->assertUnprocessable();
        $this->putJson('/api/staff/password', ['current_password' => 'TestPassword!123', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->putJson('/api/staff/password', ['current_password' => 'TestPassword!123', 'password' => 'NewStrongPassword!123', 'password_confirmation' => 'NewStrongPassword!123'])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->assertTrue(Hash::check('NewStrongPassword!123', $u->fresh()->password));
        $this->getJson('/api/members')->assertOk();
        $this->assertDatabaseHas('staff_access_audits', ['user_id' => $u->id, 'action' => 'password_changed']);
    }

    public function test_disabled_or_unassigned_complex_admin_and_inactive_complex_fail_closed(): void
    {
        [$u,$a] = $this->fixtures();
        $u->update(['is_active' => false]);
        $this->postJson('/login', ['email' => $u->email, 'password' => 'TestPassword!123'])->assertUnprocessable();
        $this->actingAs($u)->getJson('/api/members')->assertForbidden();
        $u->update(['is_active' => true, 'sports_complex_id' => null]);
        $this->actingAs($u)->getJson('/api/members')->assertForbidden();
        $this->postJson('/login', ['email' => $u->email, 'password' => 'TestPassword!123'])->assertUnprocessable();
        $u->update(['sports_complex_id' => $a->id]);
        $a->update(['is_active' => false]);
        $this->postJson('/login', ['email' => $u->email, 'password' => 'TestPassword!123'])->assertUnprocessable();
    }

    public function test_scoped_admin_cannot_change_global_configuration_or_escalate_scope(): void
    {
        [$u,$a,$b] = $this->fixtures();
        $this->actingAs($u);
        $this->putJson('/api/accounting/sports-complexes/'.$b->id, ['cost_center' => '21070001', 'profit_center' => '21070001'])->assertForbidden();
        $this->putJson('/api/accounting/membership-categories/1', [])->assertForbidden();
        $this->postJson('/api/admin/sports-complexes/'.$b->id.'/facilities', [])->assertForbidden();
        $this->postJson('/api/admin/facilities/bulk', ['sports_complex_ids' => [$a->id, $b->id]])->assertForbidden();
        $this->postJson('/api/accounting/sports-complexes/'.$b->id.'/images', [])->assertForbidden();
        $this->postJson('/api/bank/diagnostics/test-connection')->assertForbidden();
    }

    public function test_provisioning_is_idempotent_uses_unique_passwords_and_never_resets_existing_accounts(): void
    {
        [$existing,$a,$b] = $this->fixtures();
        $hash = $existing->password;
        $path = storage_path('framework/testing/complex-admin-'.Str::uuid().'.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        try {
            $this->artisan('staff:provision-complex-admins')->assertSuccessful();
            $this->assertSame(1, User::where('role', 'admin')->count());
            $this->artisan('staff:provision-complex-admins', ['--apply' => true, '--credentials' => $path])->assertSuccessful();
            $rows = array_map('str_getcsv', file($path));
            $this->assertCount(2, $rows);
            $this->assertSame('beta.admin@sports.local', $rows[1][2]);
            $new = User::where('email', $rows[1][2])->firstOrFail();
            $this->assertTrue(Hash::check($rows[1][3], $new->password));
            $this->assertTrue($new->is_complex_admin);
            $this->assertTrue($new->must_change_password);
            $this->assertSame('600', decoct(fileperms($path) & 0777));
            $this->artisan('staff:provision-complex-admins', ['--apply' => true, '--credentials' => $path])->assertSuccessful();
            $this->assertSame(2, User::where('role', 'admin')->count());
            $this->assertSame($hash, $existing->fresh()->password);
            $this->assertStringNotContainsString($rows[1][3], StaffAccessAudit::all()->toJson());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
