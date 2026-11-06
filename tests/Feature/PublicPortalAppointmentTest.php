<?php

namespace Tests\Feature;

use App\Models\AppointmentPurpose;
use App\Models\AppointmentSchedule;
use App\Models\Challan;
use App\Models\Facility;
use App\Models\FeeType;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\SportsComplex;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPortalAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_normalizes_unique_pakistani_cnic_and_phone(): void
    {
        $response = $this->postJson('/api/public/register', [
            'name' => 'Ahmad Rabbani', 'cnic' => '35202-1234567-1', 'email' => 'ahmad@example.com',
            'phone' => '+92 321 1234567', 'password' => 'Sport1234', 'password_confirmation' => 'Sport1234',
        ])->assertCreated()->assertJsonPath('user.role', 'member');

        $this->assertDatabaseHas('users', ['id' => $response->json('user.id'), 'cnic' => '3520212345671', 'phone' => '03211234567']);
        $this->postJson('/api/portal/logout')->assertNoContent();
        $this->postJson('/api/public/register', [
            'name' => 'Duplicate', 'cnic' => '3520212345671', 'email' => 'other@example.com',
            'phone' => '03001234567', 'password' => 'Sport1234', 'password_confirmation' => 'Sport1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('cnic');
    }

    public function test_member_can_login_with_cnic_or_email(): void
    {
        User::factory()->create(['role' => 'member', 'cnic' => '3520212345671', 'email' => 'member@example.com', 'password' => 'Sport1234']);
        $this->postJson('/api/public/login', ['login' => '35202-1234567-1', 'password' => 'Sport1234'])->assertOk();
        $this->postJson('/api/portal/logout')->assertNoContent();
        $this->postJson('/api/public/login', ['login' => 'member@example.com', 'password' => 'Sport1234'])->assertOk();
    }

    public function test_staff_session_can_intentionally_switch_to_member_portal_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $memberUser = User::factory()->create([
            'role' => 'member',
            'cnic' => '3520212345671',
            'email' => 'switch.member@example.com',
            'password' => 'Sport1234',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/api/public/login', [
            'login' => '35202-1234567-1',
            'password' => 'Sport1234',
        ])->assertOk()->assertJsonPath('user.id', $memberUser->id);

        $this->assertAuthenticatedAs($memberUser);
    }

    public function test_provisioned_member_must_replace_the_temporary_password_before_using_portal(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'slug' => 'gulberg', 'code' => 'GB']);
        $member = Member::create([
            'sports_complex_id' => $complex->id, 'customer_no' => '2400000001', 'full_name' => 'Portal Member',
            'cnic' => '3520212345671', 'phone' => '03211234567', 'status' => 'active',
        ]);

        $this->artisan('members:provision-logins', ['--customer' => [$member->customer_no], '--password' => 'Member@1234'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['member_id' => $member->id, 'cnic' => '3520212345671', 'must_change_password' => true]);

        $this->postJson('/api/public/login', ['login' => '35202-1234567-1', 'password' => 'Member@1234'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/portal/dashboard')->assertConflict()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->putJson('/api/portal/account', ['name' => 'Changed', 'email' => 'changed@example.com', 'phone' => '03001234567'])
            ->assertConflict()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->putJson('/api/portal/password', [
            'current_password' => 'Member@1234', 'password' => 'Private@5678', 'password_confirmation' => 'Private@5678',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/portal/dashboard')->assertOk();
    }

    public function test_member_sees_all_complex_facilities_and_maintenance_notifications(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'slug' => 'gulberg', 'code' => 'GB']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000001', 'full_name' => 'Portal Member', 'cnic' => '3520212345671']);
        $individual = MembershipPlan::create(['name' => 'Individual', 'duration_months' => 12]);
        $family = MembershipPlan::create(['name' => 'Family', 'duration_months' => 12]);
        Membership::create(['member_id' => $member->id, 'membership_plan_id' => $individual->id, 'starts_on' => today(), 'status' => 'active']);
        $shared = Facility::create(['sports_complex_id' => $complex->id, 'name' => 'Jogging Track', 'slug' => 'jogging-track', 'is_active' => true]);
        $pool = Facility::create(['sports_complex_id' => $complex->id, 'name' => 'Swimming Pool', 'slug' => 'swimming-pool', 'is_active' => true, 'operational_status' => 'maintenance', 'maintenance_notice' => 'Closed for filtration work.']);
        $pool->membershipPlans()->attach($individual);
        $familyLounge = Facility::create(['sports_complex_id' => $complex->id, 'name' => 'Family Lounge', 'slug' => 'family-lounge', 'is_active' => true]);
        $familyLounge->membershipPlans()->attach($family);
        $portal = $this->member();
        $portal->update(['member_id' => $member->id]);

        $this->actingAs($portal)->getJson('/api/portal/facilities')->assertOk()
            ->assertJsonPath('sports_complex.name', 'Gulberg Sports Complex')
            ->assertJsonPath('sports_complex.code', 'GB')
            ->assertJsonCount(3, 'facilities')
            ->assertJsonFragment(['name' => $shared->name])
            ->assertJsonFragment(['name' => $pool->name])
            ->assertJsonFragment(['name' => $familyLounge->name])
            ->assertJsonPath('maintenance_notifications.0.message', 'Closed for filtration work.');
        $this->actingAs($portal)->getJson('/api/portal/dashboard')->assertOk()
            ->assertJsonPath('sports_complex.name', 'Gulberg Sports Complex')
            ->assertJsonFragment(['type' => 'facility_maintenance']);
        $this->actingAs($portal)->getJson('/api/portal/account')->assertOk()
            ->assertJsonPath('member.sports_complex.name', 'Gulberg Sports Complex');
    }

    public function test_member_can_print_only_their_own_challan(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'slug' => 'gulberg', 'code' => 'GB']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000001', 'full_name' => 'Portal Member', 'cnic' => '3520212345671']);
        $other = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000002', 'full_name' => 'Other Member', 'cnic' => '3520212345672']);
        $challan = Challan::create(['challan_no' => 'CH-2026-GB-000001', 'sports_complex_id' => $complex->id, 'member_id' => $member->id, 'purpose' => 'Membership fee', 'amount' => 5000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'generated', 'sap_status' => 'posted', 'sap_document_no' => 'SAP-1']);
        $otherChallan = Challan::create(['challan_no' => 'CH-2026-GB-000002', 'sports_complex_id' => $complex->id, 'member_id' => $other->id, 'purpose' => 'Private fee', 'amount' => 7000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'generated', 'sap_status' => 'posted', 'sap_document_no' => 'SAP-2']);
        $portal = $this->member();
        $portal->update(['member_id' => $member->id]);

        $this->actingAs($portal)->getJson('/api/portal/ledger')->assertOk()
            ->assertJsonPath('challans.0.download_url', '/api/portal/challans/'.$challan->id.'/print')
            ->assertJsonMissing(['challan_no' => $otherChallan->challan_no]);
        $this->actingAs($portal)->get('/api/portal/challans/'.$challan->id.'/print')->assertOk()->assertSee($challan->challan_no);
        $this->actingAs($portal)->get('/api/portal/challans/'.$otherChallan->id.'/print')->assertForbidden();
    }

    public function test_public_complex_page_returns_database_facilities(): void
    {
        [$complex] = $this->appointmentData();
        Facility::create(['sports_complex_id' => $complex->id, 'name' => 'Swimming Pool', 'icon' => 'swimming', 'description' => 'Indoor pool', 'is_active' => true]);

        $this->getJson('/api/public/sports-complexes/'.$complex->slug)->assertOk()
            ->assertJsonPath('facilities.0.name', 'Swimming Pool')->assertJsonPath('facilities.0.icon', 'swimming');
    }

    public function test_public_portal_prefers_actual_photography_over_legacy_ai_media(): void
    {
        [$complex] = $this->appointmentData();
        $original = $complex->images()->create([
            'url' => '/images/original.jpg', 'alt_text' => 'Original complex photograph', 'sort_order' => 1,
        ]);
        $complex->images()->create([
            'url' => '/images/complex-ai.webp', 'alt_text' => 'AI-enhanced architectural view',
            'sort_order' => 2, 'is_featured' => true,
        ]);
        $complex->update(['image_url' => '/images/complex-ai.webp']);

        $this->getJson('/api/public/portal')->assertOk()
            ->assertJsonPath('complexes.0.image_url', $original->url);
        $this->getJson('/api/public/sports-complexes/'.$complex->slug)->assertOk()
            ->assertJsonPath('image_url', $original->url);
    }

    public function test_admin_can_upload_and_feature_a_local_sports_complex_image(): void
    {
        Storage::fake('public');
        [$complex] = $this->appointmentData();
        $admin = User::factory()->create(['role' => 'admin']);

        $image = $this->actingAs($admin)->post('/api/accounting/sports-complexes/'.$complex->id.'/images', [
            'image' => UploadedFile::fake()->image('pool.jpg', 1200, 800),
            'alt_text' => 'Swimming pool at Gulberg Sports Complex',
            'is_featured' => '1',
        ])->assertCreated()->assertJsonPath('is_featured', true)->json();

        $this->assertStringStartsWith('/storage/sports-complexes/'.$complex->id.'/', $image['url']);
        Storage::disk('public')->assertExists($image['storage_path']);
        $this->assertDatabaseHas('sports_complexes', ['id' => $complex->id, 'image_url' => $image['url']]);
        $this->getJson('/api/public/sports-complexes/'.$complex->slug)->assertOk()
            ->assertJsonPath('image_url', $image['url'])
            ->assertJsonPath('images.0.alt_text', 'Swimming pool at Gulberg Sports Complex');
    }

    public function test_admin_can_manage_multi_photo_gallery_metadata_order_and_replacement(): void
    {
        Storage::fake('public');
        [$complex] = $this->appointmentData();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/api/accounting/sports-complexes/'.$complex->id.'/images', [
            'images' => [
                UploadedFile::fake()->image('pool.jpg', 1200, 800),
                UploadedFile::fake()->image('gym.webp', 1200, 800),
            ],
            'title' => 'Aquatics and fitness',
            'caption' => 'Current facility photography.',
            'alt_text' => 'Facilities at Gulberg Sports Complex',
            'facility_category' => 'swimming_pool',
            'is_featured' => '1',
        ])->assertCreated()->assertJsonCount(2, 'images')
            ->assertJsonPath('images.0.facility_category', 'swimming_pool')
            ->assertJsonPath('images.0.is_featured', true);

        $first = $response->json('images.0');
        $oldPath = $first['storage_path'];
        $this->actingAs($admin)->post('/api/accounting/sports-complexes/'.$complex->id.'/images/'.$first['id'], [
            '_method' => 'PUT',
            'image' => UploadedFile::fake()->image('replacement.jpg', 900, 1200),
            'title' => 'Competition swimming pool',
            'caption' => 'Pool deck and lanes.',
            'alt_text' => 'Competition swimming pool lanes',
            'facility_category' => 'swimming_pool',
            'sort_order' => 9,
        ])->assertOk()->assertJsonPath('title', 'Competition swimming pool')
            ->assertJsonPath('sort_order', 9);

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertDatabaseCount('sports_complex_images', 2);
        $this->getJson('/api/public/sports-complexes/'.$complex->slug)->assertOk()
            ->assertJsonFragment([
                'title' => 'Competition swimming pool',
                'caption' => 'Pool deck and lanes.',
                'facility_category' => 'swimming_pool',
            ]);
    }

    public function test_non_admin_cannot_manage_sports_complex_images(): void
    {
        Storage::fake('public');
        [$complex] = $this->appointmentData();
        $operator = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $complex->id]);

        $this->actingAs($operator)->post('/api/accounting/sports-complexes/'.$complex->id.'/images', [
            'image' => UploadedFile::fake()->image('pool.jpg'),
        ])->assertForbidden();
    }

    public function test_admin_can_assign_a_common_facility_to_multiple_complexes_and_manage_it(): void
    {
        [$firstComplex] = $this->appointmentData();
        $secondComplex = SportsComplex::create(['name' => 'Canal Sports Complex', 'slug' => 'canal-sports-complex', 'code' => 'CW']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'sports_complex_ids' => [$firstComplex->id, $secondComplex->id],
            'name' => 'Swimming Pools', 'icon' => 'swimming', 'description' => 'Managed swimming facilities.',
            'capacity' => 100, 'hourly_rate' => 2500, 'is_active' => true,
        ];

        $this->actingAs($admin)->postJson('/api/admin/facilities/bulk', $payload)
            ->assertCreated()->assertJsonCount(2, 'facilities');
        $this->assertDatabaseCount('facilities', 2);

        $payload['hourly_rate'] = 3000;
        $this->actingAs($admin)->postJson('/api/admin/facilities/bulk', $payload)->assertCreated();
        $this->assertDatabaseCount('facilities', 2);
        $facility = Facility::where('sports_complex_id', $firstComplex->id)->firstOrFail();
        $this->assertSame('3000.00', $facility->hourly_rate);

        $this->actingAs($admin)->putJson('/api/admin/facilities/'.$facility->id, [
            'name' => 'Swimming Pools', 'icon' => 'swimming', 'description' => 'Temporarily unavailable.',
            'capacity' => 100, 'hourly_rate' => 3000, 'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/public/sports-complexes/'.$firstComplex->slug)
            ->assertOk()->assertJsonCount(0, 'facilities');
        $this->actingAs($admin)->deleteJson('/api/admin/facilities/'.$facility->id)->assertNoContent();
        $this->assertDatabaseMissing('facilities', ['id' => $facility->id]);
    }

    public function test_slot_capacity_prevents_duplicate_booking_beyond_configured_limit(): void
    {
        [$complex, $purpose, $date] = $this->appointmentData(1);
        $first = $this->member('one@example.com', '3520212345671');
        $second = $this->member('two@example.com', '3520212345672');
        $payload = ['sports_complex_id' => $complex->id, 'appointment_purpose_id' => $purpose->id, 'appointment_date' => $date, 'appointment_time' => '09:00'];

        $this->actingAs($first)->postJson('/api/portal/appointments', $payload)->assertCreated()->assertJsonPath('status', 'pending');
        $this->actingAs($second)->postJson('/api/portal/appointments', $payload)->assertUnprocessable()->assertJsonValidationErrors('appointment_time');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_current_and_past_dates_are_not_bookable(): void
    {
        [$complex, $purpose] = $this->appointmentData();
        $member = $this->member();
        $this->actingAs($member)->postJson('/api/portal/appointments', [
            'sports_complex_id' => $complex->id, 'appointment_purpose_id' => $purpose->id,
            'appointment_date' => today()->toDateString(), 'appointment_time' => '09:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_date');
    }

    public function test_form_number_is_blocked_until_arrival_and_file_entry_then_notifies_user(): void
    {
        [$complex, $purpose, $date] = $this->appointmentData();
        $member = $this->member();
        $admin = User::factory()->create(['role' => 'admin']);
        $appointment = $this->actingAs($member)->postJson('/api/portal/appointments', [
            'sports_complex_id' => $complex->id, 'appointment_purpose_id' => $purpose->id,
            'appointment_date' => $date, 'appointment_time' => '09:00',
        ])->assertCreated()->json();

        $this->actingAs($admin)->postJson('/api/admin/appointments/'.$appointment['id'].'/assign-form', ['form_number' => 'FORM-2026-000001'])
            ->assertUnprocessable()->assertJsonValidationErrors('form_number');
        $this->actingAs($admin)->postJson('/api/admin/appointments/'.$appointment['id'].'/transition', ['status' => 'confirmed'])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/appointments/'.$appointment['id'].'/transition', ['status' => 'visited'])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/appointments/'.$appointment['id'].'/file-entry', [
            'documents_verified' => true, 'file_entry_completed' => true, 'file_entry_reference' => 'FILE-77', 'remarks' => 'Verified',
        ])->assertOk()->assertJsonPath('status', 'file_entered');
        $this->actingAs($admin)->postJson('/api/admin/appointments/'.$appointment['id'].'/assign-form', ['form_number' => 'FORM-2026-000001'])
            ->assertOk()->assertJsonPath('status', 'form_assigned')->assertJsonPath('form_number', 'FORM-2026-000001');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $member->id, 'type' => 'form_number_assigned']);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment['id'], 'event' => 'file_entry_completed']);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment['id'], 'event' => 'form_number_assigned']);
    }

    public function test_member_cannot_view_another_members_appointment(): void
    {
        [$complex, $purpose, $date] = $this->appointmentData();
        $owner = $this->member('owner@example.com', '3520212345671');
        $other = $this->member('other@example.com', '3520212345672');
        $appointment = $this->actingAs($owner)->postJson('/api/portal/appointments', [
            'sports_complex_id' => $complex->id, 'appointment_purpose_id' => $purpose->id,
            'appointment_date' => $date, 'appointment_time' => '09:00',
        ])->assertCreated()->json();

        $this->actingAs($other)->getJson('/api/portal/appointments/'.$appointment['public_id'])->assertForbidden();
    }

    public function test_portal_member_cannot_access_staff_member_or_appointment_apis(): void
    {
        $member = $this->member();

        $this->actingAs($member)->getJson('/api/members')->assertForbidden();
        $this->actingAs($member)->getJson('/api/admin/appointments')->assertForbidden();
        $this->actingAs($member)->getJson('/api/me')->assertForbidden();
    }

    public function test_linked_member_can_view_only_their_ledger_totals_and_transactions(): void
    {
        $complex = SportsComplex::create(['name' => 'Canal Sports Complex', 'slug' => 'canal-sports-complex', 'code' => 'CW']);
        $member = Member::create([
            'sports_complex_id' => $complex->id, 'customer_no' => '100001', 'full_name' => 'Ahmad Rabbani',
            'cnic' => '3520212345671', 'reconciliation_account' => '260110',
        ]);
        $otherMember = Member::create([
            'sports_complex_id' => $complex->id, 'customer_no' => '100002', 'full_name' => 'Other Member',
            'cnic' => '3520212345672', 'reconciliation_account' => '260108',
        ]);
        $feeType = FeeType::create(['code' => 'INDIVIDUAL', 'name' => 'Individual Membership', 'gl_account' => '260110']);
        MemberCharge::create([
            'member_id' => $member->id, 'fee_type_id' => $feeType->id, 'description' => 'October membership fee',
            'amount' => 2500, 'gl_account' => '260110', 'reconciliation_account' => '260110', 'status' => 'outstanding',
        ]);
        MemberCharge::create([
            'member_id' => $otherMember->id, 'fee_type_id' => $feeType->id, 'description' => 'Private charge',
            'amount' => 9999, 'gl_account' => '260110', 'reconciliation_account' => '260108', 'status' => 'outstanding',
        ]);
        Payment::create([
            'member_id' => $member->id, 'receipt_no' => 'RCPT-1001', 'amount' => 1000,
            'paid_on' => today(), 'method' => 'cash', 'status' => 'paid',
        ]);
        $portalUser = $this->member();
        $portalUser->update(['member_id' => $member->id]);

        $this->actingAs($portalUser)->getJson('/api/portal/ledger')->assertOk()
            ->assertJsonPath('member.customer_no', '100001')
            ->assertJsonPath('summary.total_charged', '2500.00')
            ->assertJsonPath('summary.total_paid', '1000.00')
            ->assertJsonPath('summary.balance', '1500.00')
            ->assertJsonCount(2, 'transactions')
            ->assertJsonMissing(['description' => 'Private charge']);
    }

    public function test_unlinked_portal_account_receives_an_empty_ledger(): void
    {
        $portalUser = $this->member();

        $this->actingAs($portalUser)->getJson('/api/portal/ledger')->assertOk()
            ->assertJsonPath('member', null)
            ->assertJsonPath('summary.balance', '0.00')
            ->assertJsonCount(0, 'transactions');
    }

    public function test_complex_operator_cannot_manage_another_complex_appointment(): void
    {
        [$complex, $purpose, $date] = $this->appointmentData();
        $otherComplex = SportsComplex::create(['name' => 'Other', 'slug' => 'other', 'code' => 'OT']);
        $operator = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $otherComplex->id]);
        $member = $this->member();
        $appointment = $this->actingAs($member)->postJson('/api/portal/appointments', [
            'sports_complex_id' => $complex->id, 'appointment_purpose_id' => $purpose->id,
            'appointment_date' => $date, 'appointment_time' => '09:00',
        ])->assertCreated()->json();

        $this->actingAs($operator)->getJson('/api/admin/appointments/'.$appointment['id'])->assertForbidden();
    }

    private function appointmentData(int $capacity = 2): array
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'slug' => 'gulberg-sports-complex', 'code' => 'GB', 'is_active' => true]);
        $purpose = AppointmentPurpose::create(['name' => 'New Membership', 'is_active' => true]);
        $date = Carbon::tomorrow();
        while ($date->dayOfWeekIso === 7) {
            $date->addDay();
        }
        AppointmentSchedule::create(['sports_complex_id' => $complex->id, 'opening_time' => '09:00', 'closing_time' => '10:00', 'slot_duration_minutes' => 30, 'max_appointments_per_slot' => $capacity, 'working_days' => [$date->dayOfWeekIso], 'is_active' => true]);

        return [$complex, $purpose, $date->toDateString()];
    }

    private function member(string $email = 'member@example.com', string $cnic = '3520212345671'): User
    {
        return User::factory()->create(['name' => 'Ahmad Rabbani', 'email' => $email, 'cnic' => $cnic, 'phone' => '03211234567', 'role' => 'member']);
    }
}
