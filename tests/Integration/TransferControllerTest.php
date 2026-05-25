<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\User;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TransferControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $jwt;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        // Reset schema for each test
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Provision an authenticated API consumer for every test
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $jwtManager = $container->get(JWTTokenManagerInterface::class);

        $user = new User('test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->jwt = $jwtManager->create($user);
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
        ], $extra);
    }

    private function createAccount(string $number, string $currency, string $balance): Account
    {
        $account = new Account($number, $currency, $balance);
        $this->em->persist($account);
        $this->em->flush();
        return $account;
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'fromAccount' => 'ACC-001',
                'toAccount' => 'ACC-002',
                'amountMinor' => '2500',
                'currency' => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSuccessfulTransfer(): void
    {
        $this->createAccount('ACC-001', 'USD', '10000'); // $100
        $this->createAccount('ACC-002', 'USD', '0');

        $this->client->request(
            'POST',
            '/api/v1/transfers',
            server: $this->authHeaders(),
            content: json_encode([
                'fromAccount' => 'ACC-001',
                'toAccount' => 'ACC-002',
                'amountMinor' => '2500',
                'currency' => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('completed', $body['status']);

        $this->em->clear();
        /** @var AccountRepository $repo */
        $repo = $this->em->getRepository(Account::class);
        $this->assertSame('7500', $repo->findOneByAccountNumber('ACC-001')->getBalanceMinor());
        $this->assertSame('2500', $repo->findOneByAccountNumber('ACC-002')->getBalanceMinor());
    }

    public function testInsufficientFundsIsRejected(): void
    {
        $this->createAccount('ACC-001', 'USD', '100');
        $this->createAccount('ACC-002', 'USD', '0');

        $this->client->request(
            'POST',
            '/api/v1/transfers',
            server: $this->authHeaders(),
            content: json_encode([
                'fromAccount' => 'ACC-001',
                'toAccount' => 'ACC-002',
                'amountMinor' => '5000',
                'currency' => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('INSUFFICIENT_FUNDS', $body['error']);

        $this->em->clear();
        $repo = $this->em->getRepository(Account::class);
        $this->assertSame('100', $repo->findOneByAccountNumber('ACC-001')->getBalanceMinor());
        $this->assertSame('0', $repo->findOneByAccountNumber('ACC-002')->getBalanceMinor());
    }

    public function testIdempotentReplayReturnsSameResult(): void
    {
        $this->createAccount('ACC-001', 'USD', '10000');
        $this->createAccount('ACC-002', 'USD', '0');

        $payload = json_encode([
            'fromAccount' => 'ACC-001',
            'toAccount' => 'ACC-002',
            'amountMinor' => '1000',
            'currency' => 'USD',
        ]);
        $headers = $this->authHeaders(['HTTP_IDEMPOTENCY_KEY' => 'test-key-' . uniqid()]);

        $this->client->request('POST', '/api/v1/transfers', server: $headers, content: $payload);
        $this->assertResponseStatusCodeSame(201);
        $first = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/v1/transfers', server: $headers, content: $payload);
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replay'));
        $second = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame($first['transactionId'], $second['transactionId']);

        $this->em->clear();
        $repo = $this->em->getRepository(Account::class);
        // Only ONE debit happened despite two requests
        $this->assertSame('9000', $repo->findOneByAccountNumber('ACC-001')->getBalanceMinor());
    }

    public function testValidationRejectsBadPayload(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfers',
            server: $this->authHeaders(),
            content: json_encode([
                'fromAccount' => '',
                'toAccount' => 'ACC-002',
                'amountMinor' => '-50',
                'currency' => 'usd',
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_FAILED', $body['error']);
    }
}

