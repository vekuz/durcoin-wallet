<?php
session_start();
$FORCE_TITLE   = '';
$BRAND_SUFFIX  = 'powered by Durcoin';
$WAVES_NODE    = 'https://nodes.wavesnodes.com';
$CHAIN_ID      = 'W';

// ⚡ CSP: всё через свой сервер — connect-src 'self' (нода браузеру не нужна)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; media-src 'self' blob:; worker-src 'self'");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");

function httpGet($url, $timeout = 12) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_FOLLOWLOCATION=>true]);
        $resp = curl_exec($ch); curl_close($ch);
        return $resp === false ? null : $resp;
    }
    $ctx = stream_context_create(['http'=>['timeout'=>$timeout,'ignore_errors'=>true]]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp === false ? null : $resp;
}
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $node = $WAVES_NODE;
    $addr = trim($_GET['address'] ?? '');
    $validAddr = preg_match('/^3[A-Za-z0-9]{34}$/', $addr);
    if ($_GET['action'] === 'balance') {
        if (!$validAddr) { echo json_encode(['ok'=>false]); exit; }
        $waves  = httpGet($node . '/addresses/balance/' . urlencode($addr));
        $assets = httpGet($node . '/assets/balance/' . urlencode($addr));
        echo json_encode(['ok'=>true,'waves'=>json_decode($waves,true),'assets'=>json_decode($assets,true)]); exit;
    }
    if ($_GET['action'] === 'history') {
        if (!$validAddr) { echo json_encode(['ok'=>false]); exit; }
        $tx = httpGet($node . '/transactions/address/' . urlencode($addr) . '/limit/15');
        echo json_encode(['ok'=>true,'tx'=>json_decode($tx,true)]); exit;
    }
    if ($_GET['action'] === 'assetinfo') {
        $aid = trim($_GET['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{32,44}$/', $aid)) { echo json_encode(['ok'=>false]); exit; }
        $info = httpGet($node . '/assets/details/' . urlencode($aid));
        echo json_encode(['ok'=>true,'info'=>json_decode($info,true)]); exit;
    }
    if ($_GET['action'] === 'aliases') {
        if (!$validAddr) { echo json_encode(['ok'=>false]); exit; }
        $al = httpGet($node . '/alias/by-address/' . urlencode($addr));
        echo json_encode(['ok'=>true,'aliases'=>json_decode($al,true)]); exit;
    }
    if ($_GET['action'] === 'sponsored') {
        if (!$validAddr) { echo json_encode(['ok'=>false]); exit; }
        $assets = httpGet($node . '/assets/balance/' . urlencode($addr));
        $data = json_decode($assets, true);
        $out = [];
        if (isset($data['balances']) && is_array($data['balances'])) {
            foreach ($data['balances'] as $b) {
                $minFee = $b['minSponsoredAssetFee'] ?? null;
                if ($minFee !== null && $minFee > 0 && ($b['sponsorBalance'] ?? 0) > 0) {
                    $name = isset($b['issueTransaction']['name']) ? $b['issueTransaction']['name'] : substr($b['assetId'],0,8);
                    $dec  = isset($b['issueTransaction']['decimals']) ? $b['issueTransaction']['decimals'] : 0;
                    $out[] = ['assetId'=>$b['assetId'],'minSponsoredAssetFee'=>$minFee,'balance'=>$b['balance'],'name'=>$name,'decimals'=>$dec];
                }
            }
        }
        echo json_encode(['ok'=>true,'sponsored'=>$out]); exit;
    }
    // 🆕 BROADCAST через свой сервер (обход блокировки ноды у пользователя)
    if ($_GET['action'] === 'broadcast' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $tx  = json_decode($raw, true);
        if (!is_array($tx) || !isset($tx['type'])) { http_response_code(400); echo json_encode(['error'=>'bad tx']); exit; }
        if (function_exists('curl_init')) {
            $ch = curl_init($node . '/transactions/broadcast');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$raw,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                CURLOPT_TIMEOUT=>15,
                CURLOPT_CONNECTTIMEOUT=>8,
                CURLOPT_SSL_VERIFYPEER=>true
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http'=>[
                'method'=>'POST',
                'header'=>"Content-Type: application/json\r\n",
                'content'=>$raw,
                'timeout'=>15,
                'ignore_errors'=>true
            ]]);
            $resp = @file_get_contents($node . '/transactions/broadcast', false, $ctx);
            $code = 200;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) $code = (int)$mm[1];
        }
        http_response_code($code ?: 502);
        echo ($resp !== false && $resp !== null && $resp !== '') ? $resp : json_encode(['error'=>'node unreachable']);
        exit;
    }
    // 🆕 проверка статуса транзакции по id (для waitForTx)
    if ($_GET['action'] === 'txinfo') {
        $id = trim($_GET['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{32,44}$/', $id)) { http_response_code(400); echo json_encode(['error'=>'bad id']); exit; }
        $info = httpGet($node . '/transactions/info/' . urlencode($id));
        if ($info === null || $info === '') { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
        echo $info; exit;
    }
}
$host = $_SERVER['HTTP_HOST'] ?? 'wallet';
$host = preg_replace('/^www\./', '', $host);
$host = preg_replace('/:\d+$/', '', $host);
$autoTitle = strtoupper($host);
$WALLET_TITLE = $FORCE_TITLE !== '' ? $FORCE_TITLE : $autoTitle;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><title><?= htmlspecialchars($WALLET_TITLE) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><link rel="icon" type="image/png" href="icon.php?s=64">
<link rel="apple-touch-icon" href="icon.php?s=192">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0b0e14">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Wallet">
<style>
:root{--bg:#0b0e14;--surface:#161a23;--surface2:#1d2230;--border:#262a33;--text:#e6e6e6;--muted:#8a93a3;--accent:#3b82f6;--green:#10b981;--green2:#059669;--purple:#8b5cf6;--bad:#ef4444}
html[data-theme="light"]{--bg:#f4f6fb;--surface:#ffffff;--surface2:#eef1f7;--border:#dde2ec;--text:#1a1f2b;--muted:#6b7280;--accent:#2563eb;--green:#059669;--green2:#047857;--purple:#7c3aed;--bad:#dc2626}
*{box-sizing:border-box}body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:24px 16px;display:flex;justify-content:center;transition:background .3s,color .3s}
.wrap{width:100%;max-width:560px}
.topbar{display:flex;justify-content:flex-end;gap:8px;margin-bottom:8px}
.topbtn{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:8px 12px;font-size:14px;cursor:pointer;min-width:auto;flex:0 0 auto}
.logo{text-align:center;margin:20px 0 24px}.logo .emoji{font-size:60px}
.logo h1{background:linear-gradient(135deg,#60a5fa,#a78bfa);-webkit-background-clip:text;background-clip:text;color:transparent;font-size:40px;font-weight:700;margin:8px 0;word-break:break-word}
.logo p{color:var(--muted);font-size:15px;margin:0;line-height:1.4}.brand{color:var(--purple);font-size:12px;letter-spacing:.5px;margin-top:6px;opacity:.8}
.card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:16px;animation:fadeUp .4s ease both}.card h2{margin:0 0 16px;font-size:20px}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.card:nth-child(2){animation-delay:.05s}.card:nth-child(3){animation-delay:.1s}.card:nth-child(4){animation-delay:.15s}
label{font-size:12px;color:var(--muted);font-weight:600;letter-spacing:.5px;text-transform:uppercase;display:block;margin-bottom:8px}
textarea,input,select{width:100%;padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:12px;color:var(--text);font-size:15px;outline:none;font-family:'SF Mono',Menlo,monospace;resize:vertical}
select{font-family:inherit}textarea{min-height:110px;line-height:1.5}textarea:focus,input:focus,select:focus{border-color:var(--accent)}textarea::placeholder,input::placeholder{color:var(--muted)}
.btns{display:flex;gap:12px;margin-top:18px;flex-wrap:wrap}button{flex:1;min-width:140px;border:none;padding:16px;border-radius:14px;font-size:16px;font-weight:700;cursor:pointer;transition:filter .15s,transform .1s}
button:active{transform:scale(.97)}button:disabled{opacity:.5;cursor:not-allowed}.btn-login{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff}
.btn-create{background:var(--surface2);border:1px solid var(--purple);color:var(--purple)}button:hover:not(:disabled){filter:brightness(1.08)}.hidden{display:none}
.addr-box{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:14px;font-family:monospace;font-size:13px;word-break:break-all;margin:8px 0}
.bal{font-size:34px;font-weight:700;margin:10px 0}.bal small{font-size:16px;color:var(--muted);font-weight:400}.muted{color:var(--muted);font-size:13px;line-height:1.5}
.warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);border-radius:12px;padding:14px;color:#b45309;font-size:13px;margin:12px 0;line-height:1.5}
html[data-theme="dark"] .warn,:root:not([data-theme="light"]) .warn{color:#fcd34d}
.seed-reveal{background:var(--bg);border:2px dashed var(--purple);border-radius:12px;padding:16px;font-family:monospace;font-size:15px;line-height:1.6;word-break:break-word;margin:10px 0}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}.row button{flex:1}.sec{background:var(--surface2);border:1px solid var(--border);color:var(--text);font-weight:500;font-size:14px;padding:12px}
.msg{font-size:13px;padding:12px;border-radius:10px;margin-top:12px;line-height:1.5;word-break:break-word}.msg.ok{background:rgba(16,185,129,.15);color:var(--green)}.msg.bad{background:rgba(239,68,68,.15);color:var(--bad)}
.asset{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);font-size:14px}.asset:last-child{border:none}
.field{margin-top:14px}.field-row{display:flex;gap:8px;align-items:flex-end}.field-row input{flex:1}.field-row button{flex:0 0 auto;min-width:auto;padding:14px 18px;font-size:14px}
.checkbox{display:flex;align-items:flex-start;gap:8px;margin:14px 0;font-size:13px;color:var(--muted)}.checkbox input{width:auto;flex:0 0 auto;margin-top:2px}.loading{text-align:center;color:var(--muted);padding:20px}
.tx{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px}.tx:last-child{border:none}
.tx-in{color:var(--green)}.tx-out{color:var(--bad)}.tx-meta{color:var(--muted);font-size:11px;margin-top:2px}.tx-amt{font-weight:700;white-space:nowrap;text-align:right}.tx-token{color:var(--muted);font-size:11px;font-weight:500;text-align:right}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;padding:20px;z-index:100;animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:var(--surface);border:1px solid var(--purple);border-radius:18px;padding:24px;max-width:420px;width:100%;animation:fadeUp .25s ease both}
.modal-box h3{margin:0 0 14px;font-size:18px}.modal-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px}.modal-row span:last-child{font-weight:700;text-align:right;word-break:break-all;max-width:60%}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--green2);color:#fff;padding:12px 22px;border-radius:12px;font-size:14px;font-weight:600;z-index:200;opacity:0;transition:opacity .3s}.toast.show{opacity:1}
#qrCanvas{display:block;margin:0 auto;background:#fff;padding:12px;border-radius:12px;image-rendering:pixelated}
.contact{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:14px}.contact:last-child{border:none}
.contact .ci{flex:1;min-width:0;cursor:pointer}.contact .cn{font-weight:600}.contact .ca{color:var(--muted);font-size:11px;font-family:monospace;word-break:break-all}
.contact .cd{flex:0 0 auto;background:none;border:none;color:var(--bad);cursor:pointer;font-size:18px;padding:4px 8px;min-width:auto}
.pin-dots{display:flex;gap:12px;justify-content:center;margin:18px 0}.pin-dot{width:14px;height:14px;border-radius:50%;border:2px solid var(--muted)}.pin-dot.on{background:var(--accent);border-color:var(--accent)}
.pin-pad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}.pin-key{background:var(--surface2);border:1px solid var(--border);color:var(--text);font-size:22px;padding:18px;border-radius:14px;min-width:auto;flex:none}
.scan-wrap{position:relative;border-radius:14px;overflow:hidden;background:#000;aspect-ratio:1/1;margin:8px 0}
.scan-wrap video{width:100%;height:100%;object-fit:cover;display:block}
.scan-frame{position:absolute;inset:18%;border:3px solid var(--accent);border-radius:14px;box-shadow:0 0 0 9999px rgba(0,0,0,.35);pointer-events:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <button class="topbtn" id="langBtn">🇷🇺</button>
    <button class="topbtn" id="themeBtn">🌙</button>
    <button class="topbtn hidden" id="installBtn">⬇️ <span data-t="install"></span></button>
    <button class="topbtn hidden" id="resetBtn">🧹</button>
  </div>
  <div class="logo"><div class="emoji">💎</div><h1 id="brandTitle"><?= htmlspecialchars($WALLET_TITLE) ?></h1><p data-t="subtitle"></p><div class="brand"><?= htmlspecialchars($BRAND_SUFFIX) ?></div></div>
  <div class="card" id="loadingCard"><div class="loading" data-t="initCrypto"></div></div>

  <div class="card hidden" id="pinUnlockCard">
    <h2>🔓 <span data-t="unlock"></span></h2>
    <div class="pin-dots" id="unlockDots"></div>
    <div class="pin-pad" id="unlockPad"></div>
    <div class="row"><button class="sec" id="pinUseSeedBtn" data-t="useSeed"></button></div>
    <div class="row"><button class="sec" id="pinForgetBtn" data-t="forgetWallet"></button></div>
    <div id="unlockMsg"></div>
  </div>

  <div class="card hidden" id="authCard">
    <h2>🔐 <span data-t="login"></span></h2><label data-t="seedLabel"></label>
    <textarea id="seedInput" autocomplete="off" spellcheck="false"></textarea>
    <div class="btns"><button class="btn-login" id="loginBtn" data-t="login"></button><button class="btn-create" id="createBtn" data-t="create"></button></div>
    <div id="authMsg"></div>
  </div>

  <div class="card hidden" id="newSeedCard">
    <h2>🆕 <span data-t="newWallet"></span></h2><div class="warn">⚠️ <b data-t="writeDown"></b><br><span data-t="onlyKey"></span></div>
    <label data-t="yourSeed"></label><div class="seed-reveal" id="newSeedText"></div><div class="addr-box" id="newSeedAddr"></div>
    <div class="row"><button class="sec" id="copySeedBtn">📋 <span data-t="copy"></span></button></div>
    <label class="checkbox"><input type="checkbox" id="savedCheck"><span data-t="savedSeed"></span></label>
    <button class="btn-login" id="enterNewBtn" disabled data-t="continue"></button>
  </div>

  <div class="card hidden" id="walletCard">
    <h2>💎 <span data-t="myWallet"></span></h2><label data-t="address"></label><div class="addr-box" id="addrView"></div><div class="bal" id="balView">—</div>
    <div class="row"><button class="sec" id="receiveBtn">📥 <span data-t="receive"></span></button><button class="sec" id="copyAddrBtn">📋 <span data-t="addr"></span></button><button class="sec" id="aliasBtn">🏷 <span data-t="alias"></span></button></div>
    <div class="row"><button class="sec" id="refreshBtn">🔄 <span data-t="refresh"></span></button><button class="sec" id="lockBtn">🔒 <span data-t="logout"></span></button></div>
  </div>

  <div class="card hidden" id="sendCard">
    <h2>📤 <span data-t="send"></span></h2>
    <label data-t="token"></label><select id="assetSelect"></select>
    <div class="field"><label data-t="recipient"></label><div class="field-row"><input id="toAddr" placeholder="3P... / alias" autocomplete="off"><button class="sec" id="scanBtn">📷</button><button class="sec" id="bookBtn">📖</button></div></div>
    <div class="field"><label data-t="amount"></label><div class="field-row"><input id="amount" placeholder="0.0" inputmode="decimal"><button class="sec" id="maxBtn">MAX</button></div></div>
    <div class="field"><label data-t="comment"></label><input id="att" autocomplete="off" maxlength="140"></div>
    <div class="field"><label data-t="feeToken"></label><select id="feeSelect"></select></div>
    <button class="btn-login" id="sendBtn" style="margin-top:16px" data-t="send"></button><div id="sendMsg"></div>
  </div>

  <div class="card hidden" id="assetsCard"><h2>🪙 <span data-t="tokens"></span></h2><div id="assetsList" class="muted">—</div></div>
  <div class="card hidden" id="historyCard"><h2>📜 <span data-t="history"></span></h2><div id="historyList" class="muted">—</div></div>
</div>
<div id="toast" class="toast"></div>

<script src="sha3.min.js"></script>
<script src="axlsign.min.js"></script>
<script src="jsQR.min.js"></script>
<script src="lang.js"></script>
<script type="module">
import { blake2b } from './blakejs.min.js';

const CFG = { node: <?= json_encode($WAVES_NODE) ?>, chainId: <?= json_encode($CHAIN_ID) ?> };
const $ = id => document.getElementById(id);
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(text){ const t=$('toast'); t.textContent=text; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),1800); }

