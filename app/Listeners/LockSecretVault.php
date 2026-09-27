<?php

namespace App\Listeners;

use App\SecretVault;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Events\Windows\WindowHidden;

class LockSecretVault
{
    public function __construct(private SecretVault $vault) {}

    public function handle(WindowClosed|WindowHidden $event): void
    {
        $this->vault->revokeUnlocks();
    }
}
