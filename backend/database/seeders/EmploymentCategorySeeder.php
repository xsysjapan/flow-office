<?php

namespace Database\Seeders;

use App\Models\EmploymentCategory;
use Illuminate\Database\Seeder;

/**
 * 雇用区分マスタの既定値を作成する。work_styles(労働時間制度)とは独立した軸。
 */
class EmploymentCategorySeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            EmploymentCategory::REGULAR => '正社員',
            EmploymentCategory::CONTRACT => '契約社員',
            EmploymentCategory::PART_TIME => 'パート',
            EmploymentCategory::TEMPORARY => 'アルバイト',
            EmploymentCategory::COMMISSIONED => '嘱託',
            EmploymentCategory::OTHER => 'その他',
        ];

        foreach ($names as $code => $name) {
            EmploymentCategory::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
