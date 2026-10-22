<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetAdminAccess extends Command
{
    protected $signature = 'admin:access {email} {action : grant or revoke}';

    protected $description = 'Grant or revoke web administrator access for an existing user';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->error('Action must be either grant or revoke.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user exists with that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => $action === 'grant'])->save();
        $this->info(sprintf('Administrator access %s for %s.', $action === 'grant' ? 'granted' : 'revoked', $user->email));

        return self::SUCCESS;
    }
}
