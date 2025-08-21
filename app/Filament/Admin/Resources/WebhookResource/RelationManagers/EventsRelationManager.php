<?php

namespace App\Filament\Admin\Resources\WebhookResource\RelationManagers;

use App\Models\WebhookConfiguration;
use App\Models\WebhookEvent;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * @method WebhookConfiguration getOwnerRecord()
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'webhookEvents';

    public function table(Table $table): Table
    {
        return $table
            ->heading('')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('name'),
            ])
            ->headerActions([
                Action::make('create')
                    ->form(fn () => [
                        TextInput::make('name')
                            ->inlineLabel()
                            ->live()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $id = WebhookEvent::firstOrCreate([
                            'name' => $data['name'],
                        ]);
                        $this->getOwnerRecord()->webhookEvents()->sync([$id]);
                    }),
            ])
            ->actions([
                DeleteAction::make()
                    ->authorize(fn (WebhookConfiguration $config) => auth()->user()->can('delete', $config)),
            ]);
    }
}
