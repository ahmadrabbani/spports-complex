<?php

namespace App\Http\Controllers;

use App\Models\SportsComplex;
use App\Models\SportsComplexImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SportsComplexImageController extends Controller
{
    private const CATEGORIES = [
        'main_exterior',
        'swimming_pool',
        'gym_fitness',
        'badminton',
        'tennis',
        'basketball',
        'cricket',
        'indoor_sports',
        'outdoor_grounds',
        'community_recreation',
    ];

    public function store(Request $request, SportsComplex $sportsComplex)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'image' => ['nullable', 'required_without:images', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'images' => ['nullable', 'required_without:image', 'array', 'max:12'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:120'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'facility_category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
            'is_featured' => ['sometimes', 'boolean'],
        ]);
        $files = $request->file('images', []);
        if ($request->hasFile('image')) {
            array_unshift($files, $request->file('image'));
        }
        $nextOrder = ((int) $sportsComplex->images()->max('sort_order')) + 1;
        $created = collect();

        foreach ($files as $offset => $file) {
            $path = $file->store('sports-complexes/'.$sportsComplex->id, 'public');
            $created->push($sportsComplex->images()->create([
                'url' => '/storage/'.$path,
                'storage_path' => $path,
                'alt_text' => $data['alt_text'] ?? $sportsComplex->name,
                'title' => $data['title'] ?? null,
                'caption' => $data['caption'] ?? null,
                'facility_category' => $data['facility_category'] ?? 'main_exterior',
                'sort_order' => $nextOrder + $offset,
                'created_by' => $request->user()->id,
            ]));
        }

        $featured = $created->first();
        if ($featured && ($request->boolean('is_featured') || ! $sportsComplex->image_url)) {
            $this->feature($sportsComplex, $featured);
        }

        $fresh = $created->map->fresh()->values();

        return response()->json($fresh->count() === 1 ? $fresh->first() : ['images' => $fresh], 201);
    }

    public function update(Request $request, SportsComplex $sportsComplex, SportsComplexImage $sportsComplexImage)
    {
        $this->authorizeAdmin($request);
        $this->ensureImageBelongsToComplex($sportsComplex, $sportsComplexImage);
        $data = $request->validate([
            'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'facility_category' => ['sometimes', 'in:'.implode(',', self::CATEGORIES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);
        $oldStoragePath = null;
        if ($request->hasFile('image')) {
            $oldStoragePath = $sportsComplexImage->storage_path;
            $path = $request->file('image')->store('sports-complexes/'.$sportsComplex->id, 'public');
            $data['url'] = '/storage/'.$path;
            $data['storage_path'] = $path;
        }
        $sportsComplexImage->update(collect($data)->except(['image', 'is_featured'])->all());
        if ($request->boolean('is_featured')) {
            $this->feature($sportsComplex, $sportsComplexImage);
        } elseif ($sportsComplexImage->is_featured && array_key_exists('url', $data)) {
            $sportsComplex->update(['image_url' => $sportsComplexImage->url]);
        }
        if ($oldStoragePath) {
            Storage::disk('public')->delete($oldStoragePath);
        }

        return $sportsComplexImage->fresh();
    }

    public function destroy(Request $request, SportsComplex $sportsComplex, SportsComplexImage $sportsComplexImage)
    {
        $this->authorizeAdmin($request);
        $this->ensureImageBelongsToComplex($sportsComplex, $sportsComplexImage);
        $storagePath = $sportsComplexImage->storage_path;
        $wasFeatured = $sportsComplexImage->is_featured || $sportsComplex->image_url === $sportsComplexImage->url;

        DB::transaction(function () use ($sportsComplex, $sportsComplexImage, $wasFeatured) {
            $sportsComplexImage->delete();
            if ($wasFeatured) {
                $replacement = $sportsComplex->images()->orderBy('sort_order')->orderBy('id')->first();
                $sportsComplex->images()->update(['is_featured' => false]);
                $replacement?->update(['is_featured' => true]);
                $sportsComplex->update(['image_url' => $replacement?->url]);
            }
        });
        if ($storagePath) {
            Storage::disk('public')->delete($storagePath);
        }

        return response()->noContent();
    }

    private function feature(SportsComplex $sportsComplex, SportsComplexImage $image): void
    {
        DB::transaction(function () use ($sportsComplex, $image) {
            $sportsComplex->images()->update(['is_featured' => false]);
            $image->update(['is_featured' => true]);
            $sportsComplex->update(['image_url' => $image->url]);
        });
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    private function ensureImageBelongsToComplex(SportsComplex $sportsComplex, SportsComplexImage $image): void
    {
        abort_unless($image->sports_complex_id === $sportsComplex->id, 404);
    }
}
