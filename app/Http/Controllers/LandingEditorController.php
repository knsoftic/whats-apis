<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\LandingContent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Settings → Landing page: edit every text / image / video on the public home page. */
class LandingEditorController extends Controller
{
    public const DIR = 'uploads/landing';

    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    private const VIDEO_EXT = ['mp4', 'webm'];

    public function edit(): View
    {
        return view('settings.landing', [
            'content' => LandingContent::get(),
            'media' => $this->library(),
            'icons' => LandingContent::ICONS,
            'unread' => ContactMessage::unread()->count(),
            'uploadLimit' => ini_get('upload_max_filesize') ?: '2M',
        ]);
    }

    public function update(Request $request, ActivityLogger $log): RedirectResponse
    {
        $request->validate(['content' => ['required', 'string', 'max:300000']]);

        $input = json_decode((string) $request->input('content'), true);

        if (! is_array($input)) {
            return back()->with('error', 'Could not read the editor data — please try again.');
        }

        $clean = LandingContent::sanitize($input);
        LandingContent::save($clean);
        $log->log('landing.updated', 'Updated the landing page content');

        $redirect = redirect()->route('settings.landing')->with('success', 'Landing page saved.');
        $warnings = $this->warnings($input, $clean);

        return $warnings ? $redirect->with('warning', implode(' ', $warnings)) : $redirect;
    }

    public function reset(ActivityLogger $log): RedirectResponse
    {
        Setting::put(LandingContent::KEY, null);
        $log->log('landing.reset', 'Reset the landing page to the default content');

        return redirect()->route('settings.landing')->with('success', 'Landing page reset to the default content. Uploaded files were kept in the media library.');
    }

    /** Upload an image or video into public/uploads/landing (returns JSON for the editor). */
    public function upload(Request $request): JsonResponse
    {
        $type = $request->input('type') === 'video' ? 'video' : 'image';

        $request->validate([
            'file' => $type === 'video'
                ? ['required', 'file', 'mimetypes:video/mp4,application/mp4,video/webm', 'max:51200']
                : ['required', 'file', 'mimes:png,jpg,jpeg,webp,gif', 'max:4096'],
        ], [
            'file.max' => $type === 'video' ? 'Videos can be up to 50 MB.' : 'Images can be up to 4 MB.',
            'file.mimes' => 'Use a PNG, JPG, WebP or GIF image.',
            'file.mimetypes' => 'Use an MP4 or WebM video.',
            'file.uploaded' => 'The upload failed — the file may be bigger than the server limit ('.ini_get('upload_max_filesize').').',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $allowed = $type === 'video' ? self::VIDEO_EXT : self::IMAGE_EXT;

        if (! in_array($ext, $allowed, true)) {
            return response()->json(['message' => 'This file type is not allowed.'], 422);
        }

        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: $type;
        $name = Str::limit($base, 40, '').'-'.Str::lower(Str::random(6)).'.'.$ext;

        File::ensureDirectoryExists(public_path(self::DIR));
        $file->move(public_path(self::DIR), $name);

        return response()->json(['file' => $this->describe(public_path(self::DIR.'/'.$name))]);
    }

    public function deleteMedia(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'regex:#^uploads/landing/[A-Za-z0-9._\-]+$#']]);
        $full = public_path(self::DIR.'/'.basename($data['path']));

        if (is_file($full)) {
            File::delete($full);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * @return list<array{name: string, path: string, url: string, type: string, size: string}>
     */
    private function library(): array
    {
        $dir = public_path(self::DIR);

        if (! is_dir($dir)) {
            return [];
        }

        $files = collect(File::files($dir))
            ->filter(fn ($f) => in_array(strtolower($f->getExtension()), [...self::IMAGE_EXT, ...self::VIDEO_EXT], true))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->take(300);

        return $files->map(fn ($f) => $this->describe($f->getPathname()))->values()->all();
    }

    /**
     * @return array{name: string, path: string, url: string, type: string, size: string}
     */
    private function describe(string $fullPath): array
    {
        $name = basename($fullPath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = (int) @filesize($fullPath);

        return [
            'name' => $name,
            'path' => self::DIR.'/'.$name,
            'url' => asset(self::DIR.'/'.$name),
            'type' => in_array($ext, self::VIDEO_EXT, true) ? 'video' : 'image',
            'size' => $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB',
        ];
    }

    /**
     * Tell the admin about values the sanitizer had to drop.
     *
     * @return list<string>
     */
    private function warnings(array $input, array $clean): array
    {
        $warnings = [];
        $dropped = fn (string $section, string $field) => trim((string) ($input[$section][$field] ?? '')) !== '' && ($clean[$section][$field] ?? '') === '';

        if ($dropped('hero', 'youtube') || $dropped('video', 'youtube')) {
            $warnings[] = 'A YouTube link was not recognised and was removed (use a youtube.com/watch, youtu.be or shorts link).';
        }

        foreach ([['seo', 'og_image'], ['hero', 'image'], ['hero', 'video'], ['video', 'file']] as [$section, $field]) {
            if ($dropped($section, $field)) {
                $warnings[] = 'An image/video link was not a valid https:// address and was removed.';
                break;
            }
        }

        if ($dropped('contact', 'email')) {
            $warnings[] = 'The contact email address is not valid.';
        }

        return $warnings;
    }
}
