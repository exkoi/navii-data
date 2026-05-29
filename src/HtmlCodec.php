<?php

declare(strict_types=1);

namespace Exp\NaviiData;

/**
 * facilities.html に保存する生HTMLの圧縮・展開。
 *
 * 保存は gzip(zlib)圧縮した BLOB。構造（タグ・属性）は可逆に完全保持され、
 * decode() で元の生HTMLがバイト単位で復元できる。
 *
 * 移行期間中は圧縮済み／非圧縮が混在しうるため、decode() は gzip マジックバイト
 * (0x1f 0x8b) を見て自動判別する。非圧縮ならそのまま返すので後方互換。
 */
final class HtmlCodec
{
    /** gzip 圧縮レベル（HTMLは6で9とほぼ同等の圧縮率、CPUは軽い）。 */
    private const LEVEL = 6;

    /** 生HTML → 圧縮BLOB。 */
    public static function encode(string $html): string
    {
        $out = gzencode($html, self::LEVEL);
        if ($out === false) {
            // 圧縮できなければ生のまま保存（decode 側がマジックバイトで判別する）。
            return $html;
        }
        return $out;
    }

    /** 保存BLOB → 生HTML。圧縮済みなら展開し、非圧縮ならそのまま返す。 */
    public static function decode(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (self::isCompressed($stored)) {
            $out = gzdecode($stored);
            // 万一壊れていても元データを失わないよう、失敗時は生バイトを返す。
            return $out === false ? $stored : $out;
        }
        return $stored;
    }

    /** gzip マジックバイト (0x1f 0x8b) を持つか。 */
    public static function isCompressed(string $stored): bool
    {
        return strlen($stored) >= 2 && $stored[0] === "\x1f" && $stored[1] === "\x8b";
    }
}
