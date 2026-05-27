<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\Db;

$config = require __DIR__ . '/../config/config.php';

// state DB だけ使うので main は ATTACH 不要、state を直接開く。
$pdo = Db::open($config['state_db_path']);
// 既存スキーマがまだ未投入の可能性に備えて migrate も実行
Db::migrate($pdo, __DIR__ . '/../migrations-state');

$endpoint = 'http://data.e-stat.go.jp/lod/sparql/alldata/query';

$query = <<<'SPARQL'
PREFIX rdf:     <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs:    <http://www.w3.org/2000/01/rdf-schema#>
PREFIX dcterms: <http://purl.org/dc/terms/>
PREFIX sacs:    <http://data.e-stat.go.jp/lod/terms/sacs#>

SELECT ?code ?name ?prefName ?cls
WHERE {
  ?s a sacs:StandardAreaCode ;
     dcterms:identifier ?code ;
     rdfs:label ?name ;
     sacs:administrativeClass ?cls ;
     sacs:prefectureLabel ?prefName .
  FILTER(LANG(?name) = 'ja')
  FILTER(LANG(?prefName) = 'ja')
  FILTER(?cls IN (
    sacs:City, sacs:Town, sacs:Village, sacs:Ward,
    sacs:SpecialWard, sacs:CoreCity, sacs:DesignatedCity, sacs:SpecialCity
  ))
  FILTER NOT EXISTS { ?s dcterms:valid ?v }
}
ORDER BY ?code
SPARQL;

$url = $endpoint . '?' . http_build_query(['query' => $query]);

fwrite(STDOUT, "fetching SPARQL...\n");

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/sparql-results+json',
        'User-Agent: ' . ($config['user_agent'] ?? 'NaviiDataBot/0.1'),
    ],
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false || $status !== 200) {
    fwrite(STDERR, "SPARQL failed: status={$status} err={$err}\n");
    exit(1);
}

$data = json_decode($body, true);
if (!isset($data['results']['bindings'])) {
    fwrite(STDERR, "unexpected response shape\n");
    fwrite(STDERR, substr((string)$body, 0, 500) . "\n");
    exit(1);
}

// より具体的な指定（CoreCity 等）を優先するための優先度。値が小さいほど優先。
$priority = [
    'DesignatedCity' => 1,
    'CoreCity'       => 2,
    'SpecialCity'    => 3,
    'SpecialWard'    => 4,
    'City'           => 5,
    'Ward'           => 6,
    'Town'           => 7,
    'Village'        => 8,
];

$best = [];
foreach ($data['results']['bindings'] as $row) {
    // このエンドポイントは SELECT 変数名を大文字化して返すので、キーをすべて小文字に揃える。
    $row = array_change_key_case($row, CASE_LOWER);
    $code = $row['code']['value'] ?? null;
    $name = $row['name']['value'] ?? null;
    $prefName = $row['prefname']['value'] ?? null;
    $clsUri = $row['cls']['value'] ?? '';

    if ($code === null || $name === null || $prefName === null) {
        continue;
    }

    // sacs:City のような短縮形を取り出す（URIの # 以降）
    $cls = (string)preg_replace('~^.*[#/]~', '', $clsUri);
    if (!isset($priority[$cls])) {
        continue;
    }

    if (!isset($best[$code]) || $priority[$cls] < $priority[$best[$code]['cls']]) {
        $best[$code] = [
            'code'      => $code,
            'name'      => $name,
            'pref_name' => $prefName,
            'cls'       => $cls,
        ];
    }
}

fwrite(STDOUT, 'parsed unique codes: ' . count($best) . "\n");

$upsert = $pdo->prepare(
    'INSERT INTO municipalities (code5, lo, name, pref_cd, pref_name, admin_class, fetched_at)
     VALUES (:code5, :lo, :name, :pref_cd, :pref_name, :admin_class, datetime("now", "+9 hours"))
     ON CONFLICT(code5) DO UPDATE SET
       lo          = excluded.lo,
       name        = excluded.name,
       pref_cd     = excluded.pref_cd,
       pref_name   = excluded.pref_name,
       admin_class = excluded.admin_class,
       fetched_at  = excluded.fetched_at'
);

$pdo->beginTransaction();
try {
    foreach ($best as $code => $r) {
        $upsert->execute([
            ':code5'       => $r['code'],
            ':lo'          => '1' . $r['code'],
            ':name'        => $r['name'],
            ':pref_cd'     => substr($r['code'], 0, 2),
            ':pref_name'   => $r['pref_name'],
            ':admin_class' => $r['cls'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'DB write failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$count = (int)$pdo->query('SELECT COUNT(*) FROM municipalities')->fetchColumn();
fwrite(STDOUT, "municipalities total in DB: {$count}\n");