let LANG = localStorage.getItem('lang') || ((navigator.language||'en').startsWith('ru')?'ru':'en');
function t(k){ const L=window.I18N; return (L[LANG]&&L[LANG][k]) || (L.en&&L.en[k]) || k; }
function applyLang(){
  document.querySelectorAll('[data-t]').forEach(el=>{ el.textContent = t(el.getAttribute('data-t')); });
  $('seedInput').placeholder = t('seedPlaceholder');
  $('langBtn').textContent = LANG==='ru' ? '🇷🇺' : '🇬🇧';
  document.documentElement.lang = LANG;
}
function toggleLang(){ LANG = LANG==='ru'?'en':'ru'; localStorage.setItem('lang',LANG); applyLang(); if(ADDRESS){ loadBalance().then(()=>{loadHistory();loadSponsored();}); } }
function msg(el, key, ok, raw){ el.innerHTML = '<div class="msg '+(ok?'ok':'bad')+'">'+(raw?key:escapeHtml(t(key)))+'</div>'; }

function applyTheme(){ const th=localStorage.getItem('theme')||'dark'; document.documentElement.setAttribute('data-theme',th); $('themeBtn').textContent = th==='dark'?'🌙':'☀️'; }
function toggleTheme(){ const th=(localStorage.getItem('theme')||'dark')==='dark'?'light':'dark'; localStorage.setItem('theme',th); applyTheme(); }

