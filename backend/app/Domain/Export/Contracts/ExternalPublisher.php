<?php

namespace App\Domain\Export\Contracts;

/**
 * 経費・勤怠の外部連携出力(CSV/API/内部証跡アーカイブ)を統一的に扱う抽象。
 *
 * フェーズ1で用意する実装は CsvFilePublisher(ダウンロード用CSV/TSV)と
 * InternalArchivePublisher(証跡アーカイブExcelの内部保存)の2つのみ。freee/MoneyForward等
 * 会計クラウドへの実際のAPI送信は ExternalApiPublisher(型のみのスタブ)として型の余地だけ
 * 残し、本フェーズでは実装しない。
 *
 * 監査ログ(stored_events)への記録は ExportAuditAggregate の責務であり、Publisher自体は
 * 記録を行わない(呼び出し側のController/Serviceがpublish()の結果を使ってAggregateへ記録する)。
 */
interface ExternalPublisher
{
    /** 連携先を一意に識別するキー(例: 'csv_file', 'internal_archive', 'freee_api')。 */
    public function key(): string;

    /**
     * @param  string  $content  出力するコンテンツ本体(CSV文字列・xlsxバイナリ等)
     * @param  string  $filename  ファイル名(ダウンロード名・内部保存名に使う)
     * @param  array<string, mixed>  $context  監査ログ等に残す文脈情報(対象データID・出力種別等)
     */
    public function publish(string $content, string $filename, array $context = []): PublishedArtifact;
}
