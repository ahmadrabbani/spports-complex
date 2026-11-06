<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentPurpose;
use App\Models\AppointmentSchedule;
use App\Models\Facility;
use App\Models\SportsComplex;
use App\Services\AppointmentService;
use App\Support\CnicSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAppointmentController extends Controller
{
    public function __construct(private AppointmentService $service) {}

    public function index(Request $request)
    {
        $this->staff($request);
        $query = Appointment::with(['user:id,name,email,cnic,phone', 'sportsComplex:id,name,code', 'purpose:id,name'])->latest('appointment_date')->latest('appointment_time');
        if ($request->user()->sports_complex_id) {
            $query->where('sports_complex_id', $request->user()->sports_complex_id);
        }
        if ($request->filled('date')) {
            $query->whereDate('appointment_date', $request->date('date'));
        }
        if ($request->filled('complex_id')) {
            $query->where('sports_complex_id', $request->integer('complex_id'));
        }
        if ($request->filled('purpose_id')) {
            $query->where('appointment_purpose_id', $request->integer('purpose_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')).'%';
            $query->where(fn ($q) => $q->where('appointment_no', 'like', $term)->orWhere('form_number', 'like', $term)
                ->orWhereHas('user', function ($users) use ($term, $request) {
                    $users->where('name', 'like', $term)->orWhere('phone', 'like', $term);
                    CnicSearch::orWhereMatches($users, (string) $request->input('q'));
                }));
        }
        $scope = Appointment::query();
        if ($request->user()->sports_complex_id) {
            $scope->where('sports_complex_id', $request->user()->sports_complex_id);
        }

        return [
            'metrics' => [
                'today' => (clone $scope)->whereDate('appointment_date', today())->count(),
                'pending' => (clone $scope)->where('status', 'pending')->count(),
                'file_entry_pending' => (clone $scope)->where('status', 'visited')->count(),
                'form_number_pending' => (clone $scope)->where('status', 'file_entered')->count(),
                'completed_today' => (clone $scope)->where('status', 'completed')->whereDate('updated_at', today())->count(),
            ],
            'appointments' => $query->paginate(min($request->integer('per_page', 20), 100)),
        ];
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);

        return $appointment->load(['user', 'sportsComplex', 'purpose', 'events.actor']);
    }

    public function transition(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $request->validate(['status' => ['required', Rule::in(['confirmed', 'visited', 'completed', 'cancelled', 'no_show'])], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->service->transition($appointment, $request->user(), $data['status'], $data['notes'] ?? null);
    }

    public function fileEntry(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $request->validate(['documents_verified' => ['accepted'], 'file_entry_completed' => ['accepted'], 'file_entry_reference' => ['required', 'string', 'max:255'], 'remarks' => ['nullable', 'string', 'max:2000']]);

        return $this->service->confirmFileEntry($appointment, $request->user(), $data);
    }

    public function assignForm(Request $request, Appointment $appointment)
    {
        $this->authorizeAppointment($request, $appointment);
        $data = $request->validate(['form_number' => ['required', 'string', 'max:80', 'unique:appointments,form_number']]);

        return $this->service->assignFormNumber($appointment, $request->user(), $data['form_number']);
    }

    public function updatePurpose(Request $request, ?AppointmentPurpose $appointmentPurpose = null)
    {
        $this->admin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('appointment_purposes')->ignore($appointmentPurpose)], 'description' => ['nullable', 'string', 'max:255'], 'icon' => ['nullable', 'string', 'max:40'], 'is_active' => ['boolean']]);
        $purpose = $appointmentPurpose?->exists ? tap($appointmentPurpose)->update($data) : AppointmentPurpose::create($data);

        return response()->json($purpose->fresh(), $appointmentPurpose?->exists ? 200 : 201);
    }

    public function updateSchedule(Request $request, SportsComplex $sportsComplex)
    {
        $this->admin($request);
        $data = $request->validate(['opening_time' => ['required', 'date_format:H:i'], 'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'], 'slot_duration_minutes' => ['required', 'integer', Rule::in([15, 20, 30, 45, 60])], 'max_appointments_per_slot' => ['required', 'integer', 'min:1', 'max:100'], 'working_days' => ['required', 'array', 'min:1'], 'working_days.*' => ['integer', 'between:1,7'], 'is_active' => ['boolean']]);

        return AppointmentSchedule::updateOrCreate(['sports_complex_id' => $sportsComplex->id], $data);
    }

    public function storeFacility(Request $request, SportsComplex $sportsComplex)
    {
        $this->admin($request);
        $data = $this->facilityData($request);

        $planIds = $data['membership_plan_ids'] ?? [];
        unset($data['membership_plan_ids']);
        $facility = $sportsComplex->facilities()->create($data);
        $facility->membershipPlans()->sync($planIds);

        return response()->json($facility->load('membershipPlans:id,name'), 201);
    }

    public function storeFacilitiesBulk(Request $request)
    {
        $this->admin($request);
        $request->validate([
            'sports_complex_ids' => ['required', 'array', 'min:1'],
            'sports_complex_ids.*' => ['integer', 'distinct', 'exists:sports_complexes,id'],
        ]);
        $data = $this->facilityData($request);
        $planIds = $data['membership_plan_ids'] ?? [];
        unset($data['membership_plan_ids']);
        $facilities = collect($request->input('sports_complex_ids'))->map(function (int $complexId) use ($data, $planIds) {
            $facility = Facility::updateOrCreate(
                ['sports_complex_id' => $complexId, 'slug' => $data['slug']],
                $data,
            );
            $facility->membershipPlans()->sync($planIds);

            return $facility->load('membershipPlans:id,name');
        });

        return response()->json(['facilities' => $facilities], 201);
    }

    public function updateFacility(Request $request, Facility $facility)
    {
        $this->admin($request);
        $data = $this->facilityData($request);
        $planIds = $data['membership_plan_ids'] ?? [];
        unset($data['membership_plan_ids']);
        $facility->update($data);
        $facility->membershipPlans()->sync($planIds);

        return $facility->fresh('membershipPlans:id,name');
    }

    public function destroyFacility(Request $request, Facility $facility)
    {
        $this->admin($request);
        $facility->delete();

        return response()->noContent();
    }

    public function blockDate(Request $request, SportsComplex $sportsComplex)
    {
        $this->admin($request);
        $data = $request->validate(['blocked_on' => ['required', 'date', 'after_or_equal:today'], 'reason' => ['nullable', 'string', 'max:255']]);
        DB::table('appointment_blocked_dates')->updateOrInsert(
            ['sports_complex_id' => $sportsComplex->id, 'blocked_on' => $data['blocked_on']],
            ['reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['message' => 'Date blocked.']);
    }

    public function unblockDate(Request $request, SportsComplex $sportsComplex, string $date)
    {
        $this->admin($request);
        DB::table('appointment_blocked_dates')->where('sports_complex_id', $sportsComplex->id)->whereDate('blocked_on', $date)->delete();

        return response()->noContent();
    }

    private function facilityData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['nullable', 'url', 'max:1000'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'operational_status' => ['sometimes', Rule::in(['available', 'maintenance', 'closed'])],
            'maintenance_notice' => ['nullable', 'string', 'max:255', 'required_if:operational_status,maintenance'],
            'maintenance_starts_at' => ['nullable', 'date'],
            'maintenance_ends_at' => ['nullable', 'date', 'after_or_equal:maintenance_starts_at'],
            'membership_plan_ids' => ['nullable', 'array'],
            'membership_plan_ids.*' => ['integer', 'distinct', 'exists:membership_plans,id'],
        ]);
        $data['slug'] = Str::slug($data['slug'] ?? $data['name']);
        $data['operational_status'] ??= 'available';

        return $data;
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        $this->staff($request);
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $appointment->sports_complex_id) {
            abort(403);
        }
    }

    private function staff(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['admin', 'manager', 'operator']), 403);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Complex settings require the central administrator.');
    }
}
