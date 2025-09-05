<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailThreadResource\Pages;
use App\Filament\Resources\EmailThreadResource\RelationManagers\MessagesRelationManager;
use App\Models\EmailAccount;
use App\Models\EmailThread;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

class EmailThreadResource extends Resource
{
    protected static ?string $model = EmailThread::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Email';

    protected static ?string $navigationLabel = 'Threads';

    protected static ?int $navigationSort = 10;

    public static function form(Forms\Form $form): Forms\Form
    {
        // Read-only resource; no form needed for create/edit
        return $form;
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query(EmailThread::query()->where('originated_via_app', true))
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('account.email')
                    ->label('Account')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('participants')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                    ->label('Participants'),
                TextColumn::make('last_message_at')
                    ->label('Last Message')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('external_thread_id')
                    ->label('Thread ID')
                    ->toggleable()
                    ->limit(15)
                    ->copyable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options([
                        'google' => 'Google',
                        'microsoft' => 'Microsoft',
                    ]),
                SelectFilter::make('email_account_id')
                    ->label('Account')
                    ->options(fn () => EmailAccount::query()->pluck('email', 'id')->all()),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailThreads::route('/'),
            'view' => Pages\ViewEmailThread::route('/{record}'),
        ];
    }
}
