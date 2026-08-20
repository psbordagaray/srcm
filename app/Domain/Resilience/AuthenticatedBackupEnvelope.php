<?php

namespace App\Domain\Resilience;

use RuntimeException;

final class AuthenticatedBackupEnvelope
{
    private const MAGIC = "SRCMEB1\n";
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MAX_HEADER_BYTES = 16384;

    /**
     * @return array{plaintext_sha256:string,ciphertext_sha256:string,key_id:string,chunks:int,bytes:int}
     */
    public function encryptFile(
        string $source,
        string $destination,
        BackupEncryptionKeyMaterial $material,
        string $originalFilename,
    ): array {
        $this->assertCipherAvailable();
        if (! is_file($source)) {
            throw new RuntimeException('SRCM backup plaintext source is unavailable.');
        }
        if (file_exists($destination)) {
            throw new RuntimeException('SRCM encrypted backup destination already exists.');
        }
        if ($originalFilename !== basename($originalFilename) || $originalFilename === '') {
            throw new RuntimeException('SRCM backup original filename is invalid.');
        }

        $plaintextSha = hash_file('sha256', $source);
        if (! is_string($plaintextSha) || $plaintextSha === '') {
            throw new RuntimeException('Unable to hash SRCM backup plaintext.');
        }

        $chunkBytes = $this->chunkBytes();
        $header = json_encode([
            'version' => 1,
            'cipher' => self::CIPHER,
            'key_id' => $material->keyId,
            'filename' => $originalFilename,
            'plaintext_sha256' => strtolower($plaintextSha),
            'chunk_bytes' => $chunkBytes,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($destination);
            throw new RuntimeException('Unable to open SRCM backup encryption streams.');
        }

        $chunks = 0;
        try {
            $this->writeAll($output, self::MAGIC);
            $this->writeAll($output, pack('N', strlen($header)));
            $this->writeAll($output, $header);

            while (! feof($input)) {
                $plain = fread($input, $chunkBytes);
                if ($plain === false) {
                    throw new RuntimeException('Unable to read SRCM backup plaintext chunk.');
                }
                if ($plain === '') {
                    continue;
                }

                $nonce = random_bytes(self::NONCE_BYTES);
                $tag = '';
                $aad = self::MAGIC.$header.pack('N', $chunks);
                $ciphertext = openssl_encrypt(
                    $plain,
                    self::CIPHER,
                    $material->key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $aad,
                    self::TAG_BYTES,
                );
                if (! is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('SRCM backup chunk encryption failed.');
                }

                $this->writeAll($output, pack('N', strlen($ciphertext)));
                $this->writeAll($output, $nonce);
                $this->writeAll($output, $tag);
                $this->writeAll($output, $ciphertext);
                $chunks++;
            }

            $this->writeAll($output, pack('N', 0));
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            throw $exception;
        }

        fclose($input);
        fclose($output);

        $ciphertextSha = hash_file('sha256', $destination);
        $bytes = filesize($destination);
        if (! is_string($ciphertextSha) || ! is_int($bytes)) {
            @unlink($destination);
            throw new RuntimeException('Unable to inspect SRCM encrypted backup envelope.');
        }

        return [
            'plaintext_sha256' => strtolower($plaintextSha),
            'ciphertext_sha256' => strtolower($ciphertextSha),
            'key_id' => $material->keyId,
            'chunks' => $chunks,
            'bytes' => $bytes,
        ];
    }

    /**
     * Verify and decrypt in memory only; plaintext is never written to disk.
     *
     * @param resource $stream
     * @return array{plaintext_sha256:string,ciphertext_sha256:string,key_id:string,filename:string,chunks:int}
     */
    public function verifyStream($stream, BackupEncryptionKeyMaterial $material): array
    {
        $this->assertCipherAvailable();
        if (! is_resource($stream)) {
            throw new RuntimeException('SRCM encrypted backup verification stream is invalid.');
        }

        $cipherHash = hash_init('sha256');
        $magic = $this->readExact($stream, strlen(self::MAGIC), $cipherHash);
        if (! hash_equals(self::MAGIC, $magic)) {
            throw new RuntimeException('SRCM encrypted backup magic is invalid.');
        }

        $headerLengthRaw = $this->readExact($stream, 4, $cipherHash);
        $unpacked = unpack('Nlength', $headerLengthRaw);
        $headerLength = (int) ($unpacked['length'] ?? 0);
        if ($headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('SRCM encrypted backup header length is invalid.');
        }

        $headerRaw = $this->readExact($stream, $headerLength, $cipherHash);
        $header = json_decode($headerRaw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($header)) {
            throw new RuntimeException('SRCM encrypted backup header is invalid.');
        }

        $this->assertHeader($header, $material);
        $chunkBytes = (int) $header['chunk_bytes'];
        $plainHash = hash_init('sha256');
        $chunks = 0;

        while (true) {
            $lengthRaw = $this->readExact($stream, 4, $cipherHash);
            $unpacked = unpack('Nlength', $lengthRaw);
            $length = (int) ($unpacked['length'] ?? -1);
            if ($length === 0) {
                break;
            }
            if ($length < 1 || $length > $chunkBytes) {
                throw new RuntimeException('SRCM encrypted backup chunk length is invalid.');
            }

            $nonce = $this->readExact($stream, self::NONCE_BYTES, $cipherHash);
            $tag = $this->readExact($stream, self::TAG_BYTES, $cipherHash);
            $ciphertext = $this->readExact($stream, $length, $cipherHash);
            $aad = self::MAGIC.$headerRaw.pack('N', $chunks);
            $plain = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $material->key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $aad,
            );
            if (! is_string($plain)) {
                throw new RuntimeException('SRCM encrypted backup authentication failed.');
            }
            hash_update($plainHash, $plain);
            $chunks++;
        }

        $trailing = fread($stream, 1);
        if ($trailing !== '' && $trailing !== false) {
            throw new RuntimeException('SRCM encrypted backup contains trailing bytes.');
        }

        $plaintextSha = hash_final($plainHash);
        if (! hash_equals((string) $header['plaintext_sha256'], strtolower($plaintextSha))) {
            throw new RuntimeException('SRCM encrypted backup plaintext checksum mismatch.');
        }

        return [
            'plaintext_sha256' => strtolower($plaintextSha),
            'ciphertext_sha256' => strtolower(hash_final($cipherHash)),
            'key_id' => (string) $header['key_id'],
            'filename' => (string) $header['filename'],
            'chunks' => $chunks,
        ];
    }

