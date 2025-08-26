<?php

namespace App\Filament\Resources\EmailAccountResource\Pages;

use App\Filament\Resources\EmailAccountResource;
use App\Models\EmailAccount;
use App\Services\EmailService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ComposeEmail extends Page
{
    protected static string $resource = EmailAccountResource::class;
    protected static string $view = 'filament.resources.email-account-resource.pages.compose-email';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('email_account_id')
                    ->label('From Account')
                    ->options(
                        EmailAccount::where('user_id', Filament::auth()->user()->id)
                            ->pluck('email', 'id')
                    )
                    ->required(),

                TagsInput::make('to')
                    ->label('To')
                    ->placeholder('Enter email addresses')
                    ->required(),

                TextInput::make('subject')
                    ->required(),

                RichEditor::make('body')
                    ->required()
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function sendEmail(): void
    {
        $data = $this->form->getState();

        $account = EmailAccount::findOrFail($data['email_account_id']);
        $emailService = new EmailService();

        $emailData = [
            'to' => $data['to'],
            'subject' => $data['subject'],
            'body' => $data['body'],
        ];

        if ($emailService->sendEmail($account, $emailData)) {
            Notification::make()
                ->title('Email sent successfully!')
                ->success()
                ->send();

            $this->form->fill(); // Clear form
        } else {
            Notification::make()
                ->title('Failed to send email')
                ->danger()
                ->send();
        }
    }
}
