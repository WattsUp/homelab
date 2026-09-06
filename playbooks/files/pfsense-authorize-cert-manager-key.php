#!/usr/local/bin/php
<?php

require_once "config.inc";
require_once "auth.inc";

global $config;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: {$argv[0]} <base64-encoded-public-key>\n");
    exit(2);
}

$public_key = base64_decode($argv[1], true);
if ($public_key === false || $public_key === '') {
    fwrite(STDERR, "Invalid public key\n");
    exit(2);
}

$root_user_index = null;
$root_user = null;
foreach ($config['system']['user'] as $index => $user) {
    if (($user['uid'] ?? null) === '0' || ($user['uid'] ?? null) === 0) {
        $root_user_index = $index;
        $root_user = $user;
        break;
    }
}

if ($root_user === null) {
    fwrite(STDERR, "Could not find the pfSense root user\n");
    exit(1);
}

$authorized_keys = base64_decode($root_user['authorizedkeys'] ?? '', true);
if ($authorized_keys === false) {
    fwrite(STDERR, "Invalid configured authorized keys\n");
    exit(1);
}

if (in_array($public_key, preg_split('/\r?\n/', trim($authorized_keys)), true)) {
    echo "unchanged\n";
    exit(0);
}

// NOTE: pfSense regenerates authorized_keys from this persistent config value.
$root_user['authorizedkeys'] = base64_encode(rtrim($authorized_keys) . "\n" . $public_key . "\n");
$config['system']['user'][$root_user_index] = $root_user;
write_config('Add certificate manager deployment SSH key');
local_user_set($root_user);

echo "changed\n";
