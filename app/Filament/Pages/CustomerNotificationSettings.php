<?php

namespace App\Filament\Pages;

use App\Domain\Authorization\BusinessAccess;
use App\Domain\Notifications\CustomerNotificationService;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\CustomerNotification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CustomerNotificationSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Booking Management';

    protected static ?string $navigationLabel = null;

    protected static ?string $slug = 'customer-notifications';

    protected static string $view = 'filament.pages.customer-notification-settings';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $business = Filament::getTenant();

        return $user instanceof User
            && $business instanceof Business
            && app(BusinessAccess::class)->role($user, $business) === BusinessRole::Owner;
    }

    public array $channels = [];

    public array $credentials = [];

    public array $rules = [];

    public array $templates = [];

    public string $historyStatus = '';

    public string $historyEvent = '';

    public function mount(CustomerNotificationService $notifications): void
    {
        $this->authorizeOwner();
        $config = $notifications->settings($this->business())->settings;

        foreach (CustomerNotificationService::CHANNELS as $channel) {
            $channelSettings = $config['channels'][$channel] ?? [];
            $this->channels[$channel] = (bool) ($channelSettings['enabled'] ?? false);
            $credentials = $channelSettings['credentials'] ?? [];
            $this->credentials[$channel] = match ($channel) {
                'email' => [
                    'host' => $credentials['host'] ?? '',
                    'port' => $credentials['port'] ?? 587,
                    'encryption' => $credentials['encryption'] ?? 'starttls',
                    'username' => $credentials['username'] ?? '',
                    'password' => '',
                    'from_address' => $credentials['from_address'] ?? '',
                    'from_name' => $credentials['from_name'] ?? '',
                ],
                default => [
                    'account_sid' => $credentials['account_sid'] ?? '',
                    'auth_token' => '',
                    'from' => $credentials['from'] ?? '',
                ],
            };
        }

        foreach (CustomerNotificationService::EVENTS as $event) {
            $rule = $config['rules'][$event] ?? [];
            $formEvent = str_replace('.', '_', $event);
            $this->rules[$formEvent] = [
                'enabled' => (bool) ($rule['enabled'] ?? false),
                'channels' => array_pad(array_slice($rule['channels'] ?? [], 0, count(CustomerNotificationService::CHANNELS)), count(CustomerNotificationService::CHANNELS), ''),
                'lead_minutes' => $rule['lead_minutes'] ?? 1440,
            ];
            $this->templates[$formEvent] = $config['templates'][$event] ?? [];
        }
    }

    public function getTitle(): string
    {
        return __('notifications.customer_title');
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.customer_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.booking_management');
    }

    public function save(CustomerNotificationService $notifications): void
    {
        $this->authorizeOwner();
        $this->validate([
            'channels.*' => ['required', 'boolean'],
            'credentials.email.host' => ['nullable', 'string', 'max:255'],
            'credentials.email.port' => ['nullable', 'integer', 'between:1,65535'],
            'credentials.email.encryption' => ['required', 'in:starttls,ssl,none'],
            'credentials.email.username' => ['nullable', 'string', 'max:255'],
            'credentials.email.password' => ['nullable', 'string', 'max:1000'],
            'credentials.email.from_address' => ['nullable', 'email', 'max:255'],
            'credentials.email.from_name' => ['nullable', 'string', 'max:150'],
            'credentials.sms.account_sid' => ['nullable', 'string', 'max:100'],
            'credentials.sms.auth_token' => ['nullable', 'string', 'max:255'],
            'credentials.sms.from' => ['nullable', 'string', 'max:50'],
            'credentials.whatsapp.account_sid' => ['nullable', 'string', 'max:100'],
            'credentials.whatsapp.auth_token' => ['nullable', 'string', 'max:255'],
            'credentials.whatsapp.from' => ['nullable', 'string', 'max:50'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.channels' => ['nullable'],
            'rules.*.channels.*' => ['nullable', 'string', 'in:email,sms,whatsapp'],
            'rules.*.lead_minutes' => ['nullable', 'integer', 'between:1,525600'],
            'templates.*.*.enabled' => ['required', 'boolean'],
            'templates.*.*.subject' => ['nullable', 'string', 'max:255'],
            'templates.*.*.body' => ['nullable', 'string', 'max:5000'],
            'templates.*.*.content_sid' => ['nullable', 'regex:/^$|^HX[a-fA-F0-9]{32}$/'],
        ]);

        $settingsModel = $notifications->settings($this->business());
        $config = $settingsModel->settings;

        foreach (CustomerNotificationService::CHANNELS as $channel) {
            $config['channels'][$channel]['enabled'] = (bool) ($this->channels[$channel] ?? false);
            $input = $this->credentials[$channel] ?? [];
            $existing = $config['channels'][$channel]['credentials'] ?? [];
            $previousCredentials = $existing;
            foreach ($input as $key => $value) {
                if ($key === 'password' || $key === 'auth_token') {
                    if ($value !== '') {
                        $existing[$key] = $value;
                    }
                } else {
                    $existing[$key] = $value;
                }
            }
            $config['channels'][$channel]['credentials'] = $existing;
            if ($existing !== $previousCredentials) {
                unset($config['channels'][$channel]['suspended_at']);
            }
            $config['channels'][$channel]['connection_status'] = $this->connectionStatus($channel, $existing);
            $config['channels'][$channel]['last_checked_at'] = now()->toIso8601String();
        }

        foreach (CustomerNotificationService::EVENTS as $event) {
            $formEvent = str_replace('.', '_', $event);
            $ruleInput = $this->rules[$formEvent] ?? $this->rules[$event] ?? [];
            $templateInput = $this->templates[$formEvent] ?? $this->templates[$event] ?? [];
            $channelInput = $ruleInput['channels'] ?? [];
            $channelInput = is_array($channelInput) ? $channelInput : explode(',', (string) $channelInput);
            $channels = array_values(array_filter(array_map(
                fn (mixed $channel): string => strtolower(trim((string) $channel)),
                $channelInput,
            ), fn (string $channel): bool => $channel !== ''));
            if (count($channels) !== count(array_unique($channels)) || array_diff($channels, CustomerNotificationService::CHANNELS) !== []) {
                throw ValidationException::withMessages(["rules.{$formEvent}.channels" => ['Choose each fallback channel once, in the order it should be tried.']]);
            }

            $config['rules'][$event] = [
                'enabled' => (bool) ($ruleInput['enabled'] ?? false),
                'channels' => $channels,
                'lead_minutes' => $event === 'booking.reminder' ? (int) ($ruleInput['lead_minutes'] ?? 1440) : null,
            ];

            foreach (CustomerNotificationService::CHANNELS as $channel) {
                $template = $templateInput[$channel] ?? [];
                $this->validateTemplate($formEvent, $channel, $template);
                $config['templates'][$event][$channel] = [
                    'enabled' => (bool) ($template['enabled'] ?? false),
                    'subject' => (string) ($template['subject'] ?? ''),
                    'body' => (string) ($template['body'] ?? ''),
                    'content_sid' => (string) ($template['content_sid'] ?? ''),
                ];
            }
        }

        $settingsModel->settings = $config;
        $settingsModel->updated_by = Filament::auth()->id();
        $settingsModel->save();

        Notification::make()->title(__('notifications.settings_saved'))->success()->send();
    }

    public function resend(string $notificationId, CustomerNotificationService $notifications): void
    {
        $this->authorizeOwner();
        $notification = CustomerNotification::query()
            ->where('business_id', $this->business()->getKey())
            ->whereKey($notificationId)
            ->firstOrFail();
        $notifications->resend($notification, Filament::auth()->user());

        Notification::make()->title(__('notifications.resend_queued'))->success()->send();
    }

    public function table(Table $table): Table
    {
        $statuses = ['queued', 'processing', 'retry_wait', 'accepted', 'delivered', 'failed', 'unknown', 'superseded', 'skipped'];

        return $table
            ->query(CustomerNotification::query()
                ->where('business_id', $this->business()->getKey())
                ->with(['customer:id,name,email,phone', 'booking:id,reference', 'attempts'])
                ->when(in_array($this->historyStatus, $statuses, true), fn ($query) => $query->where('status', $this->historyStatus))
                ->when(in_array($this->historyEvent, CustomerNotificationService::EVENTS, true), fn ($query) => $query->where('event_type', $this->historyEvent))
                ->latest('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('booking.reference')
                    ->label(__('notifications.settings.booking'))
                    ->placeholder(__('notifications.settings.unavailable'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label(__('notifications.settings.customer'))
                    ->placeholder(__('notifications.settings.unavailable')),
                Tables\Columns\TextColumn::make('event_type')
                    ->label(__('notifications.settings.event'))
                    ->formatStateUsing(fn (string $state): string => __('notifications.events.'.str_replace('.', '_', $state))),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('notifications.settings.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('notifications.provider_statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'delivered' => 'success',
                        'failed' => 'danger',
                        'accepted' => 'primary',
                        'retry_wait', 'queued', 'processing' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('notifications.settings.created'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('notifications.settings.filter_status'))
                    ->options(collect($statuses)->mapWithKeys(fn (string $status): array => [$status => __('notifications.provider_statuses.'.$status)])),
                Tables\Filters\SelectFilter::make('event_type')
                    ->label(__('notifications.settings.filter_event'))
                    ->options(collect(CustomerNotificationService::EVENTS)->mapWithKeys(fn (string $event): array => [
                        $event => __('notifications.events.'.str_replace('.', '_', $event)),
                    ])),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->label(__('notifications.settings.resend'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (CustomerNotification $record): bool => $record->status === 'failed')
                    ->action(fn (CustomerNotification $record) => $this->resend((string) $record->getKey(), app(CustomerNotificationService::class))),
                Tables\Actions\Action::make('details')
                    ->label(fn (CustomerNotification $record): string => __('notifications.settings.details_attempts', ['count' => $record->attempts->count()]))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->modalHeading(__('notifications.settings.details_attempts', ['count' => '']))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-actions::modal.actions.close.label'))
                    ->modalContent(fn (CustomerNotification $record) => view('filament.pages.customer-notification-attempts', ['record' => $record])),
            ])
            ->emptyStateHeading(__('notifications.settings.no_matches'))
            ->emptyStateIcon('heroicon-o-bell-slash');
    }

    protected function getViewData(): array
    {
        $businessId = $this->business()->getKey();
        $statuses = ['queued', 'processing', 'retry_wait', 'accepted', 'delivered', 'failed', 'unknown', 'superseded', 'skipped'];
        $history = CustomerNotification::query()
            ->where('business_id', $businessId)
            ->with(['customer:id,name,email,phone', 'booking:id,reference', 'attempts'])
            ->when(in_array($this->historyStatus, $statuses, true), fn ($query) => $query->where('status', $this->historyStatus))
            ->when(in_array($this->historyEvent, CustomerNotificationService::EVENTS, true), fn ($query) => $query->where('event_type', $this->historyEvent))
            ->latest('created_at')
            ->limit(50)
            ->get();

        $settings = $this->settings();
        $warnings = [];
        foreach (CustomerNotificationService::CHANNELS as $channel) {
            if (($settings['channels'][$channel]['enabled'] ?? false) && ! $this->isConfigured($channel, $settings['channels'][$channel]['credentials'] ?? [])) {
                $warnings[$channel] = __('notifications.enabled_unconfigured');
            }
        }

        $channelStatuses = collect(CustomerNotificationService::CHANNELS)
            ->mapWithKeys(fn (string $channel): array => [$channel => isset($settings['channels'][$channel]['suspended_at'])
                ? 'suspended'
                : ($settings['channels'][$channel]['connection_status'] ?? 'unverified')])
            ->all();

        return [
            'history' => $history,
            'warnings' => $warnings,
            'channelStatuses' => $channelStatuses,
            'channelConfigured' => collect(CustomerNotificationService::CHANNELS)
                ->mapWithKeys(fn (string $channel): array => [$channel => $this->isConfigured($channel, $settings['channels'][$channel]['credentials'] ?? [])])
                ->all(),
            'historyStatuses' => $statuses,
            'historyEvents' => CustomerNotificationService::EVENTS,
        ];
    }

    private function settings(): array
    {
        return app(CustomerNotificationService::class)->settings($this->business())->settings;
    }

    private function isConfigured(string $channel, array $credentials): bool
    {
        return match ($channel) {
            'email' => filter_var($credentials['from_address'] ?? null, FILTER_VALIDATE_EMAIL)
                && filled($credentials['host'] ?? null)
                && (int) ($credentials['port'] ?? 0) > 0,
            default => filled($credentials['account_sid'] ?? null)
                && filled($credentials['auth_token'] ?? null)
                && filled($credentials['from'] ?? null),
        };
    }

    private function connectionStatus(string $channel, array $credentials): string
    {
        if (! $this->isConfigured($channel, $credentials)) {
            return 'unconfigured';
        }

        if ($channel === 'email') {
            return 'unverified';
        }

        try {
            $response = Http::withBasicAuth($credentials['account_sid'], $credentials['auth_token'])
                ->timeout(8)
                ->get('https://api.twilio.com/2010-04-01/Accounts/'.$credentials['account_sid'].'.json');

            return $response->successful() ? 'verified' : 'unverified';
        } catch (ConnectionException) {
            return 'unverified';
        }
    }

    private function validateTemplate(string $event, string $channel, array $template): void
    {
        foreach (['subject', 'body'] as $field) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', (string) ($template[$field] ?? ''), $matches);
            $unknown = array_diff($matches[1], CustomerNotificationService::PLACEHOLDERS);
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    "templates.{$event}.{$channel}.{$field}" => ['Unsupported placeholders: '.implode(', ', $unknown)],
                ]);
            }
        }
    }

    private function authorizeOwner(): void
    {
        $user = Filament::auth()->user();
        $business = $this->business();
        abort_unless($user && app(BusinessAccess::class)->role($user, $business) === BusinessRole::Owner, 403);
    }

    private function business(): Business
    {
        $business = Filament::getTenant();
        abort_unless($business instanceof Business, 404);

        return $business;
    }
}
