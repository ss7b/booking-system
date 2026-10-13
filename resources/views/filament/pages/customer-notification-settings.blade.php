<x-filament-panels::page>
    <form wire:submit="save" class="space-y-8">
        <section class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold">Provider connections</h2>
                    <p class="text-sm text-gray-500">Credentials are encrypted at rest. A saved credential does not imply successful message delivery.</p>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach (['email' => 'Email via SMTP', 'sms' => 'SMS via Twilio', 'whatsapp' => 'WhatsApp via Twilio'] as $channel => $label)
                    @php($status = $channelStatuses[$channel] ?? 'unverified')
                    @php($statusClasses = match ($status) {
                        'verified' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400',
                        'suspended' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400',
                        'unconfigured' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400',
                        default => 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-white/5 dark:text-gray-300',
                    })
                    @php($providerIcon = match ($channel) {
                        'email' => 'heroicon-o-envelope',
                        'sms' => 'heroicon-o-chat-bubble-left-right',
                        default => 'heroicon-o-phone',
                    })
                    <article class="flex min-w-0 flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <header class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                                <x-filament::icon :icon="$providerIcon" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold">{{ $label }}</h3>
                                <span @class(['mt-1 inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ring-1 ring-inset', $statusClasses])>
                                    {{ str($status)->replace('_', ' ')->headline() }}
                                </span>
                            </div>
                            <label class="inline-flex shrink-0 items-center gap-2 text-sm">
                                <span class="sr-only">Enable {{ $label }}</span>
                                <input type="checkbox" wire:model="channels.{{ $channel }}" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600">
                                <span>Enabled</span>
                            </label>
                        </header>

                        @if (isset($warnings[$channel]))
                            <p class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">{{ $warnings[$channel] }}</p>
                        @endif

                        <details class="group rounded-lg border border-gray-200 dark:border-gray-700" @if (! ($channelConfigured[$channel] ?? false)) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                                <span>Connection settings</span>
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4 text-gray-500 transition group-open:rotate-180" />
                            </summary>
                            <div class="space-y-3 border-t border-gray-200 p-3 dark:border-gray-700">
                                @if ($channel === 'email')
                                    <label class="block space-y-1 text-sm">
                                        <span>SMTP host</span>
                                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="credentials.email.host" placeholder="smtp.example.com" /></x-filament::input.wrapper>
                                        @error('credentials.email.host')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block space-y-1 text-sm">
                                            <span>Port</span>
                                            <x-filament::input.wrapper><x-filament::input type="number" wire:model="credentials.email.port" placeholder="587" /></x-filament::input.wrapper>
                                            @error('credentials.email.port')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                        </label>
                                        <label class="block space-y-1 text-sm">
                                            <span>Encryption</span>
                                            <select wire:model="credentials.email.encryption" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-950">
                                                <option value="starttls">STARTTLS</option><option value="ssl">SSL</option><option value="none">None</option>
                                            </select>
                                            @error('credentials.email.encryption')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                        </label>
                                    </div>
                                    <label class="block space-y-1 text-sm">
                                        <span>SMTP username</span>
                                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="credentials.email.username" /></x-filament::input.wrapper>
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>SMTP password</span>
                                        <x-filament::input.wrapper><x-filament::input type="password" wire:model="credentials.email.password" placeholder="Leave blank to keep saved password" autocomplete="new-password" /></x-filament::input.wrapper>
                                        @error('credentials.email.password')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block space-y-1 text-sm">
                                            <span>Sender email</span>
                                            <x-filament::input.wrapper><x-filament::input type="email" wire:model="credentials.email.from_address" /></x-filament::input.wrapper>
                                            @error('credentials.email.from_address')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                        </label>
                                        <label class="block space-y-1 text-sm">
                                            <span>Sender name</span>
                                            <x-filament::input.wrapper><x-filament::input type="text" wire:model="credentials.email.from_name" /></x-filament::input.wrapper>
                                        </label>
                                    </div>
                                @else
                                    <label class="block space-y-1 text-sm">
                                        <span>Twilio Account SID</span>
                                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="credentials.{{ $channel }}.account_sid" /></x-filament::input.wrapper>
                                        @error('credentials.'.$channel.'.account_sid')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Twilio Auth Token</span>
                                        <x-filament::input.wrapper><x-filament::input type="password" wire:model="credentials.{{ $channel }}.auth_token" placeholder="Leave blank to keep saved token" autocomplete="new-password" /></x-filament::input.wrapper>
                                        @error('credentials.'.$channel.'.auth_token')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                    <label class="block space-y-1 text-sm">
                                        <span>Sender number</span>
                                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="credentials.{{ $channel }}.from" placeholder="+15551234567" /></x-filament::input.wrapper>
                                        @error('credentials.'.$channel.'.from')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                @endif
                            </div>
                        </details>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
            <div>
                <h2 class="text-lg font-semibold">Notification events</h2>
                <p class="text-sm text-gray-500">Choose the channel order to use when delivery falls back. SMS and WhatsApp recipients need E.164 numbers.</p>
            </div>

            @foreach (['booking.created' => 'Booking confirmation', 'booking.reminder' => 'Appointment reminder', 'booking.rescheduled' => 'Booking rescheduled', 'booking.cancelled' => 'Booking cancelled'] as $event => $label)
                @php($formEvent = str_replace('.', '_', $event))
                @php($configuredChannels = array_values(array_filter($rules[$formEvent]['channels'] ?? [])))
                @php($channelLabels = ['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'])
                @php($channelSummary = $configuredChannels === [] ? 'No channels selected' : implode(' → ', array_map(fn ($channel) => $channelLabels[$channel] ?? $channel, $configuredChannels)))
                <details class="group rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 p-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                        <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition group-open:rotate-180" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold">{{ $label }}</span>
                            <span class="block text-xs text-gray-500">{{ $channelSummary }}</span>
                        </span>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="rules.{{ $formEvent }}.enabled" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600">
                            <span>Enabled</span>
                        </label>
                    </summary>

                    <div class="space-y-5 border-t border-gray-200 p-4 dark:border-gray-700">
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-semibold">Channel priority</legend>
                            <div class="grid gap-3 md:grid-cols-3">
                                @foreach (['Primary channel', 'Fallback channel 1', 'Fallback channel 2'] as $slot => $slotLabel)
                                    <label class="block space-y-1 text-sm">
                                        <span>{{ $slotLabel }}</span>
                                        <select wire:model="rules.{{ $formEvent }}.channels.{{ $slot }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-950">
                                            <option value="">Not selected</option>
                                            @foreach ($channelLabels as $channel => $channelLabel)
                                                <option value="{{ $channel }}">{{ $channelLabel }}</option>
                                            @endforeach
                                        </select>
                                        @error('rules.'.$formEvent.'.channels.'.$slot)<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>
                            @error('rules.'.$formEvent.'.channels')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                        </fieldset>

                        @if ($event === 'booking.reminder')
                            <label class="block max-w-sm space-y-1 text-sm">
                                <span>Reminder lead time (minutes)</span>
                                <x-filament::input.wrapper><x-filament::input type="number" min="1" max="525600" wire:model="rules.{{ $formEvent }}.lead_minutes" placeholder="1440" /></x-filament::input.wrapper>
                                @error('rules.'.$formEvent.'.lead_minutes')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                            </label>
                        @endif

                        <div class="space-y-3">
                            <h4 class="text-sm font-semibold">Message templates</h4>
                            @foreach ($channelLabels as $channel => $channelLabel)
                                <details class="group rounded-lg border border-gray-200 dark:border-gray-700">
                                    <summary class="flex cursor-pointer list-none items-center gap-3 px-3 py-2.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">
                                        <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition group-open:rotate-180" />
                                        <span class="min-w-0 flex-1 font-medium">{{ $channelLabel }}</span>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" wire:model="templates.{{ $formEvent }}.{{ $channel }}.enabled" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600">
                                            <span class="text-xs">Enabled</span>
                                        </label>
                                    </summary>
                                    @if ($templates[$formEvent][$channel]['enabled'] ?? false)
                                        <div class="space-y-3 border-t border-gray-200 p-3 dark:border-gray-700">
                                            @if ($channel === 'email')
                                                <label class="block space-y-1 text-sm">
                                                    <span>Email subject</span>
                                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="templates.{{ $formEvent }}.{{ $channel }}.subject" placeholder="Subject" /></x-filament::input.wrapper>
                                                    @error('templates.'.$formEvent.'.'.$channel.'.subject')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                                </label>
                                            @endif
                                            @if ($channel === 'whatsapp')
                                                <label class="block space-y-1 text-sm">
                                                    <span>Approved Twilio Content SID</span>
                                                    <x-filament::input.wrapper><x-filament::input type="text" wire:model="templates.{{ $formEvent }}.{{ $channel }}.content_sid" placeholder="HX..." /></x-filament::input.wrapper>
                                                    @error('templates.'.$formEvent.'.'.$channel.'.content_sid')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                                </label>
                                            @endif
                                            <label class="block space-y-1 text-sm">
                                                <span>Message body</span>
                                                <textarea wire:model="templates.{{ $formEvent }}.{{ $channel }}.body" rows="3" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-950" placeholder="Message body"></textarea>
                                                @error('templates.'.$formEvent.'.'.$channel.'.body')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
                                            </label>
                                        </div>
                                    @else
                                        <p class="border-t border-gray-200 px-3 py-2 text-xs text-gray-500 dark:border-gray-700">Template disabled. Saved content is retained.</p>
                                    @endif
                                </details>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endforeach
        </section>

        <div class="sticky bottom-0 z-10 -mx-4 flex justify-end border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-950/95 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:target="save" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Save customer notification settings</span>
                <span wire:loading wire:target="save">Saving...</span>
            </x-filament::button>
        </div>
    </form>

    <section class="space-y-4 border-t border-gray-200 pt-6 dark:border-gray-700">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Recent customer notifications</h2>
                <p class="text-sm text-gray-500">Provider acceptance and customer delivery are separate states.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <label class="sr-only" for="notification-status-filter">Filter by status</label>
                <select id="notification-status-filter" wire:model.live="historyStatus" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">All statuses</option>
                    @foreach ($historyStatuses as $status)
                        <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->headline() }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="notification-event-filter">Filter by event</label>
                <select id="notification-event-filter" wire:model.live="historyEvent" class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-950">
                    <option value="">All events</option>
                    @foreach ($historyEvents as $event)
                        <option value="{{ $event }}">{{ str($event)->replace('.', ' ')->headline() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full min-w-[52rem] divide-y divide-gray-200 text-start dark:divide-gray-700">
                <thead class="bg-gray-50 text-xs font-medium uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th scope="col" class="px-4 py-3">Booking</th>
                        <th scope="col" class="px-4 py-3">Customer</th>
                        <th scope="col" class="px-4 py-3">Event</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Created</th>
                        <th scope="col" class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($history as $notification)
                        @php($statusClasses = match ($notification->status) {
                            'delivered' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
                            'failed' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
                            'accepted' => 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400',
                            'retry_wait', 'queued', 'processing' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
                            default => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
                        })
                        <tr class="align-top">
                            <td class="px-4 py-3 text-sm font-medium">{{ $notification->booking?->reference ?? 'Unavailable' }}</td>
                            <td class="px-4 py-3 text-sm">{{ $notification->customer?->name ?? 'Unavailable' }}</td>
                            <td class="px-4 py-3 text-sm">{{ str($notification->event_type)->replace('.', ' ')->headline() }}</td>
                            <td class="px-4 py-3"><span @class(['inline-flex rounded-full px-2 py-1 text-xs font-medium', $statusClasses])>{{ str($notification->status)->replace('_', ' ')->headline() }}</span></td>
                            <td class="px-4 py-3 text-sm text-gray-500" title="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</td>
                            <td class="space-y-2 px-4 py-3">
                                @if ($notification->status === 'failed')
                                    <x-filament::button color="warning" size="sm" icon="heroicon-o-arrow-path" wire:click="resend('{{ $notification->getKey() }}')" wire:target="resend('{{ $notification->getKey() }}')" wire:loading.attr="disabled">
                                        Resend
                                    </x-filament::button>
                                @endif
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:text-primary-400">Details · {{ $notification->attempts->count() }} attempts</summary>
                                    <div class="mt-2 max-w-sm space-y-2 text-xs text-gray-600 dark:text-gray-300">
                                        @if ($notification->error_message)
                                            <p><span class="font-semibold">{{ $notification->error_code }}:</span> {{ $notification->error_message }}</p>
                                        @endif
                                        @forelse ($notification->attempts as $attempt)
                                            <div class="border-s-2 border-gray-200 ps-2 dark:border-gray-700">
                                                <p>{{ $attempt->created_at->format('Y-m-d H:i:s') }} · {{ $attempt->channel }} · {{ str($attempt->status)->headline() }}</p>
                                                @if ($attempt->provider_message_id)<p>Provider ID: {{ $attempt->provider_message_id }}</p>@endif
                                                @if ($attempt->error_code || $attempt->error_message)<p>{{ $attempt->error_code }} {{ $attempt->error_message }}</p>@endif
                                            </div>
                                        @empty
                                            <p>No delivery attempts recorded.</p>
                                        @endforelse
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">No customer notifications match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>