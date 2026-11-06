<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberPhotoAudit;
use App\Services\MemberPhotoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MemberPhotoController extends Controller
{
    public function __construct(private MemberPhotoService $photos) {}

    public function update(Request $request, Member $member)
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $member->sports_complex_id, 403);
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=240,min_height=240,max_width=6000,max_height=6000'],
        ]);

        $oldPath = $member->profile_image_path;
        $newPath = $this->photos->store($request->file('photo'), 'member-photos/'.$member->id);

        DB::transaction(function () use ($request, $member, $oldPath, $newPath) {
            $member->update([
                'profile_image_path' => $newPath,
                'photo_updated_at' => now(),
                'photo_updated_by' => $request->user()->id,
            ]);
            $member->portalUsers()->update(['profile_image_path' => $newPath]);
            MemberPhotoAudit::create([
                'member_id' => $member->id,
                'actor_user_id' => $request->user()->id,
                'action' => $oldPath ? 'replaced' : 'uploaded',
                'source' => 'admin',
                'old_path' => $oldPath,
                'new_path' => $newPath,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Member photograph updated successfully.',
            'member' => $member->fresh(['sportsComplex:id,name,code', 'photoUpdater:id,name']),
        ]);
    }

    public function show(Request $request, Member $member)
    {
        $user = $request->user();
        $isStaff = in_array($user->role, ['admin', 'manager', 'operator'], true)
            && (! $user->sports_complex_id || $user->sports_complex_id === $member->sports_complex_id);
        abort_unless($isStaff || ($user->role === 'member' && $user->member_id === $member->id), 403);
        abort_unless($member->profile_image_path && Storage::disk('local')->exists($member->profile_image_path), 404);

        return Storage::disk('local')->response($member->profile_image_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
