<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Enums\BranchAccessScope;
use App\Enums\BusinessRole;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SetupBookingAdmin extends Command
{
    protected $signature = 'booking:setup-admin';

    protected $description = 'Create the first business and its administrator account.';

    public function handle(): int
    {
        if (Business::query()->exists() || User::query()->exists()) {
            $this->components->error('Setup stopped: a business or user already exists. Use the existing account or provision membership explicitly.');

            return self::FAILURE;
        }

        $businessName = trim((string) $this->ask('Business name'));
        $slug = Str::slug((string) $this->ask('Business slug', Str::slug($businessName)));
        $timezone = trim((string) $this->ask('Business timezone', 'Asia/Riyadh'));
        $adminName = trim((string) $this->ask('Administrator name'));
        $email = strtolower(trim((string) $this->ask('Administrator email')));

        if ($businessName === '' || $slug === '' || $adminName === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Business name, slug, administrator name, and a valid email are required.');

            return self::FAILURE;
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->components->error('Use a valid IANA timezone, such as Asia/Riyadh.');

            return self::FAILURE;
        }

        $password = $this->secret('Administrator password (at least 12 characters)');

        if (strlen($password) < 12 || $password !== $this->secret('Confirm administrator password')) {
            $this->components->error('Passwords must match and contain at least 12 characters.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($businessName, $slug, $timezone, $adminName, $email, $password): void {
            $business = Business::query()->create([
                'name' => $businessName,
                'slug' => $slug,
                'timezone' => $timezone,
                'default_currency' => 'SAR',
                'is_active' => true,
            ]);

            $user = User::query()->create([
                'name' => $adminName,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            $business->users()->attach($user, ['role' => BusinessRole::Owner->value, 'access_scope' => BranchAccessScope::All->value]);
        });

        $this->components->info('Administrator and business created. Open /admin and sign in with the email you entered.');

        return self::SUCCESS;
    }
}