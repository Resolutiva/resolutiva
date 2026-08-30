<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Resolutiva - Evolution Instance Manager (Standalone)
|--------------------------------------------------------------------------
| Objetivo:
| - Página moderna e responsiva para: conferir status, desconectar e gerar QR Code
| - Recebe a instância via querystring: ?instance=MINHA_INSTANCIA
|
| Segurança:
| - NÃO exponha sua API Key no JavaScript
| - Configure credenciais via variáveis de ambiente (recomendado):
|     EVOLUTION_SERVER_URL="https://SEU-EVOLUTION:PORTA"
|     EVOLUTION_API_KEY="SUA-APIKEY"
|     EVOLUTION_SSL_VERIFY="1"   (opcional: 0 para desativar validação SSL)
|
| Uso:
| - Coloque este arquivo e a pasta /assets no seu servidor (Apache/Nginx + PHP)
| - Acesse: /evolution_instance.php?instance=empresa_x
|--------------------------------------------------------------------------
*/

function respond_json(array $payload, int $httpStatus = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function env_str(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val === false) return $default;
    $val = trim((string)$val);
    return $val === '' ? $default : $val;
}

/**
 * Sanitiza e valida o nome da instância.
 * Regra (mesma ideia do AppProfile): letras, números, _ e -, 3 a 50 chars.
 */
function sanitize_instance(string $instance): string {
    $instance = trim($instance);
    if ($instance === '') return '';
    if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $instance)) return '';
    return $instance;
}

function curl_request(string $method, string $url, string $apiKey, ?array $json = null, int $timeoutSeconds = 20): array {
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'apikey: ' . $apiKey,
    ];

    $method = strtoupper($method);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
    ];

    // SSL verify (recomendado manter 1; coloque 0 somente se você souber o que está fazendo)
    $sslVerify = env_str('EVOLUTION_SSL_VERIFY', '1');
    $opts[CURLOPT_SSL_VERIFYPEER] = ($sslVerify === '1' || strtolower($sslVerify) === 'true');
    $opts[CURLOPT_SSL_VERIFYHOST] = $opts[CURLOPT_SSL_VERIFYPEER] ? 2 : 0;

    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($json ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => null,
            'data' => null,
            'raw' => null,
            'error' => $err ?: 'cURL request failed',
        ];
    }

    // Tenta decodificar JSON, mas mantém raw se não for JSON válido
    $decoded = null;
    $trim = trim((string)$body);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $decoded = json_decode($trim, true);
        if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;
    }

    $ok = ($code >= 200 && $code < 300);

    return [
        'ok' => $ok,
        'status' => $code,
        'data' => $decoded ?? $trim,
        'raw' => $trim,
        'error' => $ok ? null : ("HTTP {$code}"),
    ];
}

function try_requests(string $serverUrl, string $apiKey, array $candidates): array {
    foreach ($candidates as $c) {
        $method = strtoupper((string)($c['method'] ?? 'GET'));
        $path   = (string)($c['path'] ?? '');
        $json   = $c['json'] ?? null;
        if ($path === '') continue;

        $url = rtrim($serverUrl, '/') . $path;

        try {
            $res = curl_request($method, $url, $apiKey, is_array($json) ? $json : null);

            if ($res['ok']) {
                return [
                    'ok' => true,
                    'status' => $res['status'],
                    'data' => $res['data'],
                    'endpoint' => $path,
                    'error' => null,
                ];
            }
        } catch (Throwable $e) {
            // continua tentando outros endpoints
        }
    }

    return [
        'ok' => false,
        'status' => null,
        'data' => null,
        'endpoint' => null,
        'error' => 'No endpoint matched / request failed',
    ];
}

