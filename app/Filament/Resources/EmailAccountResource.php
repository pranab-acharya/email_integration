<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailAccountResource\Pages;
use App\Models\EmailAccount;
use App\Services\OutlookSubscriptionService;
use Exception;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmailAccountResource extends Resource
{
    protected static ?string $model = EmailAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->disabled(),
            Forms\Components\TextInput::make('provider')->disabled(),
            Forms\Components\TextInput::make('name')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'google' => 'success',
                        'outlook' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('connect_google')
                    ->label('Connect Gmail')
                    ->icon('heroicon-o-plus')
                    ->color('success')
                    ->url('/auth/google'),
                Tables\Actions\Action::make('connect_outlook')
                    ->label('Connect Outlook')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url('/auth/microsoft'),
                Tables\Actions\Action::make('compose_email')
                    ->label('Compose')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(static::getUrl('compose')),
                Tables\Actions\Action::make('test_notification')
                    ->label('Test Notification')
                    ->icon('heroicon-o-plus')
                    ->color('success')
                    ->action(function () {
                        Log::info('working');
                        Notification::make()
                            ->title('Test Notification')
                            ->body('This is a test notification for test')
                            ->success()
                            // ->send();
                            ->broadcast(Filament::auth()->user());
                    })
            ])
            ->actions([
                Tables\Actions\Action::make('subscribe')
                    ->label('Subscribe PUSH')
                    ->icon('heroicon-o-bell')
                    ->color('success')
                    ->hidden(fn ($record) => $record->provider === 'outlook' && $record->has_active_subscription)
                    ->requiresConfirmation()
                    ->modalHeading('Subscribe to Outlook Webhook')
                    ->modalDescription('This will set up push notifications for this Outlook account. The process will run in the background.')
                    ->modalSubmitActionLabel('Subscribe')
                    ->action(function ($record) {
                        try {
                            // Dispatch the job to the queue
                            \App\Jobs\SubscribeToOutlookWebhook::dispatch(
                                $record,
                                Auth::user()->id
                            );

                            Notification::make()
                                ->title('Subscription Queued')
                                ->body('The Outlook webhook subscription has been queued for processing. You will receive a notification when it completes.')
                                ->info()
                                ->icon('heroicon-o-clock')
                                ->duration(5000) // Show for 5 seconds
                                ->send();
                        } catch (Exception $e) {
                            Log::error('Failed to queue Outlook subscription job: ' . $e->getMessage());

                            Notification::make()
                                ->title('Error')
                                ->body('Failed to queue the subscription process. Please try again.')
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('unsubscribe')
                    ->label('Unsubscribe PUSH')
                    ->visible(fn ($record) => $record->provider === 'outlook' && $record->has_active_subscription)
                    ->action(function ($record) {
                        $subscriptionService = (new OutlookSubscriptionService);
                        $result = $subscriptionService->deleteSubscription($record->activeOutlookSubscription());

                        if ($result['success']) {
                            Notification::make()
                                ->title('Success')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Error')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListEmailAccounts::route('/'),
            'compose' => Pages\ComposeEmail::route('/compose-email'),
        ];
    }
}
