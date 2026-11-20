<x-app-layout title="Landing page">
    @php
        $cfg = [
            'media' => $media,
            'base' => rtrim(asset(''), '/'),
            'uploadUrl' => route('settings.landing.media'),
            'deleteUrl' => route('settings.landing.media.destroy'),
            'uploadLimit' => $uploadLimit,
        ];
        $row = 'rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-800/40';
        $addBtn = 'inline-flex items-center gap-1.5 rounded-xl border border-dashed border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:border-brand-400 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-300 dark:hover:text-brand-400';
    @endphp

    <div class="mx-auto max-w-4xl" x-data="landingEditor(@js($content), @js($cfg))">
        @include('settings._tabs', ['section' => 'landing'])

        {{-- Icon sprite used by the icon picker and previews. --}}
        <div hidden>
            @foreach ($icons as $ic)<span data-icon="{{ $ic }}"><x-icon :name="$ic" class="h-5 w-5" /></span>@endforeach
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-brand-200 bg-brand-50/60 p-4 text-sm text-brand-900 dark:border-brand-500/30 dark:bg-brand-500/10 dark:text-brand-100">
            <x-icon name="info" class="h-5 w-5 shrink-0" />
            <p class="min-w-0 flex-1">Everything on your public home page ({{ parse_url(url('/'), PHP_URL_HOST) }}) is edited here. Switch sections on/off, change texts, upload images and videos, then <strong>Save</strong>. Login and Register buttons always show at the top.</p>
            <x-button variant="secondary" size="sm" icon="eye" :href="url('/')" target="_blank" rel="noopener">Preview</x-button>
            <x-button variant="secondary" size="sm" icon="inbox" :href="route('contact-messages.index')">Messages{{ $unread ? ' ('.$unread.')' : '' }}</x-button>
        </div>

        <form method="POST" action="{{ route('settings.landing.update') }}" x-on:submit="prepare()">
            @csrf @method('PUT')
            <input type="hidden" name="content" x-ref="json">

            <div class="space-y-3">
                {{-- ------------------------------------------------ Hero --}}
                <x-landing.section key="hero" title="Hero (top banner)" description="Headline, buttons and the main image or video" icon="photo">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="form-label" for="hero_badge">Small badge above the title</label>
                            <input id="hero_badge" type="text" class="form-input" x-model="c.hero.badge" maxlength="60">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="form-label" for="hero_title">Main title</label>
                            <textarea id="hero_title" rows="2" class="form-input" x-model="c.hero.title" maxlength="120"></textarea>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="form-label" for="hero_sub">Subtitle</label>
                            <textarea id="hero_sub" rows="3" class="form-input" x-model="c.hero.subtitle" maxlength="400"></textarea>
                        </div>
                        <div>
                            <label class="form-label" for="hero_p">Main button text</label>
                            <input id="hero_p" type="text" class="form-input" x-model="c.hero.primary_text" maxlength="30">
                            <p class="form-help">Opens the Register page (or Login if registration is off).</p>
                        </div>
                        <div>
                            <label class="form-label" for="hero_s">Second button text</label>
                            <input id="hero_s" type="text" class="form-input" x-model="c.hero.secondary_text" maxlength="30">
                            <p class="form-help">Scrolls to the video or "How it works". Leave empty to hide.</p>
                        </div>
                    </div>

                    <div>
                        <span class="form-label">Right-side media</span>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @foreach (['image' => ['Image', 'photo'], 'video' => ['Video file', 'paperclip'], 'youtube' => ['YouTube', 'link'], 'none' => ['Nothing', 'x']] as $val => [$label, $ic])
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm font-medium transition" x-bind:class="c.hero.media_type === '{{ $val }}' ? 'border-brand-500 bg-brand-50 text-brand-800 dark:bg-brand-500/10 dark:text-brand-200' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'">
                                    <input type="radio" class="sr-only" value="{{ $val }}" x-model="c.hero.media_type">
                                    <x-icon :name="$ic" class="h-4 w-4" /> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div x-show="c.hero.media_type === 'image' || c.hero.media_type === 'video'">
                        <x-landing.media-field path="hero.image" type="image" label="Hero image" help="PNG/JPG/WebP up to 4 MB, about 1200×900. Empty = built-in inbox illustration. With a video it is used as the poster." />
                    </div>
                    <div x-show="c.hero.media_type === 'video'" x-cloak>
                        <x-landing.media-field path="hero.video" type="video" label="Hero video (plays muted, looped)" help="MP4/WebM up to 50 MB (server limit {{ $uploadLimit }}). Keep it short — 10–30 seconds." />
                    </div>
                    <div x-show="c.hero.media_type === 'youtube'" x-cloak>
                        <label class="form-label" for="hero_yt">YouTube link</label>
                        <input id="hero_yt" type="url" class="form-input" x-model.lazy="c.hero.youtube" placeholder="https://www.youtube.com/watch?v=…">
                        <p class="form-help">youtube.com/watch, youtu.be or /shorts links work.</p>
                    </div>
                </x-landing.section>

                {{-- ------------------------------------------------ Stats --}}
                <x-landing.section key="stats" title="Numbers strip" description="Short highlights under the hero" icon="chart">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <template x-for="(item, i) in c.stats.items" :key="item._id">
                            <div class="{{ $row }} flex items-start gap-3">
                                <div class="grid min-w-0 flex-1 gap-2">
                                    <input type="text" class="form-input" x-model="item.value" maxlength="20" placeholder="e.g. 24/7" aria-label="Value">
                                    <input type="text" class="form-input" x-model="item.label" maxlength="40" placeholder="Label" aria-label="Label">
                                </div>
                                <x-landing.item-tools section="stats" />
                            </div>
                        </template>
                    </div>
                    <button type="button" class="{{ $addBtn }}" x-on:click="add('stats', { value: '', label: '' })" x-bind:disabled="c.stats.items.length >= 6"><x-icon name="plus" class="h-4 w-4" /> Add number</button>
                </x-landing.section>

                {{-- ------------------------------------------------ Features --}}
                <x-landing.section key="features" title="Features" description="Cards with an icon, title and text" icon="bolt">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Section title</label><input type="text" class="form-input" x-model="c.features.title" maxlength="100"></div>
                        <div><label class="form-label">Subtitle</label><input type="text" class="form-input" x-model="c.features.subtitle" maxlength="300"></div>
                    </div>
                    <div class="space-y-3">
                        <template x-for="(item, i) in c.features.items" :key="item._id">
                            <div class="{{ $row }} flex items-start gap-3">
                                <button type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-brand-600 hover:border-brand-500 dark:border-slate-600 dark:bg-slate-900 dark:text-brand-400" x-on:click="pickIcon(item)" title="Change icon" x-html="iconSvg(item.icon)"></button>
                                <div class="grid min-w-0 flex-1 gap-2">
                                    <input type="text" class="form-input" x-model="item.title" maxlength="60" placeholder="Feature title" aria-label="Title">
                                    <textarea rows="2" class="form-input" x-model="item.text" maxlength="220" placeholder="Short description" aria-label="Text"></textarea>
                                </div>
                                <x-landing.item-tools section="features" />
                            </div>
                        </template>
                    </div>
                    <button type="button" class="{{ $addBtn }}" x-on:click="add('features', { icon: 'check', title: '', text: '' })" x-bind:disabled="c.features.items.length >= 12"><x-icon name="plus" class="h-4 w-4" /> Add feature</button>
                </x-landing.section>

                {{-- ------------------------------------------------ Steps --}}
                <x-landing.section key="steps" title="How it works" description="Numbered steps" icon="refresh">
                    <div><label class="form-label">Section title</label><input type="text" class="form-input" x-model="c.steps.title" maxlength="100"></div>
                    <div class="space-y-3">
                        <template x-for="(item, i) in c.steps.items" :key="item._id">
                            <div class="{{ $row }} flex items-start gap-3">
                                <div class="grid min-w-0 flex-1 gap-2">
                                    <input type="text" class="form-input" x-model="item.title" maxlength="60" placeholder="Step title" aria-label="Title">
                                    <textarea rows="2" class="form-input" x-model="item.text" maxlength="220" placeholder="What happens in this step" aria-label="Text"></textarea>
                                </div>
                                <x-landing.item-tools section="steps" />
                            </div>
                        </template>
                    </div>
                    <button type="button" class="{{ $addBtn }}" x-on:click="add('steps', { title: '', text: '' })" x-bind:disabled="c.steps.items.length >= 6"><x-icon name="plus" class="h-4 w-4" /> Add step</button>
                </x-landing.section>

                {{-- ------------------------------------------------ Video --}}
                <x-landing.section key="video" title="Video section" description="A YouTube video or your own uploaded video" icon="eye">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Title</label><input type="text" class="form-input" x-model="c.video.title" maxlength="100"></div>
                        <div><label class="form-label">Subtitle</label><input type="text" class="form-input" x-model="c.video.subtitle" maxlength="300"></div>
                    </div>
                    <div>
                        <label class="form-label" for="video_yt">YouTube link</label>
                        <input id="video_yt" type="url" class="form-input" x-model.lazy="c.video.youtube" placeholder="https://www.youtube.com/watch?v=…">
                        <p class="form-help">If a YouTube link is set it is shown; otherwise the uploaded video below.</p>
                    </div>
                    <x-landing.media-field path="video.file" type="video" label="…or upload a video" help="MP4/WebM up to 50 MB (server limit {{ $uploadLimit }}). Shown with play controls." />
                    <p class="text-xs text-amber-700 dark:text-amber-400" x-show="c.video.enabled && !c.video.youtube && !c.video.file" x-cloak>Add a YouTube link or a video — otherwise this section stays hidden.</p>
                </x-landing.section>

                {{-- ------------------------------------------------ Pricing --}}
                <x-landing.section key="pricing" title="Pricing" description="Shows your active plans automatically" icon="chart">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Title</label><input type="text" class="form-input" x-model="c.pricing.title" maxlength="100"></div>
                        <div><label class="form-label">Subtitle</label><input type="text" class="form-input" x-model="c.pricing.subtitle" maxlength="300"></div>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Plan names, prices and limits come from
                        @if (Route::has('plans.index'))<a href="{{ route('plans.index') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">Plans</a>@else Plans @endif
                        — only active plans are shown.</p>
                </x-landing.section>

                {{-- ------------------------------------------------ Testimonials --}}
                <x-landing.section key="testimonials" title="Testimonials" description="What customers say" icon="chat">
                    <div><label class="form-label">Section title</label><input type="text" class="form-input" x-model="c.testimonials.title" maxlength="100"></div>
                    <div class="space-y-3">
                        <template x-for="(item, i) in c.testimonials.items" :key="item._id">
                            <div class="{{ $row }} flex items-start gap-3">
                                <div class="grid min-w-0 flex-1 gap-2 sm:grid-cols-2">
                                    <input type="text" class="form-input" x-model="item.name" maxlength="60" placeholder="Name" aria-label="Name">
                                    <input type="text" class="form-input" x-model="item.role" maxlength="60" placeholder="Role / company" aria-label="Role">
                                    <textarea rows="2" class="form-input sm:col-span-2" x-model="item.text" maxlength="400" placeholder="Quote" aria-label="Quote"></textarea>
                                </div>
                                <x-landing.item-tools section="testimonials" />
                            </div>
                        </template>
                    </div>
                    <button type="button" class="{{ $addBtn }}" x-on:click="add('testimonials', { name: '', role: '', text: '' })" x-bind:disabled="c.testimonials.items.length >= 9"><x-icon name="plus" class="h-4 w-4" /> Add testimonial</button>
                </x-landing.section>

                {{-- ------------------------------------------------ FAQ --}}
                <x-landing.section key="faq" title="FAQ" description="Questions and answers" icon="info">
                    <div><label class="form-label">Section title</label><input type="text" class="form-input" x-model="c.faq.title" maxlength="100"></div>
                    <div class="space-y-3">
                        <template x-for="(item, i) in c.faq.items" :key="item._id">
                            <div class="{{ $row }} flex items-start gap-3">
                                <div class="grid min-w-0 flex-1 gap-2">
                                    <input type="text" class="form-input" x-model="item.q" maxlength="200" placeholder="Question" aria-label="Question">
                                    <textarea rows="3" class="form-input" x-model="item.a" maxlength="1000" placeholder="Answer" aria-label="Answer"></textarea>
                                </div>
                                <x-landing.item-tools section="faq" />
                            </div>
                        </template>
                    </div>
                    <button type="button" class="{{ $addBtn }}" x-on:click="add('faq', { q: '', a: '' })" x-bind:disabled="c.faq.items.length >= 20"><x-icon name="plus" class="h-4 w-4" /> Add question</button>
                </x-landing.section>

                {{-- ------------------------------------------------ Contact --}}
                <x-landing.section key="contact" title="Contact" description="Your contact details and the contact form" icon="envelope">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Title</label><input type="text" class="form-input" x-model="c.contact.title" maxlength="100"></div>
                        <div><label class="form-label">Subtitle</label><input type="text" class="form-input" x-model="c.contact.subtitle" maxlength="300"></div>
                        <div>
                            <label class="form-label" for="ct_email">Email</label>
                            <input id="ct_email" type="email" class="form-input" x-model="c.contact.email" maxlength="150" placeholder="{{ $brand->get('site.support_email') ?: 'support@'.parse_url(url('/'), PHP_URL_HOST) }}">
                            <p class="form-help">Empty = support email from General. Form messages are also emailed here (if SMTP is set up).</p>
                        </div>
                        <div>
                            <label class="form-label" for="ct_phone">Phone</label>
                            <input id="ct_phone" type="text" class="form-input" x-model="c.contact.phone" maxlength="40" placeholder="{{ $brand->get('site.support_phone') ?: '+92 300 0000000' }}">
                        </div>
                        <div>
                            <label class="form-label" for="ct_wa">WhatsApp number</label>
                            <input id="ct_wa" type="text" class="form-input" x-model="c.contact.whatsapp" maxlength="20" placeholder="+923001234567">
                            <p class="form-help">With country code. Also adds a floating WhatsApp chat button.</p>
                        </div>
                        <div>
                            <label class="form-label" for="ct_addr">Address</label>
                            <textarea id="ct_addr" rows="2" class="form-input" x-model="c.contact.address" maxlength="300"></textarea>
                        </div>
                    </div>
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" x-model="c.contact.show_form">
                        Show the contact form — messages arrive in <a href="{{ route('contact-messages.index') }}" class="font-medium text-brand-700 hover:underline dark:text-brand-400">Contact messages</a>
                    </label>
                </x-landing.section>

                {{-- ------------------------------------------------ CTA --}}
                <x-landing.section key="cta" title="Bottom call-to-action" description="Coloured banner before the footer" icon="send">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="form-label">Title</label><input type="text" class="form-input" x-model="c.cta.title" maxlength="100"></div>
                        <div><label class="form-label">Button text</label><input type="text" class="form-input" x-model="c.cta.button" maxlength="30"></div>
                        <div class="sm:col-span-2"><label class="form-label">Subtitle</label><input type="text" class="form-input" x-model="c.cta.subtitle" maxlength="300"></div>
                    </div>
                </x-landing.section>

                {{-- ------------------------------------------------ Footer --}}
                <x-landing.section key="footer" title="Footer" description="Short text under your logo" icon="note" :toggle="false">
                    <div>
                        <label class="form-label" for="ft_text">Footer text</label>
                        <textarea id="ft_text" rows="2" class="form-input" x-model="c.footer.text" maxlength="300" placeholder="e.g. Official WhatsApp Business solutions for growing teams."></textarea>
                        <p class="form-help">Logo and name come from Settings → General. Privacy and Terms links are added automatically.</p>
                    </div>
                </x-landing.section>

                {{-- ------------------------------------------------ SEO --}}
                <x-landing.section key="seo" title="SEO & sharing" description="Google title/description and the image shown when the link is shared" icon="search" :toggle="false">
                    <div>
                        <label class="form-label" for="seo_title">Page title</label>
                        <input id="seo_title" type="text" class="form-input" x-model="c.seo.title" maxlength="70" placeholder="{{ $brand->siteName() }} – {{ $brand->tagline() }}">
                        <p class="form-help"><span x-text="(c.seo.title || '').length"></span>/70 · empty = site name and tagline.</p>
                    </div>
                    <div>
                        <label class="form-label" for="seo_desc">Description</label>
                        <textarea id="seo_desc" rows="2" class="form-input" x-model="c.seo.description" maxlength="200"></textarea>
                        <p class="form-help"><span x-text="(c.seo.description || '').length"></span>/200</p>
                    </div>
                    <x-landing.media-field path="seo.og_image" type="image" label="Share image (WhatsApp / Facebook preview)" help="1200×630 works best. Empty = hero image or logo." />
                </x-landing.section>
            </div>

            <div class="sticky bottom-0 z-20 -mx-4 mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95 sm:mx-0 sm:rounded-2xl sm:border">
                <span class="mr-auto flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400" x-show="dirty" x-cloak><span class="h-2 w-2 rounded-full bg-amber-500"></span> Unsaved changes</span>
                <x-button variant="secondary" icon="eye" :href="url('/')" target="_blank" rel="noopener">Preview</x-button>
                <x-button icon="check" x-bind:disabled="saving"><span x-show="saving" x-cloak class="spinner"></span> Save landing page</x-button>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.landing.reset') }}" class="mt-6 text-right" onsubmit="return confirm('Reset every landing page text to the default content? Uploaded files stay in the media library.')">
            @csrf
            <button type="submit" class="text-xs font-medium text-slate-500 hover:text-rose-600">Reset landing page to default content</button>
        </form>

        {{-- ------------------------------------------------ Media library modal --}}
        <div x-show="library.open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" x-on:keydown.escape.window="library.open = false">
            <div class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl dark:bg-slate-900 sm:rounded-2xl" x-on:click.outside="library.open = false">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <h2 class="font-semibold text-slate-900 dark:text-white">Media library <span class="text-sm font-normal text-slate-500" x-text="'· ' + (library.type === 'video' ? 'videos' : 'images')"></span></h2>
                    <button type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" x-on:click="library.open = false" aria-label="Close"><x-icon name="x" class="h-5 w-5" /></button>
                </div>
                <div class="overflow-y-auto p-5">
                    <p class="py-10 text-center text-sm text-slate-500" x-show="libraryItems().length === 0">Nothing uploaded yet — use the Upload button.</p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                        <template x-for="file in libraryItems()" :key="file.path">
                            <div class="group overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700" x-bind:class="get(library.field) === file.path && 'ring-2 ring-brand-500'">
                                <button type="button" class="block aspect-video w-full bg-slate-100 dark:bg-slate-800" x-on:click="choose(file)" x-bind:title="'Use ' + file.name">
                                    <template x-if="file.type === 'image'"><img x-bind:src="file.url" class="h-full w-full object-cover" alt="" loading="lazy"></template>
                                    <template x-if="file.type === 'video'"><video x-bind:src="file.url" class="h-full w-full bg-black object-cover" muted preload="metadata"></video></template>
                                </button>
                                <div class="flex items-center gap-1 px-2 py-1.5">
                                    <span class="min-w-0 flex-1 truncate text-[11px] text-slate-500" x-text="file.name + ' · ' + file.size"></span>
                                    <button type="button" class="rounded p-1 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-500/10" x-on:click="deleteMedia(file)" aria-label="Delete file"><x-icon name="trash" class="h-3.5 w-3.5" /></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------ Icon picker modal --}}
        <div x-show="iconPicker.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" x-on:keydown.escape.window="iconPicker.open = false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-slate-900" x-on:click.outside="iconPicker.open = false">
                <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">Choose an icon</h2>
                <div class="grid grid-cols-5 gap-2 sm:grid-cols-6">
                    @foreach ($icons as $ic)
                        <button type="button" class="flex aspect-square items-center justify-center rounded-xl border text-slate-600 hover:border-brand-500 hover:text-brand-600 dark:text-slate-300" x-bind:class="iconPicker.item && iconPicker.item.icon === '{{ $ic }}' ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10' : 'border-slate-200 dark:border-slate-700'" x-on:click="setIcon('{{ $ic }}')" title="{{ $ic }}">
                            <x-icon :name="$ic" class="h-5 w-5" />
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
