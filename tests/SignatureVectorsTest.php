<?php

declare(strict_types=1);

namespace Grualink\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Grualink\buildSignatureHeader;
use function Grualink\verifyWebhookSignature;

final class SignatureVectorsTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../contract/signature-vectors.json';

    /**
     * @return array<string, mixed>
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(self::VECTORS), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function vector(string $name): array
    {
        foreach (self::vectors()['cases'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail('The vector ' . $name . ' does not exist.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function cases(): iterable
    {
        $vectors = self::vectors();
        foreach ($vectors['cases'] as $case) {
            yield $case['name'] => [$case, $vectors['tolerance_seconds']];
        }
    }

    public function testTheVectorFileHasTheExpectedShape(): void
    {
        $vectors = self::vectors();
        $cases = $vectors['cases'];
        self::assertSame(1, $vectors['version']);
        self::assertSame(300, $vectors['tolerance_seconds']);
        self::assertGreaterThanOrEqual(30, count($cases));
        // Every case of the file is run: the data provider yields one test per case, keyed by its name.
        self::assertCount(count($cases), iterator_to_array(self::cases()), 'case names are unique');
        self::assertContains(true, array_column(array_column($cases, 'expected'), 'valid'));
        self::assertContains(false, array_column(array_column($cases, 'expected'), 'valid'));
        $covered = array_column(array_column($cases, 'expected'), 'reason');
        foreach ($vectors['reasons'] as $reason) {
            self::assertContains($reason, $covered, 'no case covers the reason ' . $reason);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testVector(array $case, int $toleranceSeconds): void
    {
        $rawBody = base64_decode($case['body_base64'], true);
        self::assertIsString($rawBody);
        if ($case['body'] !== null) {
            self::assertSame($case['body'], $rawBody, 'body and body_base64 agree');
        }

        $result = verifyWebhookSignature(
            rawBody: $rawBody,
            signatureHeader: $case['headers']['x-grualink-signature'] ?? null,
            secret: $case['secret'],
            nowSeconds: $case['now'],
            toleranceSeconds: $toleranceSeconds,
        );

        self::assertSame($case['expected'], $result->toArray(), $case['description']);
    }

    public function testTheReceiverMayHoldTwoSecretsDuringItsOwnRotation(): void
    {
        $case = self::vector('valid_basic');
        $rawBody = (string) base64_decode($case['body_base64'], true);
        $header = $case['headers']['x-grualink-signature'];

        $ok = verifyWebhookSignature($rawBody, $header, ['whsec_TEST_ONLY_FAKE_NEXT', $case['secret']], $case['now']);
        self::assertTrue($ok->valid);

        $none = verifyWebhookSignature($rawBody, $header, [], $case['now']);
        self::assertSame(['valid' => false, 'reason' => 'missing_secret'], $none->toArray());
    }

    public function testVerificationNeverThrowsOnHostileInput(): void
    {
        $hostileHeaders = [
            null,
            42,
            [],
            new \stdClass(),
            str_repeat('x', 100000),
            't=1,v1=' . str_repeat('a', 64) . str_repeat(',', 5000),
            "t=1\n,v1=" . str_repeat('a', 64) . "\n",
        ];
        $checked = 0;
        foreach ($hostileHeaders as $header) {
            foreach ([null, 42, new \stdClass(), 'whsec_TEST_ONLY_FAKE_SECRET_0001'] as $secret) {
                foreach (['{}', null, 42, ['{}']] as $rawBody) {
                    $result = verifyWebhookSignature($rawBody, $header, $secret, 1);
                    self::assertFalse($result->valid);
                    $checked++;
                }
            }
        }
        self::assertSame(112, $checked);
    }

    public function testBuildSignatureHeaderProducesWhatTheVerifierAccepts(): void
    {
        $body = '{"id": "x"}';
        $secrets = ['whsec_TEST_ONLY_FAKE_A', 'whsec_TEST_ONLY_FAKE_B'];
        $header = buildSignatureHeader($secrets, 1795000000, $body);

        self::assertMatchesRegularExpression('/\At=1795000000,v1=[0-9a-f]{64},v1=[0-9a-f]{64}\z/', $header);
        foreach ($secrets as $secret) {
            self::assertTrue(verifyWebhookSignature($body, $header, $secret, 1795000001)->valid);
        }
    }

    public function testTheDefaultClockIsTheSystemClock(): void
    {
        $body = '{"id": "x"}';
        $secret = 'whsec_TEST_ONLY_FAKE_A';

        $fresh = buildSignatureHeader([$secret], time(), $body);
        self::assertSame('ok', verifyWebhookSignature($body, $fresh, $secret)->reason);

        $stale = buildSignatureHeader([$secret], time() - 3600, $body);
        self::assertSame('timestamp_out_of_tolerance', verifyWebhookSignature($body, $stale, $secret)->reason);
    }
}
