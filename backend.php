<?php
// backend.php
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// مسیر ۱: GET → فقط خود updated.json را برگردان (برای لود کاراکترها در فرانت)
if ($method === 'GET') {
    $file = __DIR__ . '/updated.json';
    if (!file_exists($file)) {
        http_response_code(500);
        echo json_encode(['error' => 'updated.json not found']);
        exit;
    }
    readfile($file);
    exit;
}

// مسیر ۲: فقط POST اجازه داریم برای محاسبه نتایج
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// --- خواندن ورودی JSON از فرانت ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$p1Answers      = $data['p1Answers']      ?? []; // [qid => 1..5]
$p3Answers      = $data['p3Answers']      ?? [];
$userMbti       = strtoupper(trim($data['mbti'] ?? ''));
$prefsTagTally  = $data['prefsTagTally']  ?? []; // [trait => count]
$adaptiveAns    = $data['adaptiveAns']    ?? []; // [key => 1..5]
$userAnimeType  = $data['animeType']      ?? null;

// --- لود کاراکترها از updated.json ---
$file = __DIR__ . '/updated.json';
if (!file_exists($file)) {
    http_response_code(500);
    echo json_encode(['error' => 'updated.json not found']);
    exit;
}
$charactersJson = file_get_contents($file);
$characters = json_decode($charactersJson, true);
if (!is_array($characters)) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid updated.json']);
    exit;
}

// --- ثابت‌ها و بانک سوال‌ها ---

$ALLOWED_TRAITS = [
    "Bravery",
    "Determination",
    "Teamwork",
    "Empathy",
    "Strategy",
    "Recklessness",
    "Overconfidence",
    "Impulsiveness",
    "Naivety",
    "Stubbornness",
    "Logic",
    "Ambition",
    "Morality",
    "Intelligence",
    "Manipulativeness",
    "Arrogance",
    "Detachment",
    "Obsessiveness",
    "Moral Rigidity",
    "Sensitivity",
    "Optimism",
    "Creativity",
    "Humor",
    "Insecurity",
    "Idealism",
    "Jealousy",
    "Overattachment",
    "Emotional Instability",
    "Courage",
    "Curiosity",
    "Leadership",
    "Adaptability",
    "Kindness",
    "Escapism",
    "Overtrusting",
];

// 25 سؤال اصلی (فقط traits، متن سؤال برای بک‌اند لازم نیست)
$P1_BANK = [
    1  => ['traits' => ['Strategy'=>1.0, 'Determination'=>0.6, 'Logic'=>0.4]],
    2  => ['traits' => ['Strategy'=>1.0, 'Curiosity'=>0.6, 'Intelligence'=>0.4]],
    3  => ['traits' => ['Logic'=>1.0, 'Detachment'=>0.5, 'Courage'=>0.3]],
    4  => ['traits' => ['Logic'=>1.0, 'Strategy'=>0.5, 'Empathy'=>-0.3]],
    5  => ['traits' => ['Intelligence'=>1.0, 'Curiosity'=>0.6, 'Creativity'=>0.4]],
    6  => ['traits' => ['Leadership'=>1.0, 'Teamwork'=>0.6, 'Strategy'=>0.4]],
    7  => ['traits' => ['Leadership'=>1.0, 'Strategy'=>0.6, 'Teamwork'=>0.5]],
    8  => ['traits' => ['Determination'=>1.0, 'Stubbornness'=>0.5, 'Ambition'=>0.4]],
    9  => ['traits' => ['Adaptability'=>1.0, 'Creativity'=>0.5, 'Impulsiveness'=>0.2]],
    10 => ['traits' => ['Curiosity'=>1.0, 'Creativity'=>0.3]],
    11 => ['traits' => ['Creativity'=>1.0, 'Adaptability'=>0.6, 'Intelligence'=>0.3]],
    12 => ['traits' => ['Morality'=>1.0, 'Moral Rigidity'=>0.6, 'Courage'=>0.3]],
    13 => ['traits' => ['Moral Rigidity'=>1.0, 'Morality'=>0.8, 'Courage'=>0.2]],
    14 => ['traits' => ['Empathy'=>1.0, 'Kindness'=>0.6, 'Naivety'=>0.3]],
    15 => ['traits' => ['Empathy'=>1.0, 'Sensitivity'=>0.5, 'Teamwork'=>0.3]],
    16 => ['traits' => ['Teamwork'=>1.0, 'Leadership'=>0.6, 'Empathy'=>0.2]],
    17 => ['traits' => ['Courage'=>1.0, 'Morality'=>0.6, 'Bravery'=>0.6, 'Naivety'=>0.2]],
    18 => ['traits' => ['Bravery'=>1.0, 'Courage'=>0.6, 'Recklessness'=>0.5]],
    19 => ['traits' => ['Bravery'=>0.8, 'Detachment'=>0.6, 'Logic'=>0.4]],
    20 => ['traits' => ['Humor'=>1.0, 'Optimism'=>0.5, 'Empathy'=>0.3]],
    21 => ['traits' => ['Ambition'=>1.0, 'Overconfidence'=>0.4, 'Determination'=>0.5]],
    22 => ['traits' => ['Determination'=>1.0, 'Stubbornness'=>0.5, 'Leadership'=>0.2]],
    23 => ['traits' => ['Stubbornness'=>1.0, 'Determination'=>0.6, 'Moral Rigidity'=>0.2]],
    24 => ['traits' => ['Impulsiveness'=>1.0, 'Recklessness'=>0.5, 'Adaptability'=>0.4]],
    25 => ['traits' => ['Overtrusting'=>1.0, 'Naivety'=>0.7, 'Empathy'=>0.4]],
];

