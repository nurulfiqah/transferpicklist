<?php
// Reads secrets from transferpicklist/.env (KEY=VALUE per line, # comments allowed).
// .env is git-ignored — see .env.example for the keys. Same file the Python syncs
// read through hyper/db_config.py.

function tplEnv($key, $default = '') {
    static $vars = null;
    if ($vars === null) {
        $vars = array();
        $file = __DIR__ . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
                list($k, $v) = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $vars[$k] = $v;
            }
        }
    }
    $env = getenv($key);
    if ($env !== false && $env !== '') { return $env; }
    return (isset($vars[$key]) && $vars[$key] !== '') ? $vars[$key] : $default;
}

// Secret for scheduled / URL-triggered runs (?secret=...). If it is missing from
// .env, return a random value instead of '' so an empty ?secret= can never match
// and bypass the login.
function tplSyncSecret() {
    $s = tplEnv('TPL_SYNC_SECRET');
    return $s !== '' ? $s : bin2hex(random_bytes(16));
}
