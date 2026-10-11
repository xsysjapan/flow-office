<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * 全Projectorの書き込み先テーブルの一覧(手順書で「空にするテーブル」に使う。論点18 6章)。
 *
 * app/Domain/<X>/Projectors/*.php のソースから、DB::table('名前') の文字列と、参照しているEloquentモデル
 * (App\Models\ または App\Domain\<X>\Models\ 等の Model 派生クラス)のテーブル名を集める。
 * 文字列の静的な検査なので、動的に決まるテーブル名は拾えない(手順書では人が確認する)。
 */
final class ReadModelTableLister
{
    /** @return array<string, list<string>> テーブル名 => 参照元のProjectorファイル */
    public function tables(string $domainRoot): array
    {
        $result = [];
        foreach (glob($domainRoot.'/*/Projectors/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $origin = str_replace($domainRoot.'/', '', $file);

            foreach ($this->literalTables($source) as $table) {
                $result[$table][] = $origin;
            }
            foreach ($this->modelTables($source) as $table) {
                $result[$table][] = $origin;
            }
        }
        ksort($result);
        foreach ($result as $table => $origins) {
            $result[$table] = array_values(array_unique($origins));
        }

        return $result;
    }

    /** @return list<string> */
    private function literalTables(string $source): array
    {
        preg_match_all("/DB::table\\(\\s*['\"]([a-z_][a-z0-9_]*)['\"]\\s*\\)/", $source, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function modelTables(string $source): array
    {
        preg_match_all('/\b(?:use\s+)?((?:App\\\\)[A-Za-z0-9_\\\\]+)/', $source, $m);
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $uses);
        $classes = array_unique(array_merge($uses[1] ?? [], $m[1] ?? []));

        $tables = [];
        foreach ($classes as $class) {
            $class = ltrim($class, '\\');
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }
            /** @var Model $model */
            $model = $reflection->newInstanceWithoutConstructor();
            $tables[] = $model->getTable();
        }

        return array_values(array_unique($tables));
    }
}
