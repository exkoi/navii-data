<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use DOMDocument;
use DOMXPath;

final class ListPageParser
{
    /**
     * @return array{
     *   total_count: int|null,
     *   max_page: int|null,
     *   current_page: int|null,
     *   facilities: list<array{kikan_cd:string, pref_cd:string, kikan_kbn:string}>
     * }
     */
    public static function parse(string $html): array
    {
        $facilities = [];

        // 詳細ページリンクから kikanCd/prefCd/kikanKbn を抽出。
        // <a> 内に属性が改行されているケースもあるため正規表現で全 href を拾う。
        if (preg_match_all(
            '#href="/znk-web/juminkanja/S2430/initialize\?([^"]+)"#',
            $html,
            $matches,
        )) {
            $seen = [];
            foreach ($matches[1] as $qs) {
                $qs = html_entity_decode($qs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                parse_str($qs, $params);
                $kikanCd = isset($params['kikanCd']) ? (string)$params['kikanCd'] : '';
                $prefCd = isset($params['prefCd']) ? (string)$params['prefCd'] : '';
                $kikanKbn = isset($params['kikanKbn']) ? (string)$params['kikanKbn'] : '';
                if ($kikanCd === '' || $prefCd === '' || $kikanKbn === '') {
                    continue;
                }
                $key = $kikanCd . '|' . $prefCd . '|' . $kikanKbn;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $facilities[] = [
                    'kikan_cd'  => $kikanCd,
                    'pref_cd'   => $prefCd,
                    'kikan_kbn' => $kikanKbn,
                ];
            }
        }

        // 総件数: "21,916 件中" のような表記
        $totalCount = null;
        if (preg_match('/([\d,]+)\s*件中/u', $html, $m)) {
            $totalCount = (int)str_replace(',', '', $m[1]);
        }

        // 最大ページ: ページネーションの ">>" リンク先 page=NNNN を取得
        $maxPage = null;
        if (preg_match_all('/[?&]page=(\d+)/', $html, $m)) {
            $maxPage = max(array_map('intval', $m[1]));
        }

        // 現在ページ: <li class="active"><a ...>N</a></li> の N（1オリジン表示）
        $currentPage = null;
        if (preg_match('#<li class="active"><a [^>]*>(\d+)</a></li>#', $html, $m)) {
            $currentPage = (int)$m[1];
        }

        return [
            'total_count'  => $totalCount,
            'max_page'     => $maxPage,
            'current_page' => $currentPage,
            'facilities'   => $facilities,
        ];
    }
}
