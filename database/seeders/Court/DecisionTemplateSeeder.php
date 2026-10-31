<?php

namespace Database\Seeders\Court;

use Modules\Court\Domain\Models\DecisionTemplate;
use Illuminate\Database\Seeder;

class DecisionTemplateSeeder extends Seeder
{
    /**
     * Seed a default decision template. Idempotent: only creates it if a
     * default template does not already exist. Header/footer images are left
     * empty so staff can upload them by editing this template.
     */
    public function run(): void
    {
        if (DecisionTemplate::where('is_default', true)->exists()) {
            return;
        }

        DecisionTemplate::create([
            'title' => 'Default decision layout',
            'category' => 'Default',
            'is_default' => true,
            'placeholders' => [
                'decision_content',
            ],
            // The PDF view renders date, case, parties, judges and signatures
            // around this body. The body holds the decision narrative itself.
            'body' => '<p>{{decision_content}}</p>',
            'header_image_path' => null,
            'footer_image_path' => null,
        ]);
    }
}