let SEED=null, ADDRESS=null, PUBKEY=null, pendingSeed=null, ASSETS=[], SPONSORED=[], refreshTimer=null;

const _K=new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
function sha256sync(msg){
  let h0=0x6a09e667,h1=0xbb67ae85,h2=0x3c6ef372,h3=0xa54ff53a,h4=0x510e527f,h5=0x9b05688c,h6=0x1f83d9ab,h7=0x5be0cd19;
  const l=msg.length, bitLen=l*8;
  const padLen=(((l+1+8)+63)&~63);
  const m=new Uint8Array(padLen); m.set(msg); m[l]=0x80;
  const hi=Math.floor(bitLen/0x100000000)>>>0, lo=bitLen>>>0;
  m[padLen-8]=(hi>>>24)&255; m[padLen-7]=(hi>>>16)&255; m[padLen-6]=(hi>>>8)&255; m[padLen-5]=hi&255;
  m[padLen-4]=(lo>>>24)&255; m[padLen-3]=(lo>>>16)&255; m[padLen-2]=(lo>>>8)&255; m[padLen-1]=lo&255;
  const w=new Uint32Array(64); const rotr=(x,n)=>(x>>>n)|(x<<(32-n));
  for(let i=0;i<padLen;i+=64){
    for(let t2=0;t2<16;t2++) w[t2]=((m[i+t2*4]<<24)|(m[i+t2*4+1]<<16)|(m[i+t2*4+2]<<8)|(m[i+t2*4+3]))>>>0;
    for(let t2=16;t2<64;t2++){ const x15=w[t2-15], x2=w[t2-2];
      const s0=(rotr(x15,7)^rotr(x15,18)^(x15>>>3))>>>0; const s1=(rotr(x2,17)^rotr(x2,19)^(x2>>>10))>>>0;
      w[t2]=(w[t2-16]+s0+w[t2-7]+s1)>>>0; }
    let a=h0,b=h1,c=h2,d=h3,e=h4,f=h5,g=h6,hh=h7;
    for(let t2=0;t2<64;t2++){
      const S1=(rotr(e,6)^rotr(e,11)^rotr(e,25))>>>0; const ch=((e&f)^(~e&g))>>>0;
      const t1=(hh+S1+ch+_K[t2]+w[t2])>>>0; const S0=(rotr(a,2)^rotr(a,13)^rotr(a,22))>>>0;
      const maj=((a&b)^(a&c)^(b&c))>>>0; const t22=(S0+maj)>>>0;
      hh=g; g=f; f=e; e=(d+t1)>>>0; d=c; c=b; b=a; a=(t1+t22)>>>0; }
    h0=(h0+a)>>>0; h1=(h1+b)>>>0; h2=(h2+c)>>>0; h3=(h3+d)>>>0; h4=(h4+e)>>>0; h5=(h5+f)>>>0; h6=(h6+g)>>>0; h7=(h7+hh)>>>0;
  }
  const out=new Uint8Array(32); const hs=[h0,h1,h2,h3,h4,h5,h6,h7];
  for(let i=0;i<8;i++){ out[i*4]=(hs[i]>>>24)&255; out[i*4+1]=(hs[i]>>>16)&255; out[i*4+2]=(hs[i]>>>8)&255; out[i*4+3]=hs[i]&255; }
  return out;
}

function keccak(b){ if(typeof window.keccak256!=='function')throw new Error('sha3.min.js не загружен'); return new Uint8Array(window.keccak256.array(b)); }
function blake(b){ return blake2b(b, undefined, 32); }
function sha256(b){ return sha256sync(b); }
function strToBytes(s){ return new TextEncoder().encode(s); }
function concatBytes(...a){ let n=a.reduce((x,y)=>x+y.length,0); const o=new Uint8Array(n); let f=0; for(const x of a){o.set(x,f);f+=x.length;} return o; }
function getRandom(len){ const a=new Uint8Array(len); if(typeof crypto!=='undefined'&&crypto.getRandomValues){crypto.getRandomValues(a);return a;} for(let i=0;i<len;i++)a[i]=Math.floor(Math.random()*256); return a; }

const B58='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
function b58enc(bytes){ if(!bytes.length)return''; const d=[0]; for(let i=0;i<bytes.length;i++){let c=bytes[i];for(let j=0;j<d.length;j++){c+=d[j]<<8;d[j]=c%58;c=(c/58)|0;}while(c){d.push(c%58);c=(c/58)|0;}} let s=''; for(let i=0;i<bytes.length&&bytes[i]===0;i++)s+=B58[0]; for(let i=d.length-1;i>=0;i--)s+=B58[d[i]]; return s; }
function b58dec(str){ if(!str.length)return new Uint8Array(0); const b=[0]; for(let i=0;i<str.length;i++){const v=B58.indexOf(str[i]); if(v===-1)throw new Error('bad b58'); let c=v; for(let j=0;j<b.length;j++){c+=b[j]*58;b[j]=c&255;c>>=8;} while(c){b.push(c&255);c>>=8;}} for(let i=0;i<str.length&&str[i]===B58[0];i++)b.push(0); return new Uint8Array(b.reverse()); }

function buildAccountSeed(seed){ return sha256(concatBytes(new Uint8Array([0,0,0,0]), strToBytes(seed))); }
function getKeyPair(seed){ const acc=buildAccountSeed(seed); const h=sha256(acc); const kp=window.axlsign.generateKeyPair(h); return {privateKey:kp.private, publicKey:kp.public}; }
function deriveAddress(seed){ const {publicKey}=getKeyPair(seed); return addressFromPublicKey(publicKey); }
function addressFromPublicKey(publicKey){ const cb=CFG.chainId.charCodeAt(0); const h=keccak(blake(publicKey)).slice(0,20); const nc=concatBytes(new Uint8Array([1,cb]),h); const cs=keccak(blake(nc)).slice(0,4); return b58enc(concatBytes(nc,cs)); }
function isValidAddress(addr){
  if(!/^3[A-Za-z0-9]{34,36}$/.test(addr)) return false;
  try{ const bytes=b58dec(addr); if(bytes.length!==26)return false; if(bytes[0]!==1)return false; if(bytes[1]!==CFG.chainId.charCodeAt(0))return false;
    const body=bytes.slice(0,22), cs=bytes.slice(22,26); const calc=keccak(blake(body)).slice(0,4);
    for(let i=0;i<4;i++) if(cs[i]!==calc[i]) return false; return true; }catch{ return false; }
}
function isValidAliasName(name){ return /^[a-z0-9._@-]{4,30}$/.test(name); }