// 35 سؤال ثانویه؛ نسخه‌ی sanitize شده (فقط traits مجاز و وزن ≠ 0)
$SEC_BANK = [
    1  => ['traits' => ['Ambition'=>0.8, 'Bravery'=>0.6, 'Recklessness'=>0.4]],
    2  => ['traits' => ['Arrogance'=>1.0]],
    3  => ['traits' => ['Manipulativeness'=>1.0, 'Strategy'=>0.4, 'Leadership'=>0.3]],
    4  => ['traits' => ['Empathy'=>0.8, 'Sensitivity'=>0.8]],
    5  => ['traits' => ['Leadership'=>0.8, 'Teamwork'=>0.7, 'Kindness'=>0.3]],
    6  => ['traits' => ['Creativity'=>0.6, 'Logic'=>0.5, 'Strategy'=>0.7]],
    7  => ['traits' => ['Kindness'=>0.8, 'Overattachment'=>0.4, 'Naivety'=>0.3]],
    8  => ['traits' => ['Intelligence'=>0.8, 'Logic'=>0.6]],
    9  => ['traits' => ['Impulsiveness'=>0.8, 'Courage'=>0.5, 'Adaptability'=>0.4]],
    10 => ['traits' => ['Moral Rigidity'=>0.6, 'Determination'=>0.6, 'Obsessiveness'=>0.4]],
    11 => ['traits' => ['Overattachment'=>0.8, 'Kindness'=>0.6, 'Courage'=>0.3]],
    12 => ['traits' => ['Bravery'=>0.6, 'Strategy'=>0.4]],
    13 => ['traits' => ['Teamwork'=>0.8, 'Empathy'=>0.6, 'Kindness'=>0.5]],
    14 => ['traits' => ['Detachment'=>1.0, 'Logic'=>0.9]],
    15 => ['traits' => ['Leadership'=>0.6, 'Morality'=>0.5]],
    16 => ['traits' => ['Curiosity'=>0.8, 'Intelligence'=>0.3, 'Empathy'=>0.3]],
    17 => ['traits' => ['Strategy'=>0.9, 'Determination'=>0.6]],
    18 => ['traits' => ['Stubbornness'=>0.5, 'Moral Rigidity'=>0.5, 'Determination'=>0.4]],
    19 => ['traits' => ['Recklessness'=>0.9, 'Impulsiveness'=>0.6, 'Ambition'=>0.4]],
    20 => ['traits' => ['Kindness'=>0.9, 'Empathy'=>0.7, 'Morality'=>0.4]],
    21 => ['traits' => ['Leadership'=>0.5, 'Teamwork'=>0.5, 'Moral Rigidity'=>0.4]],
    22 => ['traits' => ['Manipulativeness'=>0.9, 'Strategy'=>0.6, 'Intelligence'=>0.4]],
    23 => ['traits' => ['Curiosity'=>0.8, 'Creativity'=>0.6, 'Optimism'=>0.4]],
    24 => ['traits' => ['Morality'=>0.8, 'Courage'=>0.5, 'Determination'=>0.4]],
    25 => ['traits' => ['Humor'=>0.9, 'Optimism'=>0.5, 'Empathy'=>0.3]],
    26 => ['traits' => ['Overattachment'=>0.6, 'Stubbornness'=>0.6]],
    27 => ['traits' => ['Strategy'=>0.8, 'Logic'=>0.6, 'Adaptability'=>-0.3]],
    28 => ['traits' => ['Curiosity'=>1.0, 'Intelligence'=>0.3]],
    29 => ['traits' => ['Leadership'=>0.5, 'Kindness'=>0.4, 'Empathy'=>0.5]],
    30 => ['traits' => ['Obsessiveness'=>0.9, 'Determination'=>0.6, 'Detachment'=>0.3]],
    31 => ['traits' => ['Arrogance'=>0.5, 'Leadership'=>0.4, 'Detachment'=>0.3]],
    32 => ['traits' => ['Adaptability'=>0.9, 'Empathy'=>0.3, 'Manipulativeness'=>0.3]],
    33 => ['traits' => ['Kindness'=>0.9, 'Empathy'=>0.7, 'Morality'=>0.4, 'Strategy'=>-0.3]],
    34 => ['traits' => ['Courage'=>0.7, 'Morality'=>0.6, 'Determination'=>0.4, 'Leadership'=>0.1]],
    35 => ['traits' => ['Morality'=>0.7, 'Logic'=>0.5]],
];

