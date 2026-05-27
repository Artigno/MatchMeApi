<?php

namespace Tests\Feature;

use App\Models\Garment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GarmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_garment_factory_creates_with_null_classification_fields(): void
    {
        $garment = Garment::factory()->create([
            'category' => null,
            'brand' => null,
            'color' => null,
            'condition' => null,
            'description' => null,
        ]);

        $this->assertNotNull($garment->id);
        $this->assertNull($garment->category);
    }

    public function test_soft_delete_sets_deleted_at(): void
    {
        $garment = Garment::factory()->create();
        $garment->delete();

        $this->assertNotNull($garment->deleted_at);
        $this->assertNull(Garment::find($garment->id));
        $this->assertNotNull(Garment::withTrashed()->find($garment->id));
    }
}
