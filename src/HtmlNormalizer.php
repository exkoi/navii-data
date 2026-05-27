<?php

declare(strict_types=1);

namespace Exp\NaviiData;

final class HtmlNormalizer
{
    /**
     * リクエストごとに変わる箇所（CSS/JSのキャッシュバスタータイムスタンプ、CSRFトークン）を
     * プレースホルダに置き換えてからハッシュを取るための正規化。元HTMLは保存にそのまま使う。
     */
    public static function canonicalize(string $html): string
    {
        // ?timeAt=1779842458577 → ?timeAt=__TS__
        $html = preg_replace('/(\?|&)timeAt=\d+/', '$1timeAt=__TS__', $html) ?? $html;

        // <input type="hidden" name="_csrf" value="...uuid..." />
        $html = preg_replace(
            '/name="_csrf"\s+value="[^"]+"/',
            'name="_csrf" value="__CSRF__"',
            $html,
        ) ?? $html;

        // <meta name="_csrf" content="...uuid..." />
        $html = preg_replace(
            '/<meta\s+name="_csrf"\s+content="[^"]+"\s*\/?>/',
            '<meta name="_csrf" content="__CSRF__"/>',
            $html,
        ) ?? $html;

        return $html;
    }
}