// ــــــــــــــــــ توابع کمکی هوش مصنوعی ــــــــــــــــــ

function normalizeText($s) {
    return trim(preg_replace('/\s+/', ' ', $s ?? ''));
}

function flattenCharacterTraitsAveraged($char) {
    $rec = [];
    $traits = isset($char['traits']) ? $char['traits'] : [];

    foreach ($traits as $genre => $data) {
        foreach (['positive', 'negative'] as $sign) {
            if (!isset($data[$sign]) || !is_array($data[$sign])) continue;
            foreach ($data[$sign] as $k => $v) {
                $v = floatval($v);
                if (!isset($rec[$k])) {
                    $rec[$k] = ['sum' => 0.0, 'count' => 0];
                }
                $rec[$k]['sum']   += $v;
                $rec[$k]['count'] += 1;
            }
        }
    }

    $avg = [];
    foreach ($rec as $k => $info) {
        $avg[$k] = $info['sum'] / max(1, $info['count']);
    }
    return $avg;
}

function getCharVectorForKeys($char, $keys) {
    $avg = flattenCharacterTraitsAveraged($char);
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = isset($avg[$k]) ? floatval($avg[$k]) : 0.0;
    }
    return $out;
}

function cosineSim($a, $b, $keys) {
    $dot = 0.0;
    $na  = 0.0;
    $nb  = 0.0;
    foreach ($keys as $k) {
        $x = isset($a[$k]) ? floatval($a[$k]) : 0.0;
        $y = isset($b[$k]) ? floatval($b[$k]) : 0.0;
        $dot += $x * $y;
        $na  += $x * $x;
        $nb  += $y * $y;
    }
    $denom = sqrt($na) * sqrt($nb);
    if ($denom == 0.0) return 0.0;
    return $dot / $denom;
}

function buildUserVectorFromAnswers($answers, $questionsBank) {
    $rec = [];
    foreach ($answers as $qid => $val) {
        $val = floatval($val);
        if (!isset($questionsBank[$qid])) continue;
        $traits = $questionsBank[$qid]['traits'];
        foreach ($traits as $trait => $wt) {
            if (!isset($rec[$trait])) {
                $rec[$trait] = ['sum' => 0.0, 'w' => 0.0];
            }
            $rec[$trait]['sum'] += $val * $wt;
            $rec[$trait]['w']   += abs($wt);
        }
    }
    $vec = [];
    foreach ($rec as $trait => $info) {
        $vec[$trait] = $info['sum'] / max(1e-9, $info['w']);
    }
    return $vec;
}

function mergeUserVectors($p1Vec, $p3Vec) {
    $keys = array_unique(array_merge(array_keys($p1Vec), array_keys($p3Vec)));
    $out = [];
    foreach ($keys as $k) {
        $hasP1 = array_key_exists($k, $p1Vec);
        $hasP3 = array_key_exists($k, $p3Vec);
        if ($hasP1 && $hasP3) {
            $out[$k] = ($p1Vec[$k] + $p3Vec[$k]) / 2.0;
        } elseif ($hasP1) {
            $out[$k] = $p1Vec[$k];
        } else {
            $out[$k] = $p3Vec[$k];
        }
    }
    return $out;
}

