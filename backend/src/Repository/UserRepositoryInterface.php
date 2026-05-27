<?php

namespace App\Repository;

use App\Entity\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    public function findByUsername(string $username): ?User;

    public function save(User $user): User;
}