function extract_qr_base64($data): ?string {
    if (!$data) return null;

    if (is_string($data)) {
        return normalize_qr_string($data);
    }

    if (is_array($data)) {
        $candidates = [
            $data['base64'] ?? null,
            $data['qrcode'] ?? null,
            $data['qr'] ?? null,
            $data['qrCode'] ?? null,
            $data['code'] ?? null,
            $data['data'] ?? null,
        ];

        if (isset($data['qrcode']['base64'])) $candidates[] = $data['qrcode']['base64'];
        if (isset($data['qrcode']['qr']))     $candidates[] = $data['qrcode']['qr'];
        if (isset($data['data']['qrcode']))   $candidates[] = $data['data']['qrcode'];
        if (isset($data['data']['base64']))   $candidates[] = $data['data']['base64'];

        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return normalize_qr_string($c);
            }
        }

        $flat = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($flat) && preg_match('/data:image\/[a-zA-Z]+;base64,([A-Za-z0-9+\/=]+)/', $flat, $m)) {
            return 'data:image/png;base64,' . $m[1];
        }
    }

    return null;
}

function normalize_qr_string(string $s): string {
    $s = trim($s);

    if (str_starts_with($s, 'data:image')) return $s;

    // base64 puro
    if (preg_match('/^[A-Za-z0-9+\/=]+$/', $s) && strlen($s) > 100) {
        return 'data:image/png;base64,' . $s;
    }

    return $s;
}

function is_connected($data): bool {
    if (!$data) return false;

    $hay = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($hay)) return false;

    $hay = strtolower($hay);

    $positive = ['open', 'connected', 'online', 'true', 'ready'];
    $negative = ['close', 'closed', 'disconnected', 'offline', 'false', 'error'];

    foreach ($negative as $n) {
        if (str_contains($hay, $n)) {
            $hasPositive = false;
            foreach ($positive as $p) {
                if (str_contains($hay, $p)) { $hasPositive = true; break; }
            }
            return $hasPositive;
        }
    }

    foreach ($positive as $p) {
        if (str_contains($hay, $p)) return true;
    }

    return false;
}

// ---------------------------------------------------------------------
// CONFIG (via ENV)
// ---------------------------------------------------------------------
$serverUrl = env_str('EVOLUTION_SERVER_URL', '');
$apiKey    = env_str('EVOLUTION_API_KEY', '');
$configOk  = ($serverUrl !== '' && $apiKey !== '');

