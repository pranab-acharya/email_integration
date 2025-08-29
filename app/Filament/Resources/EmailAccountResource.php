<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailAccountResource\Pages;
use App\Models\EmailAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
            ])
            ->actions([
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
