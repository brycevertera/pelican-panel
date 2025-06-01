<?php

namespace App\Filament\Admin\Resources\NodeResource\Pages;

use App\Filament\Admin\Resources\NodeResource;
use App\Models\ApiKey;
use App\Models\Node;
use App\Services\Acl\Api\AdminAcl;
use App\Services\Api\KeyCreationService;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\View;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\HtmlString;

class CreateNode extends CreateRecord
{
    protected static string $resource = NodeResource::class;

    protected static bool $canCreateAnother = false;

    private KeyCreationService $keyCreationService;

    public function boot(KeyCreationService $keyCreationService): void
    {
        $this->keyCreationService = $keyCreationService;
    }

    public function local(): void
    {
        $wizard = null;
        foreach ($this->form->getComponents() as $component) {
            if ($component instanceof Wizard) {
                $wizard = $component;
            }
        }

        $wizard->dispatchEvent('wizard::nextStep', $wizard->getStatePath(), 0);
    }

    public function cloud(): void
    {
        $key = ApiKey::query()
            ->where('key_type', ApiKey::TYPE_APPLICATION)
            ->whereJsonContains('permissions->' . Node::RESOURCE_NAME, AdminAcl::READ|AdminAcl::WRITE)
            ->first();

        if (!$key) {
            $key = $this->keyCreationService->setKeyType(ApiKey::TYPE_APPLICATION)->handle([
                'memo' => 'Automatically generated node cloud key.',
                'user_id' => auth()->user()->id,
                'permissions' => [Node::RESOURCE_NAME => AdminAcl::READ|AdminAcl::WRITE],
            ]);
        }

        $vars = [
            'url' => Request::root(),
            'token' => $key->identifier . $key->token,
        ];

        $domain = config('PELICAN_CLOUD_DOMAIN', 'https://hub.pelican.dev');

        $response = Http::post("$domain/api/cloud/start", $vars);

        if ($response->json('error')) {
            $code = $response->json('code');
            Notification::make()
                ->danger()
                ->persistent()
                ->title('Pelican Cloud Error')->body(new HtmlString("
                    It looks like there was a problem communicating with Pelican Cloud!
                    Please make a ticket by <a class='underline text-blue-400' href='https://hub.pelican.dev/tickets?code=$code'>clicking here</a>.
                "))
                ->send()
            ;

            return;
        }

        $uuid = $response->json('uuid');

        $this->redirect("$domain/cloud/$uuid");
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Step::make('select')
                        ->label(trans('admin/node.tabs.select_type'))
                        ->icon('tabler-cloud')
                        ->columns(2)
                        ->schema([
                            View::make('filament.admin.nodes.config.left'),
                            View::make('filament.admin.nodes.config.right'),
                        ])
                    ,
                    Step::make('basic')
                        ->label(trans('admin/node.tabs.basic_settings'))
                        ->icon('tabler-server')
                        ->columnSpanFull()
                        ->columns([
                            'default' => 2,
                            'sm' => 3,
                            'md' => 3,
                            'lg' => 4,
                        ])
                        ->schema([
                            TextInput::make('fqdn')
                                ->columnSpan(2)
                                ->required()
                                ->autofocus()
                                ->live(debounce: 1500)
                                ->rules(Node::getRulesForField('fqdn'))
                                ->prohibited(fn ($state) => is_ip($state) && request()->isSecure())
                                ->label(fn ($state) => is_ip($state) ? trans('admin/node.ip_address') : trans('admin/node.domain'))
                                ->placeholder(fn ($state) => is_ip($state) ? '192.168.1.1' : 'node.example.com')
                                ->helperText(function ($state) {
                                    if (is_ip($state)) {
                                        if (request()->isSecure()) {
                                            return trans('admin/node.fqdn_help');
                                        }

                                        return '';
                                    }

                                    return trans('admin/node.error');
                                })
                                ->hintColor('danger')
                                ->hint(function ($state) {
                                    if (is_ip($state) && request()->isSecure()) {
                                        return trans('admin/node.ssl_ip');
                                    }

                                    return '';
                                })
                                ->afterStateUpdated(function (Set $set, ?string $state) {
                                    $set('dns', null);
                                    $set('ip', null);

                                    [$subdomain] = str($state)->explode('.', 2);
                                    if (!is_numeric($subdomain)) {
                                        $set('name', $subdomain);
                                    }

                                    if (!$state || is_ip($state)) {
                                        $set('dns', null);

                                        return;
                                    }

                                    $validRecords = gethostbynamel($state);
                                    if ($validRecords) {
                                        $set('dns', true);

                                        $set('ip', collect($validRecords)->first());

                                        return;
                                    }

                                    $set('dns', false);
                                })
                                ->maxLength(255),

                            TextInput::make('ip')
                                ->disabled()
                                ->hidden(),

                            ToggleButtons::make('dns')
                                ->label(trans('admin/node.dns'))
                                ->helperText(trans('admin/node.dns_help'))
                                ->disabled()
                                ->inline()
                                ->default(null)
                                ->hint(fn (Get $get) => $get('ip'))
                                ->hintColor('success')
                                ->options([
                                    true => trans('admin/node.valid'),
                                    false => trans('admin/node.invalid'),
                                ])
                                ->colors([
                                    true => 'success',
                                    false => 'danger',
                                ])
                                ->columnSpan([
                                    'default' => 1,
                                    'sm' => 1,
                                    'md' => 1,
                                    'lg' => 1,
                                ]),

                            TextInput::make('daemon_listen')
                                ->columnSpan([
                                    'default' => 1,
                                    'sm' => 1,
                                    'md' => 1,
                                    'lg' => 1,
                                ])
                                ->label(trans('admin/node.port'))
                                ->helperText(trans('admin/node.port_help'))
                                ->minValue(1)
                                ->maxValue(65535)
                                ->default(8080)
                                ->required()
                                ->integer(),

                            TextInput::make('name')
                                ->label(trans('admin/node.display_name'))
                                ->columnSpan([
                                    'default' => 1,
                                    'sm' => 1,
                                    'md' => 1,
                                    'lg' => 2,
                                ])
                                ->required()
                                ->maxLength(100),

                            ToggleButtons::make('scheme')
                                ->label(trans('admin/node.ssl'))
                                ->columnSpan([
                                    'default' => 1,
                                    'sm' => 1,
                                    'md' => 1,
                                    'lg' => 1,
                                ])
                                ->inline()
                                ->helperText(function (Get $get) {
                                    if (request()->isSecure()) {
                                        return new HtmlString(trans('admin/node.panel_on_ssl'));
                                    }

                                    if (is_ip($get('fqdn'))) {
                                        return trans('admin/node.ssl_help');
                                    }

                                    return '';
                                })
                                ->disableOptionWhen(fn (string $value): bool => $value === 'http' && request()->isSecure())
                                ->options([
                                    'http' => 'HTTP',
                                    'https' => 'HTTPS (SSL)',
                                ])
                                ->colors([
                                    'http' => 'warning',
                                    'https' => 'success',
                                ])
                                ->icons([
                                    'http' => 'tabler-lock-open-off',
                                    'https' => 'tabler-lock',
                                ])
                                ->default(fn () => request()->isSecure() ? 'https' : 'http'),
                        ]),
                    Step::make('advanced')
                        ->label(trans('admin/node.tabs.advanced_settings'))
                        ->icon('tabler-server-cog')
                        ->columnSpanFull()
                        ->columns([
                            'default' => 2,
                            'sm' => 3,
                            'md' => 3,
                            'lg' => 4,
                        ])
                        ->schema([
                            ToggleButtons::make('maintenance_mode')
                                ->label(trans('admin/node.maintenance_mode'))->inline()
                                ->columnSpan(1)
                                ->default(false)
                                ->hinticon('tabler-question-mark')
                                ->hintIconTooltip(trans('admin/node.maintenance_mode_help'))
                                ->options([
                                    true => trans('admin/node.enabled'),
                                    false => trans('admin/node.disabled'),
                                ])
                                ->colors([
                                    true => 'danger',
                                    false => 'success',
                                ]),
                            ToggleButtons::make('public')
                                ->default(true)
                                ->columnSpan(1)
                                ->label(trans('admin/node.use_for_deploy'))->inline()
                                ->options([
                                    true => trans('admin/node.yes'),
                                    false => trans('admin/node.no'),
                                ])
                                ->colors([
                                    true => 'success',
                                    false => 'danger',
                                ]),
                            TagsInput::make('tags')
                                ->label(trans('admin/node.tags'))
                                ->columnSpan(2),
                            TextInput::make('upload_size')
                                ->label(trans('admin/node.upload_limit'))
                                ->helperText(trans('admin/node.upload_limit_help.0'))
                                ->hintIcon('tabler-question-mark')
                                ->hintIconTooltip(trans('admin/node.upload_limit_help.1'))
                                ->columnSpan(1)
                                ->numeric()->required()
                                ->default(256)
                                ->minValue(1)
                                ->maxValue(1024)
                                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
                            TextInput::make('daemon_sftp')
                                ->columnSpan(1)
                                ->label(trans('admin/node.sftp_port'))
                                ->minValue(1)
                                ->maxValue(65535)
                                ->default(2022)
                                ->required()
                                ->integer(),
                            TextInput::make('daemon_sftp_alias')
                                ->columnSpan(2)
                                ->label(trans('admin/node.sftp_alias'))
                                ->helperText(trans('admin/node.sftp_alias_help')),
                            Grid::make()
                                ->columns(6)
                                ->columnSpanFull()
                                ->schema([
                                    ToggleButtons::make('unlimited_mem')
                                        ->dehydrated()
                                        ->label(trans('admin/node.memory'))->inlineLabel()->inline()
                                        ->afterStateUpdated(fn (Set $set) => $set('memory', 0))
                                        ->afterStateUpdated(fn (Set $set) => $set('memory_overallocate', 0))
                                        ->formatStateUsing(fn (Get $get) => $get('memory') == 0)
                                        ->live()
                                        ->options([
                                            true => trans('admin/node.unlimited'),
                                            false => trans('admin/node.limited'),
                                        ])
                                        ->colors([
                                            true => 'primary',
                                            false => 'warning',
                                        ])
                                        ->columnSpan(2),
                                    TextInput::make('memory')
                                        ->dehydratedWhenHidden()
                                        ->hidden(fn (Get $get) => $get('unlimited_mem'))
                                        ->label(trans('admin/node.memory_limit'))->inlineLabel()
                                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->required(),
                                    TextInput::make('memory_overallocate')
                                        ->dehydratedWhenHidden()
                                        ->label(trans('admin/node.overallocate'))->inlineLabel()
                                        ->hidden(fn (Get $get) => $get('unlimited_mem'))
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->minValue(-1)
                                        ->maxValue(100)
                                        ->default(0)
                                        ->suffix('%')
                                        ->required(),
                                ]),
                            Grid::make()
                                ->columns(6)
                                ->columnSpanFull()
                                ->schema([
                                    ToggleButtons::make('unlimited_disk')
                                        ->dehydrated()
                                        ->label(trans('admin/node.disk'))->inlineLabel()->inline()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('disk', 0))
                                        ->afterStateUpdated(fn (Set $set) => $set('disk_overallocate', 0))
                                        ->formatStateUsing(fn (Get $get) => $get('disk') == 0)
                                        ->options([
                                            true => trans('admin/node.unlimited'),
                                            false => trans('admin/node.limited'),
                                        ])
                                        ->colors([
                                            true => 'primary',
                                            false => 'warning',
                                        ])
                                        ->columnSpan(2),
                                    TextInput::make('disk')
                                        ->dehydratedWhenHidden()
                                        ->hidden(fn (Get $get) => $get('unlimited_disk'))
                                        ->label(trans('admin/node.disk_limit'))->inlineLabel()
                                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(0)
                                        ->required(),
                                    TextInput::make('disk_overallocate')
                                        ->dehydratedWhenHidden()
                                        ->hidden(fn (Get $get) => $get('unlimited_disk'))
                                        ->label(trans('admin/node.overallocate'))->inlineLabel()
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->minValue(-1)
                                        ->maxValue(100)
                                        ->default(0)
                                        ->suffix('%')
                                        ->required(),
                                ]),
                            Grid::make()
                                ->columns(6)
                                ->columnSpanFull()
                                ->schema([
                                    ToggleButtons::make('unlimited_cpu')
                                        ->dehydrated()
                                        ->label(trans('admin/node.cpu'))->inlineLabel()->inline()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('cpu', 0))
                                        ->afterStateUpdated(fn (Set $set) => $set('cpu_overallocate', 0))
                                        ->formatStateUsing(fn (Get $get) => $get('cpu') == 0)
                                        ->options([
                                            true => trans('admin/node.unlimited'),
                                            false => trans('admin/node.limited'),
                                        ])
                                        ->colors([
                                            true => 'primary',
                                            false => 'warning',
                                        ])
                                        ->columnSpan(2),
                                    TextInput::make('cpu')
                                        ->dehydratedWhenHidden()
                                        ->hidden(fn (Get $get) => $get('unlimited_cpu'))
                                        ->label(trans('admin/node.cpu_limit'))->inlineLabel()
                                        ->suffix('%')
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->required(),
                                    TextInput::make('cpu_overallocate')
                                        ->dehydratedWhenHidden()
                                        ->hidden(fn (Get $get) => $get('unlimited_cpu'))
                                        ->label(trans('admin/node.overallocate'))->inlineLabel()
                                        ->columnSpan(2)
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(-1)
                                        ->maxValue(100)
                                        ->suffix('%')
                                        ->required(),
                                ]),
                        ]),
                ])
                    ->columnSpanFull()
                    ->nextAction(function (Action $action, Wizard $wizard) {
                        if ($wizard->getCurrentStepIndex() === 0) {
                            return $action->label(trans('admin/node.next_step'))->view('filament.admin.nodes.config.empty');
                        }

                        return $action->label(trans('admin/node.next_step'));
                    })
                    ->submitAction(new HtmlString(Blade::render(
                        <<<'BLADE'
                            <x-filament::button
                                type="submit"
                                size="sm"
                            >
                                Create Node
                            </x-filament::button>
                        BLADE
                    ))),
            ]);
    }

    protected function getRedirectUrlParameters(): array
    {
        return [
            'tab' => '-configuration-file-tab',
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
