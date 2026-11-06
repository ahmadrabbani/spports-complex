<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MemberPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_replace_and_audit_an_optimized_member_photo(): void
    {
        Storage::fake('local');
        [$admin, $member] = $this->actors();

        $first = $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->image('portrait.jpg', 900, 1200)->size(1400),
        ])->assertOk()->assertJsonPath('message', 'Member photograph updated successfully.')
            ->assertJsonPath('member.id', $member->id);

        $member->refresh();
        $firstPath = $member->profile_image_path;
        Storage::disk('local')->assertExists($firstPath);
        $dimensions = getimagesizefromstring(Storage::disk('local')->get($firstPath));
        $this->assertSame([720, 720], [$dimensions[0], $dimensions[1]]);
        $this->assertSame('image/webp', $dimensions['mime']);
        $this->assertDatabaseHas('member_photo_audits', [
            'member_id' => $member->id, 'actor_user_id' => $admin->id, 'action' => 'uploaded', 'source' => 'admin',
        ]);

        $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->image('replacement.png', 700, 700),
        ])->assertOk();

        $member->refresh();
        $this->assertNotSame($firstPath, $member->profile_image_path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($member->profile_image_path);
        $this->assertDatabaseHas('member_photo_audits', ['member_id' => $member->id, 'action' => 'replaced']);
        $this->assertStringContainsString('?v=', $first->json('member.profile_image_url'));
    }

    public function test_only_admin_can_modify_member_photos_and_complex_scope_controls_viewing(): void
    {
        Storage::fake('local');
        [$admin, $member] = $this->actors();
        $manager = User::factory()->create(['role' => 'manager']);
        $operator = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $member->sports_complex_id]);
        $otherComplex = SportsComplex::create(['name' => 'Other', 'code' => 'OT', 'slug' => 'other', 'city' => 'Lahore', 'is_active' => true]);
        $otherOperator = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $otherComplex->id]);

        foreach ([$manager, $operator] as $staff) {
            $this->actingAs($staff)->post('/api/members/'.$member->id.'/photo', [
                'photo' => UploadedFile::fake()->image('blocked.jpg', 500, 500),
            ])->assertForbidden();
        }

        $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->image('allowed.jpg', 500, 500),
        ])->assertOk();
        $this->actingAs($operator)->get(route('members.photo', $member))->assertOk()->assertHeader('content-type', 'image/webp');
        $this->actingAs($otherOperator)->get(route('members.photo', $member))->assertForbidden();
    }

    public function test_invalid_or_undersized_files_are_rejected(): void
    {
        Storage::fake('local');
        [$admin, $member] = $this->actors();
        $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->createWithContent('fake.jpg', '<script>not an image</script>'),
        ])->assertSessionHasErrors('photo');
        $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->image('tiny.png', 100, 100),
        ])->assertSessionHasErrors('photo');
        $this->assertNull($member->fresh()->profile_image_path);
    }

    public function test_applicant_photo_is_stored_and_synchronized_to_linked_member(): void
    {
        Storage::fake('local');
        $complex = $this->complex();
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'P-2', 'membership_no' => 'M-2', 'full_name' => 'Applicant Member', 'cnic' => '3520200000002', 'status' => 'active']);

        $this->post('/api/public/register', [
            'name' => 'Applicant Member', 'cnic' => '35202-0000000-2', 'email' => 'applicant@example.test',
            'phone' => '03001234567', 'password' => 'Password1', 'password_confirmation' => 'Password1',
            'photo' => UploadedFile::fake()->image('applicant.webp', 600, 800),
        ])->assertCreated();

        $user = User::where('email', 'applicant@example.test')->firstOrFail();
        $member->refresh();
        $this->assertSame($member->profile_image_path, $user->profile_image_path);
        Storage::disk('local')->assertExists($member->profile_image_path);
        $this->assertDatabaseHas('member_photo_audits', ['member_id' => $member->id, 'applicant_user_id' => $user->id, 'source' => 'registration']);
    }

    public function test_membership_card_pdf_and_signed_verification_use_latest_photo(): void
    {
        Storage::fake('local');
        [$admin, $member] = $this->actors();
        $this->actingAs($admin)->post('/api/members/'.$member->id.'/photo', [
            'photo' => UploadedFile::fake()->image('card.jpg', 800, 800),
        ])->assertOk();

        $this->actingAs($admin)->get(route('members.card', $member))->assertOk()
            ->assertSee($member->full_name)->assertSee('Download PDF')->assertSee('data:image/webp;base64', false);
        $this->actingAs($admin)->get(route('members.card.pdf', $member))->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $signed = URL::temporarySignedRoute('membership.verify', now()->addMinute(), ['member' => $member->id]);
        $this->get($signed)->assertOk()->assertSee($member->full_name)->assertSee('Secure membership record');
        $this->get(route('membership.verify', $member))->assertForbidden();
    }

    private function actors(): array
    {
        $complex = $this->complex();
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'P-1', 'membership_no' => 'M-1', 'full_name' => 'Photo Member', 'cnic' => '3520200000001', 'status' => 'active']);

        return [User::factory()->create(['role' => 'admin']), $member];
    }

    private function complex(): SportsComplex
    {
        return SportsComplex::create(['name' => 'Photo Sports Complex', 'code' => 'PS', 'slug' => 'photo-sports-complex-'.uniqid(), 'city' => 'Lahore', 'is_active' => true]);
    }
}
