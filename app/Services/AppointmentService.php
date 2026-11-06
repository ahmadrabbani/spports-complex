<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSchedule;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function availableSlots(int $complexId, string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        if (! $day->isAfter(today())) {
            return [];
        }
        $schedule = AppointmentSchedule::where('sports_complex_id', $complexId)->where('is_active', true)->first();
        if (! $schedule || ! in_array($day->dayOfWeekIso, $schedule->working_days, true)) {
            return [];
        }
        if (DB::table('appointment_blocked_dates')->where('sports_complex_id', $complexId)->whereDate('blocked_on', $day)->exists()) {
            return [];
        }

        $start = Carbon::parse($day->toDateString().' '.$schedule->opening_time);
        $end = Carbon::parse($day->toDateString().' '.$schedule->closing_time);
        $booked = Appointment::where('sports_complex_id', $complexId)->whereDate('appointment_date', $day)
            ->whereNotIn('status', ['cancelled', 'no_show'])->pluck('appointment_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))->countBy();
        $slots = [];
        while ($start->copy()->addMinutes($schedule->slot_duration_minutes)->lte($end)) {
            if ((int) ($booked[$start->format('H:i')] ?? 0) < $schedule->max_appointments_per_slot) {
                $slots[] = ['value' => $start->format('H:i'), 'label' => $start->format('h:i A')];
            }
            $start->addMinutes($schedule->slot_duration_minutes);
        }

        return $slots;
    }

    public function book(User $user, array $data): Appointment
    {
        return DB::transaction(function () use ($user, $data) {
            $schedule = AppointmentSchedule::where('sports_complex_id', $data['sports_complex_id'])->lockForUpdate()->first();
            if (! $schedule || ! collect($this->availableSlots((int) $data['sports_complex_id'], $data['appointment_date']))->contains('value', substr($data['appointment_time'], 0, 5))) {
                throw ValidationException::withMessages(['appointment_time' => 'That appointment slot is no longer available.']);
            }
            $appointment = Appointment::create([
                ...$data,
                'user_id' => $user->id,
                'public_id' => (string) Str::uuid(),
                'appointment_no' => $this->number(),
                'status' => 'pending',
            ]);
            $this->event($appointment, $user, 'created', null, 'pending', 'Appointment booked by applicant.');
            UserNotification::create([
                'user_id' => $user->id,
                'type' => 'appointment_created',
                'title' => 'Appointment requested',
                'message' => "Your appointment {$appointment->appointment_no} is awaiting confirmation.",
                'action_url' => '/my-appointments/'.$appointment->public_id,
            ]);

            return $appointment->load(['sportsComplex', 'purpose', 'events.actor']);
        });
    }

    public function transition(Appointment $appointment, User $actor, string $to, ?string $notes = null): Appointment
    {
        $allowed = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['visited', 'cancelled', 'no_show'],
            'visited' => ['cancelled'],
            'file_entered' => ['cancelled'],
            'form_assigned' => ['completed', 'cancelled'],
        ];
        if (! in_array($to, $allowed[$appointment->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Cannot move appointment from {$appointment->status} to {$to}."]);
        }

        return DB::transaction(function () use ($appointment, $actor, $to, $notes) {
            $from = $appointment->status;
            $appointment->update(['status' => $to, 'cancellation_reason' => $to === 'cancelled' ? $notes : null]);
            $this->event($appointment, $actor, $to, $from, $to, $notes);
            $this->notify($appointment, $to);

            return $appointment->fresh(['user', 'sportsComplex', 'purpose', 'events.actor']);
        });
    }

    public function confirmFileEntry(Appointment $appointment, User $actor, array $data): Appointment
    {
        if ($appointment->status !== 'visited') {
            throw ValidationException::withMessages(['status' => 'Applicant must be marked visited before file entry can be completed.']);
        }

        return DB::transaction(function () use ($appointment, $actor, $data) {
            $appointment->update([
                'documents_verified' => true,
                'file_entry_completed' => true,
                'file_entry_reference' => $data['file_entry_reference'],
                'file_entry_remarks' => $data['remarks'] ?? null,
                'file_entry_completed_at' => now(),
                'file_entry_completed_by' => $actor->id,
                'status' => 'file_entered',
            ]);
            $this->event($appointment, $actor, 'file_entry_completed', 'visited', 'file_entered', $data['remarks'] ?? null, ['reference' => $data['file_entry_reference']]);

            return $appointment->fresh(['user', 'sportsComplex', 'purpose', 'events.actor']);
        });
    }

    public function assignFormNumber(Appointment $appointment, User $actor, string $formNumber): Appointment
    {
        if (! $appointment->file_entry_completed || $appointment->status !== 'file_entered') {
            throw ValidationException::withMessages(['form_number' => 'Complete and confirm file entry before assigning a form number.']);
        }

        return DB::transaction(function () use ($appointment, $actor, $formNumber) {
            $appointment->update([
                'form_number' => $formNumber,
                'form_number_assigned_at' => now(),
                'form_number_assigned_by' => $actor->id,
                'status' => 'form_assigned',
            ]);
            $this->event($appointment, $actor, 'form_number_assigned', 'file_entered', 'form_assigned', null, ['form_number' => $formNumber]);
            UserNotification::create([
                'user_id' => $appointment->user_id,
                'type' => 'form_number_assigned',
                'title' => 'Your form number is ready',
                'message' => "Form number {$formNumber} has been assigned to {$appointment->appointment_no}.",
                'action_url' => '/my-appointments/'.$appointment->public_id,
            ]);

            return $appointment->fresh(['user', 'sportsComplex', 'purpose', 'events.actor']);
        });
    }

    private function event(Appointment $appointment, ?User $actor, string $event, ?string $from, ?string $to, ?string $notes = null, array $metadata = []): void
    {
        $appointment->events()->create(['actor_id' => $actor?->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'notes' => $notes, 'metadata' => $metadata ?: null]);
    }

    private function notify(Appointment $appointment, string $status): void
    {
        $labels = ['confirmed' => 'Appointment confirmed', 'visited' => 'Arrival recorded', 'cancelled' => 'Appointment cancelled', 'no_show' => 'Appointment marked no show', 'completed' => 'Appointment completed'];
        if (! isset($labels[$status])) {
            return;
        }
        UserNotification::create(['user_id' => $appointment->user_id, 'type' => 'appointment_'.$status, 'title' => $labels[$status], 'message' => "{$appointment->appointment_no} is now ".str_replace('_', ' ', $status).'.', 'action_url' => '/my-appointments/'.$appointment->public_id]);
    }

    private function number(): string
    {
        do {
            $number = 'APT-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Appointment::where('appointment_no', $number)->exists());

        return $number;
    }
}
