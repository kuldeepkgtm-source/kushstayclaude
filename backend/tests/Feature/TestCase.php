<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Property $property;

    protected Room $acRoom;

    protected Room $nacRoom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\KushStaySeeder::class);
        $this->property = Property::first();
        $this->acRoom = Room::where('code', 'AC')->first();
        $this->nacRoom = Room::where('code', 'NAC')->first();
    }

    protected function bed(string $code): Bed
    {
        return Bed::where('code', $code)->firstOrFail();
    }
}
