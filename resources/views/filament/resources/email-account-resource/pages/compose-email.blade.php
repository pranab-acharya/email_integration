<x-filament-panels::page>
    <form wire:submit="sendEmail">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button size="md" color="success" icon="heroicon-o-paper-airplane" wire:click="sendEmail">
                Send Email
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
