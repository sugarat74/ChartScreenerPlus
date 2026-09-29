<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Out-of-band grant of the admin role. Admin is never self-service and never
 * settable over HTTP; an operator promotes an existing account with this
 * command.
 */
class MakeAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:make-admin {email : Email of the existing account to promote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant the admin role to an existing account (out of band)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account found for [{$email}].");

            return self::FAILURE;
        }

        // `role` is intentionally not fillable; forceFill documents that this
        // is a deliberate, out-of-band bypass of the mass-assignment guard.
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        $this->info("Granted admin to {$user->email}.");

        return self::SUCCESS;
    }
}