const WORDS="abandon ability able about above absent absorb abstract absurd abuse access accident account accuse achieve acid acoustic acquire across act action actor actress actual adapt add addict address adjust admit adult advance advice aerobic affair afford afraid again age agent agree ahead aim air airport aisle alarm album alcohol alert alien all alley allow almost alone alpha already also alter always amateur amazing among amount amused analyst anchor ancient anger angle angry animal ankle announce annual another answer antenna antique anxiety any apart apology appear apple approve april arch arctic area arena argue arm armed armor army around arrange arrest arrive arrow art artefact artist artwork ask aspect assault asset assist assume asthma athlete atom attack attend attitude attract auction audit august aunt author auto autumn average avocado avoid awake aware away awesome awful awkward axis baby bachelor bacon badge bag balance balcony ball bamboo banana banner bar barely bargain barrel base basic basket battle beach bean beauty because become beef before begin behave behind believe below belt bench benefit best betray better between beyond bicycle bid bike bind biology bird birth bitter black blade blame blanket blast bleak bless blind blood blossom blouse blue blur blush board boat body boil bomb bone bonus book boost border boring borrow boss bottom bounce box boy bracket brain brand brass brave bread breeze brick bridge brief bright bring brisk broccoli broken bronze broom brother brown brush bubble buddy budget buffalo build bulb bulk bullet bundle bunker burden burger burst bus business busy butter buyer buzz".split(' ');
function genSeed(){ const a=getRandom(60); const out=[]; for(let i=0;i<15;i++){ const idx=((a[i*4]<<24)|(a[i*4+1]<<16)|(a[i*4+2]<<8)|a[i*4+3])>>>0; out.push(WORDS[idx%WORDS.length]); } return out.join(' '); }

function deriveKeyStream(pin, salt, len){
  let out=new Uint8Array(0), counter=0;
  while(out.length<len){
    const block=sha256(concatBytes(strToBytes(pin), salt, new Uint8Array([(counter>>>24)&255,(counter>>>16)&255,(counter>>>8)&255,counter&255])));
    out=concatBytes(out, block); counter++;
  }
  return out.slice(0,len);
}
function encryptSeed(seed, pin){
  const salt=getRandom(16); const data=strToBytes(seed);
  const ks=deriveKeyStream(pin, salt, data.length);
  const enc=new Uint8Array(data.length); for(let i=0;i<data.length;i++) enc[i]=data[i]^ks[i];
  const check=sha256(concatBytes(strToBytes(pin), data)).slice(0,8);
  return { v:1, salt:b58enc(salt), enc:b58enc(enc), check:b58enc(check) };
}
function decryptSeed(obj, pin){
  const salt=b58dec(obj.salt); const enc=b58dec(obj.enc);
  const ks=deriveKeyStream(pin, salt, enc.length);
  const dec=new Uint8Array(enc.length); for(let i=0;i<enc.length;i++) dec[i]=enc[i]^ks[i];
  const check=sha256(concatBytes(strToBytes(pin), dec)).slice(0,8);
  if(b58enc(check)!==obj.check) return null;
  return new TextDecoder().decode(dec);
}
function hasSavedWallet(){ return !!localStorage.getItem('encWallet'); }
function saveEncWallet(seed, pin){ localStorage.setItem('encWallet', JSON.stringify(encryptSeed(seed,pin))); }
function loadEncWallet(){ try{ return JSON.parse(localStorage.getItem('encWallet')); }catch{ return null; } }
function forgetWallet(){ localStorage.removeItem('encWallet'); }

function getContacts(){ try{ return JSON.parse(localStorage.getItem('contacts'))||[]; }catch{ return []; } }
function saveContacts(c){ localStorage.setItem('contacts', JSON.stringify(c)); }

const ASSET_CACHE = {};
function seedAssetCacheFromBalances(){ ASSET_CACHE['WAVES']={name:'WAVES',decimals:8}; ASSETS.forEach(a=>{ if(a.id) ASSET_CACHE[a.id]={name:a.name,decimals:a.decimals}; }); }
async function getAssetInfo(assetId){
  if(!assetId) return ASSET_CACHE['WAVES']||{name:'WAVES',decimals:8};
  if(ASSET_CACHE[assetId]) return ASSET_CACHE[assetId];
  try{ const j=await (await fetch('?action=assetinfo&id='+encodeURIComponent(assetId))).json();
    if(j.ok&&j.info&&typeof j.info.decimals==='number'){ ASSET_CACHE[assetId]={name:j.info.name||assetId.slice(0,8),decimals:j.info.decimals}; return ASSET_CACHE[assetId]; }
  }catch(e){ console.warn('assetinfo fail',assetId,e); }
  ASSET_CACHE[assetId]={name:assetId.slice(0,8)+'…',decimals:0}; return ASSET_CACHE[assetId];
}

function long2bytes(n){ const b=new Uint8Array(8); let v=BigInt(Math.round(Number(n))); for(let i=7;i>=0;i--){b[i]=Number(v&255n);v>>=8n;} return b; }
function short2bytes(n){ return new Uint8Array([(n>>8)&255,n&255]); }

function recipientToBytes(recipient){
  if(recipient.startsWith('alias:')){
    const parts=recipient.split(':');
    const name=parts.slice(2).join(':');
    const nb=strToBytes(name);
    return concatBytes(new Uint8Array([2]), new Uint8Array([CFG.chainId.charCodeAt(0)]), short2bytes(nb.length), nb);
  }
  return b58dec(recipient);
}

function buildAndSignTransfer({recipient,amount,fee,attachment,assetId,feeAssetId}){
  const {privateKey,publicKey}=getKeyPair(SEED); const ts=Date.now();
  const attBytes=attachment?strToBytes(attachment):new Uint8Array(0);
  const recBytes=recipientToBytes(recipient);
  const assetField=assetId?concatBytes(new Uint8Array([1]),b58dec(assetId)):new Uint8Array([0]);
  const feeAssetField=feeAssetId?concatBytes(new Uint8Array([1]),b58dec(feeAssetId)):new Uint8Array([0]);
  const body=concatBytes(
    new Uint8Array([4]),
    new Uint8Array([2]),
    publicKey,
    assetField,
    feeAssetField,
    long2bytes(ts),
    long2bytes(amount),
    long2bytes(fee),
    recBytes,
    short2bytes(attBytes.length),
    attBytes
  );
  const signature=window.axlsign.sign(privateKey,body); const id=b58enc(blake(body));
  return {type:4,version:2,senderPublicKey:b58enc(publicKey),recipient,amount,assetId:assetId||null,feeAssetId:feeAssetId||null,fee,timestamp:ts,attachment:b58enc(attBytes),proofs:[b58enc(signature)],id};
}

function buildAndSignAlias(aliasName){
  const {privateKey,publicKey}=getKeyPair(SEED); const ts=Date.now();
  const fee=100000;
  const aliasBytes=strToBytes(aliasName);
  const aliasObj=concatBytes(new Uint8Array([2]), new Uint8Array([CFG.chainId.charCodeAt(0)]), short2bytes(aliasBytes.length), aliasBytes);
  const body=concatBytes(
    new Uint8Array([10]),
    new Uint8Array([2]),
    publicKey,
    short2bytes(aliasObj.length),
    aliasObj,
    long2bytes(fee),
    long2bytes(ts)
  );
  const signature=window.axlsign.sign(privateKey,body); const id=b58enc(blake(body));
  return {type:10,version:2,senderPublicKey:b58enc(publicKey),alias:aliasName,fee,feeAssetId:null,timestamp:ts,proofs:[b58enc(signature)],id};
}

