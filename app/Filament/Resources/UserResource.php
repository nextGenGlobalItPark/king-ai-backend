<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users';

    /**
     * Form layout notes:
     * - On CREATE: admins enter only name + email + role. Password is generated
     *   automatically by CreateUser (PasswordChangeService) and emailed to
     *   the user; the user is forced to change it on first login. The
     *   plaintext password is also displayed once on the success
     *   notification so the admin can pass it along out-of-band.
     *   `must_change_password` is set true and `email_verified_at` is left
     *   null so the verification flow runs.
     * - On EDIT: name + email + roles are editable. Password and verification
     *   state are NOT editable from this form — admins can resend the
     *   verification email via the row action below; password resets are
     *   intentionally not exposed here yet (would be a separate feature).
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('User Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        // Role assignment. Uses Filament's relationship()
                        // helper so the BelongsToMany pivot is handled
                        // automatically. We use `->relationship('roles', 'name')`
                        // which displays the Role.name and stores Role.id in
                        // model_has_roles. On SAVE, Filament's RelationshipsRepository
                        // calls syncRoles equivalent under the hood.
                        //
                        // Single-value (not multiple) because a user has
                        // exactly one role in this app's design. If you ever
                        // want multi-role users, change this to ->multiple().
                        Forms\Components\Select::make('roles')
                            ->label('Role')
                            ->relationship('roles', 'name', fn ($query) => $query->where('guard_name', 'web'))
                            ->preload()
                            ->required()
                            ->default(fn () => \Spatie\Permission\Models\Role::where('name', User::ROLE_USER)
                                ->where('guard_name', 'web')
                                ->value('id'))
                            ->helperText('Admin: full panel + API access. User: API only, own resources.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->colors([
                        'danger' => User::ROLE_ADMIN,
                        'gray' => User::ROLE_USER,
                    ])
                    ->sortable(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->sortable(),
                Tables\Columns\IconColumn::make('must_change_password')
                    ->label('Temp pw')
                    ->boolean()
                    ->trueIcon('heroicon-o-key')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->must_change_password
                        ? 'User must change their temporary password on next login'
                        : null),
                // 2FA indicator: green shield if confirmed, dash if not. We
                // intentionally don't expose the secret/codes here — just whether
                // it's on. Sortable on confirmed_at so admins can find users
                // missing 2FA quickly.
                Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
                Tables\Filters\SelectFilter::make('role')
                    ->label('Role')
                    ->relationship('roles', 'name', fn ($query) => $query->where('guard_name', 'web'))
                    ->preload(),
                Tables\Filters\Filter::make('verified')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('email_verified_at')),
                Tables\Filters\Filter::make('unverified')
                    ->query(fn (Builder $query): Builder => $query->whereNull('email_verified_at')),
                Tables\Filters\Filter::make('two_factor_enabled')
                    ->label('2FA enabled')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('two_factor_confirmed_at')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Resend verification email — useful when the user lost the
                // original mail or the code expired. Hidden when the user is
                // already verified.
                Tables\Actions\Action::make('resendVerification')
                    ->label('Resend verification')
                    ->icon('heroicon-o-envelope')
                    ->color('warning')
                    ->visible(fn (User $record): bool => is_null($record->email_verified_at))
                    ->action(function (User $record, EmailVerificationService $verifier) {
                        try {
                            $verifier->sendCode($record);
                            Notification::make()
                                ->title('Verification email sent')
                                ->body("A new code has been sent to {$record->email}.")
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            $remaining = $verifier->cooldownSecondsRemaining($record);
                            Notification::make()
                                ->title('Please wait')
                                ->body("Cooldown active. Try again in {$remaining} seconds.")
                                ->warning()
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error('Resend verification failed', [
                                'admin_id' => auth()->id(),
                                'target_id' => $record->id,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Could not send email')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Regenerate temp password — for the case where the original
                // temp-password email got lost. Only visible when the user
                // hasn't yet set their own password (must_change_password = true);
                // showing it for normal users would be a footgun, since
                // clicking it would forcibly lock them out.
                //
                // The new plaintext password is shown ONCE on a persistent
                // success notification so the admin can copy and share it
                // out-of-band. A new email is also dispatched.
                Tables\Actions\Action::make('regenerateTempPassword')
                    ->label('Regenerate temp password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (User $record): bool => (bool) $record->must_change_password)
                    ->requiresConfirmation()
                    ->modalHeading('Regenerate temporary password?')
                    ->modalDescription(fn (User $record) => "This will replace {$record->email}'s temporary password and email them the new one. The old temporary password will stop working immediately.")
                    ->modalSubmitActionLabel('Regenerate')
                    ->action(function (User $record, UserService $userService) {
                        try {
                            $newTempPassword = $userService->regenerateTemporaryPassword($record);

                            Log::info('Admin regenerated temporary password', [
                                'admin_id' => auth()->id(),
                                'admin_email' => auth()->user()->email,
                                'target_user_id' => $record->id,
                                'target_email' => $record->email,
                            ]);

                            Notification::make()
                                ->title('Temporary password regenerated')
                                ->body("A new temp password has been emailed to {$record->email}.\n\nTemporary password (will not be shown again): {$newTempPassword}")
                                ->success()
                                ->persistent()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title('Cannot regenerate')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        } catch (\Throwable $e) {
                            Log::error('Regenerate temp password failed', [
                                'admin_id' => auth()->id(),
                                'target_id' => $record->id,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->title('Could not regenerate password')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // Reset 2FA — admin-only override for users who lost their
                // authenticator AND their recovery codes. This wipes the
                // secret, codes, and confirmed_at, allowing the user to log
                // in with just password and re-enroll. Logged for audit.
                Tables\Actions\Action::make('resetTwoFactor')
                    ->label('Reset 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (User $record): bool => ! is_null($record->two_factor_confirmed_at))
                    ->requiresConfirmation()
                    ->modalHeading('Reset two-factor authentication?')
                    ->modalDescription(fn (User $record) => "This will disable 2FA for {$record->email}. They will be able to log in with just their password until they re-enable it.")
                    ->modalSubmitActionLabel('Reset 2FA')
                    ->action(function (User $record, TwoFactorService $twoFactor) {
                        $twoFactor->disable($record);

                        Log::warning('Admin reset 2FA for user', [
                            'admin_id' => auth()->id(),
                            'admin_email' => auth()->user()->email,
                            'target_user_id' => $record->id,
                            'target_email' => $record->email,
                        ]);

                        Notification::make()
                            ->title('2FA disabled')
                            ->body("Two-factor authentication has been reset for {$record->email}.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}