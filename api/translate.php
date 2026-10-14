<?php
// api/translate.php - Smart Auto-Hinglish Detecting Dual-Engine Translator
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$text   = trim($_POST['text'] ?? '');
$target = trim($_POST['target_lang'] ?? 'hi');

if (empty($text)) {
    echo json_encode(['status' => false, 'message' => 'Text cannot be empty']);
    exit;
}

$target_clean = strtolower($target);

function curlFetch($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && !empty($res)) ? $res : null;
}

// Helper: Roman script ko Devanagari Hindi me convert karne ke liye
function convertToHindiDevanagari($input) {
    // 1. Google Input Tools transliteration
    $url = "https://inputtools.google.com/request?text=" . rawurlencode($input) . "&itc=hi-t-i0-und&num=1";
    $res = curlFetch($url);
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data[1][0][1][0])) {
            return $data[1][0][1][0];
        }
    }
    // 2. Primary Google Translation Engine to Hindi
    $urlHindi = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=hi&dt=t&q=" . rawurlencode($input);
    $resHindi = curlFetch($urlHindi);
    if ($resHindi) {
        $json = json_decode($resHindi, true);
        if (isset($json[0]) && is_array($json[0])) {
            $str = '';
            foreach ($json[0] as $seg) {
                if (!empty($seg[0])) $str .= $seg[0];
            }
            if (!empty($str)) return trim($str);
        }
    }
    // 3. Fallback Engine to Hindi
    $urlFallback = "https://api.mymemory.translated.net/get?q=" . rawurlencode($input) . "&langpair=autodetect|hi";
    $fbRes = curlFetch($urlFallback);
    if ($fbRes) {
        $fbJson = json_decode($fbRes, true);
        $fallbackHindi = trim($fbJson['responseData']['translatedText'] ?? '');
        if (!empty($fallbackHindi)) return $fallbackHindi;
    }

    return $input;
}

// 1. Dedicated Handler for Hinglish (hi-Latn)
if ($target_clean === 'hi-latn' || $target_clean === 'hinglish') {
    // Step A: Translate into pure Hindi first
    $hindi_text = convertToHindiDevanagari($text);

    // Step B: Convert Hindi Devanagari script to English phonetic letters (Hinglish)
    $urlTranslit = "https://inputtools.google.com/request?text=" . rawurlencode($hindi_text) . "&itc=hi-t-i0-und&num=1";
    $resTranslit = curlFetch($urlTranslit);
    $hinglish_final = '';

    if ($resTranslit) {
        $tData = json_decode($resTranslit, true);
        if (isset($tData[1][0][1][0])) {
            $hinglish_final = $tData[1][0][1][0];
        }
    }

    echo json_encode([
        'status'          => true,
        'translated_text' => !empty($hinglish_final) ? $hinglish_final : $hindi_text
    ]);
    exit;
}

// 2. Auto-Bridge Handler: Agar input text Hinglish/Roman script me hai aur target koi doosri language hai
$sourceTextToTranslate = $text;
if (preg_match('/^[a-zA-Z0-9\s.,!?\'"-]+$/', $text) && $target_clean !== 'en') {
    $devanagariAttempt = convertToHindiDevanagari($text);
    // Agar Hindi script successfully ban gayi toh use direct source banayein
    if (preg_match('/[\x{0900}-\x{097F}]/u', $devanagariAttempt)) {
        $sourceTextToTranslate = $devanagariAttempt;
    }
}

// 3. Standard International & Indian Languages (Primary Engine)
$urlPrimary = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=" . rawurlencode($target_clean) . "&dt=t&q=" . rawurlencode($sourceTextToTranslate);
$resPrimary = curlFetch($urlPrimary);

if ($resPrimary) {
    $result = json_decode($resPrimary, true);
    if (isset($result[0]) && is_array($result[0])) {
        $translated = '';
        foreach ($result[0] as $s) {
            if (!empty($s[0])) $translated .= $s[0];
        }
        $translated = trim($translated);
        if (!empty($translated)) {
            echo json_encode(['status' => true, 'translated_text' => $translated]);
            exit;
        }
    }
}

// 4. Backup Engine if Google Primary times out (MyMemory)
$urlFallback = "https://api.mymemory.translated.net/get?q=" . rawurlencode($sourceTextToTranslate) . "&langpair=autodetect|" . rawurlencode($target_clean);
$fbRes = curlFetch($urlFallback);
if ($fbRes) {
    $fbJson = json_decode($fbRes, true);
    $backupText = trim($fbJson['responseData']['translatedText'] ?? '');
    if (!empty($backupText)) {
        echo json_encode(['status' => true, 'translated_text' => $backupText]);
        exit;
    }
}

// Safe default
echo json_encode(['status' => true, 'translated_text' => $text]);
exit;