/* ===== BROADCAST через свой сервер (?action=broadcast) — работает без ВПН ===== */
async function waitForTx(txId, tries=5){
  for(let i=0;i<tries;i++){
    await new Promise(res=>setTimeout(res, 1500));
    try{
      const r=await fetch('?action=txinfo&id='+encodeURIComponent(txId), {cache:'no-store'});
      if(r.ok){ console.log('✅ TX подтверждён:', txId); return true; }
    }catch(e){ /* ещё не в блоке */ }
  }
  return false;
}
async function broadcastTx(tx){
  console.log('📦 TX:', JSON.stringify(tx));
  let r, raw;
  try{
    r = await fetch('?action=broadcast', {            // ← через свой сервер
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(tx),
      cache:'no-store'
    });
  }catch(netErr){
    console.warn('⚠️ fetch упал, проверяю TX:', netErr);
    if(await waitForTx(tx.id)) return {id:tx.id};
    throw new Error(t('netError'));
  }
  raw = await r.text();
  console.log('📥 NODE:', r.status, raw);
  let d=null;
  try{ d = raw ? JSON.parse(raw) : {}; }catch{ d={}; }
  if(r.ok){ return d.id ? d : {id:tx.id}; }
  if(await waitForTx(tx.id)) return {id:tx.id};
  throw new Error((d && d.message) ? d.message : ('HTTP '+r.status));
}

const QR = (function(){
  const EXP=new Array(256), LOG=new Array(256);
  for(let i=0,x=1;i<255;i++){ EXP[i]=x; LOG[x]=i; x<<=1; if(x&0x100)x^=0x11d; } EXP[255]=EXP[0];
  function gmul(a,b){ if(a===0||b===0)return 0; return EXP[(LOG[a]+LOG[b])%255]; }
  function genPoly(n){ let p=[1]; for(let i=0;i<n;i++){ const np=new Array(p.length+1).fill(0); for(let j=0;j<p.length;j++){ np[j]^=gmul(p[j],1); np[j+1]^=gmul(p[j],EXP[i]); } p=np; } return p; }
  function rsEncode(data,ecLen){ const gen=genPoly(ecLen); const res=data.concat(new Array(ecLen).fill(0));
    for(let i=0;i<data.length;i++){ const c=res[i]; if(c!==0) for(let j=0;j<gen.length;j++) res[i+j]^=gmul(gen[j],c); }
    return res.slice(data.length); }
  function build(text){
    const V=4, SIZE=17+V*4; const dataCW=80, ecCW=20;
    const bytes=strToBytes(text); if(bytes.length>78){ throw new Error('QR: слишком длинно'); }
    let bits=[];
    const push=(val,len)=>{ for(let i=len-1;i>=0;i--) bits.push((val>>i)&1); };
    push(0b0100,4); push(bytes.length,8);
    for(const b of bytes) push(b,8);
    push(0,4);
    while(bits.length%8) bits.push(0);
    const dcw=[]; for(let i=0;i<bits.length;i+=8){ let v=0; for(let j=0;j<8;j++) v=(v<<1)|bits[i+j]; dcw.push(v); }
    const pad=[0xEC,0x11]; let pi=0; while(dcw.length<dataCW){ dcw.push(pad[pi%2]); pi++; }
    const ecw=rsEncode(dcw, ecCW);
    const all=dcw.concat(ecw);
    const m=Array.from({length:SIZE},()=>new Array(SIZE).fill(null));
    function placeFinder(r,c){ for(let i=-1;i<=7;i++)for(let j=-1;j<=7;j++){ const rr=r+i,cc=c+j; if(rr<0||cc<0||rr>=SIZE||cc>=SIZE)continue; const inb=(i>=0&&i<=6&&(j===0||j===6))||(j>=0&&j<=6&&(i===0||i===6))||(i>=2&&i<=4&&j>=2&&j<=4); m[rr][cc]=inb?1:0; } }
    placeFinder(0,0); placeFinder(0,SIZE-7); placeFinder(SIZE-7,0);
    for(let i=8;i<SIZE-8;i++){ if(m[6][i]===null) m[6][i]=(i%2===0)?1:0; if(m[i][6]===null) m[i][6]=(i%2===0)?1:0; }
    (function(){ const ar=26,ac=26; for(let i=-2;i<=2;i++)for(let j=-2;j<=2;j++){ const v=(Math.max(Math.abs(i),Math.abs(j))!==1)?1:0; m[ar+i][ac+j]=v; } })();
    m[SIZE-8][8]=1;
    const reserve=(r,c)=>{ if(m[r][c]===null) m[r][c]=-2; };
    for(let i=0;i<9;i++){ reserve(8,i); reserve(i,8); } for(let i=0;i<8;i++){ reserve(8,SIZE-1-i); reserve(SIZE-1-i,8); }
    let dirUp=true, bitIdx=0; const dataBits=[]; for(const cw of all) for(let i=7;i>=0;i--) dataBits.push((cw>>i)&1);
    for(let col=SIZE-1;col>0;col-=2){ if(col===6)col--; for(let cnt=0;cnt<SIZE;cnt++){ const row=dirUp?SIZE-1-cnt:cnt;
      for(let c2=0;c2<2;c2++){ const cc=col-c2; if(m[row][cc]===null){ let bit=bitIdx<dataBits.length?dataBits[bitIdx]:0; bitIdx++;
        if(((row+cc)%2)===0) bit^=1; m[row][cc]=bit; } } } dirUp=!dirUp; }
    const fmt=0b111011111000100;
    const fb=[]; for(let i=14;i>=0;i--) fb.push((fmt>>i)&1);
    const tlPos=[[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
    tlPos.forEach((p,i)=>{ m[p[0]][p[1]]=fb[i]; });
    const tr=[[8,SIZE-1],[8,SIZE-2],[8,SIZE-3],[8,SIZE-4],[8,SIZE-5],[8,SIZE-6],[8,SIZE-7]];
    const bl=[[SIZE-1,8],[SIZE-2,8],[SIZE-3,8],[SIZE-4,8],[SIZE-5,8],[SIZE-6,8],[SIZE-7,8]];
    for(let i=0;i<7;i++){ m[bl[6-i][0]][bl[6-i][1]]=fb[i]; }
    for(let i=7;i<15;i++){ const idx=i-7; if(idx<tr.length) m[tr[idx][0]][tr[idx][1]]=fb[i]; }
    for(let r=0;r<SIZE;r++)for(let c=0;c<SIZE;c++){ if(m[r][c]===-2||m[r][c]===null) m[r][c]=0; }
    return m;
  }
  function draw(canvas,text){
    const m=build(text); const n=m.length; const quiet=4; const scale=Math.max(4,Math.floor(280/(n+quiet*2)));
    const dim=(n+quiet*2)*scale; canvas.width=dim; canvas.height=dim;
    const ctx=canvas.getContext('2d'); ctx.fillStyle='#fff'; ctx.fillRect(0,0,dim,dim); ctx.fillStyle='#000';
    for(let r=0;r<n;r++)for(let c=0;c<n;c++){ if(m[r][c]) ctx.fillRect((c+quiet)*scale,(r+quiet)*scale,scale,scale); }
  }
  return { draw };
})();

function openModal(html){ const m=document.createElement('div'); m.className='modal'; m.innerHTML='<div class="modal-box">'+html+'</div>'; document.body.appendChild(m); return m; }

function showReceive(){
  const m=openModal('<h3>📥 '+escapeHtml(t('receiveTitle'))+'</h3><canvas id="qrCanvas"></canvas><div class="addr-box" style="margin-top:14px">'+escapeHtml(ADDRESS)+'</div><p class="muted" style="text-align:center">'+escapeHtml(t('scanToSend'))+'</p><div class="btns"><button class="sec" id="rShare">📤 '+escapeHtml(t('shareAddr'))+'</button><button class="sec" id="rClose">'+escapeHtml(t('close'))+'</button></div>');
  try{ QR.draw(m.querySelector('#qrCanvas'), ADDRESS); }catch(e){ console.warn(e); }
  m.querySelector('#rClose').onclick=()=>m.remove();
  m.querySelector('#rShare').onclick=async()=>{ if(navigator.share){ try{ await navigator.share({text:ADDRESS}); }catch{} } else { try{ await navigator.clipboard.writeText(ADDRESS); toast(t('addrCopied')); }catch{} } };
}

function showBook(forPick){
  const list=getContacts();
  let rows = list.length ? list.map((c,i)=>'<div class="contact"><div class="ci" data-pick="'+i+'"><div class="cn">'+escapeHtml(c.name)+'</div><div class="ca">'+escapeHtml(c.addr)+'</div></div><button class="cd" data-del="'+i+'">🗑</button></div>').join('') : '<p class="muted">'+escapeHtml(t('noContacts'))+'</p>';
  const m=openModal('<h3>📖 '+escapeHtml(t('addressBook'))+'</h3><div id="bookList">'+rows+'</div><div class="field"><input id="cName" placeholder="'+escapeHtml(t('contactName'))+'"></div><div class="field"><input id="cAddr" placeholder="3P..."></div><div class="btns"><button class="btn-create" id="cAdd">'+escapeHtml(t('addContact'))+'</button><button class="sec" id="bClose">'+escapeHtml(t('close'))+'</button></div>');
  function rerender(){ m.remove(); showBook(forPick); }
  m.querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>{ const arr=getContacts(); arr.splice(+b.getAttribute('data-del'),1); saveContacts(arr); rerender(); });
  if(forPick) m.querySelectorAll('[data-pick]').forEach(b=>b.onclick=()=>{ const c=getContacts()[+b.getAttribute('data-pick')]; $('toAddr').value=c.addr; m.remove(); });
  m.querySelector('#cAdd').onclick=()=>{ const name=m.querySelector('#cName').value.trim(); const addr=m.querySelector('#cAddr').value.trim();
    if(!name||!isValidAddress(addr)){ toast(t('badAddr')); return; } const arr=getContacts(); arr.push({name,addr}); saveContacts(arr); rerender(); };
  m.querySelector('#bClose').onclick=()=>m.remove();
}

async function showAlias(){
  let existing=[];
  try{ const j=await (await fetch('?action=aliases&address='+encodeURIComponent(ADDRESS))).json();
    if(j.ok && Array.isArray(j.aliases)) existing=j.aliases; }catch(e){ console.warn(e); }
  const existHtml = existing.length
    ? '<p class="muted">'+escapeHtml(t('yourAliases'))+':</p>'+existing.map(a=>'<div class="addr-box">'+escapeHtml(a)+'</div>').join('')
    : '<p class="muted">'+escapeHtml(t('noAliases'))+'</p>';
  const m=openModal('<h3>🏷 '+escapeHtml(t('aliasTitle'))+'</h3>'+existHtml
    +'<div class="field"><label>'+escapeHtml(t('newAlias'))+'</label><input id="aliasInput" placeholder="myname" maxlength="30" autocomplete="off"></div>'
    +'<p class="muted">'+escapeHtml(t('aliasRules'))+'</p>'
    +'<div class="btns"><button class="sec" id="aClose">'+escapeHtml(t('close'))+'</button><button class="btn-login" id="aCreate">'+escapeHtml(t('createAlias'))+'</button></div><div id="aMsg"></div>');
  m.querySelector('#aClose').onclick=()=>m.remove();
  m.querySelector('#aCreate').onclick=async()=>{
    const name=m.querySelector('#aliasInput').value.trim().toLowerCase();
    if(!isValidAliasName(name)){ msg(m.querySelector('#aMsg'),'aliasBad',false); return; }
    m.querySelector('#aCreate').disabled=true;
    try{ const tx=buildAndSignAlias(name); const res=await broadcastTx(tx);
      msg(m.querySelector('#aMsg'),'✅ '+escapeHtml(t('aliasCreated')+' '+(res.id||tx.id)),true,true); toast(t('aliasCreated'));
      setTimeout(()=>m.remove(),2500);
    }catch(e){ console.error(e); msg(m.querySelector('#aMsg'),'❌ '+escapeHtml(e.message||String(e)),false,true); m.querySelector('#aCreate').disabled=false; }
  };
}

function showScanner(){
  if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof window.jsQR!=='function'){
    toast(t('noCamera')); return;
  }
  const m=openModal('<h3>📷 '+escapeHtml(t('scanTitle'))+'</h3><div class="scan-wrap"><video id="scanVideo" playsinline muted></video><div class="scan-frame"></div></div><p class="muted" style="text-align:center">'+escapeHtml(t('scanHint'))+'</p><div class="btns"><button class="sec" id="scanClose">'+escapeHtml(t('close'))+'</button></div>');
  const video=m.querySelector('#scanVideo');
  const canvas=document.createElement('canvas');
  let stream=null, raf=null, stopped=false;
  function stop(){ stopped=true; if(raf)cancelAnimationFrame(raf); if(stream)stream.getTracks().forEach(tr=>tr.stop()); m.remove(); }
  m.querySelector('#scanClose').onclick=stop;
  function tick(){
    if(stopped) return;
    if(video.readyState===video.HAVE_ENOUGH_DATA){
      canvas.width=video.videoWidth; canvas.height=video.videoHeight;
      const ctx=canvas.getContext('2d', {willReadFrequently:true});
      ctx.drawImage(video,0,0,canvas.width,canvas.height);
      const img=ctx.getImageData(0,0,canvas.width,canvas.height);
      const code=window.jsQR(img.data, img.width, img.height, {inversionAttempts:'dontInvert'});
      if(code && code.data){
        let txt=code.data.trim();
        txt=txt.replace(/^waves:/i,'').split('?')[0].trim();
        if(isValidAddress(txt)){ $('toAddr').value=txt; toast(t('scanned')); stop(); return; }
      }
    }
    raf=requestAnimationFrame(tick);
  }
  navigator.mediaDevices.getUserMedia({ video:{ facingMode:'environment' } })
    .then(s=>{ stream=s; video.srcObject=s; video.play(); raf=requestAnimationFrame(tick); })
    .catch(err=>{ console.warn(err); toast(t('noCamera')); stop(); });
}

