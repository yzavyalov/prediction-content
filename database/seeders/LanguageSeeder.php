<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [

            /*
            |--------------------------------------------------------------------------
            | Europe
            |--------------------------------------------------------------------------
            */

            ['code' => 'sq', 'name' => 'Albanian'],
            ['code' => 'hy', 'name' => 'Armenian'],
            ['code' => 'az', 'name' => 'Azerbaijani'],
            ['code' => 'eu', 'name' => 'Basque'],
            ['code' => 'be', 'name' => 'Belarusian'],
            ['code' => 'bs', 'name' => 'Bosnian'],
            ['code' => 'bg', 'name' => 'Bulgarian'],
            ['code' => 'ca', 'name' => 'Catalan'],
            ['code' => 'hr', 'name' => 'Croatian'],
            ['code' => 'cs', 'name' => 'Czech'],
            ['code' => 'da', 'name' => 'Danish'],
            ['code' => 'nl', 'name' => 'Dutch'],
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'et', 'name' => 'Estonian'],
            ['code' => 'fo', 'name' => 'Faroese'],
            ['code' => 'fi', 'name' => 'Finnish'],
            ['code' => 'fr', 'name' => 'French'],
            ['code' => 'gl', 'name' => 'Galician'],
            ['code' => 'ka', 'name' => 'Georgian'],
            ['code' => 'de', 'name' => 'German'],
            ['code' => 'el', 'name' => 'Greek'],
            ['code' => 'hu', 'name' => 'Hungarian'],
            ['code' => 'is', 'name' => 'Icelandic'],
            ['code' => 'ga', 'name' => 'Irish'],
            ['code' => 'it', 'name' => 'Italian'],
            ['code' => 'lv', 'name' => 'Latvian'],
            ['code' => 'lt', 'name' => 'Lithuanian'],
            ['code' => 'lb', 'name' => 'Luxembourgish'],
            ['code' => 'mk', 'name' => 'Macedonian'],
            ['code' => 'mt', 'name' => 'Maltese'],
            ['code' => 'ro', 'name' => 'Romanian'],
            ['code' => 'no', 'name' => 'Norwegian'],
            ['code' => 'pl', 'name' => 'Polish'],
            ['code' => 'pt', 'name' => 'Portuguese'],
            ['code' => 'ru', 'name' => 'Russian'],
            ['code' => 'sr', 'name' => 'Serbian'],
            ['code' => 'sk', 'name' => 'Slovak'],
            ['code' => 'sl', 'name' => 'Slovenian'],
            ['code' => 'es', 'name' => 'Spanish'],
            ['code' => 'sv', 'name' => 'Swedish'],
            ['code' => 'tr', 'name' => 'Turkish'],
            ['code' => 'uk', 'name' => 'Ukrainian'],
            ['code' => 'cy', 'name' => 'Welsh'],

            /*
            |--------------------------------------------------------------------------
            | Middle East & Central Asia
            |--------------------------------------------------------------------------
            */

            ['code' => 'ar', 'name' => 'Arabic'],
            ['code' => 'fa', 'name' => 'Persian'],
            ['code' => 'he', 'name' => 'Hebrew'],
            ['code' => 'ku', 'name' => 'Kurdish'],
            ['code' => 'kk', 'name' => 'Kazakh'],
            ['code' => 'ky', 'name' => 'Kyrgyz'],
            ['code' => 'tg', 'name' => 'Tajik'],
            ['code' => 'tk', 'name' => 'Turkmen'],
            ['code' => 'uz', 'name' => 'Uzbek'],

            /*
            |--------------------------------------------------------------------------
            | South Asia
            |--------------------------------------------------------------------------
            */

            ['code' => 'bn', 'name' => 'Bengali'],
            ['code' => 'hi', 'name' => 'Hindi'],
            ['code' => 'gu', 'name' => 'Gujarati'],
            ['code' => 'kn', 'name' => 'Kannada'],
            ['code' => 'ml', 'name' => 'Malayalam'],
            ['code' => 'mr', 'name' => 'Marathi'],
            ['code' => 'ne', 'name' => 'Nepali'],
            ['code' => 'pa', 'name' => 'Punjabi'],
            ['code' => 'si', 'name' => 'Sinhala'],
            ['code' => 'ta', 'name' => 'Tamil'],
            ['code' => 'te', 'name' => 'Telugu'],
            ['code' => 'ur', 'name' => 'Urdu'],

            /*
            |--------------------------------------------------------------------------
            | East & Southeast Asia
            |--------------------------------------------------------------------------
            */

            ['code' => 'zh', 'name' => 'Chinese'],
            ['code' => 'ja', 'name' => 'Japanese'],
            ['code' => 'ko', 'name' => 'Korean'],
            ['code' => 'id', 'name' => 'Indonesian'],
            ['code' => 'ms', 'name' => 'Malay'],
            ['code' => 'vi', 'name' => 'Vietnamese'],
            ['code' => 'th', 'name' => 'Thai'],
            ['code' => 'fil', 'name' => 'Filipino'],
            ['code' => 'my', 'name' => 'Burmese'],
            ['code' => 'km', 'name' => 'Khmer'],
            ['code' => 'lo', 'name' => 'Lao'],
            ['code' => 'mn', 'name' => 'Mongolian'],

            /*
            |--------------------------------------------------------------------------
            | Africa
            |--------------------------------------------------------------------------
            */

            ['code' => 'af', 'name' => 'Afrikaans'],
            ['code' => 'am', 'name' => 'Amharic'],
            ['code' => 'ha', 'name' => 'Hausa'],
            ['code' => 'ig', 'name' => 'Igbo'],
            ['code' => 'rw', 'name' => 'Kinyarwanda'],
            ['code' => 'mg', 'name' => 'Malagasy'],
            ['code' => 'so', 'name' => 'Somali'],
            ['code' => 'sw', 'name' => 'Swahili'],
            ['code' => 'yo', 'name' => 'Yoruba'],
            ['code' => 'zu', 'name' => 'Zulu'],

            /*
            |--------------------------------------------------------------------------
            | Americas
            |--------------------------------------------------------------------------
            */

            ['code' => 'ht', 'name' => 'Haitian Creole'],
            ['code' => 'gn', 'name' => 'Guarani'],
            ['code' => 'qu', 'name' => 'Quechua'],

        ];

        foreach ($languages as $language) {
            DB::table('languages')->updateOrInsert(
                ['code' => $language['code']],
                [
                    'name' => $language['name'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