// ---------------------------------------------------------------------
// API MODE (AJAX)
// ---------------------------------------------------------------------
if (isset($_GET['api'])) {
    if (!$configOk) {
        respond_json([
            'ok' => 0,
            'message' => 'Configuração incompleta. Defina EVOLUTION_SERVER_URL e EVOLUTION_API_KEY.',
        ], 500);
    }

    $action   = (string)($_GET['action'] ?? '');
    $instance = sanitize_instance((string)($_REQUEST['instance'] ?? ''));

    if ($instance === '') {
        respond_json([
            'ok' => 0,
            'message' => 'Instância inválida. Use apenas letras/números/_/-, entre 3 e 50 caracteres.',
        ], 422);
    }

    if ($action === 'status') {
        $res = try_requests($serverUrl, $apiKey, [
            ['method' => 'GET', 'path' => '/instance/connectionState/' . $instance],
            ['method' => 'GET', 'path' => '/instance/connection-state/' . $instance],
            ['method' => 'GET', 'path' => '/instance/status/' . $instance],
            ['method' => 'GET', 'path' => '/instance/info/' . $instance],
            ['method' => 'GET', 'path' => '/instance/' . $instance],
        ]);

        if (!$res['ok']) {
            respond_json([
                'ok' => 0,
                'message' => 'Não foi possível verificar o status no Evolution.',
            ], 502);
        }

        $connected = is_connected($res['data']);

        $state = null;
        if (is_array($res['data'])) {
            $state = $res['data']['state']
                ?? $res['data']['status']
                ?? ($res['data']['instance']['state'] ?? null)
                ?? ($res['data']['instance']['status'] ?? null);
        }

        respond_json([
            'ok' => 1,
            'instance' => $instance,
            'connected' => $connected ? 1 : 0,
            'state' => $state,
            'endpoint' => $res['endpoint'],
            'raw' => $res['data'],
        ]);
    }

    if ($action === 'qrcode') {
        $res = try_requests($serverUrl, $apiKey, [
            ['method' => 'GET',  'path' => '/instance/connect/' . $instance],
            ['method' => 'POST', 'path' => '/instance/connect/' . $instance, 'json' => []],

            ['method' => 'GET', 'path' => '/instance/qrcode/' . $instance],
            ['method' => 'GET', 'path' => '/instance/qr/' . $instance],
            ['method' => 'GET', 'path' => '/instance/qr-code/' . $instance],
            ['method' => 'GET', 'path' => '/qrcode/' . $instance],
        ]);

        // Se falhar, tenta criar instância e tenta de novo
        if (!$res['ok']) {
            try_requests($serverUrl, $apiKey, [
                ['method' => 'POST', 'path' => '/instance/create', 'json' => ['instanceName' => $instance]],
                ['method' => 'POST', 'path' => '/instance/create', 'json' => ['instance' => $instance]],
                ['method' => 'POST', 'path' => '/instance/init',   'json' => ['instanceName' => $instance]],
            ]);

            $res = try_requests($serverUrl, $apiKey, [
                ['method' => 'GET',  'path' => '/instance/connect/' . $instance],
                ['method' => 'POST', 'path' => '/instance/connect/' . $instance, 'json' => []],
                ['method' => 'GET', 'path' => '/instance/qrcode/' . $instance],
                ['method' => 'GET', 'path' => '/instance/qr/' . $instance],
                ['method' => 'GET', 'path' => '/instance/qr-code/' . $instance],
                ['method' => 'GET', 'path' => '/qrcode/' . $instance],
            ]);
        }

        if (!$res['ok']) {
            respond_json([
                'ok' => 0,
                'message' => 'Não foi possível obter o QR Code no Evolution.',
            ], 502);
        }

        $qr = extract_qr_base64($res['data']);
        if (!$qr) {
            respond_json([
                'ok' => 0,
                'message' => 'O Evolution respondeu, mas não encontramos o QR Code no retorno.',
                'endpoint' => $res['endpoint'],
            ], 502);
        }

        respond_json([
            'ok' => 1,
            'instance' => $instance,
            'qr' => $qr,
            'endpoint' => $res['endpoint'],
        ]);
    }

    if ($action === 'logout') {
        $res = try_requests($serverUrl, $apiKey, [
            ['method' => 'POST', 'path' => '/instance/logout/' . $instance, 'json' => []],
            ['method' => 'GET',  'path' => '/instance/logout/' . $instance],
            ['method' => 'POST', 'path' => '/instance/disconnect/' . $instance, 'json' => []],
            ['method' => 'GET',  'path' => '/instance/disconnect/' . $instance],
        ]);

        if (!$res['ok']) {
            respond_json([
                'ok' => 0,
                'message' => 'Não foi possível desconectar a instância no Evolution.',
            ], 502);
        }

        respond_json([
            'ok' => 1,
            'message' => 'Instância desconectada com sucesso.',
            'endpoint' => $res['endpoint'],
        ]);
    }

    respond_json([
        'ok' => 0,
        'message' => 'Ação inválida.',
    ], 400);
}

// ---------------------------------------------------------------------
// PAGE MODE (HTML)
// ---------------------------------------------------------------------
$instance = sanitize_instance((string)($_GET['instance'] ?? ''));

// Caminhos dos assets (ajuste se mudar a estrutura)
$logoPath = 'assets/logo-resolutiva-branca.png';
$rvPath   = 'assets/rv.png';

