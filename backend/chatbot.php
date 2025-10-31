<?php
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function load_intents($path){
    if (!file_exists($path)) return [];
    $rows = array_map('str_getcsv', file($path));
    $header = array_map('trim', $rows[0] ?? []);
    $out = [];
    for($i=1;$i<count($rows);$i++){
        $r = $rows[$i]; if(!isset($r[0]) || trim($r[0])==='') continue;
        // map by header positions
        $assoc = [];
        foreach($header as $k => $col){ $assoc[$col] = $r[$k] ?? ''; }
        $samples = array_filter(array_map('trim', explode('||', $assoc['samples'] ?? '')));
        $out[] = ['id'=> $assoc['id'] ?? $i, 'name'=>$assoc['intent_name'] ?? 'unknown', 'samples'=>$samples, 'response'=>$assoc['response_template'] ?? ''];
    }
    return $out;
}

if ($action === 'intents'){
    try{
        $intents = load_intents($config['excel_path']);
        echo json_encode(['ok'=>true,'intents'=>$intents], JSON_UNESCAPED_UNICODE);
    }catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

if ($action === 'ask'){
    $input = trim($_POST['message'] ?? ''); if ($input === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'message empty']); exit; }
    $intents = load_intents($config['excel_path']);

    // matching: similarity with similar_text (basic fuzzy)
    $best = 0; $matched = null; $matchedScore = 0;
    foreach($intents as $intent){
        foreach($intent['samples'] as $s){
            if ($s === '') continue;
            similar_text(mb_strtolower($input, 'UTF-8'), mb_strtolower($s, 'UTF-8'), $perc);
            if ($perc > $best){ $best = $perc; $matched = $intent; $matchedScore = $perc; }
        }
    }

    // if matched and response template present -> return immediately
    if ($matched && !empty($matched['response']) && $matchedScore > 45){
        echo json_encode(['ok'=>true,'source'=>'intent_template','reply'=>$matched['response']]); exit;
    }

    // otherwise, call Gemini API
    $model = $config['model'];
    $endpoint = rtrim($config['gemini_base'], '/') . "/{$model}:generateContent";
    $prompt = "User: $input\n";
    if ($matched) $prompt .= "Detected intent: " . $matched['name'] . "\n";

    $body = [ 'contents'=>[[ 'role'=>'user', 'parts'=>[['text'=>$prompt . "Tolong jawab dengan bahasa Indonesia singkat dan sopan."]] ]] ];

    $ch = curl_init($endpoint);
    $headers = [ 'Content-Type: application/json', 'x-goog-api-key: ' . $config['api_key'] ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'curl_error: '.$err]); exit; }
    if ($httpcode >= 400){ http_response_code($httpcode); echo $resp; exit; }

    $parsed = json_decode($resp, true);
	$reply = '';

	// Robust extraction for various Gemini response shapes
	if (is_array($parsed)) {
		// 1) Newer style: candidates -> content -> parts -> text
		if (isset($parsed['candidates']) && is_array($parsed['candidates']) && count($parsed['candidates']) > 0) {
			$candidate = $parsed['candidates'][0];

			// Case A: candidate.content.parts[*].text
			if (isset($candidate['content']) && isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
				$texts = [];
				foreach ($candidate['content']['parts'] as $part) {
					if (is_array($part) && isset($part['text'])) {
						$texts[] = $part['text'];
					} elseif (is_string($part)) {
						// sometimes part could be plain string
						$texts[] = $part;
					}
				}
				if (count($texts) > 0) {
					$reply = implode("\n\n", $texts);
				}
			}

			// Case B: candidate.content may nest differently: content -> { parts: [...] } (already handled),
			// or candidate.content.parts may be deeper under 'output' key in older variants.
			if ($reply === '' && isset($candidate['output']) && is_array($candidate['output'])) {
				// try output[0].content[0].text or similar
				foreach ($candidate['output'] as $out) {
					if (isset($out['content']) && is_array($out['content'])) {
						foreach ($out['content'] as $c) {
							if (isset($c['text'])) $reply .= ($reply ? "\n\n" : '') . $c['text'];
							elseif (isset($c['parts']) && is_array($c['parts'])) {
								foreach ($c['parts'] as $p) {
									if (isset($p['text'])) $reply .= ($reply ? "\n\n" : '') . $p['text'];
								}
							}
						}
					}
				}
			}
		}

		// 2) Alternative older shape: parsed['candidates'][0]['content'][0]['text']
		if ($reply === '' && isset($parsed['candidates'][0]['content'][0]['text'])) {
			$reply = $parsed['candidates'][0]['content'][0]['text'];
		}

		// 3) Another fallback: look for any 'text' fields inside the JSON (depth-first)
		if ($reply === '') {
			$texts = [];
			$it = new RecursiveIteratorIterator(new RecursiveArrayIterator($parsed));
			foreach ($it as $key => $value) {
				if ($key === 'text' && is_string($value)) $texts[] = $value;
			}
			if (count($texts) > 0) $reply = implode("\n\n", $texts);
		}

		// 4) If still empty, fallback to returning raw JSON (useful for debugging)
		if ($reply === '') {
			$reply = json_encode($parsed, JSON_UNESCAPED_UNICODE);
		}
	} else {
		// not valid json, return raw response
		$reply = $resp;		
	}
	
	// Format cleanup markdown to plain text
	$reply = preg_replace('/[*_`]/', '', $reply); // remove *, _, `
	$reply = preg_replace('/\n{2,}/', "\n", $reply); // fix double newline
	$reply = preg_replace('/\\s+\\*/', "\n•", $reply); // convert bullet *

	// return reply
	echo json_encode(['ok'=>true,'source'=>'gemini','reply'=>$reply], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid action']);
