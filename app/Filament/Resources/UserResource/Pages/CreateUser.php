<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\UserService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

/**
 * Admin user creation flow.
 *
 * Delegates the actual work (generating a temp password, persisting the
 * user, sending the two onboarding emails) to UserService so the same
 * logic is shared with UserController::store (admin API). This page
 * adds only the UI concerns: capturing the plaintext temp password
 * and surfacing it once on a persistent success notification so the
 * admin can copy it before navigating away.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** Captured during handleRecordCreation so getSavedNotification can show it. */
    protected ?string $generatedPassword = null;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        try {
            [$user, $tempPassword] = app(UserService::class)
                ->createUserWithTemporaryPassword($data);

            $this->generatedPassword = $tempPassword;

            return $user;
        } catch (\Exception $e) {
            Log::error('Failed to create User: ' . $e->getMessage(), [
                'data' => array_diff_key($data, ['password' => null]),
                'admin_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Creation Failed')
                ->body('An error occurred while creating the user: ' . $e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }

    /**
     * Override the default "User created" toast with one showing the
     * plaintext temp password. Marked persistent() so the admin has time
     * to copy it before dismissing — Filament's default success toast
     * disappears after a few seconds, which is too short for that.
     */
    protected function getSavedNotification(): ?Notification
    {
        $body = "User \"{$this->record->name}\" was created. Two emails were sent: "
            . 'a verification code and the temporary password.';

        if ($this->generatedPassword) {
            $body .= "\n\nTemporary password (will not be shown again): "
                . $this->generatedPassword;
        }

        return Notification::make()
            ->title('User created')
            ->body($body)
            ->success()
            ->persistent();
    }
}