    /** @param array<string,mixed> $header */
    private function assertHeader(array $header, BackupEncryptionKeyMaterial $material): void
    {
        $keys = array_keys($header);
        sort($keys);
        $expected = ['chunk_bytes', 'cipher', 'filename', 'key_id', 'plaintext_sha256', 'version'];
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('SRCM encrypted backup header keys are invalid.');
        }
        if (($header['version'] ?? null) !== 1 || ($header['cipher'] ?? null) !== self::CIPHER) {
            throw new RuntimeException('SRCM encrypted backup version or cipher is unsupported.');
        }
        if (($header['key_id'] ?? null) !== $material->keyId) {
            throw new RuntimeException('SRCM encrypted backup key id does not match configured material.');
        }
        if (! is_string($header['filename'] ?? null)
            || $header['filename'] !== basename($header['filename'])
            || $header['filename'] === '') {
            throw new RuntimeException('SRCM encrypted backup filename header is invalid.');
        }
        if (! is_string($header['plaintext_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $header['plaintext_sha256']) !== 1) {
            throw new RuntimeException('SRCM encrypted backup plaintext checksum header is invalid.');
        }
        if (! is_int($header['chunk_bytes'] ?? null)
            || $header['chunk_bytes'] < 65536
            || $header['chunk_bytes'] > 8388608) {
            throw new RuntimeException('SRCM encrypted backup chunk size header is invalid.');
        }
    }

    private function chunkBytes(): int
    {
        $value = config('resilience.off_host.encryption.chunk_bytes', 1048576);
        $value = is_int($value) ? $value : (int) $value;
        if ($value < 65536 || $value > 8388608) {
            throw new RuntimeException('SRCM backup encryption chunk size is out of bounds.');
        }

        return $value;
    }

    private function assertCipherAvailable(): void
    {
        if (! function_exists('openssl_encrypt')
            || ! function_exists('openssl_decrypt')
            || ! in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM OpenSSL support is required for SRCM backup encryption.');
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (! is_int($written) || $written < 1) {
                throw new RuntimeException('Unable to write SRCM encrypted backup envelope.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream @param resource $hashContext */
    private function readExact($stream, int $length, $hashContext): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($stream, $length - strlen($buffer));
            if ($part === false || $part === '') {
                throw new RuntimeException('SRCM encrypted backup envelope is truncated.');
            }
            $buffer .= $part;
        }
        hash_update($hashContext, $buffer);

        return $buffer;
    }
}
