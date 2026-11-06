<?php

namespace App\Http\Controllers;

use App\Models\AppointmentPurpose;
use App\Models\SportsComplex;

class PublicPortalController extends Controller
{
    public function index()
    {
        $complexes = SportsComplex::select(['id', 'name', 'slug', 'code', 'city', 'phone', 'address', 'description', 'opening_hours', 'image_url'])
            ->where('is_active', true)->with(['facilities' => fn ($q) => $q->where('is_active', true), 'images'])->orderBy('name')->get();
        $complexes->each(fn (SportsComplex $complex) => $this->useActualFeaturedPhoto($complex));

        return [
            'complexes' => $complexes,
            'purposes' => AppointmentPurpose::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    public function show(SportsComplex $sportsComplex)
    {
        abort_unless($sportsComplex->is_active, 404);

        $sportsComplex->load(['facilities' => fn ($q) => $q->where('is_active', true), 'images', 'appointmentSchedule']);
        $this->useActualFeaturedPhoto($sportsComplex);

        return $sportsComplex->makeHidden(['account_group', 'cost_center', 'profit_center']);
    }

    private function useActualFeaturedPhoto(SportsComplex $sportsComplex): void
    {
        $actual = $sportsComplex->images->reject(fn ($image) => preg_match(
            '/ai[- ]enhanced|architectural view/i',
            ($image->alt_text ?? '').' '.($image->url ?? '')
        ));
        $photo = $actual->firstWhere('is_featured', true) ?? $actual->first();
        $sportsComplex->setAttribute('image_url', $photo?->url);
    }
}
