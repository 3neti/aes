<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('election:research:create-reviewer {name} {email}')]
#[Description('Create a named AES research reviewer account')]
class CreateResearchReviewer extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::query()->where('email', $email)->exists()) {
            $this->error('Provide a valid email address not already assigned to an account.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Reviewer password');

        if (strlen($password) < 12) {
            $this->error('Reviewer password must have at least 12 characters.');

            return self::FAILURE;
        }

        $user = new User;
        $user->name = (string) $this->argument('name');
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->research_reviewer = true;
        $user->save();
        $this->info("Created research reviewer {$email}.");

        return self::SUCCESS;
    }
}
