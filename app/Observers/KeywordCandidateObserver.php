<?php

namespace App\Observers;

use App\Domain\Keyword\KeywordMasterSync;
use App\Models\KeywordCandidate;

/**
 * 후보가 바뀌면 키워드 마스터를 따라가게 한다(단건 경로: 발견·수집·발행·검색량 갱신).
 * insert()·mass update() 는 이벤트를 타지 않으므로 그 경로들은 `keywords:sync` 로 맞춘다.
 */
class KeywordCandidateObserver
{
    public function __construct(private KeywordMasterSync $sync) {}

    public function created(KeywordCandidate $c): void
    {
        $this->push($c);
    }

    public function updated(KeywordCandidate $c): void
    {
        $this->push($c);
    }

    public function deleted(KeywordCandidate $c): void
    {
        $this->push($c);
    }

    private function push(KeywordCandidate $c): void
    {
        $type = $c->category?->type;
        if ($type) {
            $this->sync->syncOne($c->keyword, $type);
        }
    }
}
