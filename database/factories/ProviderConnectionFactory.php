<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['provider' => 'github', 'label' => fake()->word(), 'account_id' => '123', 'login' => 'fixture-user', 'encrypted_token' => 'fixture-ciphertext', 'verified_at' => now()];
    }
}
