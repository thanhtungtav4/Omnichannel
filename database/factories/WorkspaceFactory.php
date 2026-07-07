<?php

namespace Database\Factories;

use App\Modules\Platform\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => 'ws-'.Str::lower(Str::random(10)),
            'status' => 'ACTIVE',
        ];
    }
}
