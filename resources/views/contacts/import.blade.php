<x-app-layout title="Import contacts">
    <div class="mx-auto max-w-3xl">
        <x-page-header title="Import contacts from CSV" description="Existing numbers are updated, new numbers are created. Nothing is sent to WhatsApp.">
            <x-button variant="secondary" icon="arrow-left" :href="route('contacts.index')">Contacts</x-button>
        </x-page-header>

        @if (session('importResult'))
            @php $r = session('importResult'); @endphp
            <x-card class="mb-6" title="Import result">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10"><p class="text-2xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $r['created'] }}</p><p class="text-xs text-slate-500">created</p></div>
                    <div class="rounded-xl bg-sky-50 p-3 dark:bg-sky-500/10"><p class="text-2xl font-semibold text-sky-700 dark:text-sky-300">{{ $r['updated'] }}</p><p class="text-xs text-slate-500">updated</p></div>
                    <div class="rounded-xl bg-amber-50 p-3 dark:bg-amber-500/10"><p class="text-2xl font-semibold text-amber-700 dark:text-amber-300">{{ $r['skipped'] }}</p><p class="text-xs text-slate-500">skipped</p></div>
                </div>
                @if ($r['errors'])
                    <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-rose-700 dark:text-rose-300">
                        @foreach ($r['errors'] as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                @endif
            </x-card>
        @endif

        <form method="POST" action="{{ route('contacts.import') }}" enctype="multipart/form-data" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <x-card>
                <div class="grid gap-5">
                    <div>
                        <label class="form-label" for="whatsapp_account_id">Import into WhatsApp number</label>
                        <select id="whatsapp_account_id" name="whatsapp_account_id" class="form-input" required>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}" @selected($selected === $a->id)>{{ $a->label() }}</option>
                            @endforeach
                        </select>
                        @error('whatsapp_account_id')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="file">CSV file (max 5 MB, 5,000 rows)</label>
                        <input id="file" type="file" name="file" accept=".csv,text/csv" required class="block w-full text-sm file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-emerald-700 hover:file:bg-emerald-100 dark:file:bg-emerald-500/10 dark:file:text-emerald-300">
                        @error('file')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                        <p class="font-medium">Expected columns (first row = header)</p>
                        <p class="mt-1 text-slate-600 dark:text-slate-400"><code>phone</code> (required, with country code), <code>name</code>, <code>email</code>, <code>tags</code> (separated by <code>;</code>), <code>opt_in</code> (yes / no)</p>
                        <pre class="mt-3 overflow-x-auto rounded-lg bg-white p-3 text-xs dark:bg-slate-900">phone,name,email,tags,opt_in
+919876543210,Ravi Kumar,ravi@example.com,vip;delhi,yes
+15551234567,Jane Doe,,lead,</pre>
                    </div>
                </div>
            </x-card>
            <div class="mt-6 flex justify-end">
                <x-button icon="upload" ::disabled="busy"><span x-show="busy" class="spinner" x-cloak></span> Import contacts</x-button>
            </div>
        </form>
    </div>
</x-app-layout>
