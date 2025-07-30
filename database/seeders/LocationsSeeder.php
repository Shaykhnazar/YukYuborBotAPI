<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationsSeeder extends Seeder
{
    public function run()
    {
        // First, insert countries
        $countries = [
            ['name' => 'Казахстан', 'country_code' => 'KZ'],
            ['name' => 'ОАЭ', 'country_code' => 'AE'],
            ['name' => 'Индонезия', 'country_code' => 'ID'],
            ['name' => 'Турция', 'country_code' => 'TR', 'is_active' => false],
//            ['name' => 'Россия', 'country_code' => 'RU'],
//            ['name' => 'Узбекистан', 'country_code' => 'UZ'],
        ];

        $countryIds = [];

        foreach ($countries as $country) {
            $id = DB::table('locations')->insertGetId([
                'name' => $country['name'],
                'parent_id' => null,
                'type' => 'country',
                'country_code' => $country['country_code'],
                'is_active' => $country['is_active'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $countryIds[$country['name']] = $id;
        }

        // Then, insert cities for each country
        $cities = [
            'Казахстан' => [
                'Алматы',
                'Астана',
                'Шымкент',
                'Караганда',
                'Актобе',
                'Тараз',
                'Павлодар',
                'Усть-Каменогорск',
                'Семей',
                'Атырау',
                'Костанай',
                'Кызылорда',
                'Уральск',
                'Петропавловск',
                'Актау',
                'Темиртау',
                'Туркестан',
                'Кокшетау',
                'Талдыкорган',
                'Экибастуз'
            ],
            'ОАЭ' => [
                'Дубай',
                'Абу-Даби',
                'Шарджа',
                'Аль-Айн',
                'Аджман',
                'Рас-эль-Хайма',
                'Фуджейра',
                'Умм-эль-Кайвайн'
            ],
            'Индонезия' => [
                'Джакарта',
                'Бали',
                'Убуд',
                'Суrabаya',
                'Бандунг',
                'Медан',
                'Семаранг',
                'Макассар',
                'Палембанг',
                'Танжеранг',
                'Депок',
                'Богор',
                'Пекanbaru',
                'Бекаси',
                'Паданг',
                'Малангg',
                'Джокьякарта',
                'Денпасар'
            ],
            'Турция' => [
                'Стамбул',
                'Анкара',
                'Измир',
                'Бурса',
                'Анталия',
                'Адана',
                'Газиантеп',
                'Конья',
                'Кайсери',
                'Мерсин',
                'Эскишехир',
                'Диярбакыр',
                'Самсун',
                'Денизли',
                'Малатья',
                'Кахраmanmaраш',
                'Эрзурум',
                'Ван',
                'Элязыг',
                'Манисa'
            ],
//            'Россия' => [
//                'Москва',
//                'Санкт-Петербург',
//                'Новосибирск',
//                'Екатеринбург',
//                'Казань',
//                'Нижний Новгород',
//                'Челябинск',
//                'Самара',
//                'Омск',
//                'Ростов-на-Дону',
//                'Уфа',
//                'Красноярск',
//                'Воронеж',
//                'Пермь',
//                'Волгоград',
//                'Краснодар',
//                'Саратов',
//                'Тюмень',
//                'Тольятти',
//                'Ижевск'
//            ],
//            'Узбекистан' => [
//                'Ташкент',
//                'Самарканд',
//                'Намангаn',
//                'Андижан',
//                'Бухара',
//                'Нукус',
//                'Карши',
//                'Коканд',
//                'Фергана',
//                'Маргилан',
//                'Чирчик',
//                'Ангрен',
//                'Алмалык',
//                'Термез',
//                'Джизак',
//                'Навои',
//                'Ургенч',
//                'Хива',
//                'Гулистан',
//                'Янгиер'
//            ]
        ];

        foreach ($cities as $countryName => $cityList) {
            $countryId = $countryIds[$countryName];

            foreach ($cityList as $cityName) {
                DB::table('locations')->insert([
                    'name' => $cityName,
                    'parent_id' => $countryId,
                    'type' => 'city',
                    'country_code' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
