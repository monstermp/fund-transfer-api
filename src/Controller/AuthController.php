<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
class AuthController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    /**
     * Register a new API consumer and immediately return a JWT
     * so they can call protected endpoints without a second round-trip.
     */
    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $payload,
    ): JsonResponse {
        if ($this->users->findOneByEmail($payload->email) !== null) {
            return new JsonResponse([
                'error' => 'EMAIL_ALREADY_REGISTERED',
                'message' => 'A user with this email already exists',
            ], 409);
        }

        $user = new User($payload->email);
        $user->setPassword($this->hasher->hashPassword($user, $payload->password));

        $this->em->persist($user);
        $this->em->flush();

        return new JsonResponse([
            'userId' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'token' => $this->jwtManager->create($user),
        ], 201);
    }
}
