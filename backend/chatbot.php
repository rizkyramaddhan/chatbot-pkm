<?php
declare(strict_types=1);

// ===== Bootstrap dasar =====
header('Content-Type: application/json; charset=utf-8');

// Produksi: tampilkan error ke log, bukan ke output (hindari HTML error bocor ke JSON)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ===== Util umum & helper path =====
function intents_dir()            { return __DIR__ . '/data'; }
function intents_path()           { return intents_dir() . '/intents.csv'; }
function intents_history_dir()    { return intents_dir() . '/intents_history'; }
function intents_tmp_dir()        { return intents_dir() . '/tmp'; }
function intents_lock_path()      { return intents_dir() . '/intents.lock'; }

function ensure_dirs(): void {
    foreach ([intents_dir(), intents_history_dir(), intents_tmp_dir()] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }
}

/**
 * PHP 7 friendly: pengganti str_ends_with
 */
function str_ends_with_compat(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return substr($haystack, -strlen($needle)) === $needle;
}

function json_out(array $arr, int $status = 200): void {
    if ($status !== 200) {
        http_response_code($status);
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_file_base64(string $path): ?string {
    if (!file_exists($path)) return null;
    $data = @file_get_contents($path);
    return $data === false ? null : base64_encode($data);
}

// ===== Loader & validator intents.csv =====
function load_intents(string $path): array {
    if (!file_exists($path)) return [];
    $rows = array_map('str_getcsv', file($path));
    $header = array_map('trim', $rows[0] ?? []);
    $out = [];

    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (!isset($r[0]) || trim((string)$r[0]) === '') continue;

        // Map by header positions
        $assoc = [];
        foreach ($header as $k => $col) {
            $assoc[$col] = $r[$k] ?? '';
        }

        $samples = array_filter(array_map('trim', explode('||', $assoc['samples'] ?? '')));
        $out[] = [
            'id'       => $assoc['id'] ?? $i,
            'name'     => $assoc['intent_name'] ?? 'unknown',
            'samples'  => $samples,
            'response' => $assoc['response_template'] ?? ''
        ];
    }
    return $out;
}

function validate_intents_csv(string $csvPath): array {
    $errors = [];
    $warnings = [];

    if (!file_exists($csvPath)) {
        $errors[] = "File tidak ditemukan.";
        return [$errors, $warnings];
    }

    $rows = array_map('str_getcsv', file($csvPath));
    if (!$rows || count($rows) < 2) {
        $errors[] = "CSV kosong atau hanya berisi header.";
        return [$errors, $warnings];
    }

    $header   = array_map('trim', $rows[0]);
    $expected = ['id','intent_name','samples','response_template'];
    if (count($header) !== 4 || array_map('strtolower', $header) !== $expected) {
        $errors[] = "Header wajib persis: id,intent_name,samples,response_template (berurutan).";
        return [$errors, $warnings];
    }

    $csvInjectionPrefixes = ['=', '+', '-', '@'];
    $ids = [];

    for ($i = 1; $i < count($rows); $i++) {
        $line = $i + 1;
        $r = $rows[$i];

        if (count($r) < 4) {
            $errors[] = "Baris $line: kolom kurang (harus 4).";
            continue;
        }
        if (count($r) > 4) {
            $warnings[] = "Baris $line: ada kolom berlebih, hanya 4 kolom pertama yang dipakai.";
        }

        list($id, $name, $samples, $resp) = [trim((string)$r[0]), trim((string)$r[1]), (string)$r[2], (string)$r[3]];

        if ($id === '' || !ctype_digit($id) || intval($id) <= 0) {
            $errors[] = "Baris $line: id harus angka bulat positif.";
        } else {
            if (isset($ids[$id])) $errors[] = "Baris $line: id duplikat ($id).";
            $ids[$id] = true;
        }

        if ($name === '') $errors[] = "Baris $line: intent_name kosong.";

        foreach ([$name, $samples, $resp] as $colVal) {
            $trimmed = ltrim($colVal);
            if ($trimmed !== '' && in_array(substr($trimmed, 0, 1), $csvInjectionPrefixes, true)) {
                $errors[] = "Baris $line: sel diawali salah satu dari (=,+,-,@) yang rawan CSV-injection.";
                break;
            }
        }

        $sampleList = array_filter(array_map('trim', explode('||', (string)$samples)));
        if (count($sampleList) < 1) {
            $errors[] = "Baris $line: samples minimal 1, pisahkan dengan '||'.";
        }

        if ($resp === '') $errors[] = "Baris $line: response_template kosong.";
    }

    // Uji parse pakai loader runtime
    if (empty($errors)) {
        $parsed = load_intents($csvPath);
        if (!is_array($parsed) || count($parsed) < 1) {
            $errors[] = "Gagal uji parse dengan logika lama. Periksa format nilai di setiap kolom.";
        }
    }

    return [$errors, $warnings];
}

function atomic_replace_intents(string $newCsvPath): array {
    $lock = @fopen(intents_lock_path(), 'c');
    if (!$lock) return [false, "Tidak bisa membuka lock file."];

    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        return [false, "Gagal mengunci operasi file."];
    }

    ensure_dirs();

    $cur    = intents_path();
    $histDir = intents_history_dir();
    if (!is_dir($histDir)) @mkdir($histDir, 0755, true);

    if (file_exists($cur)) {
        $stamp = date('Ymd_His');
        $arch  = $histDir . "/intents_$stamp.csv";
        if (!@rename($cur, $arch)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return [false, "Gagal mengarsipkan file lama."];
        }
    }

    if (!@rename($newCsvPath, $cur)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return [false, "Gagal menggantikan file aktif."];
    }

    fflush($lock);
    flock($lock, LOCK_UN);
    fclose($lock);
    return [true, null];
}