?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Resolutiva • Evolution WhatsApp</title>
  <style>
    :root{
      --blue:#2B43D9;
      --green:#3BDF4D;
      --black:#000000;
      --white:#ffffff;

      --bg0:#070A12;
      --bg1:#0B1022;
      --card:#0f1730;
      --card2:#0b132a;
      --text:#e9ecf7;
      --muted:#b9c0d9;
      --line:rgba(255,255,255,.10);
      --shadow: 0 20px 60px rgba(0,0,0,.45);
      --radius:18px;
    }

    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
      color:var(--text);
      background:
        radial-gradient(1100px 500px at 15% 10%, rgba(43,67,217,.35), transparent 60%),
        radial-gradient(900px 450px at 85% 15%, rgba(59,223,77,.22), transparent 60%),
        linear-gradient(180deg, var(--bg0), var(--bg1));
    }

    a{color:inherit}
    .wrap{max-width:1100px;margin:0 auto;padding:28px 18px 60px}
    .top{
      display:flex;align-items:center;justify-content:space-between;gap:14px;
      padding:14px 16px;border:1px solid var(--line);border-radius:22px;
      background:rgba(255,255,255,.03);
      backdrop-filter: blur(10px);
    }
    .brand{display:flex;align-items:center;gap:14px;min-width:240px}
    .brand img.logo{height:42px;width:auto;display:block}
    .brand .meta{display:flex;flex-direction:column;line-height:1.15}
    .brand .meta strong{font-size:14px;letter-spacing:.2px}
    .brand .meta span{font-size:12px;color:var(--muted)}
    .chip{
      display:inline-flex;align-items:center;gap:8px;
      padding:8px 12px;border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.04);
      font-size:12px;color:var(--muted);
    }
    .dot{width:9px;height:9px;border-radius:50%}
    .dot.ok{background:var(--green); box-shadow: 0 0 0 3px rgba(59,223,77,.20)}
    .dot.bad{background:#ff4d4d; box-shadow: 0 0 0 3px rgba(255,77,77,.18)}
    .dot.idle{background:#ffd166; box-shadow: 0 0 0 3px rgba(255,209,102,.18)}

    .grid{
      margin-top:18px;
      display:grid;grid-template-columns: 1.05fr .95fr;
      gap:16px;
    }

    .card{
      border:1px solid var(--line);
      border-radius: var(--radius);
      background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
      box-shadow: var(--shadow);
      overflow:hidden;
    }

    .card header{
      padding:18px 18px 14px;
      border-bottom:1px solid var(--line);
      display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
    }
    .card header h2{
      margin:0;
      font-size:16px;
      letter-spacing:.2px;
    }
    .card header p{
      margin:6px 0 0;
      color:var(--muted);
      font-size:12px;
      line-height:1.35;
    }

    .content{padding:18px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .field{
      flex:1;
      min-width: 220px;
    }
    label{display:block;font-size:12px;color:var(--muted);margin:0 0 8px}
    input[type="text"]{
      width:100%;
      padding:12px 12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.14);
      background:rgba(0,0,0,.20);
      color:var(--text);
      outline:none;
    }
    input[type="text"]:focus{
      border-color: rgba(59,223,77,.55);
      box-shadow: 0 0 0 3px rgba(59,223,77,.15);
    }

    .btns{display:flex;gap:10px;flex-wrap:wrap}
    button{
      border:0;
      cursor:pointer;
      border-radius:14px;
      padding:12px 14px;
      font-weight:650;
      font-size:13px;
      color:var(--white);
      display:inline-flex;align-items:center;justify-content:center;gap:10px;
      transition: transform .08s ease, opacity .2s ease, filter .2s ease;
      user-select:none;
    }
    button:active{transform:translateY(1px)}
    button[disabled]{opacity:.55;cursor:not-allowed}
    .btn-primary{background: linear-gradient(135deg, var(--blue), #1f31a8)}
    .btn-secondary{background: rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.14)}
    .btn-danger{background: linear-gradient(135deg, #ff4d4d, #c92b2b)}
    .btn-ghost{background: transparent; border:1px dashed rgba(255,255,255,.20); color:var(--muted); font-weight:600}

    .statusBox{
      margin-top:14px;
      border:1px solid rgba(255,255,255,.10);
      border-radius:16px;
      padding:12px 12px;
      background:rgba(0,0,0,.18);
      display:flex;align-items:center;justify-content:space-between;gap:12px;
    }
    .statusLeft{display:flex;align-items:center;gap:12px}
    .statusTitle{font-size:12px;color:var(--muted);margin:0}
    .statusValue{font-size:14px;margin:2px 0 0;font-weight:750}
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
    .small{font-size:12px;color:var(--muted)}
    .hint{margin-top:12px;color:var(--muted);font-size:12px;line-height:1.45}
    .warn{
      padding:12px;border-radius:14px;border:1px solid rgba(255,209,102,.35);
      background:rgba(255,209,102,.08); color:#ffe8a3;
      font-size:12px; line-height:1.4;
    }

    .qrWrap{
      display:flex;flex-direction:column;gap:12px;
    }
    .qrCard{
      border:1px solid rgba(255,255,255,.10);
      border-radius:16px;
      background:rgba(0,0,0,.18);
      padding:16px;
      display:flex;align-items:center;justify-content:center;
      min-height: 330px;
      position:relative;
    }
    .qrCard img{
      max-width: 240px;
      width: 75%;
      height: auto;
      border-radius:14px;
      background: var(--white);
      padding:10px;
    }
    .qrEmpty{
      text-align:center;
      color:var(--muted);
      font-size:13px;
      line-height:1.45;
      padding:12px;
    }

    .footer{
      margin-top:18px;
      display:flex;align-items:center;justify-content:space-between;gap:14px;
      color:var(--muted);
      font-size:12px;
      opacity:.95;
    }
    .badge{
      display:inline-flex;align-items:center;gap:8px;
      border-radius:999px;
      padding:8px 12px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(0,0,0,.16);
    }
    .rvIcon{
      width:32px;height:32px;border-radius:10px;
      border:1px solid rgba(255,255,255,.12);
      background:rgba(255,255,255,.06);
      display:flex;align-items:center;justify-content:center;
      overflow:hidden;
    }
    .rvIcon img{width:100%;height:100%;object-fit:cover}

    /* Toast */
    .toast{
      position:fixed;
      right:16px;
      bottom:16px;
      background:rgba(0,0,0,.78);
      border:1px solid rgba(255,255,255,.14);
      color:var(--text);
      padding:12px 12px;
      border-radius:16px;
      min-width: 260px;
      max-width: 360px;
      box-shadow: var(--shadow);
      display:none;
    }
    .toast.show{display:block;animation: pop .18s ease-out}
    @keyframes pop{from{transform:translateY(6px);opacity:.6}to{transform:translateY(0);opacity:1}}
    .toast .tTitle{font-weight:800;font-size:13px;margin:0 0 4px}
    .toast .tBody{margin:0;font-size:12px;color:var(--muted);line-height:1.35}

    /* Mobile */
    @media (max-width: 900px){
      .grid{grid-template-columns: 1fr}
      .brand{min-width: unset}
      .top{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>

<body>
  <div class="wrap">
    <div class="top">
      <div class="brand">
        <img class="logo" src="<?= htmlspecialchars($logoPath) ?>" alt="Resolutiva" />
        <div class="meta">
          <strong>Resolutiva</strong>
          <span>Gerenciador de instância Evolution</span>
        </div>
      </div>

      <div class="row">
        <div class="chip" id="chipStatus">
          <span class="dot idle" id="statusDot"></span>
          <span id="statusText">Aguardando…</span>
        </div>
        <div class="chip mono" title="Instância selecionada">
          <span>instance:</span>
          <span id="chipInstance"><?= $instance ? htmlspecialchars($instance) : '—' ?></span>
        </div>
      </div>
    </div>

    <div class="grid">
      <!-- LEFT -->
      <section class="card">
        <header>
          <div>
            <h2>Controle da instância</h2>
            <p>Confira o status, gere um QR Code para conectar ou desconecte a sessão.</p>
          </div>
          <div class="rvIcon" title="Resolutiva">
            <img src="<?= htmlspecialchars($rvPath) ?>" alt="RV" />
          </div>
        </header>

        <div class="content">
          <?php if (!$configOk): ?>
            <div class="warn">
              <strong>Configuração necessária:</strong><br/>
              Defina <span class="mono">EVOLUTION_SERVER_URL</span> e <span class="mono">EVOLUTION_API_KEY</span> nas variáveis de ambiente do servidor.
              <br/>Depois recarregue esta página.
            </div>
            <div style="height:12px"></div>
          <?php endif; ?>

          <div class="row">
            <div class="field">
              <label for="instanceInput">Nome da instância (Evolution)</label>
              <input id="instanceInput" type="text" placeholder="ex: resolutiva_cliente01" value="<?= htmlspecialchars($instance) ?>" />
              <div class="hint">Dica: você pode abrir direto com <span class="mono">?instance=nome</span>.</div>
            </div>
          </div>

          <div class="row" style="margin-top:12px">
            <div class="btns">
              <button class="btn-primary" id="btnStatus">
                <span>Conferir status</span>
              </button>
              <button class="btn-secondary" id="btnQr">
                <span>Gerar QR Code</span>
              </button>
              <button class="btn-danger" id="btnLogout">
                <span>Desconectar</span>
              </button>
              <button class="btn-ghost" id="btnShare" title="Copiar link com a instância">
                <span>Copiar link</span>
              </button>
            </div>
          </div>

          <div class="statusBox" style="margin-top:16px">
            <div class="statusLeft">
              <span class="dot idle" id="stateDot"></span>
              <div>
                <p class="statusTitle">Estado</p>
                <p class="statusValue" id="stateValue">—</p>
              </div>
            </div>
            <div style="text-align:right">
              <div class="small">Endpoint usado</div>
              <div class="mono small" id="endpointValue">—</div>
            </div>
          </div>

          <div class="footer">
            <div class="badge">
              <span class="dot idle" id="autoDot"></span>
              <span id="autoText">Auto-status: pausado</span>
            </div>
            <div class="small">
              <span class="mono">Resolutiva</span> • UI clean • <?= date('Y') ?>
            </div>
          </div>
        </div>
      </section>

      <!-- RIGHT -->
      <section class="card">
        <header>
          <div>
            <h2>QR Code</h2>
            <p>Ao gerar, aponte a câmera do WhatsApp para conectar a instância.</p>
          </div>
          <div class="chip">
            <span class="dot idle" id="qrDot"></span>
            <span id="qrText">Nenhum QR gerado</span>
          </div>
        </header>

        <div class="content">
          <div class="qrWrap">
            <div class="qrCard" id="qrCard">
              <div class="qrEmpty" id="qrEmpty">
                Clique em <strong>Gerar QR Code</strong> para solicitar ao Evolution.
                <br/><span class="small">Quando conectar, o status deve mudar para <span style="color:var(--green);font-weight:800">Conectado</span>.</span>
              </div>
              <img id="qrImg" alt="QR Code" style="display:none" />
            </div>

            <div class="hint">
              Se o QR expirar, gere novamente. Se estiver <strong>Conectado</strong> e ainda assim precisar reconectar,
              primeiro clique em <strong>Desconectar</strong>.
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>

  <div class="toast" id="toast">
    <p class="tTitle" id="toastTitle">—</p>
    <p class="tBody" id="toastBody">—</p>
  </div>

<script>
  const cfg = {
    apiBase: (() => {
      // Mantém a rota no mesmo arquivo: evolution_instance.php?api=1&action=...
      const url = new URL(window.location.href);
      url.searchParams.set('api', '1');
      // action e instance serão setados depois
      return url.toString();
    })()
  };

  const els = {
    instanceInput: document.getElementById('instanceInput'),
    chipInstance: document.getElementById('chipInstance'),

    btnStatus: document.getElementById('btnStatus'),
    btnQr: document.getElementById('btnQr'),
    btnLogout: document.getElementById('btnLogout'),
    btnShare: document.getElementById('btnShare'),

    statusDot: document.getElementById('statusDot'),
    statusText: document.getElementById('statusText'),
    chipStatus: document.getElementById('chipStatus'),

    stateDot: document.getElementById('stateDot'),
    stateValue: document.getElementById('stateValue'),
    endpointValue: document.getElementById('endpointValue'),

    qrDot: document.getElementById('qrDot'),
    qrText: document.getElementById('qrText'),
    qrImg: document.getElementById('qrImg'),
    qrEmpty: document.getElementById('qrEmpty'),

    autoDot: document.getElementById('autoDot'),
    autoText: document.getElementById('autoText'),

    toast: document.getElementById('toast'),
    toastTitle: document.getElementById('toastTitle'),
    toastBody: document.getElementById('toastBody'),
  };

  function setDot(el, mode){
    el.classList.remove('ok','bad','idle');
    el.classList.add(mode);
  }

  function toast(title, body){
    els.toastTitle.textContent = title;
    els.toastBody.textContent = body;
    els.toast.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => els.toast.classList.remove('show'), 3200);
  }

  function getInstance(){
    const v = (els.instanceInput.value || '').trim();
    return v;
  }

  function lockButtons(lock){
    [els.btnStatus, els.btnQr, els.btnLogout, els.btnShare].forEach(b => b.disabled = !!lock);
  }

  async function apiCall(action, instance){
    const u = new URL(cfg.apiBase);
    u.searchParams.set('action', action);
    u.searchParams.set('instance', instance);

    const res = await fetch(u.toString(), {
      method: (action === 'status') ? 'GET' : 'POST',
      headers: { 'Accept': 'application/json' }
    });

    const data = await res.json().catch(() => null);
    if (!data) throw new Error('Resposta inválida do servidor.');
    if (!data.ok) throw new Error(data.message || 'Falha ao executar ação.');
    return data;
  }

  function setUrlInstance(instance){
    const u = new URL(window.location.href);
    u.searchParams.set('instance', instance);
    history.replaceState({}, '', u.toString());
  }

  function setConnectedUI(connected){
    if (connected){
      setDot(els.statusDot, 'ok');
      els.statusText.textContent = 'Conectado';
      setDot(els.stateDot, 'ok');
    }else{
      setDot(els.statusDot, 'bad');
      els.statusText.textContent = 'Desconectado';
      setDot(els.stateDot, 'bad');
    }
  }

  async function checkStatus({silent=false} = {}){
    const instance = getInstance();
    if (!instance){
      if (!silent) toast('Instância', 'Informe o nome da instância.');
      return;
    }

    lockButtons(true);
    if (!silent){
      setDot(els.statusDot, 'idle');
      els.statusText.textContent = 'Verificando…';
      setDot(els.stateDot, 'idle');
      els.stateValue.textContent = '—';
      els.endpointValue.textContent = '—';
    }

    try{
      const data = await apiCall('status', instance);
      els.chipInstance.textContent = instance;
      setUrlInstance(instance);

      const state = data.state || (data.connected ? 'open' : 'close');
      els.stateValue.textContent = state;
      els.endpointValue.textContent = data.endpoint || '—';

      setConnectedUI(!!data.connected);

      if (!silent) toast('Status atualizado', data.connected ? 'Instância conectada.' : 'Instância desconectada.');
    }catch(err){
      setDot(els.statusDot, 'bad');
      els.statusText.textContent = 'Erro';
      setDot(els.stateDot, 'bad');
      els.stateValue.textContent = '—';
      if (!silent) toast('Falha', err.message || String(err));
    }finally{
      lockButtons(false);
    }
  }

  async function generateQr(){
    const instance = getInstance();
    if (!instance){
      toast('Instância', 'Informe o nome da instância.');
      return;
    }

    lockButtons(true);
    setDot(els.qrDot, 'idle');
    els.qrText.textContent = 'Gerando…';
    els.qrEmpty.style.display = 'block';
    els.qrImg.style.display = 'none';

    try{
      const data = await apiCall('qrcode', instance);

      els.chipInstance.textContent = instance;
      setUrlInstance(instance);

      els.qrImg.src = data.qr;
      els.qrImg.style.display = 'block';
      els.qrEmpty.style.display = 'none';

      setDot(els.qrDot, 'ok');
      els.qrText.textContent = 'QR gerado';

      toast('QR Code', 'Pronto! Abra o WhatsApp e conecte.');
      // depois de gerar, vale checar status
      setTimeout(() => checkStatus({silent:true}), 1200);
    }catch(err){
      setDot(els.qrDot, 'bad');
      els.qrText.textContent = 'Erro';
      els.qrEmpty.style.display = 'block';
      els.qrImg.style.display = 'none';
      toast('Falha no QR', err.message || String(err));
    }finally{
      lockButtons(false);
    }
  }

  async function logout(){
    const instance = getInstance();
    if (!instance){
      toast('Instância', 'Informe o nome da instância.');
      return;
    }

    const ok = confirm(`Desconectar a instância "${instance}"?\n\nIsso fará logout/disconnect no Evolution.`);
    if (!ok) return;

    lockButtons(true);

    try{
      await apiCall('logout', instance);
      toast('Desconectado', 'Instância desconectada com sucesso.');
      // limpar QR
      els.qrImg.src = '';
      els.qrImg.style.display = 'none';
      els.qrEmpty.style.display = 'block';
      setDot(els.qrDot, 'idle');
      els.qrText.textContent = 'Nenhum QR gerado';

      await checkStatus({silent:true});
    }catch(err){
      toast('Falha ao desconectar', err.message || String(err));
    }finally{
      lockButtons(false);
    }
  }

  async function copyLink(){
    const instance = getInstance();
    if (!instance){
      toast('Instância', 'Informe o nome da instância.');
      return;
    }
    const u = new URL(window.location.href);
    u.searchParams.set('instance', instance);

    try{
      await navigator.clipboard.writeText(u.toString());
      toast('Link copiado', 'Você pode enviar para alguém abrir direto na instância.');
    }catch(e){
      toast('Não deu para copiar', 'Seu navegador bloqueou a área de transferência. Copie manualmente pela barra de endereços.');
    }
  }

  // Auto refresh
  let autoTimer = null;
  function startAuto(){
    if (autoTimer) return;
    setDot(els.autoDot, 'ok');
    els.autoText.textContent = 'Auto-status: a cada 30s';
    autoTimer = setInterval(() => checkStatus({silent:true}), 30000);
  }

  function stopAuto(){
    if (!autoTimer) return;
    clearInterval(autoTimer);
    autoTimer = null;
    setDot(els.autoDot, 'idle');
    els.autoText.textContent = 'Auto-status: pausado';
  }

  // Events
  els.btnStatus.addEventListener('click', () => checkStatus());
  els.btnQr.addEventListener('click', () => generateQr());
  els.btnLogout.addEventListener('click', () => logout());
  els.btnShare.addEventListener('click', () => copyLink());

  els.instanceInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') checkStatus();
  });

  // Init
  (function init(){
    const instance = getInstance();
    if (instance){
      els.chipInstance.textContent = instance;
      checkStatus({silent:true});
      startAuto();
    } else {
      setDot(els.statusDot, 'idle');
      els.statusText.textContent = 'Informe a instância';
      stopAuto();
    }
  })();
</script>
</body>
</html>