function confirmSend(to,asset,amtNum,att,feeLabel){ return new Promise(resolve=>{
  const m=openModal('<h3>'+escapeHtml(t('confirmSend'))+'</h3>'
    +'<div class="modal-row"><span>'+escapeHtml(t('token'))+'</span><span>'+escapeHtml(asset.name)+'</span></div>'
    +'<div class="modal-row"><span>'+escapeHtml(t('amount'))+'</span><span>'+amtNum+'</span></div>'
    +'<div class="modal-row"><span>'+escapeHtml(t('to'))+'</span><span>'+escapeHtml(to)+'</span></div>'
    +(att?'<div class="modal-row"><span>'+escapeHtml(t('comment'))+'</span><span>'+escapeHtml(att)+'</span></div>':'')
    +'<div class="modal-row"><span>'+escapeHtml(t('fee'))+'</span><span>'+escapeHtml(feeLabel||'0.001 WAVES')+'</span></div>'
    +'<div class="btns"><button class="sec" id="mCancel">'+escapeHtml(t('cancel'))+'</button><button class="btn-login" id="mOk">'+escapeHtml(t('send'))+'</button></div>');
  m.querySelector('#mCancel').onclick=()=>{m.remove();resolve(false);};
  m.querySelector('#mOk').onclick=()=>{m.remove();resolve(true);};
}); }

