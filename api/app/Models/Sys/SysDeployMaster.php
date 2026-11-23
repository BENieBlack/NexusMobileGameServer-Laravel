<?php

namespace App\Models\Sys;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * SysDeployMaster Model
 *
 * マスターデータのデプロイ履歴を管理するモデル
 *
 * @property int $deploy_key
 * @property string $hash
 * @property string $status
 * @property string $mst_database
 * @property string $artifact_dir
 * @property string $schema_fingerprint
 * @property string $source_kind
 * @property ?Carbon $mst_dropped_at
 */
class SysDeployMaster extends _BaseSys
{
    /**
     * テーブル名
     */
    protected $table = 'sys_deploy_master';

    /**
     * 複数代入可能な属性
     *
     * @var array
     */
    /** @var list<string> */
    protected $fillable = [
        'deploy_key',
        'hash',
        'deploy_date',
        'deploy_count',
        'status',
        'deployed_by',
        'deployed_at',
        'description',
        'mst_database',
        'artifact_dir',
        'schema_fingerprint',
        'source_kind',
        'mst_dropped_at',
    ];

    /**
     * キャストする属性
     *
     * @var array
     */
    /** @var array<string, string> */
    protected $casts = [
        'deploy_date' => 'date',
        'mst_dropped_at' => 'datetime',
    ];

    /**
     * デプロイステータスの定数
     */
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    /**
     * 登録の元（source_kind）の定数
     */
    public const SOURCE_KIND_BUILD = 'build';

    public const SOURCE_KIND_IMPORT = 'import';

    /**
     * 利用可能なステータス一覧を取得
     *
     * @return array<int, string>
     */
    public static function availableStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_ROLLED_BACK,
        ];
    }

    /**
     * deploy_keyを取得
     */
    public function getDeployKey(): ?int
    {
        return $this->getAttribute('deploy_key');
    }

    /**
     * deploy_keyを設定
     */
    public function setDeployKey(int $deployKey): void
    {
        $this->setAttribute('deploy_key', $deployKey);
    }

    /**
     * hashを取得
     */
    public function getHash(): ?string
    {
        return $this->getAttribute('hash');
    }

    /**
     * hashを設定
     */
    public function setHash(string $hash): void
    {
        $this->setAttribute('hash', $hash);
    }

    /**
     * deploy_dateを取得
     */
    public function getDeployDate(): ?string
    {
        return $this->getAttribute('deploy_date');
    }

    /**
     * deploy_dateを設定
     */
    public function setDeployDate(\DateTime|string $deployDate): void
    {
        $this->setAttribute('deploy_date', $deployDate);
    }

    /**
     * deploy_countを取得
     */
    public function getDeployCount(): ?int
    {
        return $this->getAttribute('deploy_count');
    }

    /**
     * deploy_countを設定
     */
    public function setDeployCount(int $deployCount): void
    {
        $this->setAttribute('deploy_count', $deployCount);
    }

    /**
     * statusを取得
     */
    public function getStatus(): ?string
    {
        return $this->getAttribute('status');
    }

    /**
     * statusを設定
     */
    public function setStatus(string $status): void
    {
        $this->setAttribute('status', $status);
    }

    /**
     * deployed_byを取得
     */
    public function getDeployedBy(): ?string
    {
        return $this->getAttribute('deployed_by');
    }

    /**
     * deployed_byを設定
     */
    public function setDeployedBy(string $deployedBy): void
    {
        $this->setAttribute('deployed_by', $deployedBy);
    }

    /**
     * deployed_atを取得
     */
    public function getDeployedAt(): ?string
    {
        return $this->getAttribute('deployed_at');
    }

    /**
     * deployed_atを設定
     */
    public function setDeployedAt(\DateTime|string $deployedAt): void
    {
        $this->setAttribute('deployed_at', $deployedAt);
    }

    /**
     * descriptionを取得
     */
    public function getDescription(): ?string
    {
        return $this->getAttribute('description');
    }

    /**
     * descriptionを設定
     */
    public function setDescription(string $description): void
    {
        $this->setAttribute('description', $description);
    }

    /**
     * この版の mst DB 名を取得（API が接続先を決める唯一の根拠）
     */
    public function getMstDatabase(): ?string
    {
        return $this->getAttribute('mst_database');
    }

    /**
     * 成果物のディレクトリ名（{deploy_key}_{hash}）を取得
     */
    public function getArtifactDir(): ?string
    {
        return $this->getAttribute('artifact_dir');
    }

    /**
     * この版 DB の現在のスキーマ版を取得
     */
    public function getSchemaFingerprint(): ?string
    {
        return $this->getAttribute('schema_fingerprint');
    }

    /**
     * 登録の元（build / import）を取得
     */
    public function getSourceKind(): ?string
    {
        return $this->getAttribute('source_kind');
    }

    /**
     * 掃除で版の DB を消した日時を取得
     */
    public function getMstDroppedAt(): ?Carbon
    {
        return $this->getAttribute('mst_dropped_at');
    }

    /**
     * 版の DB が掃除済み（切り替えできない）かチェック
     */
    public function isMstDropped(): bool
    {
        return $this->getAttribute('mst_dropped_at') !== null;
    }

    /**
     * デプロイキーから年月日とカウントを取得
     *
     * @return array{year: int, month: int, day: int, count: int}
     */
    public function parseDeployKey(): array
    {
        $keyString = (string) $this->deploy_key;

        return [
            'year' => (int) substr($keyString, 0, 4),
            'month' => (int) substr($keyString, 4, 2),
            'day' => (int) substr($keyString, 6, 2),
            'count' => (int) substr($keyString, 8, 2),
        ];
    }

    /**
     * デプロイが完了しているかチェック
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * デプロイが失敗しているかチェック
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * デプロイがロールバック済みかチェック
     */
    public function isRolledBack(): bool
    {
        return $this->status === self::STATUS_ROLLED_BACK;
    }

    /**
     * ハッシュが有効かチェック
     */
    public function hasValidHash(): bool
    {
        return ! empty($this->hash) && strlen($this->hash) === 64;
    }

    /**
     * テーブル単位SQLiteの配信情報を取得
     *
     * @return HasMany<SysDeployMasterTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(SysDeployMasterTable::class, 'sys_deploy_master_id');
    }

    /**
     * ハッシュからバージョン文字列を生成（短縮版）
     * セキュリティのため、ハッシュの最初の8文字のみを使用
     */
    public function buildVersionString(): ?string
    {
        if (! $this->hasValidHash()) {
            return null;
        }

        return substr($this->hash, 0, 8);
    }

    /**
     * レスポンス用配列に変換
     *
     * データベース層の'id'をAPI層の'sys_deploy_master_id'に変換
     */
    public function toResponseArray(): array
    {
        $array = parent::toResponseArray();

        if (isset($array['id'])) {
            $array['sys_deploy_master_id'] = $array['id'];
            unset($array['id']);
        }

        return $array;
    }
}