function list_history_files(): array {
    $dir = intents_history_dir();
    if (!is_dir($dir)) return [];

    $files = array_values(array_filter(scandir($dir) ?: [], function ($f) {
        return $f !== '.' && $f !== '..' && str_ends_with_compat($f, '.csv');
    }));

    usort($files, function ($a, $b) use ($dir) {
        return filemtime("$dir/$b") <=> filemtime("$dir/$a");
    });

    return array_map(function ($f) use ($dir) {
        return [
            'filename' => $f,
            'size'     => @filesize("$dir/$f") ?: 0,
            'modified' => date('c', @filemtime("$dir/$f") ?: time())
        ];
    }, $files);
}

// ===== Router aksi =====
switch ($action) {
    case 'intents': {
        try {
            $intents = load_intents($config['excel_path']);
            echo json_encode(['ok' => true, 'intents' => $intents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    case 'ask': {
        $input = trim((string)($_POST['message'] ?? ''));
        if ($input === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'message empty'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $intents = load_intents($config['excel_path']);

        // Basic fuzzy matching
        $best = 0.0; $matched = null; $matchedScore = 0.0;
        foreach ($intents as $intent) {
            foreach ($intent['samples'] as $s) {
                if ($s === '') continue;
                $a = mb_strtolower($input, 'UTF-8');
                $b = mb_strtolower($s, 'UTF-8');
                similar_text($a, $b, $perc);
                if ($perc > $best) {
                    $best = $perc;
                    $matched = $intent;
                    $matchedScore = $perc;
                }
            }
        }

        if ($matched && !empty($matched['response']) && $matchedScore > 45) {
            echo json_encode(['ok' => true, 'source' => 'intent_template', 'reply' => $matched['response']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Gemini call
        $model    = $config['model'];
        $endpoint = rtrim($config['gemini_base'], '/') . "/{$model}:generateContent";
        $prompt   = "User: $input\n";
        if ($matched) $prompt .= "Detected intent: " . $matched['name'] . "\n";

        $body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [[ 'text' => $prompt . "Tolong jawab dengan bahasa Indonesia singkat dan sopan." ]]
            ]]
        ];

        $ch = curl_init($endpoint);
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $config['api_key']
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $resp     = curl_exec($ch);
        $err      = curl_error($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'curl_error: ' . $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($httpcode >= 400) {
            http_response_code($httpcode);
            echo $resp;
            exit;
        }

        $parsed = json_decode((string)$resp, true);
        $reply  = '';

        if (is_array($parsed)) {
            // 1) candidates[0].content.parts[*].text
            if (isset($parsed['candidates'][0])) {
                $candidate = $parsed['candidates'][0];

                if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
                    $texts = [];
                    foreach ($candidate['content']['parts'] as $part) {
                        if (is_array($part) && isset($part['text'])) {
                            $texts[] = $part['text'];
                        } elseif (is_string($part)) {
                            $texts[] = $part;
                        }
                    }
                    if (count($texts) > 0) {
                        $reply = implode("\n\n", $texts);
                    }
                }

                // 2) older variants under 'output'
                if ($reply === '' && isset($candidate['output']) && is_array($candidate['output'])) {
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

            // 3) alternative: candidates[0].content[0].text
            if ($reply === '' && isset($parsed['candidates'][0]['content'][0]['text'])) {
                $reply = $parsed['candidates'][0]['content'][0]['text'];
            }

            // 4) fallback: cari field 'text' di mana pun
            if ($reply === '') {
                $texts = [];
                $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($parsed));
                foreach ($it as $key => $value) {
                    if ($key === 'text' && is_string($value)) $texts[] = $value;
                }
                if (count($texts) > 0) $reply = implode("\n\n", $texts);
            }

            // 5) terakhir: kembalikan JSON mentah
            if ($reply === '') {
                $reply = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } else {
            // not JSON
            $reply = (string)$resp;
        }

        // Cleanup ringan
        $reply = preg_replace('/[*_`]/', '', (string)$reply);
        $reply = preg_replace('/\n{2,}/', "\n", (string)$reply);
        $reply = preg_replace('/\\s+\\*/', "\n•", (string)$reply);

        echo json_encode(['ok' => true, 'source' => 'gemini', 'reply' => $reply], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    case 'intents_template': {
        ensure_dirs();
        $p = intents_path();
        if (!file_exists($p)) {
            $template = "id,intent_name,samples,response_template\n";
            json_out([
                'ok'             => true,
                'filename'       => 'intents_template.csv',
                'content_base64' => base64_encode($template)
            ]);
        } else {
            $b64 = read_file_base64($p);
            json_out([
                'ok'             => true,
                'filename'       => 'intents.csv',
                'content_base64' => $b64
            ]);
        }
        // json_out sudah exit
    }

    case 'upload_intents': {
        ensure_dirs();

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'Upload gagal atau tidak ada file. Gunakan field name "file".']);
        }

        $max = 5 * 1024 * 1024; // 5MB
        if ((int)$_FILES['file']['size'] > $max) {
            json_out(['ok' => false, 'error' => 'Ukuran file terlalu besar (>5MB).']);
        }

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            json_out(['ok' => false, 'error' => 'Ekstensi wajib .csv']);
        }

        $tmpTarget = intents_tmp_dir() . '/upload_' . bin2hex(random_bytes(6)) . '.csv';
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $tmpTarget)) {
            json_out(['ok' => false, 'error' => 'Gagal menyimpan file sementara.']);
        }

        list($errors, $warnings) = validate_intents_csv($tmpTarget);
        if (!empty($errors)) {
            @unlink($tmpTarget);
            json_out(['ok' => false, 'errors' => $errors, 'warnings' => $warnings]);
        }

        list($success, $err) = atomic_replace_intents($tmpTarget);
        if (!$success) {
            @unlink($tmpTarget);
            json_out(['ok' => false, 'error' => $err]);
        }

        json_out(['ok' => true, 'message' => 'intents.csv berhasil diperbarui', 'warnings' => $warnings]);
    }

    case 'intents_history': {
        ensure_dirs();
        $list = list_history_files();
        json_out(['ok' => true, 'history' => $list]);
    }

    case 'restore_intents': {
        ensure_dirs();
        $fn = $_POST['filename'] ?? $_GET['filename'] ?? '';
        if ($fn === '') json_out(['ok' => false, 'error' => 'Parameter filename wajib.']);

        $src = intents_history_dir() . '/' . basename($fn);
        if (!file_exists($src)) json_out(['ok' => false, 'error' => 'File arsip tidak ditemukan.']);

        $tmpTarget = intents_tmp_dir() . '/restore_' . bin2hex(random_bytes(6)) . '.csv';
        if (!@copy($src, $tmpTarget)) {
            json_out(['ok' => false, 'error' => 'Gagal menyalin file arsip.']);
        }

        list($errors, $warnings) = validate_intents_csv($tmpTarget);
        if (!empty($errors)) {
            @unlink($tmpTarget);
            json_out(['ok' => false, 'errors' => $errors, 'warnings' => $warnings]);
        }

        list($success, $err) = atomic_replace_intents($tmpTarget);
        if (!$success) {
            @unlink($tmpTarget);
            json_out(['ok' => false, 'error' => $err]);
        }

        json_out(['ok' => true, 'message' => "Berhasil restore $fn", 'warnings' => $warnings]);
    }

    default: {
        json_out(['ok' => false, 'error' => 'invalid action'], 400);
    }
}