function computeSimilarityPHP($char, $userVec, $keys, $userMbti, $prefsTagTally, $adaptiveAns, $userAnimeType) {
    $charVec = getCharVectorForKeys($char, $keys);
    $sim = cosineSim($userVec, $charVec, $keys);

    // 1) MBTI bonus
    $charMbti = strtoupper(trim($char['mbti'] ?? ''));
    if ($userMbti && $charMbti === $userMbti) {
        $sim += 0.05;
    }

    // 2) preferences bonus
    $charTags = [];
    if (!empty($char['motivation']['themes']) && is_array($char['motivation']['themes'])) {
        foreach ($char['motivation']['themes'] as $t) {
            $charTags[normalizeText($t)] = true;
        }
    }
    if (!empty($char['key_decisions']) && is_array($char['key_decisions'])) {
        foreach ($char['key_decisions'] as $d) {
            if (!empty($d['theme'])) {
                $charTags[normalizeText($d['theme'])] = true;
            }
            if (!empty($d['choice_type'])) {
                $charTags[normalizeText($d['choice_type'])] = true;
            }
        }
    }

    foreach ($prefsTagTally as $tag => $count) {
        $t = normalizeText($tag);
        if ($t !== '' && isset($charTags[$t])) {
            $sim += 0.01 * intval($count);
        }
    }

    // 3) adaptive bonus (۱..۵ → ۰..۱)
    $scaled = [];
    foreach ($adaptiveAns as $k => $v) {
        $scaled[normalizeText($k)] = (floatval($v) - 1.0) / 4.0;
    }

    $addIf = function($txt) use (&$sim, $scaled) {
        if (!$txt) return;
        $k = normalizeText($txt);
        if ($k !== '' && array_key_exists($k, $scaled)) {
            $sim += 0.015 * $scaled[$k];
        }
    };

    if (!empty($char['motivation']['themes'])) {
        foreach ($char['motivation']['themes'] as $t) {
            $addIf($t);
        }
    }
    if (!empty($char['key_decisions']) && is_array($char['key_decisions'])) {
        foreach ($char['key_decisions'] as $d) {
            $addIf($d['theme'] ?? null);
            $addIf($d['choice_type'] ?? null);
        }
    }

    // 4) anime type bonus
    if ($userAnimeType && !empty($char['anime_type']) && is_array($char['anime_type'])) {
        $userTypeNorm = normalizeText($userAnimeType);
        foreach ($char['anime_type'] as $t) {
            if (normalizeText($t) === $userTypeNorm) {
                $sim += 0.02;
                break;
            }
        }
    }

    // clamp
    if ($sim < 0) $sim = 0;
    if ($sim > 1) $sim = 1;
    return $sim;
}

// ــــــــــــــــــ ساخت بردار کاربر و محاسبه نتایج ــــــــــــــــــ

// P1 و P3 را بر اساس پاسخ‌ها بساز
$p1Vec = buildUserVectorFromAnswers($p1Answers, $P1_BANK);
$p3Vec = buildUserVectorFromAnswers($p3Answers, $SEC_BANK);
$userVec = mergeUserVectors($p1Vec, $p3Vec);
$allKeys = array_keys($userVec);

// نرمال‌سازی تگ‌های preferences (اگر دوست داشتی می‌توانی محدود به ALLOWED_TRAITS کنی)
$normPrefs = [];
foreach ($prefsTagTally as $trait => $count) {
    $t = normalizeText($trait);
    if ($t === '') continue;
    $normPrefs[$t] = intval($count);
}

// حساب similarity برای همه کاراکترها
$results = [];
foreach ($characters as $char) {
    $sim = computeSimilarityPHP($char, $userVec, $allKeys, $userMbti, $normPrefs, $adaptiveAns, $userAnimeType);
    $results[] = [
        'character' => $char,
        'sim'       => $sim,
    ];
}

// مرتب‌سازی نزولی بر اساس شباهت
usort($results, function($a, $b) {
    if ($a['sim'] == $b['sim']) return 0;
    return ($a['sim'] < $b['sim']) ? 1 : -1;
});

if (empty($results)) {
    echo json_encode(['error' => 'No characters found']);
    exit;
}

$topOne  = $results[0];
$topList = array_slice($results, 0, 30);

echo json_encode([
    'topOne'  => $topOne,
    'topList' => $topList,
], JSON_UNESCAPED_UNICODE);
exit;
