<?php

namespace App\Filament\Resources\EmailThreadResource\Pages;

use App\Filament\Resources\EmailThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailThreads extends ListRecords
{
    protected static string $resource = EmailThreadResource::class;
}
