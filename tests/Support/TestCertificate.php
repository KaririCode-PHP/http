<?php

declare(strict_types=1);

namespace KaririCode\Http\Tests\Support;

/**
 * A self-signed P-256 certificate made with ext-openssl for the tests that
 * need a real one: PEM parsing, fingerprints and openssl_x509_parse(). A
 * minimal OpenSSL configuration keeps the system's default subject fields
 * (C=AU, ST=Some-State, ...) out of the certificate.
 */
final readonly class TestCertificate
{
    private function __construct(
        public \OpenSSLCertificate $certificate,
        public string $pem,
        public string $fingerprint,
    ) {}

    /**
     * @param array<string, string|list<string>> $subject
     */
    public static function create(array $subject = ['commonName' => 'client-7', 'organizationName' => 'Acme, Inc.'], int $days = 30): self
    {
        $config = tempnam(sys_get_temp_dir(), 'kariricode-http-openssl');
        is_string($config) && file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n") !== false || throw self::failure('configuration');

        try {
            return self::sign($subject, $days, ['config' => $config, 'digest_alg' => 'sha256']);
        } finally {
            unlink($config);

            while (openssl_error_string() !== false) {
                // Drains the queue: OpenSSL reports a missing RANDFILE that does not matter here.
            }
        }
    }

    /**
     * @param array<string, string|list<string>> $subject
     * @param array{config: string, digest_alg: string} $options
     */
    private static function sign(array $subject, int $days, array $options): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $options['config']]);
        $key !== false || throw self::failure('key');
        $request = openssl_csr_new($subject, $key, $options);
        $request instanceof \OpenSSLCertificateSigningRequest || throw self::failure('signing request');
        $key instanceof \OpenSSLAsymmetricKey || throw self::failure('key after signing request');
        $certificate = openssl_csr_sign($request, null, $key, $days, $options, 0x1A2B);
        $certificate !== false || throw self::failure('certificate');
        $pem = '';
        openssl_x509_export($certificate, $pem) || throw self::failure('PEM');
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        is_string($pem) && $fingerprint !== false || throw self::failure('fingerprint');

        return new self($certificate, $pem, $fingerprint);
    }

    private static function failure(string $what): \RuntimeException
    {
        return new \RuntimeException('ext-openssl could not create the test ' . $what . ': ' . (openssl_error_string() ?: 'no error string'));
    }
}