let pinBuffer='';
function renderDots(){ const d=$('unlockDots'); d.innerHTML=''; for(let i=0;i<8;i++){ const el=document.createElement('div'); el.className='pin-dot'+(i<pinBuffer.length?' on':''); d.appendChild(el); } }
function buildPad(onKey){ const pad=$('unlockPad'); pad.innerHTML=''; ['1','2','3','4','5','6','7','8','9','C','0','⌫'].forEach(k=>{ const b=document.createElement('button'); b.className='pin-key'; b.textContent=k; b.onclick=()=>onKey(k); pad.appendChild(b); }); }
function pinKeyUnlock(k){
  if(k==='C'){ pinBuffer=''; } else if(k==='⌫'){ pinBuffer=pinBuffer.slice(0,-1); }
  else if(pinBuffer.length<8){ pinBuffer+=k; }
  renderDots();
  if(pinBuffer.length>=4 && k!=='C' && k!=='⌫'){ tryUnlock(); }
}
function tryUnlock(){
  const enc=loadEncWallet(); if(!enc) return;
  const seed=decryptSeed(enc, pinBuffer);
  if(seed){ pinBuffer=''; loginWithSeed(seed); }
  else if(pinBuffer.length>=8){ msg($('unlockMsg'),'pinWrong',false); pinBuffer=''; renderDots(); }
}
function showPinUnlock(){
  ['authCard','newSeedCard','loadingCard','walletCard','sendCard','assetsCard','historyCard'].forEach(id=>$(id).classList.add('hidden'));
  $('pinUnlockCard').classList.remove('hidden'); pinBuffer=''; renderDots(); buildPad(pinKeyUnlock);
}
function askSetPin(seed){ return new Promise(resolve=>{
  let stage=1, first=''; let buf='';
  const m=openModal('<h3>🔒 '+escapeHtml(t('savePin'))+'</h3><p class="muted" id="pinHint">'+escapeHtml(t('pinCreate'))+'</p><div class="pin-dots" id="sDots"></div><div class="pin-pad" id="sPad"></div><div class="row"><button class="sec" id="pinSkip">'+escapeHtml(t('cancel'))+'</button></div><div id="sMsg"></div>');
  const dots=m.querySelector('#sDots'); const pad=m.querySelector('#sPad');
  function rd(){ dots.innerHTML=''; for(let i=0;i<8;i++){ const e=document.createElement('div'); e.className='pin-dot'+(i<buf.length?' on':''); dots.appendChild(e);} }
  ['1','2','3','4','5','6','7','8','9','C','0','⌫'].forEach(k=>{ const b=document.createElement('button'); b.className='pin-key'; b.textContent=k; pad.appendChild(b);
    b.onclick=()=>{ if(k==='C')buf=''; else if(k==='⌫')buf=buf.slice(0,-1); else if(buf.length<8)buf+=k; rd(); };
  });
  const okBtn=document.createElement('button'); okBtn.className='btn-login'; okBtn.style.marginTop='12px'; okBtn.textContent=t('continue');
  m.querySelector('.modal-box').insertBefore(okBtn, m.querySelector('.row'));
  okBtn.onclick=()=>{
    if(buf.length<4){ msg(m.querySelector('#sMsg'),'pinShort',false); return; }
    if(stage===1){ first=buf; buf=''; rd(); stage=2; m.querySelector('#pinHint').textContent=t('pinRepeat'); }
    else { if(buf!==first){ msg(m.querySelector('#sMsg'),'pinMismatch',false); buf=''; rd(); return; }
      saveEncWallet(seed, first); m.remove(); toast(t('pinSet')); resolve(true); }
  };
  m.querySelector('#pinSkip').onclick=()=>{ m.remove(); resolve(false); };
  rd();
}); }

function loginWithSeed(seed){
  try{ const kp=getKeyPair(seed); PUBKEY=kp.publicKey; ADDRESS=addressFromPublicKey(kp.publicKey); SEED=seed; showWallet(); }
  catch(e){ console.error(e); msg($('authMsg'),'cryptoErr',false); }
}
function login(seed){
  seed=(seed||'').trim();
  if(!seed){ msg($('authMsg'),'enterSeedErr',false); return; }
  if(seed.length<12){ msg($('authMsg'),'seedShort',false); return; }
  loginWithSeed(seed);
  if(!hasSavedWallet()){ askSetPin(seed); }
}
function showWallet(){
  ['authCard','newSeedCard','loadingCard','pinUnlockCard'].forEach(id=>$(id).classList.add('hidden'));
  ['walletCard','sendCard','assetsCard','historyCard'].forEach(id=>$(id).classList.remove('hidden'));
  $('addrView').textContent=ADDRESS; $('seedInput').value='';
  loadBalance().then(()=>{ loadHistory(); loadSponsored(); });
  if(refreshTimer) clearInterval(refreshTimer);
  refreshTimer=setInterval(()=>{ loadBalance(); },30000);
}
function fmt(amount,decimals){ return (amount/Math.pow(10,decimals)).toLocaleString(LANG==='ru'?'ru-RU':'en-US',{maximumFractionDigits:decimals}); }

async function loadBalance(){
  try{
    const j=await (await fetch('?action=balance&address='+encodeURIComponent(ADDRESS))).json(); if(!j.ok)return;
    const wavesBal=(j.waves&&typeof j.waves.balance==='number')?j.waves.balance:0;
    ASSETS=[{id:null,name:'WAVES',decimals:8,balance:wavesBal}];
    const arr=(j.assets&&j.assets.balances)||[];
    arr.forEach(a=>{ const dec=a.issueTransaction?a.issueTransaction.decimals:0; const name=a.issueTransaction?a.issueTransaction.name:a.assetId.slice(0,10); ASSETS.push({id:a.assetId,name,decimals:dec,balance:a.balance}); });
    seedAssetCacheFromBalances();
    $('balView').innerHTML=fmt(wavesBal,8)+' <small>WAVES</small>';
    const list=$('assetsList'); list.innerHTML='';
    ASSETS.forEach(a=>{ const r=document.createElement('div'); r.className='asset'; r.innerHTML='<span>'+escapeHtml(a.name)+'</span><span>'+fmt(a.balance,a.decimals)+'</span>'; list.appendChild(r); });
    const sel=$('assetSelect'); const prev=sel.value; sel.innerHTML='';
    ASSETS.forEach((a,i)=>{ const o=document.createElement('option'); o.value=i; o.textContent=a.name+' ('+fmt(a.balance,a.decimals)+')'; sel.appendChild(o); });
    if(prev&&ASSETS[prev]) sel.value=prev;
  }catch(e){ console.error(e); }
}
async function loadSponsored(){
  try{
    const j=await (await fetch('?action=sponsored&address='+encodeURIComponent(ADDRESS))).json();
    SPONSORED=(j.ok && Array.isArray(j.sponsored))?j.sponsored:[];
    SPONSORED.forEach(s=>{ if(!ASSET_CACHE[s.assetId]) ASSET_CACHE[s.assetId]={name:s.name||s.assetId.slice(0,8),decimals:s.decimals||0}; });
  }catch(e){ console.warn(e); SPONSORED=[]; }
  fillFeeSelect();
}
function fillFeeSelect(){
  const sel=$('feeSelect'); if(!sel) return;
  const prev=sel.value; sel.innerHTML='';
  const oW=document.createElement('option'); oW.value=''; oW.textContent='WAVES (0.001)'; sel.appendChild(oW);
  SPONSORED.forEach(s=>{
    const info=ASSET_CACHE[s.assetId]||{name:s.name||s.assetId.slice(0,8),decimals:s.decimals||0};
    if(s.balance>=s.minSponsoredAssetFee){
      const o=document.createElement('option'); o.value=s.assetId; o.dataset.minfee=s.minSponsoredAssetFee;
      o.textContent=info.name+' ('+fmt(s.minSponsoredAssetFee,info.decimals)+')';
      sel.appendChild(o);
    }
  });
  if(prev) sel.value=prev;
}
async function loadHistory(){
  try{
    seedAssetCacheFromBalances();
    const j=await (await fetch('?action=history&address='+encodeURIComponent(ADDRESS))).json();
    const list=$('historyList');
    if(!j.ok||!j.tx||!j.tx[0]){ list.textContent=t('noOps'); return; }
    const txs=j.tx[0]; const transfers=txs.filter(x=>x.type===4);
    const needIds=[...new Set(transfers.map(x=>x.assetId).filter(id=>id&&!ASSET_CACHE[id]))];
    await Promise.all(needIds.map(id=>getAssetInfo(id)));
    list.innerHTML='';
    txs.forEach(tx=>{
      if(tx.type!==4){ const row=document.createElement('div'); row.className='tx'; row.innerHTML='<div><div>'+t('operation')+' ('+tx.type+')</div><div class="tx-meta">'+new Date(tx.timestamp).toLocaleString()+'</div></div>'; list.appendChild(row); return; }
      const incoming=tx.recipient===ADDRESS;
      const info=tx.assetId?(ASSET_CACHE[tx.assetId]||{name:tx.assetId.slice(0,8),decimals:0}):(ASSET_CACHE['WAVES']||{name:'WAVES',decimals:8});
      const amt=(tx.amount/Math.pow(10,info.decimals)).toLocaleString(LANG==='ru'?'ru-RU':'en-US',{maximumFractionDigits:info.decimals});
      const sign=incoming?'+':'−'; const cls=incoming?'tx-in':'tx-out';
      const who=incoming?(t('from')+': '+(tx.sender||'').slice(0,12)+'…'):(t('to')+': '+(tx.recipient||'').slice(0,12)+'…');
      const row=document.createElement('div'); row.className='tx';
      row.innerHTML='<div><div>'+(incoming?'📥 '+t('received'):'📤 '+t('sent'))+'</div><div class="tx-meta">'+who+' • '+new Date(tx.timestamp).toLocaleString()+'</div></div><div><div class="tx-amt '+cls+'">'+sign+amt+'</div><div class="tx-token">'+escapeHtml(info.name)+'</div></div>';
      list.appendChild(row);
    });
  }catch(e){ console.error(e); $('historyList').textContent=t('loadFail'); }
}
function selectedAsset(){ return ASSETS[$('assetSelect').value]||ASSETS[0]; }

