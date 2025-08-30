<?php

namespace App\Filament\Resources\EmailThreadResource\RelationManagers;

use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Messages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('direction')
                    ->badge()
                    ->label('Direction')
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => $state === 'outgoing' ? 'success' : 'warning'),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->wrap()
                    ->limit(60)
                    // ->description(fn ($record): HtmlString => new HtmlString($record->body_html))
                    ->searchable(),
                TextColumn::make('from_email')
                    ->label('From')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('to')
                    ->label('To')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : ($state ?? ''))
                    ->wrap()
                    ->limit(60),
                TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('received_at')
                    ->label('Received At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->poll()
            ->actions([
                ViewAction::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->infolist([
                        TextEntry::make('body_html')
                            ->label('Body')
                            ->html(),
                    ]),
            ]);
    }
}
