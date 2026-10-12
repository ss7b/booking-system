<x-filament-panels::page>
    <div class="space-y-6">
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left dark:border-gray-700">
                    <tr>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Branch access</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="px-4 py-3">{{ $member->name }}<div class="text-xs text-gray-500">{{ $member->email }}</div></td>
                            <td class="px-4 py-3">{{ str($member->pivot->role->value)->headline() }}</td>
                            <td class="px-4 py-3">{{ str($member->pivot->access_scope->value)->headline() }}</td>
                            <td class="px-4 py-3">
                                <div class="flex gap-3">
                                    <button type="button" class="text-primary-600" wire:click="editMember('{{ $member->getKey() }}')">Edit</button>
                                    <button type="button" class="text-danger-600" wire:click="removeMember('{{ $member->getKey() }}')" wire:confirm="Remove this business membership?">Remove</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form wire:submit="save" class="space-y-4 rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            {{ $this->form }}
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="submit">Save membership</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="$set('data.create_new_account', true)">Create new user account</x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>