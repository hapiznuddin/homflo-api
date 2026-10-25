<?php

use App\Models\Asset;
use App\Models\Household;
use App\Models\Property;
use Illuminate\Support\Facades\Schema;

test('assets property_id is nullable, indexed, and references properties with nullOnDelete', function () {
    $columns = collect(Schema::getColumns('assets'))->keyBy('name');

    expect($columns->has('property_id'))->toBeTrue();
    expect($columns['property_id']['nullable'])->toBeTrue();

    $indexes = collect(Schema::getIndexes('assets'));

    expect($indexes->contains(
        fn (array $index) => $index['columns'] === ['property_id']
    ))->toBeTrue();

    $foreignKey = collect(Schema::getForeignKeys('assets'))
        ->first(fn (array $key) => $key['columns'] === ['property_id']);

    expect($foreignKey)->not->toBeNull();
    expect($foreignKey['foreign_table'])->toBe('properties');
    expect($foreignKey['foreign_columns'])->toBe(['id']);
    expect($foreignKey['on_delete'])->toBe('set null');
});

test('existing assets remain valid without a property relation', function () {
    $household = Household::factory()->create();
    $asset = Asset::factory()->for($household)->create();

    expect($asset->refresh()->property_id)->toBeNull();
    expect($asset->property)->toBeNull();
});

test('property and asset expose their bidirectional relation', function () {
    $household = Household::factory()->create();
    $property = Property::factory()->for($household)->create();
    $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

    expect($asset->refresh()->property?->id)->toBe($property->id);
    expect($property->refresh()->assets->pluck('id')->all())->toBe([$asset->id]);
});

test('deleting a property sets assets property_id to null without deleting the asset', function () {
    $household = Household::factory()->create();
    $property = Property::factory()->for($household)->create();
    $asset = Asset::factory()->for($household)->create(['property_id' => $property->id]);

    $property->delete();

    $fresh = $asset->refresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->property_id)->toBeNull();
});
