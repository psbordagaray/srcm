#!/usr/bin/env php
<?php

declare(strict_types=1);

const TARGET_HOST = 'straleon-prod-01';
const SECRET_DIRECTORY = '/etc/srcm/runtime-secrets';
const SECRET_NAME = 'SRCM_OFFLINE_SIGNED_GRANT_SIGNING_SECRET_B64URL';

function fail(string $code, int $status = 1): never
{
    fwrite(STDERR, 'SRCM_SIGNING_SECRET_GENERATOR_ERROR='.$code.PHP_EOL);
    exit($status);
}

function base64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

if (PHP_SAPI !== 'cli') {
    fail('cli_required', 64);
}

if (gethostname() !== TARGET_HOST) {
    fail('unexpected_host', 78);
}

if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fail('root_required', 77);
}

if (! extension_loaded('sodium')) {
    fail('sodium_required', 69);
}

if (! is_dir(SECRET_DIRECTORY)) {
    fail('secret_directory_missing', 66);
}
if (is_link(SECRET_DIRECTORY) || realpath(SECRET_DIRECTORY) !== SECRET_DIRECTORY) {
    fail('secret_directory_must_not_be_symlink', 77);
}

$directoryMode = fileperms(SECRET_DIRECTORY);
if (! is_int($directoryMode) || ($directoryMode & 0777) !== 0700) {
    fail('secret_directory_mode_must_be_0700', 77);
}

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);

if (
    strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
    || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
) {
    fail('unexpected_key_size', 70);
}

$fingerprint = hash('sha256', $public);
$kid = 'sg-ed25519-'.substr($fingerprint, 0, 16);
$encodedSecret = base64Url($secret);
$encodedPublic = base64Url($public);

if (strlen($encodedSecret) !== 86) {
    fail('unexpected_secret_encoding_length', 70);
}

$stagedPath = SECRET_DIRECTORY.'/.offline-signed-grant.env.staged-'.$kid;
$temporaryPath = SECRET_DIRECTORY.'/.offline-signed-grant.env.tmp-'.bin2hex(random_bytes(8));

if (file_exists($stagedPath)) {
    fail('staged_secret_already_exists', 73);
}

umask(0077);
$content = SECRET_NAME.'='.$encodedSecret.PHP_EOL;
if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
    fail('temporary_secret_write_failed', 74);
}
if (! chmod($temporaryPath, 0600)) {
    @unlink($temporaryPath);
    fail('temporary_secret_chmod_failed', 74);
}
if (! rename($temporaryPath, $stagedPath)) {
    @unlink($temporaryPath);
    fail('staged_secret_atomic_rename_failed', 74);
}

$jwk = [
    'kty' => 'OKP',
    'crv' => 'Ed25519',
    'x' => $encodedPublic,
    'alg' => 'EdDSA',
    'use' => 'sig',
];

sodium_memzero($secret);
sodium_memzero($keypair);
unset($encodedSecret, $content);

printf('KID=%s'.PHP_EOL, $kid);
printf(
    'PUBLIC_JWK_JSON=%s'.PHP_EOL,
    json_encode($jwk, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
);
printf('PUBLIC_FINGERPRINT_SHA256=%s'.PHP_EOL, $fingerprint);