window.addEventListener('DOMContentLoaded', () => {
  applyTheme(); applyLang();
  try{
    if(typeof window.keccak256!=='function')throw new Error('sha3.min.js не загрузился');
    if(!window.axlsign||typeof window.axlsign.generateKeyPair!=='function')throw new Error('axlsign.min.js не загрузился');
    if(typeof blake2b!=='function')throw new Error('blakejs.min.js не загрузился');
    if(!window.I18N) throw new Error('lang.js не загрузился');
    const empty=sha256(new Uint8Array(0)); const hex=Array.from(empty).map(x=>x.toString(16).padStart(2,'0')).join('');
    if(hex!=='e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855') throw new Error('SHA-256 самопроверка не прошла');
    const ta=deriveAddress('test seed phrase for waves wallet check one two three');
    console.log('🔎 Тест-адрес:', ta); console.log('✅ Крипто ОК');
  }catch(e){ console.error('ОШИБКА:',e); $('loadingCard').innerHTML='<div class="msg bad">'+escapeHtml(t('cryptoErr')+' '+(e.message||e))+'</div>'; return; }

  $('loadingCard').classList.add('hidden');
  if(hasSavedWallet()) showPinUnlock(); else $('authCard').classList.remove('hidden');

  $('langBtn').addEventListener('click', toggleLang);
  $('themeBtn').addEventListener('click', toggleTheme);
  $('loginBtn').addEventListener('click', ()=>login($('seedInput').value));
  $('createBtn').addEventListener('click', ()=>{
    try{ pendingSeed=genSeed(); const addr=deriveAddress(pendingSeed);
      $('newSeedText').textContent=pendingSeed; $('newSeedAddr').textContent='📬 '+addr;
      $('authCard').classList.add('hidden'); $('newSeedCard').classList.remove('hidden'); $('savedCheck').checked=false; $('enterNewBtn').disabled=true;
    }catch(e){ msg($('authMsg'),'cryptoErr',false); }
  });
  $('savedCheck').addEventListener('change',e=>{ $('enterNewBtn').disabled=!e.target.checked; });
  $('copySeedBtn').addEventListener('click', async ()=>{ try{ await navigator.clipboard.writeText(pendingSeed); toast(t('seedCopied')); }catch{ toast(t('copyManual')); } });
  $('enterNewBtn').addEventListener('click', ()=>{ $('newSeedCard').classList.add('hidden'); const s=pendingSeed; pendingSeed=null; loginWithSeed(s); if(!hasSavedWallet()) askSetPin(s); });
  $('receiveBtn').addEventListener('click', showReceive);
  $('copyAddrBtn').addEventListener('click', async ()=>{ try{ await navigator.clipboard.writeText(ADDRESS); toast(t('addrCopied')); }catch{ toast(t('copyManual')); } });
  $('aliasBtn').addEventListener('click', showAlias);
  $('refreshBtn').addEventListener('click', ()=>{ loadBalance().then(()=>{loadHistory();loadSponsored();}); toast(t('updated')); });
  $('lockBtn').addEventListener('click', ()=>{ SEED=null;ADDRESS=null;PUBKEY=null; if(refreshTimer)clearInterval(refreshTimer); if(hasSavedWallet()) showPinUnlock(); else location.reload(); });
  $('bookBtn').addEventListener('click', ()=>showBook(true));
  $('scanBtn').addEventListener('click', showScanner);
  $('maxBtn').addEventListener('click', ()=>{ const a=selectedAsset(); let max=a.balance; if(a.id===null) max=Math.max(0,a.balance-100000); $('amount').value=(max/Math.pow(10,a.decimals)).toString(); });
  $('pinUseSeedBtn').addEventListener('click', ()=>{ $('pinUnlockCard').classList.add('hidden'); $('authCard').classList.remove('hidden'); });
  $('pinForgetBtn').addEventListener('click', ()=>{ forgetWallet(); $('pinUnlockCard').classList.add('hidden'); $('authCard').classList.remove('hidden'); toast('OK'); });

  $('sendBtn').addEventListener('click', async ()=>{
    if(!SEED){ msg($('sendMsg'),'notUnlocked',false); return; }
    const raw=$('toAddr').value.trim(); const amt=$('amount').value.trim().replace(',','.'); const att=$('att').value.trim(); const asset=selectedAsset();

    let recipient;
    if(raw.startsWith('alias:')){ recipient=raw; }
    else if(isValidAddress(raw)){ recipient=raw; }
    else if(isValidAliasName(raw.toLowerCase())){ recipient='alias:'+CFG.chainId+':'+raw.toLowerCase(); }
    else { msg($('sendMsg'),'badAddr',false); return; }

    const amtNum=parseFloat(amt); if(!(amtNum>0)){ msg($('sendMsg'),'badAmount',false); return; }
    const amountMin=Math.round(amtNum*Math.pow(10,asset.decimals));
    if(amountMin>asset.balance){ msg($('sendMsg'),'noFunds',false); return; }

    const feeSel=$('feeSelect'); const feeAssetId=feeSel.value||null;
    let feeAmount, feeLabel;
    if(feeAssetId){
      const sp=SPONSORED.find(s=>s.assetId===feeAssetId);
      feeAmount=sp?sp.minSponsoredAssetFee:100000;
      const info=ASSET_CACHE[feeAssetId]||{name:feeAssetId.slice(0,8),decimals:0};
      feeLabel=fmt(feeAmount,info.decimals)+' '+info.name;
    } else { feeAmount=100000; feeLabel='0.001 WAVES'; }

    const ok=await confirmSend(recipient,asset,amtNum,att,feeLabel); if(!ok)return;
    $('sendBtn').disabled=true;
    try{ const tx=buildAndSignTransfer({recipient,amount:amountMin,fee:feeAmount,attachment:att||'',assetId:asset.id,feeAssetId});
      const res=await broadcastTx(tx);
      msg($('sendMsg'),'✅ '+escapeHtml(t('sentOk')+' '+(res.id||tx.id)),true,true); toast(t('txSent'));
      $('amount').value=''; $('att').value=''; setTimeout(()=>{ loadBalance().then(()=>{loadHistory();loadSponsored();}); },3000);
    }catch(e){ console.error(e); msg($('sendMsg'),'❌ '+escapeHtml((e&&e.message)?e.message:JSON.stringify(e)),false,true); }
    finally{ $('sendBtn').disabled=false; }
  });

  // ===== PWA =====
  if('serviceWorker' in navigator){
    navigator.serviceWorker.register('sw.js').then(()=>console.log('✅ PWA SW зарегистрирован')).catch(e=>console.warn('SW fail',e));
  }
  let deferredPrompt=null;
  window.addEventListener('beforeinstallprompt', e=>{ e.preventDefault(); deferredPrompt=e; $('installBtn').classList.remove('hidden'); });
  $('installBtn').addEventListener('click', async ()=>{ if(!deferredPrompt)return; deferredPrompt.prompt(); const {outcome}=await deferredPrompt.userChoice; console.log('Установка:',outcome); deferredPrompt=null; $('installBtn').classList.add('hidden'); });
  window.addEventListener('appinstalled', ()=>{ $('installBtn').classList.add('hidden'); toast('OK'); });

  // ===== Аварийный сброс SW+кеша: 3 тапа по логотипу → кнопка 🧹 =====
  let logoTaps=0, logoTimer=null;
  document.querySelector('.logo').addEventListener('click', ()=>{
    logoTaps++; clearTimeout(logoTimer); logoTimer=setTimeout(()=>logoTaps=0,600);
    if(logoTaps>=3){ $('resetBtn').classList.remove('hidden'); toast('🧹'); logoTaps=0; }
  });
  $('resetBtn').addEventListener('click', async ()=>{
    try{
      if('serviceWorker' in navigator){ const rs=await navigator.serviceWorker.getRegistrations(); await Promise.all(rs.map(r=>r.unregister())); }
      if('caches' in window){ const ks=await caches.keys(); await Promise.all(ks.map(k=>caches.delete(k))); }
      toast('OK'); setTimeout(()=>location.reload(),800);
    }catch(e){ console.error(e); }
  });
});
</script>
</body>
</html>